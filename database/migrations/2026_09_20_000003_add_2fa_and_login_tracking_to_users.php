<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 给 users 表增加两步验证（TOTP）与登录失败计数字段。
 *
 * 字段设计:
 *   - totp_secret           TOTP 共享密钥,Crypt::encryptString 加密后的 base64 字符串。
 *   - totp_enabled_at       启用 2FA 的时间;NULL 表示未启用。
 *   - login_fail_count      滑窗内累计失败次数(MAX_ATTEMPTS=10 触发锁定)。
 *   - login_fail_first_at   滑窗起点,登录成功或超过 1h 自动重置。
 *   - login_locked_until    账号级锁定的到期时间,>now() 时拒绝登录。
 *   - backup_codes          备用码(JSON 数组,Crypt::encryptString 加密),单次使用后从数组中移除。
 *
 * 索引:
 *   - wz_users_login_locked_until_idx —— 后台清理/监控任务扫描锁定账户列表。
 *
 * 与 web-login rate limit(5/min/用户+IP,Cache 存储)正交:
 *   rate limit 防"单 IP + 任意用户名"洪水;
 *   login_locked_until 防"分布式 IP + 单用户名"目标爆破。
 */
class Add2faAndLoginTrackingToUsers extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('totp_secret', 255)->nullable()->after('remember_token')
                ->comment('TOTP 共享密钥(Crypt::encryptString 加密)');
            $table->timestamp('totp_enabled_at')->nullable()->after('totp_secret')
                ->comment('2FA 启用时间,NULL 表示未启用');

            $table->unsignedInteger('login_fail_count')->default(0)->after('totp_enabled_at')
                ->comment('滑窗内登录失败计数');
            $table->timestamp('login_fail_first_at')->nullable()->after('login_fail_count')
                ->comment('滑窗起点,首次失败时间');
            $table->timestamp('login_locked_until')->nullable()->after('login_fail_first_at')
                ->comment('账号锁定到期时间');

            $table->text('backup_codes')->nullable()->after('login_locked_until')
                ->comment('备用码(Crypt::encryptString 加密的 JSON 数组),单次使用');

            $table->index('login_locked_until', 'wz_users_login_locked_until_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('wz_users_login_locked_until_idx');
            $table->dropColumn([
                'totp_secret',
                'totp_enabled_at',
                'login_fail_count',
                'login_fail_first_at',
                'login_locked_until',
                'backup_codes',
            ]);
        });
    }
}