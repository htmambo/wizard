<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Providers;

use App\Events\CommentCreated;
use App\Events\DocumentMarkModified;
use App\Events\UserCreated;
use App\Listeners\CommentCreatedListener;
use App\Listeners\DocumentMarkModifiedListener;
use App\Listeners\UserCreatedListener;
use App\Observers\ClearDashboardCache;
use App\Repositories\Comment;
use App\Repositories\Document;
use App\Repositories\Group;
use App\Repositories\Project;
use App\Repositories\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'App\Events\DocumentCreated'   => [
            'App\Listeners\DocumentCreatedListener',
        ],
        'App\Events\DocumentModified'  => [
            'App\Listeners\DocumentModifiedListener'
        ],
        'App\Events\DocumentRecovered' => [
            'App\Listeners\DocumentRecoveredListener'
        ],
        'App\Events\DocumentDeleted'   => [
            'App\Listeners\DocumentDeletedListener'
        ],
        'App\Events\ProjectCreated'    => [
            'App\Listeners\ProjectCreatedListener'
        ],
        'App\Events\ProjectModified'   => [
            'App\Listeners\ProjectModifiedListener'
        ],
        'App\Events\ProjectDeleted'    => [
            'App\Listeners\ProjectDeletedListener'
        ],
        DocumentMarkModified::class => [
            DocumentMarkModifiedListener::class,
        ],
        CommentCreated::class          => [
            CommentCreatedListener::class,
        ],
        UserCreated::class             => [
            UserCreatedListener::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        // 业务实体变更时清空 Dashboard 统计缓存
        $flush = [ClearDashboardCache::class, 'flush'];
        foreach ([Project::class, Document::class, Comment::class, User::class, Group::class] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }
    }
}
