<?php

namespace App\Repositories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Tag
 *
 * @package App\Repositories
 * @property int $id
 * @property string $name 标签名称
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Document> $pages
 * @property-read int|null $pages_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Tag whereUpdatedAt($value)
 * @mixin \Eloquent
 */
class Tag extends Model
{
    protected $table = 'tags';
    protected $fillable = ['name'];

    /**
     * 标签的页面
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function pages()
    {
        return $this->belongsToMany(Document::class, 'page_tag', 'tag_id', 'page_id');
    }
}