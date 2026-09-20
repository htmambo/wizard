<?php

namespace App\Http\Controllers\Api;

use App\Events\DocumentCreated;
use App\Events\DocumentDeleted;
use App\Events\DocumentMarkModified;
use App\Events\DocumentModified;
use App\Events\ProjectCreated;
use App\Events\ProjectDeleted;
use App\Events\ProjectModified;
use App\Policies\ProjectPolicy;
use App\Repositories\Document;
use App\Repositories\DocumentHistory;
use App\Repositories\DocumentScore;
use App\Repositories\Group as GroupModel;
use App\Repositories\OperationLogs;
use App\Repositories\PageShare;
use App\Repositories\Project;
use Carbon\Carbon;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;
use SoapBox\Formatter\Formatter;

#[Group('项目相关', '项目相关API', 3)]
class ProjectController extends Controller
{

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

    /**
     * 获取项目列表
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function lists(Request $request, $format = 'json')
    {
        $projectModel = Project::query();
        $user = Auth::user();
        $userGroupIds = empty($user) ? [] : $user->groups->pluck('id')->all();
        $projectModel->where(function ($query) use ($user, $userGroupIds) {
            $query->where('visibility', Project::VISIBILITY_PUBLIC)->orWhere('user_id', $user->id);
            if (!empty($userGroupIds)) {
                $query->orWhere(function ($query) use ($userGroupIds) {
                    $query->where('visibility', '!=', Project::VISIBILITY_PUBLIC)
                        ->whereHas('groups', function ($query) use ($userGroupIds) {
                            $query->whereIn('groups.id', $userGroupIds);
                        });
                });
            }
        });
        $projects = $projectModel->select(['id', 'name', 'catalog_id', 'sort_level'])->orderBy('catalog_id', 'ASC')->orderBy('sort_level', 'ASC')->orderBy('id', 'ASC')->get();
        return $this->success($projects, 'Projects retrieved successfully', [
            'usergroups' => $userGroupIds,
            'user' => $user ? $user->only(['id', 'name']) : null
        ]);
    }

    /**
     * 获取项目详情
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function view(Request $request, $id)
    {
        $project = Project::with(['catalog', 'user'])->find($id);
        if (empty($project)) {
            return $this->error('Project not found', 404);
        }

        // 检查用户权限
        if (!Auth::user()->can('project-view', $project)) {
            return $this->error('Unauthorized', 403);
        }

        return $this->success([
            'id'                => $project->id,
            'name'              => $project->name,
            'description'       => $project->description,
            'visibility'        => $project->visibility,
            'sort_level'        => $project->sort_level,
            'catalog_id'        => $project->catalog_id,
            'catalog'           => $project->catalog ? $project->catalog->only(['id', 'name']) : null,
            'user'              => $project->user ? $project->user->only(['id', 'name']) : null,
            'is_favorited'      => $project->isFavoriteByUser(Auth::user()),
            'created_at'        => $project->created_at,
            'updated_at'        => $project->updated_at,
        ], 'Project retrieved successfully');
    }

    /**
     * 创建新项目
     *
     * @param Request $request
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(Request $request)
    {
        // 检查用户是否有创建项目的权限
        if (!Auth::user()->can('project-create')) {
            return $this->error('Unauthorized', 403);
        }

        $this->validate(
            $request,
            [
                'name'        => 'required|between:1,100',
                'description' => 'max:255',
                'visibility'  => 'required|in:1,2',
                'sort_level'  => 'integer|between:-9999999999,999999999',
                'catalog'     => 'required|integer',
            ],
            [
                'name.required'   => __('project.validation.project_name_required'),
                'name.between'    => __('project.validation.project_name_between'),
                'description.max' => __('project.validation.project_description_max'),
            ]
        );

        if (Auth::user()->can('project-sort')) {
            $sortLevel = $request->input('sort_level', 1000);
        } else {
            $sortLevel = 1000;
        }

        $project = Project::create([
            'name'        => $request->input('name'),
            'description' => $request->input('description'),
            'user_id'     => Auth::user()->id,
            'visibility'  => $request->input('visibility'),
            'sort_level'  => (int)$sortLevel,
            'catalog_id'  => $request->input('catalog'),
        ]);

        // 触发项目创建事件（记录操作日志、通知管理员）
        event(new ProjectCreated($project));

        return $this->success([
            'id'          => $project->id,
            'name'        => $project->name,
            'description' => $project->description,
            'visibility'  => $project->visibility,
            'sort_level'  => $project->sort_level,
            'catalog_id'  => $project->catalog_id,
        ], 'Project created successfully');
    }

    /**
     * 更新项目基本信息
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, $id)
    {
        $project = Project::find($id);
        if (empty($project)) {
            return $this->error('Project not found', 404);
        }

        // 检查用户权限
        if (!Auth::user()->can('project-edit', $project)) {
            return $this->error('Unauthorized', 403);
        }

        $this->validate(
            $request,
            [
                'name'               => 'required|between:1,100',
                'description'        => 'max:255',
                'visibility'         => 'required|in:1,2',
                'sort_level'         => 'integer|between:-999999999,999999999',
                'catalog'            => 'required|integer',
                'catalog_sort_style' => 'in:0,1',
                'catalog_fold_style' => 'in:0,1,2',
            ],
            [
                'name.required'   => __('project.validation.project_name_required'),
                'name.between'    => __('project.validation.project_name_between'),
                'description.max' => __('project.validation.project_description_max'),
            ]
        );

        $project->name = $request->input('name');
        $project->description = $request->input('description');
        $project->visibility = $request->input('visibility');
        $project->catalog_id = $request->input('catalog');
        $project->catalog_sort_style = $request->input('catalog_sort_style', Project::SORT_STYLE_DIR_FIRST);
        $project->catalog_fold_style = $request->input('catalog_fold_style', Project::FOLD_STYLE_AUTO);
        if (Auth::user()->can('project-sort') && $request->input('sort_level') != null) {
            $project->sort_level = (int)$request->input('sort_level');
        }

        if ($project->isDirty()) {
            $project->save();

            // 触发项目基本信息更新事件（记录操作日志）
            event(new ProjectModified($project, 'basic'));
        }

        return $this->success($project->fresh()->toArray(), 'Project updated successfully');
    }

    /**
     * 删除项目
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function delete(Request $request, $id)
    {
        $project = Project::find($id);
        if (empty($project)) {
            return $this->error('Project not found', 404);
        }

        // 检查用户权限
        if (!Auth::user()->can('project-delete', $project)) {
            return $this->error('Unauthorized', 403);
        }

        $project->delete();

        // 触发项目删除事件（记录操作日志、清理搜索索引）
        event(new ProjectDeleted($project));

        return $this->success(null, 'Project deleted successfully');
    }

    /**
     * 获取项目成员列表（项目关联的用户组及其权限）
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function members(Request $request, $id)
    {
        $project = Project::find($id);
        if (empty($project)) {
            return $this->error('Project not found', 404);
        }

        // 检查用户权限
        if (!Auth::user()->can('project-view', $project)) {
            return $this->error('Unauthorized', 403);
        }

        $members = $project->groups()
            ->select('groups.id', 'groups.name', 'groups.user_id')
            ->get()
            ->map(function (GroupModel $group) {
                return [
                    'id'         => $group->id,
                    'name'       => $group->name,
                    'privilege'  => $group->pivot->privilege,
                    'created_at' => $group->pivot->created_at,
                ];
            });

        return $this->success($members, 'Project members retrieved successfully');
    }

    /**
     * 添加项目成员（为项目关联用户组并授予读写/只读权限）
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function addMember(Request $request, $id)
    {
        $project = Project::find($id);
        if (empty($project)) {
            return $this->error('Project not found', 404);
        }

        // 检查用户权限
        if (!Auth::user()->can('project-edit', $project)) {
            return $this->error('Unauthorized', 403);
        }

        $this->validate(
            $request,
            [
                'group_id'  => 'required|integer|min:1|exists:groups,id',
                'privilege' => 'in:wr,r',
            ]
        );

        $groupID = (int)$request->input('group_id');
        $privilege = $request->input('privilege', 'r');

        $project->groups()->detach($groupID);
        $project->groups()->attach($groupID, ['privilege' => $privilege == 'r' ? Project::PRIVILEGE_RO : Project::PRIVILEGE_WR]);

        // 触发项目权限更新事件（记录操作日志）
        event(new ProjectModified($project, 'privilege'));

        return $this->success([
            'project_id' => $project->id,
            'group_id'   => $groupID,
            'privilege'  => $privilege == 'r' ? Project::PRIVILEGE_RO : Project::PRIVILEGE_WR,
        ], 'Project member added successfully');
    }

    /**
     * 删除项目成员（解除项目与用户组的关联，回收权限）
     *
     * @param Request $request
     * @param int     $id       项目ID
     * @param int     $memberId 用户组ID
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteMember(Request $request, $id, $memberId)
    {
        $project = Project::find($id);
        if (empty($project)) {
            return $this->error('Project not found', 404);
        }

        // 检查用户权限
        if (!Auth::user()->can('project-edit', $project)) {
            return $this->error('Unauthorized', 403);
        }

        if (!$project->groups()->where('groups.id', $memberId)->exists()) {
            return $this->error('Member not found', 404);
        }

        $project->groups()->detach($memberId);

        // 触发项目权限更新事件（记录操作日志）
        event(new ProjectModified($project, 'privilege'));

        return $this->success(null, 'Project member deleted successfully');
    }

    /**
     * 获取项目操作日志
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logs(Request $request, $id)
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

        $result = OperationLogs::where('project_id', $id)
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
        $meta = [
            'total'        => $result->total(),
            'per_page'     => $result->perPage(),
            'current_page' => $result->currentPage(),
            'last_page'    => $result->lastPage(),
        ];

        return $this->success($result->items(), 'Project logs retrieved successfully', $meta);
    }
}