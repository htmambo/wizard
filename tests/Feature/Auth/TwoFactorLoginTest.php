<?php

namespace Tests\Feature\Auth;

use App\Repositories\User;
use App\Services\LoginAttemptService;
use App\Services\TwoFactorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use Tests\TestCase;

/**
 * 端到端: 启用 2FA 的账号必须经过两步验证才能登录,锁定账号被拒绝。
 *
 * 覆盖:
 *   - 未启用 2FA: 账号密码一次完成登录
 *   - 启用 2FA: 密码正确后跳转到 /auth/2fa,正确 TOTP code 才能完成登录
 *   - 启用 2FA: 错误 TOTP code 仍停留在 /auth/2fa 且记录失败计数
 *   - 启用 2FA: backup code 也能完成登录(单次使用)
 *   - 锁定账号即使密码正确也被 LoginController 拒绝
 */
class TwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_user_without_2fa_logs_in_with_password_only(): void
    {
        $user = $this->createUser([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
            '_token'   => 'test-csrf-token',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect('/');
        // 不应走到 2FA 步骤
        $this->assertNull(session('2fa_pending_user_id'));
    }

    public function test_user_with_2fa_requires_totp_to_complete_login(): void
    {
        $user = $this->createUser([
            'password' => Hash::make(self::PASSWORD),
        ]);

        $this->enable2faFor($user);

        // 步骤 1: 密码登录,应跳转到 /auth/2fa
        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
            '_token'   => 'test-csrf-token',
        ]);

        $response->assertRedirect(route('auth.2fa.show'));
        $this->assertGuest();  // 尚未真正登录
        $this->assertSame($user->id, session('2fa_pending_user_id'));

        // 步骤 2a: 错误 TOTP code -> 仍停留在 /auth/2fa
        $wrongCode = '000000';  // 高概率非当前窗口码
        $this->post('/auth/2fa', [
            'code'   => $wrongCode,
            '_token' => 'test-csrf-token',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertSame($user->id, session('2fa_pending_user_id'), '错误 code 不应清掉 pending id');

        // 失败计数应该已经 +1
        $user->refresh();
        $this->assertSame(1, (int) $user->login_fail_count);

        // 步骤 2b: 正确 TOTP code -> 完成登录
        $service = new TwoFactorService();
        $secret = $this->decryptSecret($user);
        $currentCode = $this->computeCodeAt($service, $secret, Carbon::now()->getTimestamp());

        $response = $this->post('/auth/2fa', [
            'code'   => $currentCode,
            '_token' => 'test-csrf-token',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNull(session('2fa_pending_user_id'), '成功后应清掉 pending id');

        // 失败计数被清零
        $user->refresh();
        $this->assertSame(0, (int) $user->login_fail_count);
    }

    public function test_backup_code_can_complete_login(): void
    {
        $user = $this->createUser([
            'password' => Hash::make(self::PASSWORD),
        ]);
        $this->enable2faFor($user);

        // 获取备用码
        $service = new TwoFactorService();
        $backupCodes = $service->getBackupCodes($user);
        $this->assertNotEmpty($backupCodes);
        $backup = $backupCodes[0];

        // 密码登录
        $this->post('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
            '_token'   => 'test-csrf-token',
        ])->assertRedirect(route('auth.2fa.show'));

        // 备用码登录
        $response = $this->post('/auth/2fa', [
            'code'   => $backup,
            '_token' => 'test-csrf-token',
        ]);
        $this->assertAuthenticatedAs($user);

        // 备用码被消耗,数组中少一项
        $user->refresh();
        $remaining = $service->getBackupCodes($user);
        $this->assertCount(count($backupCodes) - 1, $remaining);
        $this->assertNotContains($backup, $remaining);

        // 注销后再次登录,再用同一个备用码应失败
        auth()->logout();
        $this->post('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
            '_token'   => 'test-csrf-token',
        ])->assertRedirect(route('auth.2fa.show'));

        // 注意: 第二个备用码仍能登录;第一个已被消耗,无法再用
        $wrongBackup = $backup;
        $this->post('/auth/2fa', [
            'code'   => $wrongBackup,
            '_token' => 'test-csrf-token',
        ])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_wrong_totp_code_returns_to_form_with_error(): void
    {
        $user = $this->createUser([
            'password' => Hash::make(self::PASSWORD),
        ]);
        $this->enable2faFor($user);

        // 密码登录
        $this->post('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
            '_token'   => 'test-csrf-token',
        ])->assertRedirect(route('auth.2fa.show'));

        // 错误 TOTP code
        $response = $this->post('/auth/2fa', [
            'code'   => '111111',
            '_token' => 'test-csrf-token',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_locked_user_cannot_login_even_with_correct_password(): void
    {
        $user = $this->createUser([
            'password' => Hash::make(self::PASSWORD),
        ]);

        // 直接锁账号(模拟连续 10 次失败)
        $attempt = new LoginAttemptService();
        for ($i = 0; $i < LoginAttemptService::MAX_ATTEMPTS; $i++) {
            $attempt->recordFailure($user->fresh());
        }

        // 即使密码正确,也必须被拒绝
        $response = $this->post('/login', [
            'email'    => $user->email,
            'password' => self::PASSWORD,
            '_token'   => 'test-csrf-token',
        ]);

        $response->assertRedirect();  // 重定向回 /login
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_get_2fa_form_without_pending_session_redirects_to_login(): void
    {
        // 没有 pending session,直接 GET /auth/2fa 应重定向到 /login
        $response = $this->get('/auth/2fa');
        $response->assertRedirect(route('login'));
    }

    public function test_post_2fa_without_pending_session_redirects_to_login(): void
    {
        $response = $this->post('/auth/2fa', [
            'code'   => '000000',
            '_token' => 'test-csrf-token',
        ]);
        $response->assertRedirect(route('login'));
    }

    /**
     * 启用 2FA: 生成 secret 并使用当前窗口的 TOTP code 完成 enable。
     */
    private function enable2faFor(User $user): void
    {
        $service = new TwoFactorService();
        $secret = $service->generateSecret($user);
        $code = $this->computeCodeAt($service, $secret, Carbon::now()->getTimestamp());
        $this->assertTrue($service->enable($user, $code));
        $user->refresh();
        $this->assertNotNull($user->totp_enabled_at);
    }

    /**
     * 读取 totp_secret 明文(由 Encrypted Cast 自动解密)。
     */
    private function decryptSecret(User $user): string
    {
        $secret = $user->totp_secret;
        if (!is_string($secret)) {
            $this->fail('totp_secret 不是字符串');
        }
        return $secret;
    }

    /**
     * 反射调用 TwoFactorService::computeCode 计算指定 counter 下的 TOTP code。
     */
    private function computeCodeAt(TwoFactorService $service, string $secret, int $timestamp): string
    {
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('computeCode');
        $method->setAccessible(true);
        $counter = intdiv($timestamp, 30);
        return $method->invoke($service, $secret, $counter);
    }
}