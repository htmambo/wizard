<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * 配置/特性开关助手(T7)。
 *
 * 替代 helpers.php::register_enabled(),用户统计等轻量函数。
 */
class ConfigHelper
{
    /**
     * 是否启用注册功能。
     *
     * 静态缓存,避免每次重复读 config。
     */
    public static function registerEnabled(): bool
    {
        static $enabled = null;
        if ($enabled === null) {
            $enabled = (bool) config('wizard.register_enabled');
        }
        return $enabled;
    }

    /**
     * 是否启用评论功能。
     */
    public static function replySupport(): bool
    {
        static $enabled = null;
        if ($enabled === null) {
            $enabled = (bool) config('wizard.reply_support', true);
        }
        return $enabled;
    }

    /**
     * 静态资源版本号(用于 CDN 缓存刷新)。
     */
    public static function resourceVersion(): string
    {
        static $version = null;
        if ($version === null) {
            $version = 'v=' . config('wizard.resource_version');
        }
        return $version;
    }

    /**
     * 用户通知数(>limit 时返回 "limit+")。
     */
    public static function userNotificationCount(int $limit = 0)
    {
        if (Auth::guest()) {
            return 0;
        }
        $unreadCount = count(Auth::user()->unreadNotifications);
        if ($limit > 0) {
            return $unreadCount > $limit ? "{$limit}+" : $unreadCount;
        }
        return $unreadCount;
    }

    /**
     * 用户是否有未读通知。
     */
    public static function userHasNotifications(): bool
    {
        if (Auth::guest()) {
            return false;
        }
        return self::userNotificationCount() > 0;
    }
}
