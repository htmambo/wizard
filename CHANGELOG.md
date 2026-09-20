# CHANGELOG

本项目的所有重要变更记录于此。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

### Fixed
- **API `lists()` 用户组过滤 bug**：原实现使用 `$user->groups->toArray()` 将二维数组传入 `where`，导致通过用户组获得权限的私有项目无法被查到。已改为 `pluck('id')->all()` + `whereIn`，并新增回归测试。

### Changed
- **`Api\ProjectController::lists()` 返回字段**：select 新增 `catalog_id`、`sort_level` 字段，供前端后续按目录/排序分组使用。
- **数据库迁移**：
  - 新增 `pages(project_id, sort_level)` 与 `projects(catalog_id, sort_level)` 复合索引，覆盖项目内文档排序与项目目录排序两类高频查询。
  - `page_score` 索引名由 `idx_page_id` 改为 `idx_page_score_page_id`，消除与 `page_tag` 同名索引冲突（SQLite 索引名全局唯一）。
- **`composer.lock`**：调整 `.gitignore`，应用锁定文件将入库以保证生产环境依赖一致。
- **`phpunit.xml`**：升级至 PHPUnit 13 schema；测试使用 SQLite `:memory:`。

### Added
- **API 项目模块**：`Api\ProjectController` 补全 8 个缺失方法（`view`、`create`、`update`、`delete`、`members`、`addMember`、`deleteMember`、`logs`），权限与校验对齐 Web 端；并补注册缺失的 `GET api/project/{id}/documents` 路由。
- **核心链路 Feature 测试**：新增 `tests/Feature/Api/` 三个测试文件，共 36 个用例、99 断言，覆盖项目列表（组权限回归）、CRUD、成员管理、日志、认证。`vendor/bin/phpunit` 全绿。
- **GitHub Actions CI**：新增 `.github/workflows/tests.yml`，PHP 8.3 / 8.4 双版本矩阵运行 PHPUnit。

### Removed
- 前端 Vue 死代码：`resources/assets/js/app.js`、`bootstrap.js`、`components/Example.vue`、`resources/assets/sass/`、`webpack.mix.js`、`package.json`、`yarn.lock`；该套构建从未生成产物、也无任何页面引用，连同 `laravel/ui` dev 依赖一并清理。

### Security
- 不涉及本次变更；近期 Wizard V2 加固（T1–T7）已单独归档在 `docs/Task/Archive/2026-06/`。

### Documentation
- 新增 `docs/Task/Archive/2026-09/WIZARD_PROJECT_ANALYSIS.md`：2026-09-20 项目全面体检报告。

## [历史版本]

2026 年 6 月的安全与现代化加固（T1–T7，SSRF / XSS / JWT / helpers 拆分等）见 `docs/Task/Archive/2026-06/`。更早的变更历史可参考 Git 提交记录。