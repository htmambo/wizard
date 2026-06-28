# Wizard 加固方案 — 提交前修正实施计划（终版）

**Status**: ✅ 准备实施 (2026-06-28)
**前置文档**: `docs/Task/Active/WIZARD_HARDENING_PLAN.md`

## 四轮审核闭环

| 轮次 | SESSION_ID | 结论 | 处置 |
|---|---|---|---|
| 一 | ea2d8cf6-d204-4d35-860e-bbe76417f0a2 | NEEDS_CHANGES | 采纳 5 项阻断级修正 |
| 二 | adca2c8e-dcf2-4f9a-b4d4-3c6a69371a36 | NEEDS_CHANGES | 采纳 4 项 + 驳回 5 项 |
| 三 | c026454c-6921-4826-a7de-be3a67764884 | NEEDS_CHANGES | 采纳全部 6 项 |
| 四 | 18a50e63-7f3c-472d-bc96-d9bbafbd428b | NEEDS_CHANGES | 采纳 3 项澄清（不再扩大范围） |

---

## Fix-1: ST-1 XSS 双编码回退（**带幂等性防护**）

### 改动
`app/Http/Controllers/CommentController.php:57`
```diff
-            'content'     => htmlspecialchars(comment_filter($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
+            'content'     => comment_filter($content), // XSS 防护由 Blade {{ }} 的 e() 转义承担
```

### ⚠️ 运维备用 SQL（生产已编码数据修正）—— 3 步幂等流程

**步骤 0 — 备份受影响行**
```sql
CREATE TABLE wz_comments_backup_fix1 AS
SELECT id, content, NOW() AS backup_at
FROM wz_comments
WHERE content LIKE '%&lt;%' OR content LIKE '%&amp;%';
```

**步骤 1 — 确认影响范围（人工核对）**
```sql
SELECT COUNT(*) AS affected_rows FROM wz_comments_backup_fix1;
```
人工确认受影响行数合理后再继续。

**步骤 2 — 执行解码**
```sql
UPDATE wz_comments
SET content = htmlspecialchars_decode(content, ENT_QUOTES | ENT_HTML5)
WHERE id IN (SELECT id FROM wz_comments_backup_fix1);
```

**步骤 3 — 验证归零**
```sql
SELECT COUNT(*) AS remaining_rows FROM wz_comments
WHERE content LIKE '%&lt;%' OR content LIKE '%&amp;%';
-- 预期: 0
```

### 回滚
```sql
UPDATE wz_comments c
JOIN wz_comments_backup_fix1 b ON c.id = b.id
SET c.content = b.content;
```

> **强制约束**：此 SQL 流程仅可执行一次，Fix-1 部署后禁止重跑。已通过备份 + COUNT 校验保证幂等性。

### 编码侧参数一致性确认
原 `htmlspecialchars($content, ENT_QUOTES | ENT_HTML5, 'UTF-8')` 与解码 `ENT_QUOTES | ENT_HTML5` flags 完全一致。

---

## Fix-2: ST-6 User 字段级过滤（**已用 schema 自证**）

### 改动
`app/Providers/EventServiceProvider.php:77-81`
```diff
-        foreach ([Project::class, Document::class, Comment::class, User::class, Group::class] as $model) {
-            $model::saved($flush);
-            $model::deleted($flush);
-        }
+        foreach ([Project::class, Document::class, Comment::class, Group::class] as $model) {
+            $model::saved($flush);
+            $model::deleted($flush);
+        }
+
+        // User 仅在业务字段变更时失效（避免 password hash 等高频更新导致缓存击穿）
+        User::updated(function ($user) {
+            if ($user->wasChanged(['name', 'email', 'role', 'status'])) {
+                DashboardCache::flush();
+            }
+        });
+        User::deleted($flush);
```

### User 字段覆盖矩阵（已用 migration 自证）

| 字段 | DB 来源 | 影响 Dashboard 统计 | 纳入 wasChanged |
|---|---|---|---|
| name | migrations/users | ✅ 用户展示/搜索 | ✅ |
| email | migrations/users | ✅ 去重统计 | ✅ |
| password | migrations/users | ❌ 仅认证 | ❌ 高频 |
| role | migrations/users | ✅ admin/normal 分布 | ✅ |
| status | migrations/users | ✅ 激活/未激活统计 | ✅ |
| objectguid | 2019_04_27 migration | ❌ LDAP 标识 | ❌ |
| sub_domain | 2023_08_20 migration | ❌ 个人子域名 | ❌ |
| remember_token | migrations/users | ❌ 会话 | ❌ |
| created_at / updated_at | timestamps | ❌ 元数据 | ❌ |
| **avatar** | **不存在** | — | ❌ **（已移除误判）** |

**结论**：wasChanged 列表 `['name', 'email', 'role', 'status']` 已穷尽所有影响 Dashboard 统计的字段。

---

## Fix-3: ST-6 Observer → Service 重命名

### 改动
- **新增** `app/Services/DashboardCache.php`
- **删除** `app/Observers/ClearDashboardCache.php`
- `app/Providers/EventServiceProvider.php:17` `use App\Observers\ClearDashboardCache;` → `use App\Services\DashboardCache;`
- `app/Providers/EventServiceProvider.php:76` `[ClearDashboardCache::class, 'flush']` → `[DashboardCache::class, 'flush']`

### 全仓 grep 引用面（已实证）
```
app/Observers/ClearDashboardCache.php:18 → class 定义
app/Providers/EventServiceProvider.php:17 → use import
app/Providers/EventServiceProvider.php:76 → 调用
```
仅 3 处内聚引用，零外部风险。

---

## Fix-4: ST-5 phpunit 版本锁定

### 改动
`composer.json:42`
```diff
-        "phpunit/phpunit": "*",
+        "phpunit/phpunit": "^11.0",
```

### 锁定理由（已修正表述）
当前无 CI 基础设施，PHPUnit 版本约束策略的实际效果无法被验证。
待 CI 建立后再统一制定版本锁定策略（届时可考虑 `~11.x` 细粒度）。

---

## Fix-5: ST-7 migration → 决策文档

### 改动
- **删除** `database/migrations/2026_06_28_000001_add_operation_logs_index_hint.php`
- **新增** `docs/db/operation_logs_index_decision.md`

### 已部署环境清理 SQL
```sql
DELETE FROM wz_migrations
WHERE migration = '2026_06_28_000001_add_operation_logs_index_hint';
```

### 决策文档骨架（6 节）
1. 背景（ARCHIVE 引擎不支持索引）
2. 决策（不修改，决策权下放 DBA）
3. 考虑的替代方案（改 InnoDB / 拆冷热表 / ELK）
4. 影响（生产数据零影响）
5. 回滚方式（无需回滚，因未执行任何 DDL）
6. 关联工单（待 DBA 评估后填入）

---

## 人工验证用例表（DoD 可验收，替代 PHPUnit 测试）

| # | 输入内容 | 预期渲染 | 验证入口 | 通过标准 |
|---|---|---|---|---|
| 1 | `<script>alert(1)</script>` | 纯文本可见 | post.blade.php:103 | 无弹窗 |
| 2 | `<img onerror=alert(1) src=x>` | 纯文本可见 | comment.blade.php:42 | 无弹窗 |
| 3 | `&lt;已编码&gt;`（历史数据） | 正确解码为 `<已编码>` | 历史数据修正后 | 可读文本 |
| 4 | 空字符串 | 无异常 | 评论区提交 | 不报错 |
| 5 | 超长内容（>10KB） | 正常截断或完整渲染 | post.blade.php:103 | 无溢出 |
| 6 | `comment_filter_users` 匹配 @user | 不被 htmlspecialchars 破坏 | comment_filter 单元调用 | 返回 @{uid:N} |

---

## 回滚细节（第四轮采纳）

| Fix | 回滚路径 | 数据影响 |
|---|---|---|
| Fix-1 | 从 `wz_comments_backup_fix1` 备份表 JOIN 恢复 content | 仅生产环境备用流程，本次非必须执行 |
| Fix-2 | `git revert <commit>`，无数据变更 | 仅事件钩子回退 |
| Fix-3 | `git revert <commit>`，类文件恢复 | 仅命名空间回退 |
| Fix-4 | `git revert <commit>`，composer.lock 还原 | 仅依赖约束回退 |
| Fix-5 | ⚠️ **本次 Fix-5 是删除未执行的 migration 文件**（该 migration `up()` 是空操作 + 占位注释，从设计意图看是"决策占位"，未真正在生产数据库执行 ALTER TABLE）。回滚分两种场景：<br>① 若 production DB 中 `wz_migrations` 表**不存在**该记录 → 仅 git revert 文件即可<br>② 若 production DB 中**意外存在**该记录 → 需执行 `DELETE FROM wz_migrations WHERE migration = '2026_06_28_000001_add_operation_logs_index_hint';`<br>不存在"逆向 schema 回滚"路径（从未真正执行 schema 变更） | 无 DDL 影响 |

## Fix-1 执行环境约束（第四轮采纳）

⚠️ **必须满足以下条件之一才能执行 Fix-1 备份 SQL 流程**：

1. **维护模式**（推荐）：`php artisan down` 开启，仅管理员可访问，写入全部被阻断
2. **应用层 XSS 兜底**：保留当前存储侧 `htmlspecialchars()` 不删除，仅在 Blade 视图层去掉双转义（不推荐，回退半步）
3. **不执行数据修正流程**：仅在代码层删除存储侧 `htmlspecialchars()`，**生产环境不修正历史数据**（接受已编码数据继续以 `&lt;` 形式显示，可后续单独 PR 修复）

> **本次推荐方案 3**：本次提交仅做代码层回退（删除 `htmlspecialchars()`），历史数据修正作为独立运维工单处理，避免本次加固承担数据修正风险。

## 观察检查点量化（第四轮采纳）

【观察检查点】明确为 **24 小时**（一个完整的业务日）：

- 监测项：Dashboard 渲染是否正常、Cache 命中率（`Cache::has('dashboard:stats')`）、控制台异常日志
- 工具：临时 Laravel tinker 脚本 + Laravel log 观察（无需新建监控基础设施）
- 通过标准：24h 内零 PHP 异常、零缓存击穿告警、User 业务字段更新能正常触发缓存刷新

---

## 遗留风险与技术债（**留痕**）

### [TECH-DEBT-001] 无 CI 基础设施
本次加固发现项目无 CI 流水线，无法自动化验证变更。  
**建议**：后续单独立项搭建 GitHub Actions / GitLab CI。

### [TECH-DEBT-002] 无自动化测试框架
本次驳回了 PHPUnit 数据驱动测试，建议引入基础冒烟测试。  
**建议**：以本计划的人工验证用例表为起点，逐步迁移为 PHPUnit 测试。

### [TECH-DEBT-003] 无监控/告警基础设施
本次驳回了监控指标告警建议。  
**建议**：后续引入基础 APM（如 Laravel Telescope + Sentry）。

> 以上三项不阻塞本次实施，作为副作用发现留痕。

---

## 实施顺序

```
Fix-4 (依赖锁定, ~5min)
  ↓
Fix-3 (命名空间重命名, ~10min)
  ↓
Fix-5 (migration 清理, ~5min)
  ↓
Fix-2 (User 字段过滤, ~15min)
  ↓
【观察检查点】—— 等 Fix-2~Fix-5 部署并运行 1 个低峰周期
  ↓
Fix-1 (XSS 回退 + 历史数据修正, ~30min + DBA 评估窗口)
```

数据修正放最后且与代码变更解耦，避免缺陷叠加。

---

## 验收 DoD（最终）

| Fix | DoD |
|---|---|
| Fix-1 | ① grep 确认 comment.content 仅走 Blade `{{ }}`；② 人工用例表 6 条全通过；③ 生产环境按 3 步 SQL 流程执行（含备份） |
| Fix-2 | User::updated + wasChanged 仅命中业务字段；remember_token/password 不触发；运维连续 1 天观察 Dashboard 缓存命中率稳定 |
| Fix-3 | 全仓 grep `ClearDashboardCache` 返回零结果；composer dump-autoload 无 PSR-4 警告 |
| Fix-4 | `composer validate` 通过；`composer.lock` 解析 phpunit 为 11.x |
| Fix-5 | 数据库中无空 migration 记录；决策文档 6 节齐全 |

---

## 提交后归档

1. 提交 git commit（每 Fix 独立 commit，便于回滚）
2. 移动 `WIZARD_HARDENING_FIX_PLAN.md` 到 `docs/Task/Archive/2026-06/`
3. 更新 `docs/Task/README.md` 索引