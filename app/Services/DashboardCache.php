<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Dashboard 统计缓存管理
 *
 * 集中处理缓存键 'dashboard:stats' 的清理。
 * 由 EventServiceProvider::boot() 注册到 Project/Document/Comment/Group
 * 的 saved/deleted 事件，以及 User 的 updated 事件（业务字段过滤）。
 */
class DashboardCache
{
    public static function flush(): void
    {
        Cache::forget('dashboard:stats');
    }
}