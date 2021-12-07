<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Controllers;


use App\Repositories\Document;
use App\Repositories\PageShare;
use App\Repositories\Project;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class ShareController extends Controller
{

    /**
     * 检查页面是否已经过期
     *
     * @param Request $request
     * @param         $hash
     *
     * @return array
     * @throws \Illuminate\Validation\ValidationException
     */
    public function checkPageExpired(Request $request, $hash)
    {
        /** @var PageShare $share */
        $share = PageShare::where('code', $hash)->firstOrFail();

        $this->validate(
            $request,
            [
                'l' => 'required|date',
            ]
        );

        $lastModifiedAt = Carbon::parse($request->input('l'));

        /** @var Document $pageItem */
        $pageItem = Document::where('id', $share->page_id)->firstOrFail();

        // 检查文档是否已经被别人修改过了，避免修改覆盖
        if (!$pageItem->updated_at->equalTo($lastModifiedAt)) {
            return [
                'message' => __('document.validation.doc_modified_by_user', [
                    'username' => $pageItem->lastModifiedUser->name,
                    'time'     => $pageItem->updated_at
                ]),
                'expired' => true,
            ];
        }

        return [
            'message' => 'ok',
            'expired' => false,
        ];
    }

    /**
     * 分享链接访问
     *
     * @param Request $request
     * @param         $hash
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function page(Request $request, $hash)
    {
        /** @var PageShare $share */
        $share = PageShare::where('code', $hash)->firstOrFail();

        $projectId = $share->project_id;
        $pageId    = $share->page_id;

        /** @var Project $project */
        $project = Project::with([
            'pages' => function (Relation $query) {
                $query->select('id', 'pid', 'title', 'description', 'project_id', 'type', 'status');
            }
        ])->findOrFail($projectId);

        $page = Document::where('project_id', $projectId)->where('id', $pageId)->firstOrFail();
        $type = $page->type == Document::TYPE_DOC ? 'markdown' : 'swagger';
        /**
         * 检查一下当前分享是否需要使用密码打开
         * 如果有权限处理文档的话，就不需要输入密码了
         */
        if ($share->password) {
            try{
                $this->authorize('page-edit', $page);
            }
            catch (\Exception $e) {
                $inppwd = $request->input('password');
                if (!$inppwd) {
                    $inppwd = Cookie::get('share-page-' . $share->id);
                }
                if (!$inppwd || $share->password != $inppwd) {
                    return view('share-password', [
                        'project'  => $project,
                        'pageItem' => $page,
                        'type'     => $type,
                        'code'     => $hash,
                        'noheader' => true,
                    ]);
                } else {
                    Cookie::queue('share-page-' . $share->id, $inppwd, 24*60);
                }
            }
        }
        if($page->isMarkdown()) {
            $page->content = processMarkdown($page->content);
        }
        return view('share-show', [
            'hash'     => $hash,
            'project'  => $project,
            'pageItem' => $page,
            'type'     => $type,
            'code'     => $hash,
            'noheader' => true,
        ]);
    }

    /**
     * 删除分享链接
     *
     * @param Request $request
     * @param $project_id
     * @param $page_id
     * @return array
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function delete(Request $request, $project_id, $page_id)
    {
        $this->validateParameters(
            ['page_id' => $page_id,],
            ['page_id' => "required|page_exist:{$project_id}",]
        );

        $this->authorize('page-share', $page_id);

        PageShare::where('project_id', $project_id)
            ->where('page_id', $page_id)
            ->delete();

        $this->alertSuccess('取消分享成功');
        return [];
    }

    /**
     * 创建分享链接
     *
     * @param Request $request
     * @param         $project_id
     * @param         $page_id
     *
     * @return array
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function create(Request $request, $project_id, $page_id)
    {
        $this->validateParameters(
            ['page_id' => $page_id,],
            ['page_id' => "required|page_exist:{$project_id}",]
        );
        $password = $request->input('password');

        $this->authorize('page-share', $page_id);

        $share = PageShare::where('project_id', $project_id)
            ->where('page_id', $page_id)
            ->where('user_id', \Auth::user()->id)
            ->first();
        if (empty($share)) {
            $code  = sha1("{$project_id}-{$page_id}-" . microtime() . rand(0, 9999999999));
            $share = PageShare::create([
                'code'       => $code,
                'project_id' => $project_id,
                'page_id'    => $page_id,
                'user_id'    => \Auth::user()->id,
                'password'   => (string)$password,
            ]);
        }

        return [
            'code' => $share->code,
            'link' => wzRoute('share:show', ['hash' => $share->code]),
        ];
    }

}