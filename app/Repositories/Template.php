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
 * Class Template
 *
 * @package App\Repositories
 * @property int $id
 * @property string $name 模板标题
 * @property string|null $description 模板简述
 * @property string|null $content 文档模板内容
 * @property int|null $user_id 创建用户ID
 * @property int $type 模板类型：1-swagger 2-markdown
 * @property int $status 模板状态: 1-正常；2-禁用
 * @property int $scope 可用范围：1-全局可用；2-个人
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereScope($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Template whereUserId($value)
 * @mixin \Eloquent
 */
class Template extends Model
{
    const TYPE_SWAGGER = 1;
    const TYPE_DOC     = 2;

    const STATUS_NORMAL = 1;
    const STATUS_FORBID = 2;

    const SCOPE_GLOBAL  = 1;
    const SCOPE_PRIVATE = 2;

    protected $table = 'templates';
    protected $fillable
        = [
            'name',
            'description',
            'content',
            'user_id',
            'type',
            'status',
            'scope',
        ];

    /**
     * 查询模板用于显示
     *
     * @param int       $type
     * @param User|null $user
     *
     * @return array
     */
    public static function queryForShow($type, ?User $user = null)
    {
        $templates = self::where('type', $type)
            ->where('status', self::STATUS_NORMAL)
            ->where(function ($query) use ($user) {
                if (!empty($user)) {
                    $query->where('user_id', $user->id);
                }

                $query->orWhere('scope', self::SCOPE_GLOBAL);
            })->get();

        return array_map(function (array $template) {
            return [
                'id'          => $template['id'],
                'name'        => $template['name'],
                'content'     => $template['content'],
                'user_id'     => $template['user_id'],
                'scope'       => $template['scope'],
                'description' => $template['description'],
                'default'     => false,
            ];
        }, $templates->toArray());
    }
}