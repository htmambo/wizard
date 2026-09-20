<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Services;

use App\Repositories\User;
use Carbon\Carbon;
use RuntimeException;

/**
 * TOTP（RFC 6238）两步验证域服务。
 *
 * 职责:
 *  - 生成并存储 base32 共享密钥(totp_secret,落盘加密由 User 模型 Encrypted Cast 负责)
 *  - 启用 2FA 时生成 8 个单次使用的备用码(backup_codes)
 *  - 校验登录时输入的 6 位 TOTP 或备用码,±1 个时间窗口(±30s)漂移
 *  - 禁用 2FA 时清空密钥/启用时间/备用码
 *
 * 约定:
 *  - plain class,Laravel 自动解析依赖
 *  - 不写接口
 *  - 入参已校验;授权由 Controller 负责
 *  - 时间使用 Carbon::now()->getTimestamp(),以便测试可通过 Carbon::setTestNow() 控制
 *  - 不重复加密:totp_secret / backup_codes 由 User 模型的 $casts(Encrypted::class)
 *    在 save() 时统一加密,本服务只读写明文/明文数组
 *
 * 不引入新 composer 依赖:纯 PHP 实现 hash_hmac('sha1', ...) + 自写 base32 兼容。
 */
class TwoFactorService
{
    /**
     * 默认时间窗口长度 (TOTP 30 秒为一周期)
     */
    const PERIOD = 30;

    /**
     * 漂移窗口数(±1),即验证时检查 [-1, 0, +1] 三个相邻 counter
     */
    const DRIFT_WINDOWS = 1;

    /**
     * 密钥长度(字节),生成后映射到 base32 等长字符
     */
    const SECRET_BYTES = 20;

    /**
     * base32 字母表(RFC 4648)
     */
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * 备用码数量(启用时一次性生成)
     */
    const BACKUP_CODES_COUNT = 8;

    /**
     * 生成新的 base32 共享密钥,明文写入 totp_secret,返回给前端展示二维码。
     *
     * 注意:
     *  - 本方法不会设置 totp_enabled_at;只有 verify 成功并调用 {@see enable()} 后才视为启用。
     *  - 重复调用会覆盖未启用的密钥;启用后调用本方法会重置密钥但保留 totp_enabled_at,
     *    一般用于管理员强制重置流程。
     *
     * 落盘加密由 User 模型的 Encrypted Cast 在 save() 时完成。
     */
    public function generateSecret(User $user): string
    {
        $secret = $this->generateBase32Secret(self::SECRET_BYTES);
        $user->totp_secret = $secret;
        $user->save();

        return $secret;
    }

    /**
     * 构造 otpauth:// URI,前端可直接渲染二维码。
     */
    public function getOtpAuthUri(User $user, string $secret): string
    {
        $issuer = config('app.name', 'Wizard');
        $label = rawurlencode($issuer) . ':' . rawurlencode($user->email ?: $user->name);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=%d',
            $label,
            $secret,
            rawurlencode($issuer),
            self::PERIOD
        );
    }

    /**
     * 校验 code 后正式启用 2FA:生成 8 个备用码并存入加密字段。
     *
     * 返回:
     *   true  - code 校验通过,已启用
     *   false - code 错误或用户尚未调用 {@see generateSecret()}
     *
     * 副作用:成功后 totp_enabled_at = now(),并填充 backup_codes(明文数组,由 Encrypted Cast 加密落盘)。
     *
     * 备用码生成后,调用 {@see getBackupCodes()} 可获取明文列表(用于一次性展示给用户)。
     */
    public function enable(User $user, string $code): bool
    {
        $secret = $user->totp_secret;
        if (!is_string($secret) || $secret === '') {
            return false;
        }

        if (!$this->verifyTotpCode($secret, $code)) {
            return false;
        }

        $backupCodes = $this->generateBackupCodes(self::BACKUP_CODES_COUNT);

        $user->totp_enabled_at = now();
        $user->backup_codes = $backupCodes;
        $user->save();

        return true;
    }

    /**
     * 读取用户剩余备用码(明文列表)。
     *
     * backup_codes 由 Encrypted Cast 自动解密;若解密失败或为空,返回 []。
     */
    public function getBackupCodes(User $user): array
    {
        $codes = $user->backup_codes;
        return is_array($codes) ? $codes : [];
    }

    /**
     * 校验用户输入的 6 位 TOTP code 或备用码。
     *
     *   - 6 位数字 → TOTP 校验,允许 ±1 窗口(±30s)漂移
     *   - 其它格式 → 备用码查找;命中则从数组中移除并持久化(单次使用)
     *
     * 副作用:成功消耗备份码时会更新 backup_codes 字段。
     */
    public function verify(User $user, string $code): bool
    {
        if ($user->totp_enabled_at === null) {
            return false;
        }

        $code = trim($code);
        if ($code === '') {
            return false;
        }

        $secret = $user->totp_secret;
        if (!is_string($secret) || $secret === '') {
            return false;
        }

        // 6 位数字视为 TOTP
        if (preg_match('/^\d{6}$/', $code)) {
            return $this->verifyTotpCode($secret, $code);
        }

        // 否则视为备用码
        return $this->consumeBackupCode($user, $code);
    }

    /**
     * 禁用 2FA:清空密钥/启用时间/备用码。
     */
    public function disable(User $user): void
    {
        $user->totp_secret = null;
        $user->totp_enabled_at = null;
        $user->backup_codes = null;
        $user->save();
    }

    /**
     * TOTP 校验主逻辑:在当前 counter ± DRIFT_WINDOWS 范围内逐一比较。
     */
    private function verifyTotpCode(string $secret, string $code): bool
    {
        $timestamp = Carbon::now()->getTimestamp();
        $baseCounter = intdiv($timestamp, self::PERIOD);

        for ($offset = -self::DRIFT_WINDOWS; $offset <= self::DRIFT_WINDOWS; $offset++) {
            $counter = $baseCounter + $offset;
            $expected = $this->computeCode($secret, $counter);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 给定密钥与 counter 计算 6 位 TOTP code(RFC 6238 HOTP 截断 + mod 10^6)。
     */
    private function computeCode(string $secret, int $counter): string
    {
        $secretBytes = $this->base32Decode($secret);
        // 8-byte big-endian counter
        $counterBytes = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);

        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1] ?? chr(0)) & 0xFF) << 16) |
            ((ord($hash[$offset + 2] ?? chr(0)) & 0xFF) << 8) |
            (ord($hash[$offset + 3] ?? chr(0)) & 0xFF)
        );

        $code = $binary % 1000000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 消耗备用码(命中后从数组中移除并持久化)。
     */
    private function consumeBackupCode(User $user, string $code): bool
    {
        $codes = $this->getBackupCodes($user);
        $key = array_search($code, $codes, true);
        if ($key === false) {
            return false;
        }

        unset($codes[$key]);
        $user->backup_codes = array_values($codes);
        $user->save();

        return true;
    }

    /**
     * 生成 N 个 10 位 base32 备用码(格式 XXXXX-XXXXX,易读)。
     */
    private function generateBackupCodes(int $count): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // 10 字节 = 16 字符 base32,取前 10 字符分两段展示
            $bytes = random_bytes(10);
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= self::BASE32_ALPHABET[ord($bytes[$j]) & 31];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }
        return $codes;
    }

    /**
     * 生成 N 字节随机密钥并以 base32 形式返回。
     */
    private function generateBase32Secret(int $byteLength): string
    {
        $bytes = random_bytes($byteLength);
        $secret = '';
        for ($i = 0; $i < $byteLength; $i++) {
            $secret .= self::BASE32_ALPHABET[ord($bytes[$i]) & 31];
        }
        return $secret;
    }

    /**
     * RFC 4648 base32 解码;忽略 '=' 填充,字符必须严格在字母表内。
     */
    private function base32Decode(string $input): string
    {
        $input = strtoupper(rtrim($input, '='));
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        $length = strlen($input);
        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];
            $value = strpos(self::BASE32_ALPHABET, $char);
            if ($value === false) {
                throw new RuntimeException("Invalid base32 character at position {$i}: {$char}");
            }
            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
                $buffer &= (1 << $bitsLeft) - 1;
            }
        }

        return $output;
    }
}