<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Observers;

use Illuminate\Support\Facades\Cache;

/**
 * 写入 Project / Document / User / Comment 时清空仪表盘统计缓存
 *
 * 通过单例观察者集中处理，避免在 4 个 Model 中重复注册。
 */
class ClearDashboardCache
{
    public static function flush(): void
    {
        Cache::forget('dashboard:stats');
    }
}