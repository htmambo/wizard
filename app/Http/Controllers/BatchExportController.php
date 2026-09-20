<?php
/**
 * wizard
 *
 * @link      https://www.yunsom.com/
 * @copyright 管宜尧 <guanyiyao@yunsom.com>
 */

namespace App\Http\Controllers;

use App\Policies\ProjectPolicy;
use App\Repositories\Project;
use App\Services\ExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
     * @param Request $request
     * @param         $project_id
     *
     * @return BinaryFileResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Mpdf\MpdfException
     * @throws \ZipStream\Exception\OverflowException
     */
    public function batchExport(Request $request, $project_id)
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

        $filePath = $this->exportService->exportBatch(\Auth::user(), $project, $navigators, $type);

        $downloadName = $type === 'pdf'
            ? "{$project->name}.pdf"
            : "{$project->name}.zip";

        $this->exportService->notifyExportComplete(\Auth::user(), $filePath, $downloadName);

        return response()
            ->download($filePath, $downloadName)
            ->deleteFileAfterSend(true);
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
