<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Support;

use App\Repositories\Document;
use Illuminate\Support\Facades\Cache;

/**
 * 导航数据缓存层。
 *
 * 替代 app/helpers.php 中 navigator() 的进程级 static 缓存,
 * 解决 PHP-FPM worker 间数据漂移、Octane 内存泄漏、文档变更无失效问题。
 *
 * 实现策略(避免 tags API 依赖):
 *   每个项目维护一个 "nav_version" key(单调递增整数),
 *   实际缓存 key = nav_version + 子键。
 *   flushProject 时,递增 nav_version,旧 key 自然过期被 LRU 清理。
 *   读路径首次 miss 后回填,后续命中旧 key 不存在直接重算。
 *
 * 灰度开关:config('wizard.navigator_cache_enabled') = false 时走无缓存模式。
 */
class NavigatorCache
{
    public const CACHE_PREFIX = 'wizard:navigator:';
    public const VERSION_PREFIX = 'wizard:navver:';

    /**
     * 取导航数据(缓存或重算)。
     */
    public static function get(int $projectId, int $pageId, array $exclude): array
    {
        if (!self::isEnabled()) {
            return self::compute($projectId, $pageId, $exclude);
        }

        $ver = self::version($projectId);
        $key = self::CACHE_PREFIX . "{$ver}:{$projectId}:{$pageId}:" . md5(implode(',', $exclude));
        $ttl = (int) config('wizard.navigator_cache_ttl', 600);

        return Cache::remember($key, $ttl, function () use ($projectId, $pageId, $exclude) {
            return self::compute($projectId, $pageId, $exclude);
        });
    }

    /**
     * 失效项目级缓存(通过递增 version 实现)。
     *
     * 用 Cache::lock 包裹 read-modify-write,避免多 worker 并发覆盖导致版本号丢失递增。
     */
    public static function flushProject(int $projectId): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $key = self::VERSION_PREFIX . $projectId;
        $lock = Cache::lock('wizard:navlock:' . $projectId, 5);

        try {
            $lock->block(3);
            $current = Cache::get($key, 1);
            Cache::forever($key, (int) $current + 1);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            // 锁超时兜底:仍执行递增,但记录日志便于监控高并发场景
            \Log::warning('navigator_cache_lock_timeout', [
                'project_id' => $projectId,
                'lock_key'   => 'wizard:navlock:' . $projectId,
            ]);
            $current = Cache::get($key, 1);
            Cache::forever($key, (int) $current + 1);
        } finally {
            $lock?->release();
        }
    }

    /**
     * 失效用户组关联项目缓存(AD3)。
     *
     * 通过 project_group_ref pivot 表找出关联 project_id,逐个 flush。
     * 表名约定见 database/migrations/2017_08_06_124106_create_project_group_ref_table.php
     */
    public static function flushGroup(int $groupId): void
    {
        if (!self::isEnabled()) {
            return;
        }
        try {
            $projectIds = \DB::table('project_group_ref')
                ->where('group_id', $groupId)
                ->pluck('project_id');
            foreach ($projectIds as $pid) {
                self::flushProject((int) $pid);
            }
        } catch (\Throwable $e) {
            \App\Support\ErrorLogger::record($e, ['context' => 'NavigatorCache']);
            \Log::warning('navigator_cache_flush_group_failed', [
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * 全量失效(角色/全局权限变更等极端场景)。
     */
    public static function flushAll(): void
    {
        Cache::flush();
    }

    /**
     * 读项目的当前 nav_version(首次访问初始化为 1)。
     */
    private static function version(int $projectId): int
    {
        $key = self::VERSION_PREFIX . $projectId;
        $ver = Cache::get($key);
        if ($ver === null) {
            Cache::forever($key, 1);
            return 1;
        }
        return (int) $ver;
    }

    /**
     * 重算导航(无缓存)。实现与 helpers.php::navigator() 完全一致。
     */
    private static function compute(int $projectID, int $pageID, array $exclude): array
    {
        $pages = Document::where('project_id', $projectID)->select(
            'id', 'pid', 'title', 'project_id', 'type', 'status',
            'created_at', 'updated_at', 'sort_level'
        )->orderBy('pid')->get();

        $navigators = [];
        foreach ($pages as $page) {
            if (in_array((int) $page->id, $exclude, true)) {
                continue;
            }
            $navigators[$page->id] = [
                'id'         => (int) $page->id,
                'name'       => $page->title,
                'pid'        => (int) $page->pid,
                'url'        => wzRoute('project:home', ['id' => $projectID, 'p' => $page->id]),
                'selected'   => $pageID === (int) $page->id,
                'type'       => documentType($page->type),
                'status'     => $page->status,
                'created_at' => $page->created_at,
                'updated_at' => $page->updated_at,
                'sort_level' => $page->sort_level ?? 1000,
            ];
        }

        foreach ($navigators as &$nav) {
            if ($nav['pid'] === 0 || in_array($nav['id'], $exclude, true)) {
                continue;
            }
            if (isset($navigators[$nav['pid']])) {
                $navigators[$nav['pid']]['nodes'][] = &$nav;
            }
        }
        unset($nav);

        return array_filter($navigators, fn ($nav) => $nav['pid'] === 0);
    }

    private static function isEnabled(): bool
    {
        return (bool) config('wizard.navigator_cache_enabled', true);
    }
}
