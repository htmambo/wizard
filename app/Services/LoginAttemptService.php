<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Services;

use App\Notifications\AccountLockedNotification;
use App\Repositories\User;
use Carbon\Carbon;

/**
 * 登录失败计数与账号锁定服务。
 *
 * 与 web-login rate limit(5/min/用户名+IP,Cache 存储)正交:
 *   - rate limit 防"单 IP + 任意用户名"洪水攻击
 *   - 本服务防"分布式 IP + 单用户名"目标账号爆破
 *
 * 策略(可在子类或配置中扩展):
 *   - 1 小时滑窗,失败 N 次触发锁定
 *   - 锁定后 1 小时拒绝登录,无论密码是否正确
 *   - 任意一次成功登录即清零计数
 *
 * 约定:
 *  - plain class,Laravel 自动解析依赖
 *  - 不写接口
 *  - 入参已校验(已存在的 User)
 */
class LoginAttemptService
{
    /**
     * 阈值:1 小时滑窗内累计失败达到此次数后锁定账号
     */
    const MAX_ATTEMPTS = 10;

    /**
     * 滑窗长度(秒):失败首次发生在 1 小时之前的将清零计数
     */
    const WINDOW_SECONDS = 3600;

    /**
     * 锁定时长(秒)
     */
    const LOCK_SECONDS = 3600;

    /**
     * 记录一次登录失败。
     *
     *   1. 若 login_fail_first_at 为空或早于 (now - WINDOW_SECONDS),
     *      视为新窗口:count 重置为 1,first_at 重置为 now。
     *   2. 否则 count += 1。
     *   3. 当 count 达到 MAX_ATTEMPTS 时:
     *      - 设置 login_locked_until = now + LOCK_SECONDS
     *      - 发送 AccountLockedNotification(mail + database)
     *
     * 注意:若当前已处于锁定状态(isLocked == true),不再延长锁定或重新发通知,
     * 仅持久化最新计数(便于事后审计)。
     */
    public function recordFailure(User $user): void
    {
        $now = Carbon::now();
        $firstAt = $this->toCarbon($user->login_fail_first_at);

        $windowStart = $now->copy()->subSeconds(self::WINDOW_SECONDS);

        if ($firstAt === null || $firstAt->lessThan($windowStart)) {
            $user->login_fail_count = 1;
            $user->login_fail_first_at = $now;
        } else {
            $user->login_fail_count = ($user->login_fail_count ?? 0) + 1;
        }

        // 已锁定时不延长或重复通知,但仍持久化最新计数
        $lockedUntil = $this->toCarbon($user->login_locked_until);
        $alreadyLocked = $lockedUntil !== null && $lockedUntil->greaterThan($now);

        if (!$alreadyLocked && $user->login_fail_count >= self::MAX_ATTEMPTS) {
            $user->login_locked_until = $now->copy()->addSeconds(self::LOCK_SECONDS);
            $user->notify(new AccountLockedNotification($user, $user->login_locked_until));
        }

        $user->save();
    }

    /**
     * 登录成功:清零所有失败计数与锁定状态。
     */
    public function recordSuccess(User $user): void
    {
        $user->login_fail_count = 0;
        $user->login_fail_first_at = null;
        $user->login_locked_until = null;
        $user->save();
    }

    /**
     * 是否当前处于锁定状态(login_locked_until > now)。
     *
     * 兼容:User 模型未将 login_locked_until 加入 $casts,refresh() 后的值为 string,
     * 此处统一解析为 Carbon 比较。
     */
    public function isLocked(User $user): bool
    {
        $lockedUntil = $this->toCarbon($user->login_locked_until);
        if ($lockedUntil === null) {
            return false;
        }
        return $lockedUntil->greaterThan(Carbon::now());
    }

    /**
     * 返回当前剩余可尝试次数(未锁定时返回阈值 MAX_ATTEMPTS)。
     */
    public function remainingAttempts(User $user): int
    {
        $count = (int) ($user->login_fail_count ?? 0);
        return max(0, self::MAX_ATTEMPTS - $count);
    }

    /**
     * 当前阈值(用于展示/对外接口)。
     */
    public function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /**
     * 将可能为 string/Carbon/null 的时间字段归一为 Carbon。
     */
    private function toCarbon($value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }
}