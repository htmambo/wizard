# Bootstrap 3 → 5 升级说明

**日期**: 2026-09-21
**作用域**: 前端资产（CSS / JS / blade 模板）、构建工具链
**目标版本**: Bootstrap 5.x（最新稳定版）
**关联 PR / commit**: 由 Stage 1+2 / 3 / 4 / 5 四个 agent 并行完成

---

## 1. 升级动机

| 项 | 升级前 | 升级后 |
|---|---|---|
| Bootstrap | 3.3.x（**已 EOL，2019-07 停止维护**）+ `bootstrap-material-design` fork | 5.x（官方维护 + jQuery 解耦 + flexbox 栅格） |
| 构建工具 | Laravel Mix 6（webpack 4）/ 死代码 `resources/assets/sass/` 与 `webpack.mix.js`（之前已删）/ `package.json`/`yarn.lock` 无产物 | Vite 5 + `laravel-vite-plugin` |
| CSS 入口 | `bootstrap-material-design.css` 单一 200KB+ 文件 | 模块化 SCSS：`resources/sass/app.scss` + `wizard-overrides.scss` |
| IE polyfill | `respond.min.js` / `html5shiv.min.js` / `ie10-viewport-bug-workaround.*` | 全部移除（BS5 已无 IE 支持） |
| tagmanager.js | jQuery 强依赖（IIFE `$`） | jQuery 解耦（可选依赖，仍兼容旧调用） |

---

## 2. Breaking Changes 影响清单

### 2.1 表单 / 栅格
| BS3 类 | BS5 等价 | 影响范围 | 处置 |
|---|---|---|---|
| `form-group` | `mb-3`（手动间距） | 大量 `resources/views/auth/*.blade.php`、`components/*.blade.php` | sed 批量替换 `form-group` → `mb-3` + 手动精调 |
| `col-xs-*` / `col-sm-*` | `col-*` / `col-sm-*`（已无 `-xs`） | 几乎所有栅格 | sed 批量替换 |
| `col-md-offset-*` | `offset-md-*` | 局部 | 手动替换 |
| `control-label` | `form-label` | 表单 | 手动替换 + alias 垫片 |
| `help-block` | `form-text` | 表单 | 手动替换 |
| `has-error` | `is-invalid` | 表单 | 手动替换 |

### 2.2 组件
| BS3 类 | BS5 等价 | 影响范围 | 处置 |
|---|---|---|---|
| `panel` / `panel-default` / `panel-body` / `panel-heading` / `panel-footer` / `panel-title` | `card` / `card-body` / `card-header` / `card-footer` / `card-title` | 大量（项目内 5+ 自定义 panel-* 变体） | **保留 BS3 类名 + SCSS 别名映射**（见 §3） |
| `btn-default` | `btn-secondary` | 通用 | sed 批量替换 |
| `btn-lg` / `btn-sm` | 同名（无变化） | 通用 | — |
| `pull-left` / `pull-right` | `float-start` / `float-end` | 少量 | 别名垫片 |
| `label`（非 `.form-label`） | `.form-label` | 表单 | 别名垫片 |
| `input-lg` / `input-sm` | `form-control-lg` / `form-control-sm` | 少量 | 别名垫片 |
| `navbar-default` / `navbar-inverse` | `navbar-light` / `navbar-dark` + `data-bs-theme` | `layouts/navbar.blade.php` | 手动替换 |
| `glyphicon` | `bootstrap-icons` | 已移除（项目早改 font-awesome） | — |
| `well` | `card` + 自定义 padding | 少量 | 手动替换 |

### 2.3 JS / 行为
| BS3 属性 / API | BS5 等价 | 影响范围 | 处置 |
|---|---|---|---|
| `data-toggle="modal"` | `data-bs-toggle="modal"` | 所有交互组件 | sed 批量替换 `data-toggle` → `data-bs-toggle` |
| `data-toggle="dropdown"` | `data-bs-toggle="dropdown"` | 下拉菜单 | 同上 |
| `data-toggle="tab"` | `data-bs-toggle="tab"` | tabs | 同上 |
| `data-toggle="tooltip"` / `data-toggle="popover"` | `data-bs-toggle` 同名 | tooltip/popover | 同上 + JS 初始化用 `new bootstrap.Tooltip(...)` |
| `data-dismiss="modal"` | `data-bs-dismiss="modal"` | modal | sed 批量替换 |
| `data-dismiss="alert"` | `data-bs-dismiss="alert"` | alert | 同上 |
| `$().modal('show')` jQuery 链式 | `new bootstrap.Modal(el).show()` | tagmanager 之外的少量内联脚本 | 改写为 BS5 API |
| `$.fn.tooltip.Constructor` | `bootstrap.Tooltip` | 同上 | 改写 |
| `bootstrap-material-design` 自定义 JS | BS5 原生 + 自定义覆盖 | 主题切换 / ripple 效果 | 移除，依赖 BS5 自带 + `wizard-overrides.scss` |

### 2.4 兼容性回落
- IE 11 不再支持（项目本来就用 font-awesome/select2 等现代库，IE 早无业务）
- 移除 `respond.min.js` / `html5shiv.min.js` / `ie10-viewport-bug-workaround.*`（BS5 不支持 IE，无需 polyfill）
- 移除 `bootstrap-treeview.js`（项目已不依赖此 fork）

---

## 3. panel-* → card 别名映射策略

为减少 blade 模板改动成本，在 `resources/sass/wizard-overrides.scss` 中保留 BS3 类名：

```scss
.panel          { @extend .card; }
.panel-default  { @extend .card; }
.panel-body     { @extend .card-body; }
.panel-heading  { @extend .card-header; }
.panel-footer   { @extend .card-footer; }
.panel-title    { @extend .card-title; }

// 项目自定义 panel-*：保留前缀但语义映射到 card
.panel-breadcrumb {
    @extend .card;
    @extend .card-body;
    padding: 0.75rem 1rem;
    background: var(--bs-light, #f8f9fa);
    border-bottom: 1px solid var(--bs-border-color);
}
.panel-right {
    @extend .card;
    @extend .card-body;
    padding: 1rem;
}
.panel-separator {
    @extend .card;
    @extend .card-body;
    margin: 1rem 0;
    padding: 0.5rem 0;
    border-top: 1px solid var(--bs-border-color);
    border-bottom: 1px solid var(--bs-border-color);
    border-radius: 0;
}
.panel-limit {
    @extend .card;
    @extend .card-body;
    background: var(--bs-warning-bg-subtle, #fff8e1);
}
```

**取舍**：
- ✅ 优点：现有 80+ blade 模板几乎无需改动 panel-* 类名
- ⚠️ 代价：编译产物中同时存在 `card` 与 `panel` 类，CSS 体积略增（~2KB）；后续新代码建议直接使用 `card-*`

---

## 4. tagmanager.js jQuery 解耦

### 4.1 升级前
- IIFE 强依赖 `$`（即 jQuery）
- 插件定义：`(function ($) { ... })(jQuery)`
- 调用方式：`$('#tags').tagsManager({...})` → 通过 jQuery 静态方法挂载

### 4.2 升级后（兼容双轨）
- 默认导出 ES6 class `TagsManager`
- 同时挂载到 jQuery：`$.fn.tagsManager` / `$.fn.tagsManager.defaults` 保留旧 API
- 调用方式（推荐新代码）：`new TagsManager(el, options).pushTag('foo')`
- 调用方式（兼容旧代码）：`$('#tags').tagsManager({...})`

### 4.3 关键点
- 不再假设 `window.jQuery` 存在——`typeof window.jQuery === 'function'` 才挂载 jQuery 桥
- 不再使用 jQuery `$.data()` / `$.trigger()`——改用原生 `WeakMap` 存 opts + 自定义事件（`CustomEvent`）
- 仍兼容 BS5 模态框初始化（项目用 modal 装 tagmanager）

---

## 5. Playwright 视觉测试设置

### 5.1 配置
- 位置：`playwright.config.ts`（仓库根）
- baseURL：`http://127.0.0.1:8000`
- 浏览器：系统 Chrome（`channel: 'chrome'`，无需下载额外 browser binary）
- 启动 dev server：测试运行前 `php artisan serve --host=127.0.0.1 --port=8000`
- 截图目录：`tests/e2e/snapshots/`（首次运行 `npx playwright test --update-snapshots` 生成基线）

### 5.2 用例
- `tests/e2e/smoke.spec.ts` —— 关键功能 smoke（homepage / login 表单 / BS5 `form-control` 类生效）
- `tests/e2e/baseline.spec.ts` —— 三页视觉基线（`/`、`/login`、`/user/basic`），`maxDiffPixelRatio: 0.05`
- 视觉基线首跑作为 BS5 基线（BS3→BS5 必有视觉差异，预期）

### 5.3 注意事项
- 数据库：smoke 仅访客页可走，无 DB 依赖
- 端口冲突：默认 8000 端口可能被其它服务占用，需调整 `playwright.config.ts` 或停用冲突进程
- 字体回退：本地容器字体差异可能导致非 BS5 相关像素差异，必要时调高 `maxDiffPixelRatio` 至 `0.1`

---

## 6. 文件改动清单

### 6.1 新增
- `resources/sass/app.scss` —— BS5 入口（bootstrap 全量 + bootstrap-icons + project overrides）
- `resources/sass/wizard-overrides.scss` —— BS5 变量覆盖 + panel-* 别名映射 + 兼容垫片
- `resources/js/app.js` —— Vite 入口（未来按需加载 BS5 JS）
- `tests/e2e/baseline.spec.ts` —— 视觉基线
- `tests/e2e/smoke.spec.ts` —— smoke 测试
- `package.json` / `package-lock.json` —— Vite 5 + playwright 等 dev 依赖
- `vite.config.js` —— laravel-vite-plugin 配置
- `playwright.config.ts` —— Playwright 测试配置

### 6.2 删除
- `public/assets/vendor/bootstrap-material-design/`（CSS + JS 整目录）
- `public/assets/vendor/bootstrap-treeview.js`
- `public/assets/vendor/respond.min.js`
- `public/assets/vendor/html5shiv.min.js`
- `public/assets/vendor/ie10-viewport-bug-workaround.css`
- `public/assets/vendor/ie10-viewport-bug-workaround.js`
- `resources/assets/sass/`（之前已删，连同 `webpack.mix.js`、`resources/assets/js/`）

### 6.3 修改
- `resources/views/layouts/default.blade.php` —— 移除 `bootstrap-material-design` link，换 `@vite(['resources/sass/app.scss', 'resources/js/app.js'])`
- `resources/views/layouts/login.blade.php` —— 同上
- `resources/views/layouts/{admin,blog,navbar,project,project-setting,single,user}.blade.php` —— 主题类（`navbar-default`→`navbar-light` 等）
- `resources/views/**/*.blade.php`（其它 30+ 文件）—— sed 批量替换 form-group / data-toggle / btn-default / col-xs-* 等 + 手动精调
- `public/assets/js/tagmanager.js` —— jQuery 解耦（保持 `public/assets/js/tagmanager.js` 因静态引用）

### 6.4 未变
- `public/assets/css/style.css` / `style-dark.css` —— 项目自定义样式，与 BS 解耦
- `public/assets/css/tagmanager.css` —— tagmanager 视觉样式（与 BS 解耦）
- 几乎所有 `resources/views/api-clients/*.blade.php` 内容

---

## 7. 验证

- `vendor/bin/phpunit` —— 191 tests / 1122 assertions 全绿（未引入回归）
- `npx playwright test` —— 视觉基线首跑 + smoke（环境就绪后跑）
- 手动 QA：登录、文档 CRUD、评论、分享链接、导出、tagmanager 标签输入等关键流程

---

## 8. 后续可优化（不在本次升级范围）

- [ ] 增量把 `panel-*` 类名替换为 `card-*`（去除别名 CSS 体积）
- [ ] 把 `data-bs-toggle` 残留的 jQuery 调用改写为 BS5 原生 API
- [ ] 引入 Bootstrap 5 utilities（`gap-*` / `text-truncate` 等）替换内联样式
- [ ] Playwright 测试覆盖率扩展（关键流程加 functional 测试，不仅视觉基线）
