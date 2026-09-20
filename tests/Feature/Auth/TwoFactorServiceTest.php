<?php

namespace Tests\Feature\Auth;

use App\Repositories\User;
use App\Services\TwoFactorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * TwoFactorService（TOTP, RFC 6238）单元/集成测试。
 *
 * 覆盖:
 *   - generateSecret 返回 base32 串且具备唯一性
 *   - enable 写 totp_enabled_at 并生成 backup_codes
 *   - verify 接受正确 TOTP code,拒绝错误码
 *   - verify 接受备用码但单次使用(第二次返回 false)
 *   - 时钟漂移 ±1 窗口通过
 *   - disable 清空所有 2FA 字段
 *   - 校验未启用用户直接拒绝(防止越权)
 */
class TwoFactorServiceTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TwoFactorService();
    }

    protected function tearDown(): void
    {
        // 确保不会污染其它测试
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_generate_secret_returns_base32_and_is_unique(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();

        $secretA = $this->service->generateSecret($userA);
        $secretB = $this->service->generateSecret($userB);

        // RFC 4648 base32 字母表: 大写 A-Z + 数字 2-7
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{20}$/', $secretA);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{20}$/', $secretB);

        $this->assertNotSame($secretA, $secretB, '两次生成的密钥应不同');

        // 加密后落盘,数据库里是密文(User 模型 Encrypted Cast 负责)
        $userA->refresh();
        $this->assertNotEmpty($userA->totp_secret);
        $this->assertSame($secretA, $userA->totp_secret, 'Eloquent 应自动解密回明文');

        // 数据库原始值应为密文而非明文
        $raw = \DB::table('users')->where('id', $userA->id)->value('totp_secret');
        $this->assertNotSame($secretA, $raw, '数据库中应为加密字符串而非明文');
        $this->assertNotEmpty($raw);
    }

    public function test_enable_sets_totp_enabled_at_and_generates_backup_codes(): void
    {
        $user = $this->createUser();
        $secret = $this->service->generateSecret($user);
        $code = $this->computeCodeAt($secret, Carbon::now()->getTimestamp());

        $beforeEnable = Carbon::now();
        $result = $this->service->enable($user, $code);
        $this->assertTrue($result);

        $user->refresh();
        $enabledAt = $this->asCarbon($user->totp_enabled_at);
        $this->assertNotNull($enabledAt);
        $this->assertGreaterThanOrEqual(
            $beforeEnable->getTimestamp(),
            $enabledAt->getTimestamp()
        );

        $codes = $this->service->getBackupCodes($user);
        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $code);
        }
    }

    public function test_verify_accepts_correct_totp_code(): void
    {
        $user = $this->createUser();
        $secret = $this->service->generateSecret($user);
        $code = $this->computeCodeAt($secret, Carbon::now()->getTimestamp());

        $this->assertTrue($this->service->enable($user, $code));
        $this->assertTrue($this->service->verify($user, $code));
    }

    public function test_verify_rejects_wrong_totp_code(): void
    {
        $user = $this->createUser();
        $secret = $this->service->generateSecret($user);
        $correctCode = $this->computeCodeAt($secret, Carbon::now()->getTimestamp());

        $this->assertTrue($this->service->enable($user, $correctCode));

        // 错误码: 取相反码(每位数变为 9 - 原数)生成扰动码,大概率不命中
        $wrongCode = str_pad((string) ((int) $correctCode + 123456) % 1000000, 6, '0', STR_PAD_LEFT);
        if ($wrongCode === $correctCode) {
            $wrongCode = '000000';
        }
        $this->assertFalse($this->service->verify($user, $wrongCode));
    }

    public function test_verify_backup_code_is_single_use(): void
    {
        $user = $this->createUser();
        $secret = $this->service->generateSecret($user);
        $code = $this->computeCodeAt($secret, Carbon::now()->getTimestamp());
        $this->assertTrue($this->service->enable($user, $code));

        $codes = $this->service->getBackupCodes($user);
        $backup = $codes[0];

        // 第一次使用:成功
        $this->assertTrue($this->service->verify($user, $backup));

        // 第二次使用:已被消耗,返回 false
        $this->assertFalse($this->service->verify($user, $backup));

        // 其它备用码仍然可用
        $this->assertCount(7, $this->service->getBackupCodes($user->fresh()));
    }

    public function test_verify_accepts_codes_within_window_drift(): void
    {
        $user = $this->createUser();
        $secret = $this->service->generateSecret($user);

        $baseTime = Carbon::create(2026, 9, 20, 12, 0, 0);
        Carbon::setTestNow($baseTime);

        $baseCounter = intdiv($baseTime->getTimestamp(), 30);
        $codeAtBase = $this->computeCodeAt($secret, $baseTime->getTimestamp());

        $this->assertTrue($this->service->enable($user, $codeAtBase));

        // 当前窗口:code_at_C 在 counter C 时通过
        $this->assertTrue($this->service->verify($user, $codeAtBase), '当前 counter 通过');

        // -1 窗口:把测试时间向前 30 秒,code_at_C 仍属 ±1 范围
        Carbon::setTestNow($baseTime->copy()->subSeconds(30));
        $this->assertTrue(
            $this->service->verify($user, $codeAtBase),
            '±1 窗口: 向前 30 秒的 counter 仍通过'
        );

        // +1 窗口:把测试时间向后 30 秒
        Carbon::setTestNow($baseTime->copy()->addSeconds(30));
        $this->assertTrue(
            $this->service->verify($user, $codeAtBase),
            '±1 窗口: 向后 30 秒的 counter 仍通过'
        );

        // 超出 ±1:向后 60 秒,code_at_C 已不在 C±1 内
        Carbon::setTestNow($baseTime->copy()->addSeconds(60));
        $this->assertFalse(
            $this->service->verify($user, $codeAtBase),
            '±1 窗口之外: 向后 60 秒被拒绝'
        );

        // baseCounter 仅作内部参考
        $this->assertGreaterThan(0, $baseCounter);
    }

    public function test_disable_clears_all_2fa_fields(): void
    {
        $user = $this->createUser();
        $secret = $this->service->generateSecret($user);
        $code = $this->computeCodeAt($secret, Carbon::now()->getTimestamp());
        $this->assertTrue($this->service->enable($user, $code));

        $this->service->disable($user);

        $user->refresh();
        $this->assertNull($user->totp_secret);
        $this->assertNull($user->totp_enabled_at);
        $this->assertNull($user->backup_codes);
        $this->assertNull($this->asCarbon($user->totp_enabled_at));

        // 禁用后 verify 必须返回 false
        $this->assertFalse($this->service->verify($user, $code));
    }

    public function test_verify_returns_false_when_user_has_not_enabled_2fa(): void
    {
        $user = $this->createUser();
        // 只调用 generateSecret,没有 enable
        $this->service->generateSecret($user);

        $this->assertNull($user->fresh()->totp_enabled_at);
        $this->assertFalse($this->service->verify($user, '123456'));
    }

    public function test_get_otp_auth_uri_is_well_formed(): void
    {
        $user = $this->createUser(['email' => 'a@b.cc']);
        $secret = $this->service->generateSecret($user);

        $uri = $this->service->getOtpAuthUri($user, $secret);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('issuer=Wizard', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    /**
     * 反射访问 TwoFactorService::computeCode —— 测试需要构造已知 counter 下的 code 来验证 ±1 窗口。
     */
    private function computeCodeAt(string $secret, int $timestamp): string
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('computeCode');
        $method->setAccessible(true);
        $counter = intdiv($timestamp, 30);
        return $method->invoke($this->service, $secret, $counter);
    }

    /**
     * 将可能为 string/Carbon/null 的列值归一为 Carbon(便于调用 getTimestamp/format 等)。
     * User 模型未将这些列加入 $casts,refresh() 后是字符串。
     */
    private function asCarbon($value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }
}