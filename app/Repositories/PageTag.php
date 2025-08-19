<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;

/**
 * Class Tag
 *
 * @package App\Repositories
 * @property int $id
 * @property int $page_id 页面 ID
 * @property int $tag_id 标签 ID
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageTag newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageTag newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageTag query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageTag whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageTag wherePageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PageTag whereTagId($value)
 * @mixin \Eloquent
 */
class PageTag extends Model
{
    protected $table = 'page_tag';
    public $timestamps = false;
    protected $fillable = ['page_id', 'tag_id'];
}