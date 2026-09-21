<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Services;

use App\Events\ProjectCreated;
use App\Events\ProjectDeleted;
use App\Events\ProjectModified;
use App\Repositories\Document;
use App\Repositories\Project;
use App\Repositories\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;

/**
 * 项目（Project）域业务服务
 *
 * 职责：
 *  - 项目 CRUD 与软删除
 *  - 项目成员（关联用户组）读写
 *  - 项目可见性过滤查询
 *  - 项目下文档列表
 *
 * 约定：
 *  - 入参已校验，授权由 Controller 调用 Policy 完成
 *  - 不写接口；plain class，Laravel 自动解析依赖
 *  - 领域事件由本服务触发
 */
class ProjectService
{
    /**
     * 项目列表缓存的版本键（单调递增）。
     * 写入侧（create/update/delete/addMember/removeMember）通过
     * bumpCacheVersion() 让版本号 +1，所有旧版本键即自动失效。
     */
    private const CACHE_VERSION_KEY = 'projects:list:version';

    /**
     * 列表缓存 TTL（秒）。短 TTL + 主动失效 = 数据安全性。
     */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * 列出用户可见的项目
     *
     * 业务规则：
     *  - 公开项目（VISIBILITY_PUBLIC）
     *  - user_id = $user->id 的私有项目
     *  - 关联了 $user 所属用户组的私有项目
     *
     * 排序：catalog_id ASC, sort_level ASC, id ASC
     *
     * 缓存：按 user 维度 + 单调递增版本号缓存 5 分钟；
     * 任何写入操作都会让版本号 +1，旧键自动失效。
     *
     * @return Collection<int, Project>
     */
    public function listVisibleForUser(User $user): Collection
    {
        $version = $this->getCacheVersion();
        $cacheKey = "projects:list:v{$version}:user:{$user->id}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($user) {
            $userGroupIds = $user->groups->pluck('id')->all();

            return Project::query()
                ->where(function ($query) use ($user, $userGroupIds) {
                    $query->where('visibility', Project::VISIBILITY_PUBLIC)
                          ->orWhere('user_id', $user->id);

                    if (!empty($userGroupIds)) {
                        $query->orWhere(function ($query) use ($userGroupIds) {
                            $query->where('visibility', '!=', Project::VISIBILITY_PUBLIC)
                                  ->whereHas('groups', function ($query) use ($userGroupIds) {
                                      $query->whereIn('groups.id', $userGroupIds);
                                  });
                        });
                    }
                })
                ->select(['id', 'name', 'catalog_id', 'sort_level'])
                ->orderBy('catalog_id', 'ASC')
                ->orderBy('sort_level', 'ASC')
                ->orderBy('id', 'ASC')
                ->get();
        });
    }

    /**
     * 获取项目列表缓存的当前版本号。
     *
     * 用 Cache::add() 兜底确保键存在（首次访问时初始化为 0），
     * 然后返回 int 值。该键本身以较长的 TTL 持久存在，避免
     * 每次都重建。
     */
    private function getCacheVersion(): int
    {
        Cache::add(self::CACHE_VERSION_KEY, 0, now()->addDays(30));

        return (int) Cache::get(self::CACHE_VERSION_KEY, 0);
    }

    /**
     * 让项目列表缓存版本号 +1，使所有旧版本的 per-user 缓存键
     * 自动失效（无需主动 forget 所有用户键）。
     */
    private function bumpCacheVersion(): void
    {
        if (!Cache::increment(self::CACHE_VERSION_KEY)) {
            // 文件/数组等驱动下 increment 在键不存在时返回 false
            // 这里直接 put 一个大于当前值的版本，避免并发场景下的回退
            Cache::put(self::CACHE_VERSION_KEY, 1, now()->addDays(30));
        }
    }

    /**
     * 创建新项目
     *
     * @param User  $user 创建者（已通过授权）
     * @param array $data 已校验数据：name, description, visibility, sort_level?, catalog
     *
     * @return Project
     */
    public function create(User $user, array $data): Project
    {
        $sortLevel = $user->can('project-sort')
            ? ($data['sort_level'] ?? 1000)
            : 1000;

        $project = Project::create([
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'user_id'     => $user->id,
            'visibility'  => $data['visibility'],
            'sort_level'  => (int)$sortLevel,
            'catalog_id'  => $data['catalog'],
        ]);

        $this->bumpCacheVersion();
        event(new ProjectCreated($project));

        return $project;
    }

    /**
     * 更新项目基本信息
     *
     * @param Project $project
     * @param User    $user    当前操作用户（仅用于 sort_level 权限判定）
     * @param array   $data    已校验数据
     *
     * @return Project 项目实例（无论是否发生持久化变更均返回）
     */
    public function update(Project $project, User $user, array $data): Project
    {
        $project->name = $data['name'];
        $project->description = $data['description'] ?? null;
        $project->visibility = $data['visibility'];
        $project->catalog_id = empty($data['catalog']) ? null : $data['catalog'];
        $project->catalog_sort_style = $data['catalog_sort_style'] ?? Project::SORT_STYLE_DIR_FIRST;
        $project->catalog_fold_style = $data['catalog_fold_style'] ?? Project::FOLD_STYLE_AUTO;

        if ($user->can('project-sort') && array_key_exists('sort_level', $data) && $data['sort_level'] !== null) {
            $project->sort_level = (int)$data['sort_level'];
        }

        if ($project->isDirty()) {
            $project->save();
            $this->bumpCacheVersion();
            event(new ProjectModified($project, 'basic'));
        }

        return $project;
    }

    /**
     * 软删除项目
     */
    public function delete(Project $project): void
    {
        $project->delete();
        $this->bumpCacheVersion();
        event(new ProjectDeleted($project));
    }

    /**
     * 为项目添加成员（关联用户组并赋予权限）
     *
     * @param Project $project
     * @param int     $groupId
     * @param string  $privilege 'wr' 读写 / 'r' 只读
     */
    public function addMember(Project $project, int $groupId, string $privilege): void
    {
        $privilegeValue = $privilege === 'r' ? Project::PRIVILEGE_RO : Project::PRIVILEGE_WR;

        $project->groups()->detach($groupId);
        $project->groups()->attach($groupId, ['privilege' => $privilegeValue]);

        $this->bumpCacheVersion();
        event(new ProjectModified($project, 'privilege'));
    }

    /**
     * 移除项目成员（用户组关联）
     *
     * @return bool true=存在并已移除；false=不存在
     */
    public function removeMember(Project $project, int $groupId): bool
    {
        if (!$project->groups()->where('groups.id', $groupId)->exists()) {
            return false;
        }

        $project->groups()->detach($groupId);
        $this->bumpCacheVersion();
        event(new ProjectModified($project, 'privilege'));

        return true;
    }

    /**
     * 获取项目关联的用户组（含权限与加入时间）
     *
     * @return SupportCollection<int, array{id:int,name:string,privilege:int,created_at:mixed}>
     */
    public function getMembers(Project $project): SupportCollection
    {
        return $project->groups()
            ->select('groups.id', 'groups.name', 'groups.user_id')
            ->get()
            ->map(function ($group) {
                return [
                    'id'         => $group->id,
                    'name'       => $group->name,
                    'privilege'  => $group->pivot->privilege,
                    'created_at' => $group->pivot->created_at,
                ];
            });
    }

    /**
     * 项目下的文档分页列表
     *
     * 按 sort_level ASC 排序，with('user', 'project') 仅 select id/name。
     */
    public function listDocuments(Project $project, int $perPage): LengthAwarePaginator
    {
        return Document::where('project_id', $project->id)
            ->orderBy('sort_level', 'ASC')
            ->with([
                'user' => function ($query) {
                    $query->select('id', 'name');
                },
                'project' => function ($query) {
                    $query->select('id', 'name');
                },
            ])
            ->select(['id', 'title', 'description', 'project_id', 'user_id'])
            ->paginate($perPage);
    }
}
