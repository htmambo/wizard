<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class PageShare
 *
 * @package App\Repositories
 * @property int $id
 * @property string $code 分享标识
 * @property int $project_id 项目ID
 * @property int $page_id 页面ID
 * @property int $user_id 分享人ID
 * @property string|null $expired_at 过期时间
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property string $password 是否需要使用密码打开
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereExpiredAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare wherePageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageShare whereUserId($value)
 * @mixin \Eloquent
 */
class PageShare extends Model
{
    protected $table = 'page_share';
    protected $fillable
        = [
            'code',
            'project_id',
            'page_id',
            'user_id',
            'expired_at',
            'password',
        ];
}