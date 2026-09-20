<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace Tests\Feature\Csp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SecurityHeaders 中间件端到端测试。
 *
 * 覆盖:
 *   - 公开页面(/)应携带完整的 CSP / X-Frame-Options / X-Content-Type-Options /
 *     Referrer-Policy / Permissions-Policy
 *   - API 接口(/api/project/lists.json)同样注入 CSP
 *   - config('security.csp_exempt_routes') 白名单内的路由不注入 CSP,
 *     其它安全头仍按业务需求保留(此处要求同样豁免以避免破坏 scramble UI)
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_home_page_returns_security_headers(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('same-origin', $response->headers->get('Referrer-Policy'));
        $this->assertSame(
            'camera=(), microphone=(), geolocation=()',
            $response->headers->get('Permissions-Policy')
        );
    }

    public function test_csp_directives_match_specification(): void
    {
        $response = $this->get('/');
        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $csp);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline'", $csp);
        $this->assertStringContainsString("img-src 'self' data: https://*.alicdn.com", $csp);
        $this->assertStringContainsString("connect-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_api_endpoint_includes_csp_header(): void
    {
        $response = $this->getJson('/api/project/lists.json');

        $response->assertStatus(401);
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function test_exempt_route_does_not_receive_csp_header(): void
    {
        // 把 api/version 临时加入白名单(测试结束后还原),验证中间件确实读取了配置。
        config(['security.csp_exempt_routes' => ['api/version']]);

        $response = $this->getJson('/api/version');

        $response->assertStatus(200);
        $this->assertNull(
            $response->headers->get('Content-Security-Policy'),
            '白名单路由不应注入 CSP'
        );
    }

    public function test_exempt_route_glob_pattern_does_not_receive_csp_header(): void
    {
        config(['security.csp_exempt_routes' => ['api/document/*/markdown']]);

        // 该路由实际不存在 → 404;但中间件应已经看到白名单并跳过 CSP 注入。
        $response = $this->getJson('/api/document/42/markdown');

        $this->assertNull(
            $response->headers->get('Content-Security-Policy'),
            '通配符白名单路由不应注入 CSP'
        );
    }

    public function test_nonexempt_route_still_receives_csp_header(): void
    {
        // 显式清空白名单,所有路由都必须有 CSP。
        config(['security.csp_exempt_routes' => []]);

        $response = $this->get('/');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->headers->get('Content-Security-Policy'));
    }
}
