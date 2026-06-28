**Status**: 🔄 修正中 (2026-06-28) — 提交前发现 XSS 双编码实证问题，需修正后再提交

## 外部审核意见（第二轮 — 用户提交前的独立复核）

- **provider**: coding-bridge
- **SESSION_ID**: `ea2d8cf6-d204-4d35-860e-bbe76417f0a2`
- **verdict**: NEEDS_CHANGES（采纳大部分，1 处误报已用代码证据反驳）

### 实证采纳的修正项（阻断提交）

| # | 问题 | 证据 | 处置 |
|---|---|---|---|
| C1 | **ST-1 XSS 双编码** | `resources/views/components/comment.blade.php:42` 和 `resources/views/blog/post.blade.php:103` 都用 `{{ $comment->content }}`（Blade 默认 `e()`）；`comment_filter()` 内部仅做 `@user` → `@{uid:N}` 纯文本替换，不涉及 HTML 标签 | **必须回退 ST-1**。删除 `htmlspecialchars()`，依赖 Blade 默认转义；同时更新 `comment_filter()` 注解 |
| C2 | **ST-6 User::saved 触发 login_at 缓存击穿** | User 模型频繁更新 last_login 等字段 → Cache::forget('dashboard:stats') 被频繁触发 → 缓存形同虚设 | 移除 `User::class` 注册，仅保留 Project/Document/Comment/Group |
| C3 | **ST-6 Observer 类命名误导** | `app/Observers/ClearDashboardCache` 无具体 Model 观察者，仅是工具类 | 重命名 `app/Services/DashboardCache.php` |
| C4 | **ST-5 phpunit 通配符** | `phpunit/phpunit: *` 在 PHP 8.2 + Laravel 12 下会拉 PHPUnit 11，但 Laravel 12 推荐 `^11.0`（API 稳定） | 锁定 `^11.0` |
| C5 | **ST-7 空 migration 副作用** | 空 migration 会在 `migrations` 表写记录，运维 `migrate` 后误判"已加索引" | 删除 migration 文件，改为 `docs/db/operation_logs_index_decision.md` 决策文档 |

### 误报反驳（已用代码证据否定）

| # | 外部审核结论 | 反驳证据 |
|---|---|---|
| F1 | "ST-6 缺 return 导致控制器返回 null" | DashboardController.php:33 `$payload = ...` 是赋值语句，第 35 行才是 `return view(...)`，控制流完整正确 |

### 整体评审结论

**verdict**: NEEDS_CHANGES（5 项修正后才能提交）
**最高风险**: C1（XSS 双编码会破坏现有 `@user` 提醒与评论格式可见性）—— 必须修复

---

# Wizard 安全与现代化加固方案（原始任务计划）

## 任务目标

针对 Wizard 文档管理系统审计中发现的关键问题（XSS、API 调试信息泄露、Dockerfile PHP 版本不一致、过期依赖），制定并执行完整的加固方案。

## 问题分析

| 优先级 | 问题 | 位置 |
|---|---|---|
| 🔴 P0 | XSS 过滤未实现（仅 TODO 注释） | `app/Http/Controllers/CommentController.php:57` |
| 🔴 P0 | API 返回 SQL 调试信息（`toSql()`） | `app/Http/Controllers/Api/ProjectController.php:91` |
| 🔴 P0 | Dockerfile 与 composer PHP 版本不一致（PHP 7.3 vs 8.2） | `Dockerfile` |
| 🟠 P1 | jQuery 3.1.1 / axios 0.16.2 已知 CVE | `package.json` |
| 🟠 P1 | Vue 2.1.10 / laravel-mix 1.0 严重过时 | `package.json` |
| 🟡 P2 | fzaninotto/faker 已废弃 | `composer.json` (开发依赖) |
| 🟡 P2 | 缺少 Service 层（业务逻辑散落 Controller） | 多个 Controller |
| 🟡 P2 | 缺少缓存策略（Redis 未系统应用） | Repository 层 |
| 🟢 P3 | 数据库索引缺失 / ARCHIVE 引擎查询性能 | migration |

## 子任务列表

- [ ] **ST-1**：修复 XSS（CommentController）— 引入 `e()` + 白名单过滤
- [ ] **ST-2**：移除 API 调试 SQL 输出（Api/ProjectController）
- [ ] **ST-3**：修正 Dockerfile PHP 版本至 8.2
- [ ] **ST-4**：更新前端依赖到安全版本（jQuery 3.7+, axios 1.6+, Vue 2.7）
- [ ] **ST-5**：替换废弃包 fzaninotto/faker → fakerphp/faker
- [ ] **ST-6**：为 Dashboard 引入缓存（Redis/file cache）
- [ ] **ST-7**：补充 ARCHIVE 表的索引优化方案

## 实施完成 + 待修正清单

- ✅ ST-2 已完成（无争议）
- ✅ ST-3 已完成（composer.json PHP 约束 ^8.2 已满足）
- ✅ ST-4 已完成（package.json 版本升级）
- 🔄 ST-1 待回退（C1）
- 🔄 ST-5 待锁版本（C4）
- 🔄 ST-6 待剥离 User + 重命名（C2 + C3）
- 🔄 ST-7 改为决策文档（C5）

## 阶段 0/1 输出

- `.omc/fullauto/wizard-hardening/spec.md`
- `.omc/plans/fullauto-wizard-hardening-impl.md`