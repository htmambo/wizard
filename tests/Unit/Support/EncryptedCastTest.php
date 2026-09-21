<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace Tests\Unit\Support;

use App\Models\Casts\Encrypted;
use App\Models\Casts\EncryptedCast;
use App\Repositories\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * EncryptedCast 端到端测试:
 *   - string 模式加密落库 + 解密读回往返一致
 *   - array 模式加密 JSON 数组并还原
 *   - 解密失败(密文被改)时安全降级为 null
 *   - null / '' 短路:不加密,直接落库
 *   - 与 User 模型 $casts 集成(Encrypted::class / 'encrypted:array')
 */
class EncryptedCastTest extends TestCase
{
    use RefreshDatabase;

    public function test_encrypted_cast_string_round_trip(): void
    {
        $cast = new EncryptedCast('string');
        $model = new User();

        $setValue = $cast->set($model, 'totp_secret', 'JBSWY3DPEHPK3PXP', []);
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $setValue);
        $this->assertNotEmpty($setValue);

        $getValue = $cast->get($model, 'totp_secret', $setValue, []);
        $this->assertSame('JBSWY3DPEHPK3PXP', $getValue);
    }

    public function test_encrypted_cast_array_round_trip(): void
    {
        $cast = new EncryptedCast('array');
        $model = new User();

        $codes = ['abcd-1234', 'efgh-5678'];
        $setValue = $cast->set($model, 'backup_codes', $codes, []);

        $this->assertNotSame(json_encode($codes), $setValue);
        $this->assertIsString($setValue);

        $getValue = $cast->get($model, 'backup_codes', $setValue, []);
        $this->assertSame($codes, $getValue);
    }

    public function test_encrypted_cast_collection_round_trip(): void
    {
        $cast = new EncryptedCast('collection');
        $model = new User();

        $col = collect(['x', 'y', 'z']);
        $setValue = $cast->set($model, 'codes', $col, []);
        $getValue = $cast->get($model, 'codes', $setValue, []);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $getValue);
        $this->assertSame(['x', 'y', 'z'], $getValue->all());
    }

    public function test_encrypted_cast_short_circuits_null(): void
    {
        $cast = new EncryptedCast('string');

        $this->assertNull($cast->set(new User(), 'k', null, []));
        $this->assertNull($cast->set(new User(), 'k', '', []));

        $this->assertNull($cast->get(new User(), 'k', null, []));
        $this->assertNull($cast->get(new User(), 'k', '', []));
    }

    public function test_encrypted_cast_returns_null_on_decrypt_failure(): void
    {
        $cast = new EncryptedCast('string');

        // 把一个非 Crypt 格式的字符串喂给 get(),不应抛 500,而是返回 null
        $this->assertNull($cast->get(new User(), 'k', 'not-a-valid-cipher', []));
    }

    public function test_encrypted_castable_factory_returns_cast_instance(): void
    {
        $cast = Encrypted::castUsing([]);
        $this->assertInstanceOf(EncryptedCast::class, $cast);

        $arrayCast = Encrypted::castUsing(['array']);
        $this->assertInstanceOf(EncryptedCast::class, $arrayCast);
    }

    public function test_user_model_totp_secret_round_trip_via_eloquent(): void
    {
        // totp_secret 不在 $fillable 内,必须用属性赋值(模拟 2FA 服务内部写入路径)
        $user = new User();
        $user->name = 'u_' . uniqid();
        $user->email = uniqid('u_') . '@example.com';
        $user->password = bcrypt('password');
        $user->role = User::ROLE_NORMAL;
        $user->status = User::STATUS_ACTIVATED;
        $user->totp_secret = 'JBSWY3DPEHPK3PXP';
        $user->save();

        // 数据库里存的是密文
        $raw = \DB::table('users')->where('id', $user->id)->value('totp_secret');
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw);
        $this->assertNotEmpty($raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', Crypt::decryptString($raw));

        // Eloquent 读出后自动解密
        $reloaded = User::find($user->id);
        $this->assertSame('JBSWY3DPEHPK3PXP', $reloaded->totp_secret);
    }

    public function test_user_model_backup_codes_round_trip_via_eloquent(): void
    {
        $codes = ['aaaa-bbbb', 'cccc-dddd', 'eeee-ffff'];
        $user = new User();
        $user->name = 'u_' . uniqid();
        $user->email = uniqid('u_') . '@example.com';
        $user->password = bcrypt('password');
        $user->role = User::ROLE_NORMAL;
        $user->status = User::STATUS_ACTIVATED;
        $user->backup_codes = $codes;
        $user->save();

        // 数据落库应是密文(JSON 数组 → 加密 → base64)
        $raw = \DB::table('users')->where('id', $user->id)->value('backup_codes');
        $this->assertNotSame(json_encode($codes), $raw);
        $this->assertNotEmpty($raw);
        $this->assertSame($codes, json_decode(Crypt::decryptString($raw), true));

        $reloaded = User::find($user->id);
        $this->assertSame($codes, $reloaded->backup_codes);
    }
}
