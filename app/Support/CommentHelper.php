<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use App\Repositories\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * 评论相关助手(T7)。
 *
 * 替代 helpers.php 中 comment_filter* 系列。
 */
class CommentHelper
{
    /**
     * 从内容中解析出 @uid:N 标记对应的用户列表。
     */
    public static function filterUsers(string $content): ?SupportCollection
    {
        preg_match_all('/@{uid:(\d+)}/', $content, $matches);
        if (!empty($matches[1])) {
            return User::whereIn('id', $matches[1])->select('id', 'name', 'email')->get();
        }
        return null;
    }

    /**
     * 预处理评论内容:把 @用户名 替换为 @{uid:N} 标记(用于后续高亮)。
     */
    public static function filter(string $comment): string
    {
        $matchRegexp = '/@(.*?)(?:\s|$)/';
        $users = (function ($content) use ($matchRegexp) {
            preg_match_all($matchRegexp, $content, $matches);
            if (!empty($matches[1])) {
                return User::whereIn('name', $matches[1])->select('id', 'name', 'email')->get();
            }
            return null;
        })($comment);

        if ($users === null || $users->isEmpty()) {
            return $comment;
        }

        return preg_replace_callback(
            $matchRegexp,
            function ($matches) use ($users) {
                if (count($matches) < 2) {
                    return $matches[0];
                }
                $user = $users->firstWhere('name', '=', $matches[1]);
                if (!empty($user)) {
                    return "@{uid:{$user->id}} ";
                }
                return $matches[0];
            },
            $comment
        );
    }

    /**
     * 返回 UI 用户名列表(用于 at.js 自动补全)。
     *
     * 返回 JS 数组字面量字符串:['user1','user2',...]
     *
     * T7 审核改进:接受 Eloquent|Support Collection(原签名只支持 Eloquent,导致调用方传 Support Collection 时 TypeError)
     * 注:T2 审核后建议改用 json_encode + HEX,但这是另一个 PR 范围,此处保持原行为。
     *
     * @param  \Illuminate\Support\Collection|Collection  $users
     */
    public static function uiUsernames($users, bool $actived = true): string
    {
        if ($users instanceof Collection) {
            // Eloquent Collection,类型已知为 User
            return $users->filter(
                fn (User $user) => $actived ? $user->isActivated() : true
            )->map(
                fn (User $user) => "'{$user->name}'"
            )->implode(',');
        }
        // Support Collection / 任何 Collection — 每个 item 假设为 User
        return $users->filter(
            fn ($user) => $actived ? $user->isActivated() : true
        )->map(
            fn ($user) => "'{$user->name}'"
        )->implode(',');
    }
}
