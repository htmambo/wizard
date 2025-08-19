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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Attachment
 *
 * @package App\Repositories
 * @property int $id
 * @property string $name 附件名称
 * @property string $path 存储路径
 * @property int $user_id 上传人ID
 * @property int|null $page_id 附件对应的文档ID
 * @property int|null $project_id 附件对应的项目ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Repositories\Document|null $page
 * @property-read \App\Repositories\Project|null $project
 * @property-read \App\Repositories\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment wherePageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment wherePath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Attachment withoutTrashed()
 * @mixin \Eloquent
 */
class Attachment extends Model
{
    use SoftDeletes;

    protected $table = 'attachments';
    protected $fillable
        = [
            'name',
            'path',
            'page_id',
            'project_id',
            'user_id'
        ];

    public $dates = ['deleted_at'];

    /**
     * 附件所属的文档
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function page()
    {
        return $this->belongsTo(Document::class, 'page_id', 'id');
    }

    /**
     * 附件所属的用户
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 附件所属的项目
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }
}