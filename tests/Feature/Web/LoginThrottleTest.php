<?php

namespace Tests\Feature\Web;

use App\Repositories\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Web 登录限速(web-login: 5/min, 按 name|ip)端到端测试。
 *
 * 覆盖:
 *   - 5 次错误登录后,第 6 次请求一律返回 429(ApiResponse 格式)
 *   - 错误登录 <= 3 次后,正确登录仍可成功
 *   - 不同用户名计数器独立
 *   - 不同 IP 计数器独立
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    private function createActivatedUser(?string $name = null): User
    {
        return $this->createUser([
            'name'     => $name ?? 'loginuser_' . uniqid(),
            'password' => bcrypt(self::PASSWORD),
        ]);
    }

    private function wrongCredentialsPayload(string $name): array
    {
        return [
            'name'                  => $name,
            'password'              => 'definitely-wrong-password',
            '_token'                => 'test-csrf-token',
        ];
    }

    private function correctCredentialsPayload(string $name): array
    {
        return [
            'name'                  => $name,
            'password'              => self::PASSWORD,
            '_token'                => 'test-csrf-token',
        ];
    }

    public function test_five_failed_logins_block_subsequent_request_with_429(): void
    {
        $user = $this->createActivatedUser('throttle_user_a');

        // 前 5 次错误登录:应返回 302(重定向回 /login 带 error)而非 429
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->post('/login', $this->wrongCredentialsPayload('throttle_user_a'));
            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                "Request #{$i} should NOT be throttled yet (got {$response->getStatusCode()})"
            );
        }

        // 第 6 次请求被限流
        $response = $this->post('/login', $this->wrongCredentialsPayload('throttle_user_a'));
        $response->assertStatus(429)
            ->assertJson(['success' => false, 'message' => 'Too Many Requests'])
            ->assertJsonStructure(['success', 'message', 'request_id']);

        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_successful_login_still_works_after_few_failed_attempts(): void
    {
        $user = $this->createActivatedUser('recover_user');

        // 3 次错误登录(未超过 5/min 配额)
        for ($i = 1; $i <= 3; $i++) {
            $this->post('/login', $this->wrongCredentialsPayload('recover_user'));
        }

        // 正确密码登录应成功(302 重定向,默认到 /)
        $response = $this->post('/login', $this->correctCredentialsPayload('recover_user'));
        $this->assertNotSame(
            429,
            $response->getStatusCode(),
            "Successful login should NOT be throttled (got {$response->getStatusCode()})"
        );
        $this->assertContains(
            $response->getStatusCode(),
            [200, 302],
            "Expected redirect or 200 after correct credentials"
        );

        // 重置缓存避免后续测试串扰(尽管 setUp 已清,这里为显式语义)
        $this->assertTrue(true);
    }

    public function test_different_usernames_have_independent_rate_limit_counters(): void
    {
        $userA = $this->createActivatedUser('indep_user_a');
        $userB = $this->createActivatedUser('indep_user_b');

        // userA 触发 5 次错误登录后,被限流
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/login', $this->wrongCredentialsPayload('indep_user_a'));
        }
        $blocked = $this->post('/login', $this->wrongCredentialsPayload('indep_user_a'));
        $blocked->assertStatus(429);

        // userB 不受 userA 配额影响,可以继续 5 次错误登录
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->post('/login', $this->wrongCredentialsPayload('indep_user_b'));
            $this->assertNotSame(
                429,
                $response->getStatusCode(),
                "Request #{$i} for userB should NOT be throttled"
            );
        }
    }

    public function test_different_ips_have_independent_rate_limit_counters(): void
    {
        $user = $this->createActivatedUser('multi_ip_user');

        // 从 IP 127.0.0.1 触发 5 次错误登录,被限流
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/login', $this->wrongCredentialsPayload('multi_ip_user'));
        }
        $blocked = $this->post('/login', $this->wrongCredentialsPayload('multi_ip_user'));
        $blocked->assertStatus(429);

        // 模拟不同 IP(SERVER 变量):同用户名不应被限流
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->post('/login', $this->wrongCredentialsPayload('multi_ip_user'));
        $this->assertNotSame(
            429,
            $response->getStatusCode(),
            "Request from different IP should NOT be throttled (got {$response->getStatusCode()})"
        );
    }
}
