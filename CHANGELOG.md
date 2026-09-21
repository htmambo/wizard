# CHANGELOG

本项目的所有重要变更记录于此。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## 如何贡献

每次合入非平凡变更（bug、特性、API 变更、安全修复）时请在本文件 `[Unreleased]` 段补一行。维护原则：

- **Fixed** / **Security**：bug 或安全修复
- **Added** / **Changed** / **Removed**：新增 / 行为变更 / 移除
- **Deprecated**：计划移除的接口或字段（注明版本）
- **Documentation**：文档或注释

不必发版本号——release 时统一打 tag + 把 `[Unreleased]` 改为 `[x.y.z] - YYYY-MM-DD`。

## [Unreleased]

### Fixed
- **API `lists()` 用户组过滤 bug**：原实现使用 `$user->groups->toArray()` 将二维数组传入 `where`，导致通过用户组获得权限的私有项目无法被查到。已改为 `pluck('id')->all()` + `whereIn`，并新增回归测试。
- **`DocumentHistory::write()` 调用未定义的 `array_only()`** —— Laravel 12 移除全局函数导致文档创建/更新全部抛 `Call to undefined function array_only()`。已在 `app/helpers.php` 加 shim 恢复。
- **`Document*Listener` 在 CLI/队列场景崩溃** —— `Auth::user()->id` 在非 HTTP 上下文返回 null；改为 `Auth::id() ?? 0` + `Auth::user()?->name ?? 'system'`。
- **`UserHelper::impersonateUser()` 对 null Auth user 不守卫** —— 增加 null 守卫。
- **`page_score` 迁移索引名冲突** —— SQLite 索引名全局唯一，`idx_page_id` 与 page_tag 同名冲突，改名 `idx_page_score_page_id`。
- **`Exceptions\Handler` 把 `HttpResponseException` 错误转 500** —— 导致 throttle 中间件抛的 429 不可达；新增放行逻辑。

### Added
- **API 项目模块**：`Api\ProjectController` 补全 8 个缺失方法（`view`、`create`、`update`、`delete`、`members`、`addMember`、`deleteMember`、`logs`），权限与校验对齐 Web 端；并补注册缺失的 `GET api/project/{id}/documents` 路由。
- **`resources/sass/app.scss` + `wizard-overrides.scss`**：BS5 入口 + 项目色板覆盖
- **Vite 5 + laravel-vite-plugin**：取代 `webpack.mix.js`（之前已删）的构建基础设施
- **Playwright e2e 套件**：`tests/e2e/` 目录 + 视觉基线
- **Service 层抽取**：4 个 plain-class Service
  - `App\Services\ProjectService` —— listVisibleForUser / create / update / delete / addMember / removeMember / getMembers / listDocuments（带缓存版本号失效）
  - `App\Services\DocumentService` —— 9 个方法覆盖 CRUD、状态、过期检测、远程同步、移动、评分、博客开关（432 行）
  - `App\Services\CommentService` —— create / update / delete / listForDocument / listByUser
  - `App\Services\ExportService` —— collectNavigators / exportBatch / exportSingle / packageAsZip / notifyExportComplete
- **核心链路 Feature 测试**：`tests/Feature/Api/` 与 `tests/Unit/Services/` 共 75+ 用例 / 192+ 断言覆盖项目列表（组权限回归）、CRUD、成员管理、日志、认证、文档/评论/导出 Service 单元测试
- **GitHub Actions CI**：`.github/workflows/tests.yml`，PHP 8.3 / 8.4 矩阵
- **Scramble API 文档**：`Api\ProjectController` / `Api\DocumentController` / `Api\UserController` 关键方法加 `#[Dedoc\Scramble\Attributes\Response(403/404/422)]` PHP 属性，403/404 全部到位
- **请求追踪中间件**：`App\Http\Middleware\RequestId`，读 `X-Request-Id` 头 → Log context → 响应头回写；注册到 Kernel 全局栈
- **应用错误日志**：增强 `ErrorLogger::record` —— 自动注入 http_method/url/route/ip/user_id/ua/referer/request_id/runtime/call_stack；CLI/无 Request 安全降级 `-`
- **API 异常统一兜底**：`api/*` 路径未预期异常 → `{success:false, message, request_id}` JSON；显式跳过 ValidationException / AuthenticationException / AuthorizationException / HttpExceptionInterface / ModelNotFoundException / TokenMismatchException
- **批量导出走队列**：`App\Jobs\ExportBatchJob`（ShouldQueue，database 通知送达）；Controller 改 dispatch + 立即返回 202
- **缓存**：`Api\ProjectController::lists` 缓存 5 分钟，通过版本号单调自增失效（兼容 file/array/redis 全部 cache driver）
- **FormRequest 抽取**：`App\Http\Requests\Api\{CreateProject,UpdateProject,AddProjectMember,CreateDocument,UpdateDocument}Request`
- **9 个命名 RateLimiter**（`config/ratelimit.php`）：
  - api-read / api-write / api-lists / api-search / api-export（API）
  - oauth-token / web-login / web-register / web-password（认证）
  - 429 统一项目 ApiResponse 格式 + Retry-After 头 + request_id 关联
- **11 个高频查询复合索引**：`database/migrations/2026_09_20_000002_add_additional_perf_indexes.php` 覆盖 comments / histories / share / score / projects / pages 等
- **Sentry 错误追踪**：sentry/sentry-laravel ^4.0（composer + composer.lock），`config/sentry.php` + `app/Support/SentryEventFilter` 单一可信源过滤 4xx，`ErrorLogger::record` 联动 Sentry，`Handler::reportable` 注入登录用户上下文；7 个 `tests/Feature/SentryIntegrationTest.php` 用例 + InMemorySentryTransport
- **2FA TOTP + 登录失败计数**：`database/migrations/2026_09_20_000003_add_2fa_and_login_tracking_to_users.php` 加 totp_secret/totp_enabled_at/login_fail_count/login_fail_first_at/login_locked_until/backup_codes；`TwoFactorService` 纯 PHP 实现 RFC 6238（hash_hmac SHA-1，零 composer 依赖，±1 窗口漂移，8 个 XXXXX-XXXXX 单次使用备用码）；`LoginAttemptService` 10 次/小时滑窗 → 1h 锁定 + `AccountLockedNotification`（database + mail）；`LoginController` 改造两步登录；24 个 Feature 用例
- **CSP 中间件**：`App\Http\Middleware\SecurityHeaders` 注入 CSP / X-Frame-Options / X-Content-Type-Options / Referrer-Policy / Permissions-Policy 全局响应头；`config('security.csp_exempt_routes')` 路由级豁免
- **文件上传白名单**：`App\Support\UploadWhitelist`（图 5MB / 文档 50MB；php/phar/htaccess/svg 黑名单；MIME + 扩展名 + 文件名安全三重校验）；`App\Exceptions\InvalidUploadException`；`AttachmentController::upload` / `FileController::imageUpload` 接入
- **敏感字段加密 Cast**：`App\Models\Casts\EncryptedCast` + `App\Models\Casts\Encrypted`（Castable 工厂，string/array/collection/object 模式）；`User::totp_secret` / `User::backup_codes` 挂载 Encrypted cast
- **导出完成 Notification**：`App\Notifications\ExportCompleted`（database-channel）
- **`wz_operation_logs` 决策文档**：`docs/db/operation_logs_index_decision.md` 明确 ARCHIVE→InnoDB 转换留给 DBA，本项目不动表结构

### Changed
- **`Api\ProjectController::lists()` 返回字段**：select 新增 `catalog_id`、`sort_level`，供前端按目录/排序分组使用
- **前端 Bootstrap 3 → 5 升级**：移除已停维护的 `bootstrap-material-design` fork，引入 BS5 官方主题 + Sass 编译（Vite 5）；自定义 `panel-*` → `card` 别名映射保留向后兼容；blade 模板 sed 批量替换 + 手动精调；tagmanager.js jQuery 解耦
- **数据库迁移**：
  - `2026_09_20_000001_add_sort_composite_indexes` —— `pages(project_id, sort_level)` + `projects(catalog_id, sort_level)`
  - `2026_09_20_000002_add_additional_perf_indexes` —— 11 个新索引（pages/comments/histories/share/score/projects 等）
  - `2026_09_20_000003_add_2fa_and_login_tracking_to_users` —— users 表加 2FA 与登录失败计数字段
- **`composer.lock`**：`.gitignore` 取消忽略，应用锁定文件入库
- **`phpunit.xml`**：升级 PHPUnit 13 schema；测试用 SQLite `:memory:`；加 `MAIL_DRIVER=array` 让 AccountLockedNotification 在测试环境可跑
- **Controller 瘦身**：`DocumentController` 952→757行；`BatchExportController` 311→90行
- **`BatchExportController`** 同步流写改为临时文件 + `response()->download(..., deleteFileAfterSend:true)`
- **`BatchExportController::exportRaw()` 修复 ZipStream v3 API 不兼容**（`ZipStream\Option\Archive` 在 v3 已移除，改用命名参数 `new ZipStream(outputName:..., outputStream:..., sendHttpHeaders:false)`）
- **`Mpdf` `$author` 未定义变量引用** 修复（显式传参）

### Removed
- **前端 Vue 死代码**：`resources/assets/js/app.js`、`bootstrap.js`、`components/Example.vue`、`resources/assets/sass/`、`webpack.mix.js`、`package.json`、`yarn.lock`；该套构建从未生成产物、也无任何页面引用，连同 `laravel/ui` dev 依赖一并清理
- **`bootstrap-material-design/`、`bootstrap-treeview.js`、`respond.min.js`、`html5shiv.min.js`、`ie10-viewport-bug-workaround.*`**：BS3 + IE 兼容时代的死代码与 polyfill
- **旧 `Kernel.php` 的 `throttle:60,1`** —— 由命名 limiter `api-read`/`api-write` 等替代

### Documentation
- `docs/Task/Archive/2026-09/WIZARD_PROJECT_ANALYSIS.md` —— 项目全面体检报告
- `docs/Task/Archive/2026-09/BS5_UPGRADE_NOTES.md` —— Bootstrap 3 → 5 升级说明（breaking changes、panel→card 别名、tagmanager 解耦）
- `docs/db/operation_logs_index_decision.md` —— 操作日志表索引优化决策
- Scramble 注解改用 PHP Attributes 后 `docs/api.json` 中 8 个 `/project/*` 端点补齐 403/404 描述

## [历史版本]

- 2026 年 6 月：安全与现代化加固（T1–T7，SSRF / XSS / JWT / helpers 拆分等）见 `docs/Task/Archive/2026-06/`
- 2026 年 9 月以前：参考 Git 提交记录

## 模板（下次提 PR 时复制）

```
## [Unreleased]

### Fixed
- <描述>

### Added
- <描述>

### Changed
- <描述>

### Removed
- <描述>

### Deprecated
- <描述>

### Security
- <描述>

### Documentation
- <描述>
```