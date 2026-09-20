<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

/**
 * 安全相关全局配置。
 *
 * csp_exempt_routes: 在该白名单内的路由,SecurityHeaders 中间件不会注入
 * 默认 CSP / 其它安全头,留给业务自行处理。常见用例:
 *   - Editor.md / x-spreadsheet / swagger-editor / scramble 等依赖 unsafe-inline
 *     或 unsafe-eval 的页面
 *   - iframe sandbox 测试页
 *
 * 支持精确匹配('/foo/bar')与通配符('/api/document/*')。
 */
return [
    'csp_exempt_routes' => [
        // 公共访问且渲染 markdown / 富文本编辑器的接口
        'api/document/*/markdown',
        // Swagger UI / Scramble 自动文档(注入大量内联脚本)
        'docs/api',
        'docs/api/*',
        'api/documentation',
        'api/documentation/*',
    ],
];
