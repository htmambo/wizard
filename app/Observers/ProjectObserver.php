<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Observers;

use App\Repositories\Project;
use App\Support\NavigatorCache;

/**
 * 项目变更触发导航缓存失效。
 *
 * 触发事件:
 *   - saved(visibility/catalog_id/sort_level 等变更)
 *   - deleted
 */
class ProjectObserver
{
    public function saved(Project $project): void
    {
        NavigatorCache::flushProject((int) $project->id);
    }

    public function deleted(Project $project): void
    {
        NavigatorCache::flushProject((int) $project->id);
    }
}
