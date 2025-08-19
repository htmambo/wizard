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
 * 文档评价模型
 *
 * @package App\Repositories
 * @property int $id
 * @property int $page_id 页面 ID
 * @property int $user_id 用户 ID
 * @property int $score_type 评分类型：
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Repositories\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentScore newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentScore newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentScore query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentScore whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentScore whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentScore wherePageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentScore whereScoreType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentScore whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DocumentScore whereUserId($value)
 * @mixin \Eloquent
 */
class DocumentScore extends Model
{
    const SCORE_USEFUL = 1;
    const SCORE_HARD_TO_READ = 2;
    const SCORE_NO_USE = 3;
    const SCORE_GARBAGE = 4;

    protected $table = 'page_score';
    protected $fillable
        = [
            'page_id',
            'user_id',
            'score_type',
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

}