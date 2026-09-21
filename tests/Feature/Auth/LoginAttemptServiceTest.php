<?php

namespace Tests\Feature\Auth;

use App\Repositories\User;
use App\Services\LoginAttemptService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Notifications\AccountLockedNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * LoginAttemptService（账号级登录失败计数与锁定）测试。
 *
 * 覆盖:
 *   - 默认状态 isLocked/remainingAttempts
 *   - 10 次失败触发锁定,并发送通知(mail + database)
 *   - recordSuccess 清零计数与锁定
 *   - 滑窗重置:首次失败超过 1 小时后,新失败触发 first_at=now+count=1
 *   - 锁定期间 recordFailure 不延长锁定、不重复通知
 *   - 锁定到期后 isLocked 自动解除
 *   - 锁定用户即使密码正确也被 LoginController::login 拒绝
 */
class LoginAttemptServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoginAttemptService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LoginAttemptService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_is_locked_returns_false_when_no_lock_recorded(): void
    {
        $user = $this->createUser();
        $this->assertFalse($this->service->isLocked($user));
        $this->assertSame(
            LoginAttemptService::MAX_ATTEMPTS,
            $this->service->remainingAttempts($user)
        );
    }

    public function test_ten_failures_lock_account_and_send_notification(): void
    {
        Notification::fake();

        $user = $this->createUser();
        $now = Carbon::create(2026, 9, 20, 12, 0, 0);
        Carbon::setTestNow($now);

        // 第 1 ~ 9 次失败: 不应锁定
        for ($i = 1; $i <= LoginAttemptService::MAX_ATTEMPTS - 1; $i++) {
            $this->service->recordFailure($user->fresh());
        }

        $user->refresh();
        $this->assertFalse($this->service->isLocked($user), '前 9 次失败不应触发锁定');
        $this->assertSame(1, $this->service->remainingAttempts($user));

        // 第 10 次失败: 触发锁定
        $this->service->recordFailure($user->fresh());

        $user->refresh();
        $this->assertTrue($this->service->isLocked($user));
        $this->assertSame(0, $this->service->remainingAttempts($user));

        // 锁定到期时间应大致 = now + 3600 秒
        $expected = $now->copy()->addSeconds(LoginAttemptService::LOCK_SECONDS);
        $this->assertEqualsWithDelta(
            $expected->getTimestamp(),
            $this->asCarbon($user->login_locked_until)->getTimestamp(),
            2  // 容忍秒级偏差(包含 save 耗时)
        );

        // 通知:AccountLockedNotification 投递了一次
        Notification::assertSentTo($user, AccountLockedNotification::class);
        Notification::assertSentTimes(AccountLockedNotification::class, 1);
    }

    public function test_record_success_resets_counter_and_lock(): void
    {
        $user = $this->createUser();

        // 制造 5 次失败
        for ($i = 0; $i < 5; $i++) {
            $this->service->recordFailure($user->fresh());
        }

        $user->refresh();
        $this->assertSame(5, $user->login_fail_count);
        $this->assertNotNull($user->login_fail_first_at);

        // 模拟成功登录
        $this->service->recordSuccess($user->fresh());

        $user->refresh();
        $this->assertSame(0, $user->login_fail_count);
        $this->assertNull($user->login_fail_first_at);
        $this->assertNull($user->login_locked_until);
    }

    public function test_lock_expires_after_one_hour(): void
    {
        Notification::fake();

        $user = $this->createUser();
        $now = Carbon::create(2026, 9, 20, 12, 0, 0);
        Carbon::setTestNow($now);

        for ($i = 0; $i < LoginAttemptService::MAX_ATTEMPTS; $i++) {
            $this->service->recordFailure($user->fresh());
        }

        $user->refresh();
        $this->assertTrue($this->service->isLocked($user));

        // 30 分钟后: 仍锁定
        Carbon::setTestNow($now->copy()->addMinutes(30));
        $this->assertTrue($this->service->isLocked($user->fresh()));

        // 61 分钟后: 锁定解除
        Carbon::setTestNow($now->copy()->addMinutes(61));
        $this->assertFalse($this->service->isLocked($user->fresh()));
    }

    public function test_record_failure_during_lock_does_not_extend_lock_or_notify_again(): void
    {
        Notification::fake();

        $user = $this->createUser();
        $now = Carbon::create(2026, 9, 20, 12, 0, 0);
        Carbon::setTestNow($now);

        for ($i = 0; $i < LoginAttemptService::MAX_ATTEMPTS; $i++) {
            $this->service->recordFailure($user->fresh());
        }
        $user->refresh();
        $originalLockedUntil = $this->asCarbon($user->login_locked_until)->copy();

        // 锁定后再失败 3 次
        Carbon::setTestNow($now->copy()->addMinutes(10));
        for ($i = 0; $i < 3; $i++) {
            $this->service->recordFailure($user->fresh());
        }

        $user->refresh();
        $this->assertTrue($this->service->isLocked($user));
        // 锁定到期时间不应被延长
        $this->assertSame(
            $originalLockedUntil->getTimestamp(),
            $this->asCarbon($user->login_locked_until)->getTimestamp(),
            '锁定期间不应延长 login_locked_until'
        );
        // 没有产生新的通知
        Notification::assertSentTimes(AccountLockedNotification::class, 1);
    }

    public function test_window_resets_after_one_hour(): void
    {
        $user = $this->createUser();
        $now = Carbon::create(2026, 9, 20, 12, 0, 0);
        Carbon::setTestNow($now);

        // 第 1 次失败
        $this->service->recordFailure($user);
        $user->refresh();
        $firstAt = $this->asCarbon($user->login_fail_first_at)->copy();
        $this->assertSame(1, $user->login_fail_count);

        // 1 小时 1 秒后:滑窗已过
        Carbon::setTestNow($now->copy()->addSeconds(LoginAttemptService::WINDOW_SECONDS + 1));

        // 再失败 1 次:应被视为新窗口(count 重置为 1,first_at 更新)
        $this->service->recordFailure($user->fresh());
        $user->refresh();

        $this->assertSame(1, $user->login_fail_count, '滑窗外失败应重置 count');
        $this->assertGreaterThan(
            $firstAt->getTimestamp(),
            $this->asCarbon($user->login_fail_first_at)->getTimestamp(),
            'first_at 应被重置'
        );

        // 计数从 1 开始,剩余 9 次
        $this->assertSame(9, $this->service->remainingAttempts($user));

        // 之后再失败 8 次(共 9 次),仍不应触发锁定
        for ($i = 0; $i < 8; $i++) {
            $this->service->recordFailure($user->fresh());
        }
        $user->refresh();
        $this->assertFalse(
            $this->service->isLocked($user),
            '新窗口内 9 次失败不应继承旧窗口的累计计数'
        );
    }

    public function test_remaining_attempts_decreases_on_failure(): void
    {
        $user = $this->createUser();
        $this->assertSame(10, $this->service->remainingAttempts($user));

        $this->service->recordFailure($user->fresh());
        $this->assertSame(9, $this->service->remainingAttempts($user->fresh()));

        $this->service->recordFailure($user->fresh());
        $this->assertSame(8, $this->service->remainingAttempts($user->fresh()));
    }

    public function test_locked_user_blocked_from_login_even_with_correct_password(): void
    {
        Notification::fake();

        $password = 'correct-horse-battery-staple';
        $user = $this->createUser([
            'name'     => 'lockeduser',
            'password' => bcrypt($password),
        ]);

        // 直接锁账号(模拟连续 10 次失败)
        for ($i = 0; $i < LoginAttemptService::MAX_ATTEMPTS; $i++) {
            $this->service->recordFailure($user->fresh());
        }

        // 即使密码正确,LoginController 也必须拒绝
        $response = $this->post('/login', [
            'name'     => 'lockeduser',
            'password' => $password,
            '_token'   => 'test-csrf-token',
        ]);

        $response->assertRedirect();  // 重定向回登录页
        $response->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /**
     * 把可能为 string/Carbon/null 的字段归一为 Carbon。
     */
    private function asCarbon($value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }
}