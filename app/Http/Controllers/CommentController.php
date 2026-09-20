<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Controllers;


use App\Policies\ProjectPolicy;
use App\Repositories\Document;
use App\Services\CommentService;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    /**
     * @var CommentService
     */
    protected $commentService;

    public function __construct(CommentService $commentService)
    {
        $this->commentService = $commentService;
    }

    /**
     * 发表评论
     *
     * @param Request $request
     * @param         $id
     * @param         $page_id
     *
     * @return array
     */
    public function publish(Request $request, $id, $page_id)
    {
        $content = $request->input('content');
        $this->validateParameters(
            [
                'project_id' => $id,
                'page_id'    => $page_id,
                'content'    => $content,
            ],
            [
                'project_id' => "required|integer|min:1|in:{$id}|project_exist",
                'page_id'    => "required|integer|min:1|in:{$page_id}|page_exist:{$id}",
                'content'    => 'required|between:1,10000',
            ],
            [
                'content.required' => '评论内容不能为空',
                'content.between'  => '评论内容最大不能超过10000字符',
            ]
        );

        $policy = new ProjectPolicy();
        if (!$policy->view(\Auth::user(), $id)) {
            abort(404);
        }

        $document = Document::findOrFail($page_id);

        $comment = $this->commentService->create(\Auth::user(), $document, $content);

        return [
            'id' => $comment->id
        ];
    }
}