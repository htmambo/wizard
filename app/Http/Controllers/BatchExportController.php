<?php
/**
 * wizard
 *
 * @link      https://www.yunsom.com/
 * @copyright 管宜尧 <guanyiyao@yunsom.com>
 */

namespace App\Http\Controllers;

use App\Jobs\ExportBatchJob;
use App\Policies\ProjectPolicy;
use App\Repositories\Project;
use App\Services\ExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchExportController extends Controller
{
    /**
     * @param ExportService $exportService 导出域服务
     */
    public function __construct(private readonly ExportService $exportService)
    {
    }

    /**
     * 批量导出文档
     *
     * PDF 渲染 + ZIP 打包是重操作,改为异步任务执行:
     *  - 入参校验 / 授权 / 导航树收集仍在本方法内（轻量）
     *  - 重活 dispatch 给 ExportBatchJob,完成后通过通知送达用户
     *  - 同步立即返回 202 Accepted + queued 标记
     *
     * 同步队列 (QUEUE_DRIVER=sync) 下 dispatch 立即执行,
     * 保留原有同步行为,生产环境切换 database/redis 即可获得真异步。
     *
     * @param Request $request
     * @param         $project_id
     *
     * @return JsonResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function batchExport(Request $request, $project_id): JsonResponse
    {
        $this->canExport($project_id);

        $this->validate(
            $request,
            [
                'pid'  => 'integer',
                'type' => 'required|in:pdf,raw'
            ]
        );

        $pid  = (int)$request->input('pid', 0);
        $type = $request->input('type');

        /** @var Project $project */
        $project = Project::where('id', $project_id)->firstOrFail();

        $navigators = $this->exportService->collectNavigators($project, $pid);

        ExportBatchJob::dispatch(\Auth::user(), $project, $navigators, $type);

        return new JsonResponse([
            'success' => true,
            'queued'  => true,
            'message' => '导出任务已开始，完成后会通过通知送达',
        ], 202);
    }

    /**
     * 检查是否用户有导出权限
     *
     * @param int $projectId
     *
     * @return Project
     */
    private function canExport($projectId)
    {
        /** @var Project $project */
        $project = Project::findOrFail($projectId);

        $policy = new ProjectPolicy();
        if (!$policy->view(\Auth::user(), $project)) {
            abort(404);
        }

        return $project;
    }
}
