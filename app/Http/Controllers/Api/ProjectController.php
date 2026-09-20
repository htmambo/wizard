<?php

namespace App\Http\Controllers\Api;

use App\Repositories\Document;
use App\Repositories\Project;
use App\Services\ProjectService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Repositories\OperationLogs;

#[Group('项目相关', '项目相关API', 3)]
class ProjectController extends Controller
{

    /**
     * @var ProjectService
     */
    protected $projectService;

    public function __construct(ProjectService $projectService)
    {
        $this->projectService = $projectService;
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

        $result = $this->projectService->listDocuments($project, (int)$perPage);
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
        $user = Auth::user();
        $projects = $this->projectService->listVisibleForUser($user);
        $userGroupIds = $user->groups->pluck('id')->all();
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
     * @response 401 {"success":false,"message":"Unauthorized"}
     * @response 403 {"success":false,"message":"Unauthorized"}
     * @response 404 {"success":false,"message":"Project not found"}
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
     * @response 401 {"success":false,"message":"Unauthorized"}
     * @response 403 {"success":false,"message":"Unauthorized"}
     * @response 422 {"success":false,"message":"Validation failed"}
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

        $project = $this->projectService->create(Auth::user(), $request->all());

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
     * @response 401 {"success":false,"message":"Unauthorized"}
     * @response 403 {"success":false,"message":"Unauthorized"}
     * @response 404 {"success":false,"message":"Project not found"}
     * @response 422 {"success":false,"message":"Validation failed"}
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

        $updated = $this->projectService->update($project, Auth::user(), $request->all());

        return $this->success($updated->fresh()->toArray(), 'Project updated successfully');
    }

    /**
     * 删除项目
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @response 401 {"success":false,"message":"Unauthorized"}
     * @response 403 {"success":false,"message":"Unauthorized"}
     * @response 404 {"success":false,"message":"Project not found"}
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

        $this->projectService->delete($project);

        return $this->success(null, 'Project deleted successfully');
    }

    /**
     * 获取项目成员列表（项目关联的用户组及其权限）
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @response 401 {"success":false,"message":"Unauthorized"}
     * @response 403 {"success":false,"message":"Unauthorized"}
     * @response 404 {"success":false,"message":"Project not found"}
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

        $members = $this->projectService->getMembers($project);

        return $this->success($members, 'Project members retrieved successfully');
    }

    /**
     * 添加项目成员（为项目关联用户组并授予读写/只读权限）
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @response 401 {"success":false,"message":"Unauthorized"}
     * @response 403 {"success":false,"message":"Unauthorized"}
     * @response 404 {"success":false,"message":"Project not found"}
     * @response 422 {"success":false,"message":"Validation failed"}
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
        $privilege = (string)$request->input('privilege', 'r');

        $this->projectService->addMember($project, $groupID, $privilege);

        return $this->success([
            'project_id' => $project->id,
            'group_id'   => $groupID,
            'privilege'  => $privilege === 'r' ? Project::PRIVILEGE_RO : Project::PRIVILEGE_WR,
        ], 'Project member added successfully');
    }

    /**
     * 删除项目成员（解除项目与用户组的关联，回收权限）
     *
     * @param Request $request
     * @param int     $id       项目ID
     * @param int     $memberId 用户组ID
     *
     * @response 401 {"success":false,"message":"Unauthorized"}
     * @response 403 {"success":false,"message":"Unauthorized"}
     * @response 404 {"success":false,"message":"Project or member not found"}
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

        if (!$this->projectService->removeMember($project, (int)$memberId)) {
            return $this->error('Member not found', 404);
        }

        return $this->success(null, 'Project member deleted successfully');
    }

    /**
     * 获取项目操作日志
     *
     * @param Request $request
     * @param int     $id 项目ID
     *
     * @response 401 {"success":false,"message":"Unauthorized"}
     * @response 403 {"success":false,"message":"Unauthorized"}
     * @response 404 {"success":false,"message":"Project not found"}
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
