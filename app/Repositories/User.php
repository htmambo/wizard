<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Repositories;

use Laravel\Passport\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Lab404\Impersonate\Models\Impersonate;
use Lab404\Impersonate\Services\ImpersonateManager;

/**
 * Class User
 *
 * @package App\Repositories
 * @property int $id
 * @property string|null $objectguid
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $role 用户角色：1-普通用户，2-管理员
 * @property int|null $status 用户状态：0-未激活，1-已激活，2-已禁用
 * @property string|null $sub_domain 用户自定义子域名
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Project> $favoriteProjects
 * @property-read int|null $favorite_projects_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Group> $groups
 * @property-read int|null $groups_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\DocumentHistory> $histories
 * @property-read int|null $histories_count
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Document> $pages
 * @property-read int|null $pages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Project> $projects
 * @property-read int|null $projects_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereObjectguid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSubDomain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable, Impersonate;

    /**
     * 普通用户
     */
    const ROLE_NORMAL = 1;
    /**
     * 管理员用户
     */
    const ROLE_ADMIN = 2;

    const STATUS_NONE = 0;
    const STATUS_ACTIVATED = 1;
    const STATUS_DISABLED = 2;

    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable
        = [
            'name',
            'email',
            'password',
            'role',
            'status',
            'objectguid',
        ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden
        = [
            'password',
            'remember_token',
        ];

    /**
     * 用户拥有的项目
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    /**
     * 用户拥有的页面
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function pages()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * 用户所属的分组
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'user_group_ref', 'user_id', 'group_id');
    }

    /**
     * 用户关注的项目
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function favoriteProjects()
    {
        return $this->belongsToMany(Project::class, 'project_stars', 'user_id', 'project_id');
    }

    /**
     * 用户编辑过的历史页面
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function histories()
    {
        return $this->hasMany(DocumentHistory::class, 'operator_id', 'id');
    }

    /**
     * 判断当前用户是否为管理员
     *
     * @return bool
     */
    public function isAdmin()
    {
        return (int)$this->role === self::ROLE_ADMIN;
    }

    /**
     * 是否用户已激活
     *
     * @return bool
     */
    public function isActivated()
    {
        return (int)$this->status === self::STATUS_ACTIVATED;
    }

    /**
     * 是否用户已经禁用
     *
     * @return bool
     */
    public function isDisabled()
    {
        return (int)$this->status === self::STATUS_DISABLED;
    }

    /**
     * 用户是否可以扮演其它用户
     *
     * @return bool
     */
    public function canImpersonate()
    {
        return $this->isAdmin();
    }

    /**
     * 用户是否可以被扮演
     *
     * @return bool
     */
    public function canBeImpersonated()
    {
        return !$this->isAdmin();
    }

    /**
     * 查询扮演者信息
     *
     * @return User
     */
    public function impersonator()
    {
        return app(ImpersonateManager::class)->getImpersonator();
    }
}
