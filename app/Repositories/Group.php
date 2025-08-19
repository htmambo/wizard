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
 * Class Group
 *
 * @package App\Repositories
 * @property int $id
 * @property string $name 组名称
 * @property int|null $user_id 创建者ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Repositories\User|null $creator
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Project> $projects
 * @property-read int|null $projects_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\User> $users
 * @property-read int|null $users_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Group whereUserId($value)
 * @mixin \Eloquent
 */
class Group extends Model
{
    protected $table = 'groups';
    protected $fillable
        = [
            'name',
            'user_id',
        ];

    /**
     * 分组包含的用户
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_group_ref', 'group_id', 'user_id');
    }

    /**
     * 分组创建者
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 分组包含的项目
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function projects()
    {
        return $this->belongsToMany(Project::class, 'project_group_ref', 'group_id', 'project_id')
            ->withPivot('created_at', 'updated_at', 'privilege');
    }
}