<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Services;

use App\Events\CommentCreated;
use App\Repositories\Comment;
use App\Repositories\Document;
use App\Repositories\User;
use App\Support\CommentHelper;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * 评论（Comment）域业务服务
 *
 * 职责：
 *  - 评论创建（含 @mention 解析、事件触发，副作用由监听器分发通知与写操作日志）
 *  - 评论更新
 *  - 评论软删除
 *  - 评论分页查询（按文档 / 按用户）
 *
 * 约定：
 *  - 入参已校验，授权由 Controller 调用 Policy 完成
 *  - 不写接口；plain class，Laravel 自动解析依赖
 *  - 领域事件由本服务触发
 *  - XSS 防护依赖 Blade `{{ }}` 的 `e()` 转义；内容预处理仅做 @mention 解析
 */
class CommentService
{
    /**
     * 创建新评论
     *
     * 行为：
     *  - 通过 {@see CommentHelper::filter()} 解析 @username → @{uid:N} 标记
     *  - 持久化到 comments 表（page_id = $document->id）
     *  - 触发 {@see CommentCreated} 事件，监听器负责通知分发与操作日志
     *
     * @param User     $user     评论作者（已通过授权）
     * @param Document $document 所属文档
     * @param string   $content  评论原始内容（已通过长度校验）
     *
     * @return Comment
     */
    public function create(User $user, Document $document, string $content): Comment
    {
        $comment = Comment::create([
            'content'     => CommentHelper::filter($content),
            'user_id'     => $user->id,
            'reply_to_id' => 0,
            'page_id'     => $document->id,
        ]);

        event(new CommentCreated($comment));

        return $comment;
    }

    /**
     * 更新评论内容
     *
     * 行为：
     *  - 重新走 @mention 解析
     *  - 仅在内容发生变化时持久化
     *
     * @param Comment $comment 已存在的评论
     * @param User    $user    当前操作用户（仅用于审计上下文）
     * @param string  $content 新的评论内容（已通过长度校验）
     *
     * @return Comment
     */
    public function update(Comment $comment, User $user, string $content): Comment
    {
        $filtered = CommentHelper::filter($content);

        if ($comment->content !== $filtered) {
            $comment->content = $filtered;
            $comment->save();
        }

        return $comment;
    }

    /**
     * 软删除评论
     *
     * @param Comment $comment 待删除评论
     * @param User    $user    当前操作用户（仅用于审计上下文）
     */
    public function delete(Comment $comment, User $user): void
    {
        $comment->delete();
    }

    /**
     * 文档下的评论分页列表
     *
     * 按 created_at DESC 排序，预加载作者（仅 id/name）。
     */
    public function listForDocument(Document $document, int $perPage = 20): LengthAwarePaginator
    {
        return $document->comments()
            ->with([
                'user' => function ($query) {
                    $query->select('id', 'name');
                },
            ])
            ->orderBy('created_at', 'DESC')
            ->paginate($perPage);
    }

    /**
     * 用户发表过的评论分页列表
     *
     * 按 created_at DESC 排序，预加载所属文档（仅 id/title）。
     */
    public function listByUser(User $user, int $perPage = 20): LengthAwarePaginator
    {
        return Comment::query()
            ->where('user_id', $user->id)
            ->with([
                'document' => function ($query) {
                    $query->select('id', 'title');
                },
            ])
            ->orderBy('created_at', 'DESC')
            ->paginate($perPage);
    }
}