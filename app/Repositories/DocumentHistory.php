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
 * Class DocumentHistory
 *
 * @package App\Repositories
 * @property int $id
 * @property int $page_id 文档ID
 * @property int|null $pid 上级页面ID
 * @property string|null $title 页面标题
 * @property string|null $description 页面描述
 * @property string|null $content 页面内容
 * @property int|null $project_id 项目ID
 * @property int|null $type 页面内容
 * @property int|null $status 页面状态
 * @property int|null $user_id 用户ID
 * @property int|null $operator_id 操作用户ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $sort_level 项目排序，排序值越大越靠后
 * @property string|null $sync_url 文档同步地址：swagger专用
 * @property string|null $last_sync_at 文档最后同步时间
 * @property-read \App\Repositories\User|null $operator
 * @property-read \App\Repositories\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereLastSyncAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereOperatorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory wherePageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory wherePid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereSortLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereSyncUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentHistory whereUserId($value)
 * @mixin \Eloquent
 */
class DocumentHistory extends Model
{
    protected $table = 'page_histories';
    protected $fillable
        = [
            'page_id',
            'pid',
            'title',
            'description',
            'content',
            'project_id',
            'user_id',
            'type',
            'status',
            'operator_id',
            'sort_level',
            'sync_url',
            'last_sync_at',
        ];

    /**
     * 记录文档历史
     *
     * @param Document $document
     *
     * @return DocumentHistory
     */
    public static function write(Document $document): DocumentHistory
    {
        $history = self::create(array_only(
                $document->toArray(),
                (new static)->fillable) + [
                'operator_id' => $document->last_modified_uid,
                'page_id'     => $document->id,
            ]
        );

        $document->history_id = $history->id;
        $document->save();

        return $history;
    }

    /**
     * 文档所属用户
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 记录操作用户
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id', 'id');
    }
}