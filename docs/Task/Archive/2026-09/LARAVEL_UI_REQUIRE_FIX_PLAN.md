# laravel/ui require-dev → require 迁移

**Status**: ✅ Completed
**日期**: 2026-09-23
**分支**: `feat/bootstrap-5-upgrade`
**类型**: 部署配置 / 运行时依赖误归类

---

## 1. 问题与背景

生产服务器（Laravel 12 应用）出现以下运行时异常：

```
RuntimeException: In order to use the Auth::routes() method, please install the laravel/ui package.
  at vendor/laravel/framework/src/Illuminate/Support/Facades/Auth.php:93
  triggered by routes/web.php:49 → Auth::routes($authRoutes)
```

错误源在 Laravel 12 框架 `Auth` Facade：

```php
// vendor/laravel/framework/src/Illuminate/Support/Facades/Auth.php:91-99
public static function routes(array $options = [])
{
    if (! static::$app->providerIsLoaded(UiServiceProvider::class)) {
        throw new RuntimeException(
            'In order to use the Auth::routes() method, please install the laravel/ui package.'
        );
    }
    static::$app->make('router')->auth($options);
}
```

`providerIsLoaded(Laravel\Ui\UiServiceProvider::class)` 在生产为 `false` —— 因为 `laravel/ui` 不在 `vendor/`。

### 应用代码对 `laravel/ui` 的实际依赖

| 文件 | 行 | 引用 |
|---|---|---|
| `routes/web.php` | 49 | `Auth::routes($authRoutes)`（直接调用） |
| `app/Http/Controllers/Auth/LoginController.php` | 26 | `use Illuminate\Foundation\Auth\AuthenticatesUsers;` |
| `app/Http/Controllers/Auth/RegisterController.php` | 25 | `use Illuminate\Foundation\Auth\RegistersUsers, UserActivateChannel;` |
| `app/Http/Controllers/Auth/ForgotPasswordController.php` | — | `use SendsPasswordResetEmails;` |
| `app/Http/Controllers/Auth/ResetPasswordController.php` | — | `use ResetsPasswords;` |

第二组的 trait 命名空间 `Illuminate\Foundation\Auth\*` 由 `laravel/ui` 的 PSR-4 autoload 映射提供：

```json
// vendor/laravel/ui/composer.json
"autoload": {
    "psr-4": {
        "Laravel\\Ui\\": "src/",
        "Illuminate\\Foundation\\Auth\\": "auth-backend/"
    }
}
```

→ **应用代码在运行时强依赖 `laravel/ui` 包**。

## 2. 历史脉络

| commit | 时间 | 操作 | 评价 |
|---|---|---|---|
| 早期 | — | `laravel/ui` 长期在 `require-dev` | 巧合：dev 环境总跑 `--dev` 没问题 |
| `134b961 chore(ci+deps)` | 2026-09-20 15:25 | 从 `require-dev` 移除 `laravel/ui`，commit message 写 "前端脚手架残留" | **误判**：`laravel/ui` 同时承载 `Auth::routes()` 注册与 `AuthenticatesUsers`/`RegistersUsers`/`ResetsPasswords`/`SendsPasswordResetEmails`/`ConfirmsPasswords`/`ThrottlesLogins` 等运行时 trait，不仅是 `php artisan ui vue/react` 工具 |
| `e2f1d17 feat(sentry)` | 2026-09-20 17:41 | 重新加回 `require-dev` | 解决了 dev 跑通，但 `require-dev`/`require` 归类仍错 |

注意：`134b961` 同步把 `composer.lock` 减了 ~11000 行（小但精准的 nosync 校验），加回后需重新 lock 一遍。

## 3. 修复方案

**唯一正确的代码级修复**：将 `laravel/ui` 从 `require-dev` 迁移到 `require`，并刷新 `composer.lock`。

### 3.1 编辑对象

- `composer.json`：第 42 行的 `laravel/ui` 配置块
- `composer.lock`：依赖部分（autoload 段、内容哈希）

### 3.2 步骤

1. 编辑 `composer.json`：把 `"laravel/ui": "^4.6"` 从 `require-dev` 块删除，加入 `require` 块（按字母序插入 `jdenticon` 后）。
2. 跑 `composer update laravel/ui --no-interaction` 刷新 lock（小范围更新，不影响其他包）。
3. 跑 `composer dump-autoload --optimize` 确认 autoload 索引重建。
4. 验证：
   - `php -r 'require "vendor/autoload.php"; var_dump(class_exists("Laravel\\Ui\\UiServiceProvider"));'` → `true`
   - `php artisan route:list` 应当看到 `login`、`register`、`password/email`、`password/reset` 等 13 条 auth 路由。
5. 提交 `composer.json` + `composer.lock`。

### 3.3 不推荐的方案（已讨论过、明确放弃）

- **手动注册 `Laravel\Ui\UiServiceProvider` 到 `bootstrap/providers.php`**：仅能让 `Auth::routes()` 不抛，控制器里的 `Illuminate\Foundation\Auth\*` trait 仍依赖包 PSR-4 autoload，治标不治本。
- **用 `composer require laravel/ui` 在生产补包**：缓解但不固化配置，下次部署还会漂移。
- **重写为 Laravel 12 内置 Auth 路由（手动注册）**：与现行 `Auth::routes()` 行为等价的工作量大几个 PR 级别，超出本次 bug 范围。

## 4. 验收标准

| 项 | 验证方法 | 期望 |
|---|---|---|
| 配置文件 | `grep -n laravel/ui composer.json` | `require` 块 1 处，`require-dev` 块 0 处 |
| 包安装 | `php -r 'require "vendor/autoload.php"; var_dump(class_exists("Laravel\\Ui\\UiServiceProvider"));'` | `bool(true)` |
| Provider 已注册 | `php artisan route:list \| grep -E "^.*auth/ui"` | 空（Laravel 不暴露内部 provider 名称） |
| Auth 路由 | `php artisan route:list \| grep -E "login\|register\|password"` | 至少看到 `login`、`register`、`password/email`、`password/reset`、`logout` |
| 现有测试 | `vendor/bin/phpunit` | 全绿（不应有新增故障） |
| 生产部署手册 | 见 `### 4.1` | `composer install --no-dev --optimize-autoloader` 可拉到 `vendor/laravel/ui` |

### 4.1 生产部署运行手册（顺手）

```bash
cd /www/wwwroot/wizard
git pull   # 或 rsync composer.json + composer.lock
composer install --no-dev --optimize-autoloader
php artisan route:list | grep -c login   # 期望非 0
```

## 5. 风险

| 风险 | 概率 | 影响 | 缓解 |
|---|---|---|---|
| 改 `require` 段破坏现有自动排序 | 低 | 极小 | 按字母序插入，diff 清晰 |
| `composer update laravel/ui` 同时拉到新版本 | 低 | 极小 | lock 当前 `4.6.3`，`^4.6` 不破坏 |
| Linux / Windows 路径差异导致 autoload 错 | — | — | 走 Laravel 内置 PSR-4，与平台无关 |

## 6. 实施日志

- [x] 2026-09-23 — 根因已分析
- [x] 2026-09-23 — 任务文档 `docs/Task/Active/LARAVEL_UI_REQUIRE_FIX_PLAN.md` 创建
- [x] 2026-09-23 — `composer.json` 编辑:`require-dev` 删除,`require` 加入
- [x] 2026-09-23 — `composer update laravel/ui --ignore-platform-req=ext-sodium`(本机 dev PHP 缺 sodium,需要 ignore;CI/生产不需要)
- [x] 2026-09-23 — 验证(`providerIsLoaded=true`,route:list 见 login/register/password,phpunit 102 tests 通过)
- [ ] 2026-09-23 — 提交 + 归档

## 7. 后续观察

- 任务管理章程规定:`composer.json` 不得再把 `laravel/ui` 降级到 `require-dev`(Laravel 12 起 `Auth::routes()` 强依赖,前端 scaffold 残留是 `php artisan ui vue` 等 CLI,与运行时无关)。
- 后续如有 Laravel 12 风格的认证迁移(移除 `Auth::routes()` 改 Breeze/Jetstream 或手动路由),此依赖可正式退役——届时按新架构审慎迁移。
