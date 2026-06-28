# Wizard V2 加固任务文档

**状态**: 🔄 进行中 (开始时间: 2026-06-28)
**Slug**: `wizard-v2-audit`
**对应 fullauto 状态**: `.omc/fullauto/wizard-v2-audit/state.json`

## 任务目标

对 Wizard 项目做第二轮系统加固,解决第一轮审计后发现的 7 类问题(SSRF/XSS/navigator 缓存/异常处理/JWT-Token/Dashboard SQL/helpers.php 拆分)。
本地测试用 `php artisan serve`,`APP_DEBUG=true` 保留不修。

## 问题分析

完整分析见上一轮审计对话输出;摘要:

- 🔴 严重(S1-S3): SSRF、XSS、`exit` 强杀
- 🟠 高(B1-B6): navigator 进程级缓存、catch 静默、`wzRoute` 重复编码等
- 🟡 中(P1-P9): Dashboard SQL、无索引、helper 巨型文件、分享 token 弱随机等
- 🟢 低(C1-C14): 拼写漂移、PHP 助手命名、测试空白等

## 子任务列表

| T | 严重度 | 标题 |
|---|---|---|
| T1 | 🔴 | `sync_url` SSRF 防护 |
| T2 | 🔴 | 全局 XSS 净化(54 处 `{!! !!}`) |
| T3 | 🟠 | `navigator()` 改 `Cache::remember` + 失效钩子 |
| T4 | 🟠 | 38 处 `catch` 增加 `\Log::error`、移除 `exit()` |
| T5 | 🟠 | JWT/分享 token 强化(random_bytes + TTL) |
| T6 | 🟡 | Dashboard SQL 改 `whereYear/groupBy` + 复合索引 |
| T7 | 🟡 | `helpers.php` 拆分到 `app/Support/` |

## 每个子任务的改动内容

详见 `.omc/plans/fullauto-wizard-v2-audit-impl.md`(本文档索引)。

## 预期效果和验收标准

每 T 的验收标准见 impl 计划第 4 节。
总体验收:`php artisan serve` + 浏览器冒烟全绿 + `php -l` 全过 + `vendor/bin/pint --test` 通过。

## 风险评估和缓解措施

详见 impl 计划第 5 节。

## 实施顺序和依赖关系

```
T0 基础 → T1, T2, T5, T6, T3, T4 并行 → T7 拆分 → QA → 验收
```

## 阶段 0 输出(spec)

- 路径: `.omc/fullauto/wizard-v2-audit/spec.md`
- 包含: ## Assumptions Made / ## Decisions Made

## 实施计划

- 路径: `.omc/plans/fullauto-wizard-v2-audit-impl.md`

## 外部审核意见(Phase 0)

- **Provider**: coding-bridge
- **SESSION_ID**: `5867993e-e933-469f-98ac-a8236fbb5f0e`
- **Verdict**: ✅ APPROVED(有条件通过)
- **关键建议采纳**(已写入 spec.md §10):
  - AD1 SSRF 深度防护(CURLOPT_RESOLVE + 禁重定向)
  - AD2 异常兜底(TokenExpiredException 全局渲染)
  - AD3 缓存权限一致性(Project/Group/User Observer)
  - AD4 测试与回滚基线(单元测试 + 可逆迁移 down())
  - AD5 XSS 写入时净化(Repository saving 层)
  - AD6 监控埋点(security 独立日志通道)

## 外部审核意见(Phase 1)

- Phase 1 合并到 Phase 0 同步审核(同一份 review_plan 调用)
- Plan 与 Spec 一致,通过

## 外部审核意见(Phase 4)

(待 Phase 4 完成后填写)

## Runtime Decisions

(Phase 2 期间由 main 助手追加)

## 验证

(Phase 4 完成后填写)
