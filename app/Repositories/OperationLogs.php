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
 * App\Repositories\OperationLogs
 *
 * @property int $id
 * @property int|null $user_id 操作用户ID
 * @property string|null $message 日志消息内容
 * @property string|null $context 记录日志时候的上下文信息
 * @property string $created_at 创建时间
 * @property int|null $project_id 关联的项目ID
 * @property int|null $page_id 关联的文档ID
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs whereMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs wherePageId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs whereProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OperationLogs whereUserId($value)
 * @mixin \Eloquent
 */
class OperationLogs extends Model
{
    protected $table = 'operation_logs';
    protected $fillable
        = [
            'user_id',
            'message',
            'context',
            'project_id',
            'page_id',
            'created_at',
        ];

    public $timestamps = false;

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            $model->created_at = $model->freshTimestamp();
        });
    }

    /**
     * 记录业务日志
     *
     * @param integer $user_id
     * @param string $message
     * @param array $context
     * @param array $impersonateUser
     *
     * @return $this|\Illuminate\Database\Eloquent\Model
     */
    public static function log($user_id, string $message, array $context = [], $impersonateUser = [])
    {
        if (!empty($impersonateUser)) {
            $context['impersonate'] = $impersonateUser;
        }

        $data = [
            'user_id' => $user_id,
            'message' => $message,
            'context' => $context,
        ];

        if (isset($context['project_id'])) {
            $data['project_id'] = $context['project_id'];
        }

        if (isset($context['page_id'])) {
            $data['page_id'] = $context['page_id'];
        } else if (isset($context['doc_id'])) {
            $data['page_id'] = $context['doc_id'];
        }

        return self::create($data);
    }

    /**
     * 记录业务日志
     *
     * @param        $user_id
     * @param array $context
     * @param string $message
     * @param array ...$args
     *
     * @return OperationLogs|\Illuminate\Database\Eloquent\Model
     */
    public static function logf($user_id, array $context, string $message, ...$args)
    {
        return self::log($user_id, sprintf($message, ...$args), $context);
    }

    public function setContextAttribute($value)
    {
        $this->attributes['context'] = json_encode($value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function getContextAttribute($value)
    {
        if (!is_string($value)) {
            return $value;
        }
        return json_decode($value);
    }
}