<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Jobs;

use App\Repositories\Project;
use App\Repositories\User;
use App\Services\ExportService;
use App\Support\ErrorLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Mpdf\MpdfException;
use Throwable;
use ZipStream\Exception\OverflowException;

/**
 * 批量导出异步任务
 *
 * 触发入口:BatchExportController::batchExport。
 *
 * 设计要点:
 *  - 渲染 PDF / 打包 ZIP 是重操作（PDF 渲染 + 大文档打包可能 O(分钟)）,
 *    把整个流程从 Web 请求线程剥离,避免 PHP-FPM 长占用与超时。
 *  - 完成后通过 notifyExportComplete() 走 database 通知通道,前端
 *    通过现有通知中心拉取结果。
 *  - 失败时不写入 Laravel 默认 logger（避免污染 stack 日志）,
 *    统一走 App\Support\ErrorLogger::record()。
 *
 * 同步队列:测试环境 (QUEUE_DRIVER=sync) 下 dispatch 立即同步执行,
 *          与改造前的同步行为等价,无回归风险。
 */
class ExportBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 任务最大执行秒数。覆盖默认的 60s,因为 PDF 渲染可能较慢。
     */
    public int $timeout = 600;

    /**
     * 失败重试次数。导出对源数据敏感,失败重试往往无效,
     * 默认 0 次,交由 failed() 记录后由用户重新发起。
     */
    public int $tries = 1;

    public function __construct(
        public User $user,
        public Project $project,
        public array $navigators,
        public string $format,
    ) {
    }

    /**
     * 执行任务
     *
     *  - 调用 ExportService::exportBatch 渲染/打包
     *  - 完成后通过 ExportService::notifyExportComplete 通知用户
     *
     * @throws MpdfException
     * @throws OverflowException
     */
    public function handle(ExportService $exportService): void
    {
        $filePath = $exportService->exportBatch(
            $this->user,
            $this->project,
            $this->navigators,
            $this->format,
        );

        $downloadName = $this->format === 'pdf'
            ? "{$this->project->name}.pdf"
            : "{$this->project->name}.zip";

        $exportService->notifyExportComplete($this->user, $filePath, $downloadName);
    }

    /**
     * 任务失败回调。
     *
     * 不重抛异常（Laravel 默认会再交给全局 logger 一次）,
     * 统一走 ErrorLogger 以便统一监控通道。
     */
    public function failed(Throwable $e): void
    {
        ErrorLogger::record($e, [
            'context'    => 'ExportBatchJob',
            'user_id'    => $this->user->id,
            'project_id' => $this->project->id,
            'format'     => $this->format,
        ]);
    }

    /**
     * 任务标签（便于队列监控 / Horizon / 失败排查）。
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'export',
            'user:' . $this->user->id,
            'project:' . $this->project->id,
        ];
    }
}
