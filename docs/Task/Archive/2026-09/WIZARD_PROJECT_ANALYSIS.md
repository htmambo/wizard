# Wizard 项目检查与分析报告

**检查日期**: 2026-09-20
**分支**: `Laravel-12`（最近提交 2026-06-28，`fba34f6` helpers 拆分重构）
**检查范围**: 技术栈、架构、运行状态、安全状况（对照 2024 年审计）、风险与建议

---

## 1. 项目概况

Wizard 是一款开源文档管理系统（github.com/mylxsw/wizard），基于 **Laravel 12 + PHP 8.2** 开发，支持三类文档：

- **Markdown**（基于 Editor.md 扩展，支持模板、图片粘贴上传）
- **Swagger**（OpenAPI 3.0，集成官方编辑器）
- **Table**（基于 x-spreadsheet 的类 Excel 表格）

企业级功能：文档历史与差异对比、权限管理（管理员/普通用户 + 用户组）、项目分组、搜索、评论、消息通知、文档分享、统计、多主题等。

## 2. 技术栈

### 后端
| 类别 | 依赖 |
|------|------|
| 框架 | laravel/framework ^12，laravel/passport ^13（OAuth2） |
| API 文档 | dedoc/scramble ^0.12.30 |
| PDF 导出 | mpdf/mpdf ^8.2，gotenberg/gotenberg-php ^2.14 |
| 文档转换 | league/html-to-markdown、erusev/parsedown、ezyang/htmlpurifier |
| 其他 | intervention/image、jdenticon（头像）、greenlion/php-sql-parser、overtrue/laravel-pinyin |
| 数据库 | MySQL 5.7+ / MariaDB（操作日志表使用 ARCHIVE 引擎） |

### 前端
Laravel Mix 6 + jQuery 3.7 + Vue 2.7 + Bootstrap 3（blade 模板 80 个为主，混合 Vue 组件），技术栈偏传统。

## 3. 代码结构（约 151 个 PHP 文件）

```
app/
├── Http/Controllers/   38 个（含 Api 子目录）— Controller 层偏重
├── Repositories/       16 个类 — Eloquent Model 直接放在此命名空间，并非真正的 Repository 模式
├── Services/           仅 2 个（DashboardCache、SyncUrlGuard）— Service 层薄弱
├── Support/            17 个 Helper 类 — 近期重构将 helpers.php 全局函数拆分至此
├── Http/Middleware/    AdminAuth / GlobalAuth / SharePermission / Locale 等，较完整
├── Policies / Observers / Events / Listeners / Notifications  齐全
└── helpers.php         全局函数（正在逐步拆分到 Support/）
```

近期活跃方向：安全加固（SSRF/XSS/JWT/SQL，2026-06）与 helpers 拆分重构（T1-T7 已完成，见 `docs/Task/Archive/2026-06/`）。

## 4. 运行状态评估

| 项目 | 状态 |
|------|------|
| `.env` | ❌ 不存在（仅有 `.env.example` / `.env.docker`） |
| `vendor/` | ❌ 未安装（且**缺少 `composer.lock`**） |
| `node_modules/` | ❌ 未安装（有 `yarn.lock`） |
| 测试 | ⚠️ 仅骨架 `ExampleTest` ×2，业务测试覆盖率为零 |
| 迁移文件 | 49 个，2017 年延续至今 |

当前代码库**无法直接运行**，需先 `composer install`、配置 `.env`、执行迁移与 `passport:keys`。

## 5. 安全状况（对照 PROJECT_AUDIT_IMPROVEMENTS.md 2024 审计）

### 已修复 ✅
- 前端依赖升级：axios 1.6 / jQuery 3.7 / Vue 2.7 / Mix 6（原 Critical 项）
- `fzaninotto/faker` → `fakerphp/faker`
- 废弃的 yzalis/identicon → jdenticon
- API 泄露 SQL/绑定参数：`Api/ProjectController` 已无 `'sql'`/`'bindings'` 输出
- 评论 XSS：`CommentController.php:57` 的 TODO 已改为明确策略（Blade `{{ }}` 转义），并有 `Support/HtmlPurifierService`
- 2026-06 完成一轮 SSRF / XSS / navigator 缓存 / 异常 / JWT / SQL 加固

### 审计中仍成立的问题 ⚠️
- Controller 直接使用 `DB::`，缺真正的 Service 层
- 操作日志表 ARCHIVE 引擎无索引、缺复合索引（见 `docs/db/operation_logs_index_decision.md`）
- 缺系统性缓存与队列异步处理
- 测试覆盖率基本为零（审计目标 70–90% 未落地）

## 6. 主要风险与建议（按优先级）

1. **测试缺失是最大短板** —— 151 个源文件对 2 个示例测试；刚完成安全加固却无回归测试保障。优先为核心链路（认证、文档 CRUD、权限）补 Feature 测试。
2. **缺 `composer.lock`** —— 生产部署无法锁定依赖版本，建议提交 lock 文件。
3. **架构分层** —— Controller 业务逻辑下沉到 Service 层；`Repositories` 命名空间下的 Model 归位或更名。
4. **性能** —— 缓存策略、队列异步（导出/邮件）、日志表索引仍未实施。
5. **前端陈旧** —— Vue 2 已 EOL、Bootstrap 3 停止维护，中长期建议 Vue 3 / Inertia 路线。

## 7. 总结

项目功能成熟、仍在活跃维护（2017 年至今）。最近一轮工作集中在安全加固与结构重构，方向正确。最薄弱环节不是安全（刚加固过），而是**完全没有测试安全网**——对持续重构中的项目风险很高；其次是补齐 lock 文件与可运行的开发环境。

### 后续可执行项
- [x] 为核心链路补 Feature 测试（认证 / 文档 CRUD / 权限）—— 2026-09-20 完成，36 tests / 99 assertions 全绿（`tests/Feature/Api/`）
- [x] 生成并提交 `composer.lock` —— 2026-09-20 已生成（注：仓库 `.gitignore` 忽略了 composer.lock，如需入库需调整忽略规则）
- [x] 搭建可运行开发环境（composer install + .env + migrate + passport:keys）—— 2026-09-20 完成，本地使用 SQLite（MySQL/ARCHIVE 不可用场景下已兼容）
- [ ] Controller → Service 层下沉重构
- [ ] 操作日志表索引优化落地（已确认按 `docs/db/operation_logs_index_decision.md` 决策留给 DBA，应用侧不动表结构；pages/projects 排序复合索引已于 2026-09-20 通过 `2026_09_20_000001_add_sort_composite_indexes` 迁移落地）
