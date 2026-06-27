# 移除 processSpreedSheet 死逻辑 — 设计文档

**状态**: ⏳ 待实施
**创建时间**: 2026-06-28
**关联**: 合并 `9849a7d` 回归修复（commit `f5fb3d4` 已止血，本文档为后续清理任务 B）

## 1. 背景与目标

项目表格组件已从 x-spreadsheet（`TYPE_TABLE=3`）迁移至 LuckySheet（`TYPE_SHEET=6`）。
`processSpreedSheet` 系列函数是为 x-spreadsheet 时代编写的存储/展示前规范化逻辑，
对 LuckySheet 关联对象格式不兼容，且函数体存在空 cells 行导致 `array + int` 崩溃的潜伏 bug。

合并 `9849a7d` 曾将 `wizard.spreedsheet.disabled` 默认值翻转为 `false` 激活该逻辑，
导致表格文档查看/保存崩溃，已由 `f5fb3d4` 还原默认 `true` 止血。

**本任务目标**：彻底移除 `processSpreedSheet` 死逻辑，使表格文档在存储与展示路径
均使用原始内容，消除崩溃风险与不兼容的格式改写。

**范围严格限定**：仅移除 `processSpreedSheet` 死逻辑本身。不动 `TYPE_TABLE=3` 常量、
`table.blade.php` 视图、x-spreadsheet 前端资源、`wizard.spreedsheet` 配置段。

## 2. 现状事实（已核实）

- 前端 `sheet.blade.php` 提交 `type=sheet` → `documentType('sheet', true)` → 存库 `TYPE_SHEET=6`
- 前端 `table.blade.php` 提交 `type=table` → 存库 `TYPE_TABLE=3`
- 存储守卫 `DocumentController:139/252` 仅在 `type==='table'` / `isTable()` 时调 `processTableRequest`；
  LuckySheet（type=sheet）**不进存储守卫**
- 展示路径 5 处调用 `processSpreedSheet`，对 LuckySheet 数据也会跑（崩溃来源）
- 库中 `TYPE_TABLE=3` 旧数据 0 条（含软删除），`TYPE_SHEET=6` 数据 2 条格式正确 → **无需数据迁移**
- `wizard.spreedsheet` 配置键（`disabled`/`max_rows`/`max_cols`/`min_rows`/`min_cols`）仅被
  `processSpreedSheet` 内部引用，移除函数后该配置段成为孤立键（按决策保留不动，加废弃注释）
- **x-spreadsheet 前端自处理行列数**：`table.blade.php` 的 `loadData` 前会
  `data[i].cols.len = options.col.len` / `rows.len = options.row.len` 重置，
  后端 `processSpreedSheet` 的行列规范化对 x-spreadsheet 是冗余的；
  且其 `cells` 对象→数组的 JSON round-trip 改写可能有害 → 移除不损 x-spreadsheet 功能
- **无动态调用**：全项目无 `call_user_func` 等动态引用 `processSpreedSheet`，grep 零残留检查足够安全

## 3. 改动清单

### 3.1 `app/helpers.php` — 删除三个函数

- `processSpreedSheet()`（约 720-764 行）
- `processSpreedSheetSingle()`（约 775-815 行）
- `processSpreedSheetRows()`（约 826 行起）

### 3.2 `app/Http/Controllers/DocumentController.php` — 存储路径

- 第 147 行：删除 `$content = $this->processTableRequest($content);`（保留 `type==='table'` 守卫与 json_decode 校验）
- 第 252 行：同上
- 第 843-846 行：删除 `processTableRequest()` 方法（唯一调用点已删，成为死代码）

### 3.3 展示路径 5 处 Blade 视图 — 改用原始 content

| 文件 | 行 | 改动 |
|---|---|---|
| `resources/views/doc/table.blade.php` | 13 | `base64_encode(processSpreedSheet($pageItem->content ?? ''))` → `base64_encode($pageItem->content ?? '')` |
| `resources/views/doc/sheet.blade.php` | 13 | 同上 |
| `resources/views/doc/history-doc.blade.php` | 56 | `processSpreedSheet($history->content)` → `$history->content` |
| `resources/views/project/project.blade.php` | 118 | `processSpreedSheet($pageItem->content)` → `$pageItem->content` |
| `resources/views/share-show.blade.php` | 27 | `processSpreedSheet($pageItem->content)` → `$pageItem->content` |

### 3.4 `config/wizard.php` — 标注配置段废弃

`spreedsheet` 配置段加注释说明自本次清理起仅 `max_rows`/`max_cols` 仍被
`table.blade.php` 前端使用，`disabled`/`min_rows`/`min_cols` 已成孤立键
（原消费方 `processSpreedSheet` 已移除），便于后续清理识别。配置值保留不动。

## 4. 数据流变更

- **存储**：x-spreadsheet 文档（type=table）创建/更新时保留 json 合法性校验，内容原样入库。
  LuckySheet（type=sheet）本就不进守卫，无变化。
- **展示**：所有表格文档渲染时用原始 content；前端 x-spreadsheet / LuckySheet 各自 `JSON.parse`
  处理（`sheet.blade.php:61` 已有此逻辑）。

## 5. 风险与验证

**风险点**：
- 是否有其他文件引用被删函数（需全局 grep 确认零残留）
- Blade 移除 `processSpreedSheet` 调用后语法完整性

**验证步骤**：
1. `php -l` 全部改动文件
2. 全局 grep 确认 `processSpreedSheet` / `processTableRequest` / `processSpreedSheetSingle` / `processSpreedSheetRows` 零残留
3. 端到端：
   - LuckySheet 文档查看（sheet.blade.php）、历史对比、分享、项目预览不报错
   - x-spreadsheet 文档创建 + 查看，json 合法性校验仍生效
4. `php artisan route:list` 正常（146 行，无崩溃）
5. 基础页面回归：`/blog`、`/`、登录

## 6. 不做（YAGNI）

- 不删 `TYPE_TABLE=3` 常量与分支（保留 x-spreadsheet 仍可创建的能力）
- 不删 `table.blade.php`、x-spreadsheet 前端资源
- 不删 `wizard.spreedsheet` 配置段（`config/wizard.php` + `.env`）
- 不补新规范化逻辑（LuckySheet 自处理，无需后端预处理）
- 不做数据迁移（无旧数据）

## 7. 实施顺序（拟定）

1. 删 `app/helpers.php` 三个函数
2. 改 `DocumentController.php` 两处存储调用 + 删 `processTableRequest` 方法
3. 改 5 处 Blade 视图
4. 语法检查 + grep 残留检查
5. 端到端验证
6. 提交并推送
