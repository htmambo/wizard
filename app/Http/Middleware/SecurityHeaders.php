<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 安全响应头中间件:为所有 HTTP 响应注入 CSP 与其它安全相关头。
 *
 * 设计要点:
 *   - 全局中间件,作用于每一次响应(包括 4xx/5xx,Symfony Response::sendContent 之前)
 *   - 通过 config('security.csp_exempt_routes') 配置白名单,部分老牌前端组件
 *     (Editor.md / x-spreadsheet / swagger-editor)依赖 unsafe-inline / unsafe-eval,
 *     仍可在白名单内继续工作。其余路由统一走 strict 策略。
 *   - CSP 指令故意保留 self + unsafe-inline/unsafe-eval 而非 strict-dynamic:
 *     渲染视图层仍有大量内联脚本/样式(Blade 注入、vendor 包),短期内无法彻底清退。
 *   - 已知外部 CDN 域名(blog 子站使用的 BS4/font-awesome/jQuery 等老依赖,以及
 *     KaTeX 的字体与样式)显式加入白名单,避免被 CSP 拦截导致控制台一片红叉。
 */
class SecurityHeaders
{
    /**
     * 受信任的外部 CDN 列表。所有列出的域名在 style-src / script-src / font-src
     * / img-src 中都会放开;若业务对 CSP 严格度有进一步要求,可在后续迭代中按需
     * 拆分(例如把 jQuery 单独的 blog 路由加入 csp_exempt_routes)。
     */
    private const TRUSTED_CDN_HOSTS = [
        // blog 子站使用 BS4 + font-awesome 5 + jQuery 3 的 CDN
        'cdn.jsdelivr.net',
        'code.jquery.com',
        // cdnjs 公共镜像(KaTeX / FontAwesome 备用源)
        'cdnjs.cloudflare.com',
    ];

    /** @var string 默认 CSP 策略 */
    private function defaultCsp(): string
    {
        $cdn = implode(' ', array_map(static fn (string $host) => "https://{$host}", self::TRUSTED_CDN_HOSTS));

        return "default-src 'self';"
            . " script-src 'self' 'unsafe-inline' 'unsafe-eval' {$cdn};"
            . " style-src 'self' 'unsafe-inline' {$cdn};"
            . " font-src 'self' data: {$cdn};"
            . " img-src 'self' data: https://*.alicdn.com {$cdn};"
            . " connect-src 'self';"
            . " frame-ancestors 'self';"
            . " object-src 'none';"
            . " base-uri 'self';"
            . " form-action 'self'";
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->shouldApply($request, $response)) {
            return $response;
        }

        $headers = $response->headers;
        if (!$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', $this->defaultCsp());
        }
        if (!$headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        if (!$headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }
        if (!$headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'same-origin');
        }
        if (!$headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        }

        return $response;
    }

    /**
     * 判断是否对当前请求注入安全头。
     *
     * 白名单内的路由直接跳过(CSP / 其它头留给业务自行处理)。
     * 非 Response 对象(如某些流式响应)跳过,避免调用 headers API 抛错。
     */
    private function shouldApply(Request $request, $response): bool
    {
        if (!$response instanceof Response || !isset($response->headers) || !method_exists($response->headers, 'set')) {
            return false;
        }

        $exempt = config('security.csp_exempt_routes', []);
        if (!is_array($exempt) || empty($exempt)) {
            return true;
        }

        $path = ltrim($request->getPathInfo(), '/');
        foreach ($exempt as $pattern) {
            if (!is_string($pattern) || $pattern === '') {
                continue;
            }
            $normalized = ltrim($pattern, '/');
            if ($normalized === $path) {
                return false;
            }
            if (str_contains($normalized, '{') || str_contains($normalized, '*')) {
                $regex = '#^' . str_replace('\*', '.*', preg_quote($normalized, '#')) . '$#';
                if (preg_match($regex, $path) === 1) {
                    return false;
                }
            }
        }

        return true;
    }
}
