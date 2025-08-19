<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * App\Repositories\Project
 *
 * @property int $id
 * @property string $name 项目名称
 * @property string|null $description 项目描述
 * @property int $visibility 可见性
 * @property int $user_id 创建用户ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property int $sort_level 项目排序，排序值越大越靠后
 * @property int|null $catalog_id 目录ID
 * @property int $catalog_fold_style 目录展开样式：0-自动 1-全部展开 2-全部折叠
 * @property int $catalog_sort_style 目录排序样式：0-目录优先 1-自由排序
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Attachment> $attachments
 * @property-read int|null $attachments_count
 * @property-read \App\Repositories\Catalog|null $catalog
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\User> $favoriteUsers
 * @property-read int|null $favorite_users_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Group> $groups
 * @property-read int|null $groups_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Document> $pages
 * @property-read int|null $pages_count
 * @property-read \App\Repositories\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereCatalogFoldStyle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereCatalogId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereCatalogSortStyle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereSortLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project whereVisibility($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Project withoutTrashed()
 * @mixin \Eloquent
 */
class Project extends Model
{

    use SoftDeletes;

    /**
     * 公开项目
     */
    const VISIBILITY_PUBLIC = '1';
    /**
     * 私有项目
     */
    const VISIBILITY_PRIVATE = '2';

    /**
     * 读写
     */
    const PRIVILEGE_WR = 1;
    /**
     * 只读
     */
    const PRIVILEGE_RO = 2;

    /**
     * 目录折叠样式：自动
     */
    const FOLD_STYLE_AUTO = 0;
    /**
     * 目录折叠样式：全部展开
     */
    const FOLD_STYLE_UNFOLD = 1;
    /**
     * 目录折叠样式：全部折叠
     */
    const FOLD_STYLE_FOLD = 2;
    /**
     * 排序样式：文件夹优先
     */
    const SORT_STYLE_DIR_FIRST = 0;
    /**
     * 排序样式：自由排序
     */
    const SORT_STYLE_FREE = 1;

    protected $table = 'projects';
    protected $fillable
        = [
            'name',
            'description',
            'visibility',
            'user_id',
            'sort_level',
            'catalog_id',
            'catalog_fold_style',
            'catalog_sort_style',
        ];

    public $dates = ['deleted_at'];

    /**
     * 项目下的所有页面
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function pages()
    {
        return $this->hasMany(Document::class);
    }

    /**
     * 项目所属的用户
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 项目所属的分组
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function groups()
    {
        return $this->belongsToMany(Group::class, 'project_group_ref', 'project_id', 'group_id')
                    ->withPivot('created_at', 'updated_at', 'privilege');
    }

    /**
     * 关注该项目的用户
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function favoriteUsers()
    {
        return $this->belongsToMany(User::class, 'project_stars', 'project_id', 'user_id');
    }

    /**
     * 项目下所有的附件
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'project_id', 'id');
    }

    /**
     * 项目所属的目录
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function catalog()
    {
        return $this->belongsTo(Catalog::class, 'catalog_id', 'id');
    }

    /**
     * 判断是否用户关注了该项目
     *
     * @param User $user
     *
     * @return bool
     */
    public function isFavoriteByUser(?User $user = null)
    {
        if (empty ($user)) {
            return false;
        }

        return $this->favoriteUsers()->wherePivot('user_id', $user->id)->exists();
    }
}