<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * 命名限速器 API 端到端测试。
 *
 * 覆盖:
 *   - GET api-read:    120/min (默认 user.id)
 *   - POST api-write:  30/min  (默认 user.id)
 *   - GET api-lists:   300/min (project/lists.{format})
 *   - GET api-search:  60/min  (search)
 *   - POST oauth-token: 10/min (api/oauth/token)
 *   - 429 响应体 ApiResponse 格式 + request_id + Retry-After 头
 *   - 不同用户计数器独立
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_api_read_endpoint_returns_429_after_120_requests(): void
    {
        $user = $this->createUser();
        Passport::actingAs($user);

        // 前 120 次请求成功
        for ($i = 1; $i <= 120; $i++) {
            $response = $this->getJson('/api/user');
            $this->assertContains(
                $response->getStatusCode(),
                [200, 201],
                "Request #{$i} should succeed (got {$response->getStatusCode()})"
            );
        }

        // 第 121 次请求被限流
        $response = $this->getJson('/api/user');
        $response->assertStatus(429)
            ->assertJson(['success' => false, 'message' => 'Too Many Requests'])
            ->assertJsonStructure(['success', 'message', 'request_id']);

        $this->assertNotEmpty($response->json('request_id'));
        $this->assertNotSame('-', $response->json('request_id'));
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_post_api_write_endpoint_returns_429_after_30_requests(): void
    {
        $owner = $this->createUser();
        Passport::actingAs($owner);

        // 前 30 次创建项目请求成功
        for ($i = 1; $i <= 30; $i++) {
            $response = $this->postJson('/api/project/create', [
                'name'        => 'p_' . $i,
                'description' => 'd',
                'visibility'  => \App\Repositories\Project::VISIBILITY_PUBLIC,
                'catalog'     => 0,
            ]);
            $this->assertContains(
                $response->getStatusCode(),
                [200, 201],
                "Request #{$i} should succeed (got {$response->getStatusCode()})"
            );
        }

        // 第 31 次请求被限流
        $response = $this->postJson('/api/project/create', [
            'name'        => 'p_overflow',
            'description' => 'd',
            'visibility'  => \App\Repositories\Project::VISIBILITY_PUBLIC,
            'catalog'     => 0,
        ]);
        $response->assertStatus(429)
            ->assertJson(['success' => false, 'message' => 'Too Many Requests']);

        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_project_lists_endpoint_returns_429_after_300_requests(): void
    {
        $user = $this->createUser();
        Passport::actingAs($user);

        // 前 300 次请求成功
        for ($i = 1; $i <= 300; $i++) {
            $response = $this->getJson('/api/project/lists.json');
            $this->assertSame(
                200,
                $response->getStatusCode(),
                "Request #{$i} should succeed (got {$response->getStatusCode()})"
            );
        }

        // 第 301 次请求被限流
        $response = $this->getJson('/api/project/lists.json');
        $response->assertStatus(429)
            ->assertJson(['success' => false, 'message' => 'Too Many Requests'])
            ->assertJsonStructure(['success', 'message', 'request_id']);

        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_oauth_token_endpoint_returns_429_after_10_requests(): void
    {
        // OAuth token 是公共端点(无需 Passport::actingAs),按 IP 限速。
        // 用密码授权请求模拟,无论成功失败都消耗计数。
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->postJson('/api/oauth/token', [
                'grant_type' => 'password',
                'client_id'  => 'fake-client',
                'client_secret' => 'fake-secret',
                'username'   => 'fake-user',
                'password'   => 'fake-pass',
            ]);
            // 不关心 401/400/200,只要不是 429
            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                "Request #{$i} should NOT be throttled yet (got {$response->getStatusCode()})"
            );
        }

        // 第 11 次被限流
        $response = $this->postJson('/api/oauth/token', [
            'grant_type' => 'password',
            'client_id'  => 'fake-client',
            'client_secret' => 'fake-secret',
            'username'   => 'fake-user',
            'password'   => 'fake-pass',
        ]);
        $response->assertStatus(429)
            ->assertJson(['success' => false, 'message' => 'Too Many Requests'])
            ->assertJsonStructure(['success', 'message', 'request_id']);
    }

    public function test_different_users_have_independent_rate_limit_counters(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();

        // userA 用满 30 次 write 配额
        Passport::actingAs($userA);
        for ($i = 1; $i <= 30; $i++) {
            $this->postJson('/api/project/create', [
                'name'        => 'a_' . $i,
                'description' => 'd',
                'visibility'  => \App\Repositories\Project::VISIBILITY_PUBLIC,
                'catalog'     => 0,
            ]);
        }
        $blocked = $this->postJson('/api/project/create', [
            'name'        => 'a_overflow',
            'description' => 'd',
            'visibility'  => \App\Repositories\Project::VISIBILITY_PUBLIC,
            'catalog'     => 0,
        ]);
        $blocked->assertStatus(429);

        // 切换到 userB,不应受 userA 配额影响
        Passport::actingAs($userB);
        $response = $this->postJson('/api/project/create', [
            'name'        => 'b_first',
            'description' => 'd',
            'visibility'  => \App\Repositories\Project::VISIBILITY_PUBLIC,
            'catalog'     => 0,
        ]);
        $response->assertStatus(200)->assertJson(['success' => true]);
    }

    public function test_api_search_uses_separate_limiter_at_60_per_minute(): void
    {
        $user = $this->createUser();
        Passport::actingAs($user);

        // 触发一次以确认 200,确认 search 走的是 api-search limiter(60/min)
        // 不必真的发 60 次,只需确认连续 60 次都未触发 429,即可证明其阈值
        // 不同于默认的 api-read(120/min) 与 api-lists(300/min)。
        for ($i = 1; $i <= 60; $i++) {
            $response = $this->getJson('/api/search?q=test');
            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                "Request #{$i} on /api/search should not be throttled"
            );
        }

        // 第 61 次应当被限流(api-search 阈值 60/min)
        $response = $this->getJson('/api/search?q=test');
        $response->assertStatus(429)
            ->assertJson(['success' => false, 'message' => 'Too Many Requests']);
    }

    public function test_429_response_includes_x_request_id_header(): void
    {
        $user = $this->createUser();
        Passport::actingAs($user);

        // 用满 30 次 write 配额
        for ($i = 1; $i <= 30; $i++) {
            $this->postJson('/api/project/create', [
                'name'        => 'p_' . $i,
                'description' => 'd',
                'visibility'  => \App\Repositories\Project::VISIBILITY_PUBLIC,
                'catalog'     => 0,
            ]);
        }

        $response = $this->postJson('/api/project/create', [
            'name'        => 'overflow',
            'description' => 'd',
            'visibility'  => \App\Repositories\Project::VISIBILITY_PUBLIC,
            'catalog'     => 0,
        ]);
        $response->assertStatus(429);

        // X-Request-Id 由 RequestId 中间件注入,响应头中应存在
        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        // JSON 中的 request_id 应与 header 一致
        $this->assertSame(
            $response->headers->get('X-Request-Id'),
            $response->json('request_id')
        );
    }
}
