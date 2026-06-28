<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use App\Repositories\Catalog;
use App\Repositories\Document;
use App\Repositories\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * 用户/项目数据查询助手(T7)。
 *
 * 替代 helpers.php 中 users() / allCatalogs() / subDocuments() / impersonateUser()。
 */
class UserHelper
{
    /**
     * 获取所有用户列表(进程级静态缓存,新版建议用 Cache 替代)。
     */
    public static function users(): Collection
    {
        static $users = null;
        if ($users === null) {
            $users = User::all();
        }
        return $users;
    }

    /**
     * 获取所有目录(进程级静态缓存)。
     */
    public static function allCatalogs(): Collection
    {
        static $catalogs = null;
        if ($catalogs === null) {
            $catalogs = Catalog::all();
        }
        return $catalogs;
    }

    /**
     * 获取某页面的子文档列表。
     */
    public static function subDocuments($pid): Collection
    {
        return Document::where('pid', $pid)->select('id', 'title')->get();
    }

    /**
     * 返回当前被扮演用户的基本信息(若有)。
     *
     * @return array{id: int, name: string}|null
     */
    public static function impersonateUser(): ?array
    {
        /** @var User $user */
        $user = Auth::user();
        if (!$user->isImpersonated()) {
            return null;
        }
        $impersonateUser = $user->impersonator();
        return ['id' => $impersonateUser->id, 'name' => $impersonateUser->name];
    }
}
