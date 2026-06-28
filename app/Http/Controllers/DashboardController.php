<?php
/**
 * wizard
 *
 * @link      https://www.yunsom.com/
 * @copyright 管宜尧 <guanyiyao@yunsom.com>
 */

namespace App\Http\Controllers;


use App\Repositories\Catalog;
use App\Repositories\Comment;
use App\Repositories\Document;
use App\Repositories\Group;
use App\Repositories\Project;
use App\Repositories\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{

    public function index(Request $request)
    {
        $ttl = (int) config('wizard.dashboard_cache_ttl', 300);

        $payload = $ttl > 0
            ? Cache::remember('dashboard:stats', $ttl, function () {
                return $this->computeStats();
            })
            : $this->computeStats();

        return view('admin.dashboard', [
            'op'       => 'dashboard',
            'user'     => [
                'counts'      => $payload['userCounts'],
                'group_count' => $payload['groupCount'],
            ],
            'project'  => [
                'counts'        => $payload['projectCounts'],
                'catalog_count' => $payload['catalogCount'],
            ],
            'document' => [
                'counts'        => $payload['documentCounts'],
                'comment_count' => $payload['commentCount'],
            ],
            'stats'    => [
                'document' => $payload['documentStat'],
            ]
        ]);
    }

    /**
     * 计算 Dashboard 统计数据
     *
     * 提取为独立方法以支持：
     * 1) Cache::remember() 缓存命中
     * 2) ttl<=0 时直查（绕过 Cache 永久存储语义陷阱）
     */
    private function computeStats(): array
    {
        $userCounts = User::groupBy('role')
            ->select(\DB::raw('role, count(id) as user_count'))
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    $item['role'] == User::ROLE_ADMIN ? 'admin' : 'normal' => $item['user_count']
                ];
            })->toArray();
        $groupCount = Group::count();

        // 项目统计
        $projectCounts = Project::groupBy('visibility')
            ->select(\DB::raw('visibility, count(id) as project_count'))
            ->get()
            ->mapWithKeys(function ($item) {
                $visibility =
                    $item['visibility'] == Project::VISIBILITY_PRIVATE ? 'private' : 'public';
                return [
                    $visibility => $item['project_count']
                ];
            })->toArray();
        $catalogCount  = Catalog::count();

        // 文档统计
        $total = 0;
        $documentCounts = Document::groupBy('type')
            ->select(\DB::raw('type, count(id) as document_count'))
            ->get()
            ->mapWithKeys(function ($item) use (&$total) {
                $type = documentType($item['type']);
                $total += $item['document_count'];
                return [
                    $type => $item['document_count']
                ];
            });
        $documentCounts['total'] = $total;

        // 评论统计
        $commentCount = Comment::count();


        $documentStat = Document::select(
                \DB::raw('YEAR(created_at) as year, MONTH(created_at) as month'),
                \DB::raw('count(id) as document_count')
            )
            ->whereNotNull('created_at')   // 防御历史脏数据,YEAR(NULL) 会返回 NULL
            ->where('created_at', '>=', Carbon::now()->addMonths(-10))
            ->groupBy(\DB::raw('YEAR(created_at)'), \DB::raw('MONTH(created_at)'))
            ->orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                return [
                    'month'          => sprintf('%04d-%02d', $row->year, $row->month),
                    'document_count' => $row->document_count,
                ];
            })
            ->toArray();

        return [
            'userCounts'     => $userCounts,
            'groupCount'     => $groupCount,
            'projectCounts'  => $projectCounts,
            'catalogCount'   => $catalogCount,
            'documentCounts' => $documentCounts,
            'commentCount'   => $commentCount,
            'documentStat'   => $documentStat,
        ];
    }
}