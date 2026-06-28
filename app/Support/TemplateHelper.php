<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use App\Repositories\Template;
use App\Repositories\User;
use Illuminate\Support\Facades\Auth;

/**
 * 文档模板助手(T7)。
 *
 * 替代 helpers.php::wzTemplates(),返回模板数组供前端下拉框使用。
 */
class TemplateHelper
{
    public static function list(int $type = Template::TYPE_DOC, ?User $user = null): array
    {
        if ($user === null && !Auth::guest()) {
            $user = Auth::user();
        }
        return Template::queryForShow($type, $user);
    }
}
