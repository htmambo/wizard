<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Observers;

use App\Repositories\Group;
use App\Support\NavigatorCache;

/**
 * 用户组变更触发关联项目导航缓存失效(AD3)。
 *
 * 注意:Laravel 原生 Observer 不支持 pivot attach/detach 事件,
 * 成员/项目授权变更需在 Controller 层显式调用 NavigatorCache::flushGroup()。
 * 见 GroupController::addUser/removeUser/grantProjects 与本 Observer 协同工作。
 *
 * 触发场景:
 *   - 用户组基础信息变更(saved/deleted)
 *   - 成员/项目授权变更:Controller 层手动调 flushGroup
 */
class GroupObserver
{
    public function saved(Group $group): void
    {
        NavigatorCache::flushGroup((int) $group->id);
    }

    public function deleted(Group $group): void
    {
        NavigatorCache::flushGroup((int) $group->id);
    }

    public function forceDeleted(Group $group): void
    {
        NavigatorCache::flushGroup((int) $group->id);
    }
}
