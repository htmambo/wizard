<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * 通用加密 Cast:把任意字段值用 APP_KEY 对称加密落库。
 *
 * 支持 Cast 参数:
 *   - encrypted            → 原样存取(明文出库就明文,密文出库就是密文)
 *   - encrypted:array      → JSON 数组
 *   - encrypted:object     → JSON 对象(等同 array)
 *   - encrypted:collection → Illuminate\Support\Collection
 *
 * 算法:AES-256-CBC + 随机 IV,通过 Laravel Crypt facade 走 APP_KEY 派生。
 *
 * 选型说明:不与 password / remember_token 混用,后者仍由 Hash 承担(单向、
 * 不可逆);本 cast 仅用于需要解密还原原文的 secrets(access_token、share
 * password 等)。
 */
class EncryptedCast implements CastsAttributes
{
    /** @var string 解码方式 */
    private string $mode;

    public function __construct(string $mode = 'string')
    {
        $this->mode = $mode;
    }

    /**
     * 从数据库读取密文并解密。
     *
     * null / 空字符串保持原样,避免 Crypt::decryptString 对 '' 抛 DecryptException。
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($value);
        } catch (\Throwable $e) {
            // 解密失败(APP_KEY 变更、数据迁移错位)→ 返回 null 让上层兜底,
            // 不在读取路径上抛 500。
            return null;
        }

        return $this->decode($decrypted);
    }

    /**
     * 写入时加密。
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $encoded = $this->encode($value);
        return Crypt::encryptString($encoded);
    }

    /**
     * 根据 mode 把密文底层字符串还原成 PHP 数据。
     */
    private function decode(string $raw): mixed
    {
        return match ($this->mode) {
            'array', 'object' => json_decode($raw, true),
            'collection'      => collect(json_decode($raw, true) ?? []),
            default           => $raw,
        };
    }

    /**
     * 写入前的 JSON 序列化(仅 array/object/collection)。
     */
    private function encode(mixed $value): string
    {
        return match ($this->mode) {
            'array', 'object', 'collection' => json_encode(
                $value instanceof \Illuminate\Support\Collection ? $value->all() : $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) ?: '',
            default => (string) $value,
        };
    }
}
