<?php

namespace App\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Widget
 *
 * @package App\Repositories
 * @property int $id
 * @property string|null $name 控件标题
 * @property string $ref_id UUID，用于唯一标识控件
 * @property int $type 控件类型: 1-思维导图
 * @property string|null $description 控件描述
 * @property string|null $content 空间内容
 * @property int|null $user_id 用户ID
 * @property int|null $operator_id 操作用户ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereOperatorId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereRefId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Widget withoutTrashed()
 * @mixin \Eloquent
 */
class Widget extends Model
{
    use SoftDeletes;

    const TYPE_MIND_MAPPING = 1;
    const TYPE_MX_GRAPH = 2;

    protected $table = 'widgets';
    protected $fillable
        = [
            'name',
            'ref_id',
            'type',
            'description',
            'content',
            'user_id',
            'operator_id',
        ];

    public $dates = ['deleted_at'];

}