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
 * Class Page
 *
 * @package App\Repositories
 * @property int $id
 * @property int|null $pid 上级页面ID
 * @property string $title 页面标题
 * @property string|null $description 页面描述
 * @property string|null $content 页面内容
 * @property int|null $project_id 项目ID
 * @property int|null $user_id 用户ID
 * @property int $type 页面内容
 * @property int $status 页面状态
 * @property int|null $last_modified_uid 最后修改的用户ID
 * @property int|null $history_id 对应的历史版本ID，用于版本控制
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int $sort_level 项目排序，排序值越大越靠后
 * @property string|null $sync_url 文档同步地址：swagger专用
 * @property string|null $last_sync_at 文档最后同步时间
 * @property string|null $html_code Markdown渲染后的HTML内容
 * @property int $is_blog 是否设置为博客
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Attachment> $attachments
 * @property-read int|null $attachments_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Comment> $comments
 * @property-read int|null $comments_count
 * @property-read \App\Repositories\User|null $lastModifiedUser
 * @property-read Document|null $parentPage
 * @property-read \App\Repositories\Project|null $project
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Document> $subPages
 * @property-read int|null $sub_pages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Repositories\Tag> $tags
 * @property-read int|null $tags_count
 * @property-read \App\Repositories\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereHistoryId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereHtmlCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereIsBlog($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereLastModifiedUid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereLastSyncAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document wherePid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereSortLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereSyncUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Document withoutTrashed()
 * @mixin \Eloquent
 */
class Document extends Model
{

    use SoftDeletes;

    const TYPE_HTML         = 0;     //富文本，HTML文档
    const TYPE_DOC          = 1;     //MD文档
    const TYPE_SWAGGER      = 2;     //SWAGGER文档
    const TYPE_TABLE        = 3;     //表格
    const TYPE_SHEET        = 6;     //LuckySheet表格

    /**
     * 状态：正常
     */
    const STATUS_NORMAL = 1;
    /**
     * 状态：已过时
     */
    const STATUS_OUTDATED = 2;

    protected $table = 'pages';
    protected $fillable
        = [
            'pid',
            'title',
            'description',
            'content',
            'html_code',                //添加一个直接存放渲染过的HTML的字段
            'project_id',
            'user_id',
            'last_modified_uid',
            'history_id',
            'type',
            'status',
            'sort_level',
            'sync_url',
            'last_sync_at',
        ];

    public $dates = ['deleted_at'];
    public $timestamps = true;

    /**
     * 文档恢复
     *
     * @param Document        $document
     * @param DocumentHistory $history
     *
     * @return Document
     */
    public static function recover(Document $document, DocumentHistory $history): Document
    {
        $document->pid               = $history->pid;
        $document->title             = $history->title;
        $document->description       = $history->description;
        $document->content           = $history->content;
        $document->last_modified_uid = $history->operator_id;
        $document->type              = $history->type;
        $document->status            = $history->status;
        $document->sort_level        = $history->sort_level;
        $document->sync_url          = $history->sync_url;
        $document->last_sync_at      = $history->last_sync_at;

        $document->save();

        return $document;
    }

    /**
     * 所属的项目
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'id');
    }

    /**
     * 页面所属的用户
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 最后修改页面的用户ID
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function lastModifiedUser()
    {
        return $this->belongsTo(User::class, 'last_modified_uid', 'id');
    }

    /**
     * 上级页面
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function parentPage()
    {
        return $this->belongsTo(self::class, 'pid', 'id');
    }

    /**
     * 子页面
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subPages()
    {
        return $this->hasMany(self::class, 'pid', 'id');
    }

    /**
     * 文档下的评论
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comments()
    {
        return $this->hasMany(Comment::class, 'page_id', 'id');
    }

    /**
     * 页面下所有的附件
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'page_id', 'id');
    }

    /**
     * 页面的标签
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'page_tag', 'page_id', 'tag_id');
    }

    /**
     * 判断当前文档是否为 Markdown 文档
     *
     * @return bool
     */
    public function isMarkdown()
    {
        return (int)$this->type === self::TYPE_DOC;
    }

    /**
     * 判断当前文档是否为 Swagger 文档
     *
     * @return bool
     */
    public function isSwagger()
    {
        return (int)$this->type === self::TYPE_SWAGGER;
    }

    /**
     * 判断当前文档是否为 Table 文档
     *
     * @return bool
     */
    public function isTable()
    {
        return (int)$this->type === self::TYPE_TABLE;
    }

    public function isSheet()
    {
        return (int)$this->type === self::TYPE_SHEET;
    }

    /**
     * 判断当前文档是否为 Html 文档
     *
     * @return bool
     */
    public function isHtml()
    {
        return (int)$this->type === self::TYPE_HTML;
    }

    public function exists(string $url): bool
    {
        return Document::where('sync_url', $url)->exists();
    }
}