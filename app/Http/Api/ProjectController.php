<?php
namespace App\Http\Api;

use App\Http\Controllers\ApiController;
use App\Events\DocumentCreated;
use App\Events\DocumentDeleted;
use App\Events\DocumentMarkModified;
use App\Events\DocumentModified;
use App\Policies\ProjectPolicy;
use App\Repositories\Document;
use App\Repositories\DocumentHistory;
use App\Repositories\DocumentScore;
use App\Repositories\PageShare;
use App\Repositories\Project;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;
use SoapBox\Formatter\Formatter;

class ProjectController extends ApiController
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * 获取项目文档
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function documents(Request $request, $id)
    {
        $perPage = $request->input('per_page', 20);
        $project = Project::find($id);
        if (empty($project)) {
            return $this->error('Project not found', 404);
        }

        // 检查用户权限
        if (!Auth::user()->can('project-view', $project)) {
            return $this->error('Unauthorized', 403);
        }

        // 获取项目文档
        $result = Document::where('project_id', $id)
            ->orderBy('sort_level', 'ASC')
            ->with(['user' => function ($query) {
                $query->select('id', 'name');
            }, 'project' => function ($query) {
                $query->select('id', 'name');
            }])
            ->select(['id', 'title', 'description', 'project_id', 'user_id'])
            ->paginate($perPage);
        $documents = $result->items();
        $meta = [
            'total'        => $result->total(),
            'per_page'     => $result->perPage(),
            'current_page' => $result->currentPage(),
            'last_page'    => $result->lastPage(),
        ];
        return $this->success($documents, 'Documents retrieved successfully', $meta);
    }
    public function all(Request $request)
    {
        $projectModel = Project::query();
        $user = \Auth::user();
        $userGroups = empty($user) ? null : $user->groups->pluck('id')->toArray();
        $projectModel->where(function ($query) use ($user, $userGroups) {
            $query->where('visibility', Project::VISIBILITY_PUBLIC);
            if (!empty($userGroups)) {
                $query->orWhere(function ($query) use ($userGroups) {
                    $query->where('visibility', '!=', Project::VISIBILITY_PUBLIC)
                          ->whereHas('groups', function ($query) use ($userGroups) {
                              $query->where('wz_groups.id', $userGroups);
                          });
                })->orWhere('user_id', $user->id);
            }
        });
        $projects = $projectModel->select(['id', 'name'])->orderBy('sort_level', 'ASC')->get();
        return $this->success($projects);
    }
}