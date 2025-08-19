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
 * Class Comment
 *
 * @package App\Repositories
 * @property int $id
 * @property int $page_id 页面ID
 * @property int $user_id 评论人
 * @property string|null $content 评论内容
 * @property int $reply_to_id 回复的ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read \App\Repositories\Document|null $document
 * @property-read Comment|null $replyComment
 * @property-read \App\Repositories\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment wherePageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment whereReplyToId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Comment withoutTrashed()
 * @mixin \Eloquent
 */
class Comment extends Model
{
    use SoftDeletes;

    protected $table = 'comments';
    protected $fillable
        = [
            'page_id',
            'user_id',
            'content',
            'reply_to_id',
        ];

    public $dates = ['deleted_at'];

    /**
     * 发表评论的用户
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 所属文档ID
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function document()
    {
        return $this->belongsTo(Document::class, 'page_id', 'id');
    }

    /**
     * 当前评论回复的评论
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function replyComment()
    {
        return $this->belongsTo(Comment::class, 'id', 'reply_to_id');
    }

}