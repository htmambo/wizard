<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Observers;

use App\Repositories\Document;
use App\Support\NavigatorCache;

/**
 * 文档变更触发导航缓存失效。
 *
 * 触发事件:
 *   - saved(创建/更新)
 *   - deleted(软删除)
 *   - restored(恢复)
 *   - moved(项目间移动,在 DocumentController::move 内显式调用)
 */
class DocumentObserver
{
    public function saved(Document $document): void
    {
        NavigatorCache::flushProject((int) $document->project_id);

        // 文档移动场景:旧项目也要失效(因为项目_id 变了)
        if ($document->wasChanged('project_id')) {
            $original = $document->getOriginal('project_id');
            if ($original && $original !== $document->project_id) {
                NavigatorCache::flushProject((int) $original);
            }
        }
    }

    public function deleted(Document $document): void
    {
        NavigatorCache::flushProject((int) $document->project_id);
    }

    public function forceDeleted(Document $document): void
    {
        // 硬删除(softDeletes 模式):deleted 事件不触发,需单独监听 forceDeleted
        NavigatorCache::flushProject((int) $document->project_id);
    }

    public function restored(Document $document): void
    {
        NavigatorCache::flushProject((int) $document->project_id);
    }
}
