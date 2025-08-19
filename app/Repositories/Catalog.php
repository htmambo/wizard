<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;

/**
 * 项目目录
 *
 * @package App\Repositories
 * @property int $id
 * @property string $name 项目目录名称
 * @property int $sort_level 排序，排序值越大越靠后
 * @property int $user_id 创建用户ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $show_in_home 是否在首页展示
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Project> $projects
 * @property-read int|null $projects_count
 * @property-read \App\Repositories\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog whereShowInHome($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog whereSortLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Catalog whereUserId($value)
 * @mixin \Eloquent
 */
class Catalog extends Model
{
    /**
     * 在首页展示
     */
    const SHOW_IN_HOME     = 1;
    /**
     * 不在首页展示，只能通过搜索功能搜索内部文档，不再保留公共入口
     */
    const NOT_SHOW_IN_HOME = 0;

    protected $table = 'project_catalogs';
    protected $fillable = [
        'name',
        'sort_level',
        'user_id',
        'show_in_home',
    ];

    /**
     * 所属的用户
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 目录下包含的项目
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}