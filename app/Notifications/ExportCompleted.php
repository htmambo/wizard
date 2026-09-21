<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * 批量导出完成通知
 *
 * 由 App\Services\ExportService::notifyExportComplete() 派发。
 * 仅走 database 通道，由前端通知中心拉取展示。
 */
class ExportCompleted extends Notification
{
    use Queueable;

    /**
     * @param string      $filePath    临时文件路径
     * @param string|null $archiveName 显示/下载时使用的归档名
     */
    public function __construct(
        private readonly string $filePath,
        private readonly ?string $archiveName = null
    ) {
    }

    /**
     * @param  mixed $notifiable
     *
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * @param  mixed $notifiable
     *
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        $name = $this->archiveName ?: basename($this->filePath);

        return [
            'archive_name' => $name,
            'file_path'    => $this->filePath,
            'message'      => sprintf('导出完成：%s', $name),
        ];
    }
}
