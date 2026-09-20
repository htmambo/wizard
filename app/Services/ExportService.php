<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Services;

use App\Notifications\ExportCompleted;
use App\Repositories\Document;
use App\Repositories\Project;
use App\Repositories\User;
use App\Support\ErrorLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use Mpdf\MpdfException;
use SoapBox\Formatter\Formatter;
use ZipStream\Exception\OverflowException;
use ZipStream\ZipStream;

/**
 * 导出（Export）域业务服务
 *
 * 职责：
 *  - 收集导出范围（项目下的导航树 / 文档集合）
 *  - 按格式（pdf / raw）执行导出，统一写入临时文件并返回路径
 *  - 将多文件打包为 zip（maennchen/zipstream-php v3）
 *  - 导出完成后通知用户（Notification）
 *  - 临时文件清理由 Controller 在流式下发后通过 deleteFileAfterSend() 处理
 *
 * 约定：
 *  - 入参已校验，授权由 Controller 调用 ProjectPolicy::view 完成
 *  - 不写接口；plain class，Laravel 自动解析依赖
 *  - 返回值：领域对象 / 字符串（临时文件路径）/ void
 *  - 临时文件统一写到 sys_get_temp_dir() 下，前缀 wizard_export_
 */
class ExportService
{
    /**
     * 导出最大处理超时时间（秒）
     */
    const TIMEOUT = 320;

    /**
     * 收集项目下的导航树（按项目目录排序风格排序），可按 pid 过滤为子树。
     *
     * @param Project $project 目标项目
     * @param int|null $pid    子文档 ID；0 或 null 表示全量
     *
     * @return array 过滤并排序后的导航树
     */
    public function collectNavigators(Project $project, ?int $pid = null): array
    {
        $navigators = navigatorSort(navigator($project->id, 0), $project->catalog_sort_style);

        if (!empty($pid)) {
            $navigators = $this->filterNavigators($navigators, function (array $nav) use ($pid) {
                return (int)$nav['id'] === (int)$pid;
            });
        }

        return $navigators;
    }

    /**
     * 批量导出：format = 'raw' 输出 zip，format = 'pdf' 输出 PDF。
     *
     * @param User       $user       当前操作用户（用于 mpdf 作者）
     * @param Project    $project    目标项目
     * @param array      $navigators 由 collectNavigators 生成的导航树
     * @param string     $format     'pdf' 或 'raw'
     *
     * @return string 临时文件路径（zip/pdf 视 format 而定）
     *
     * @throws MpdfException
     * @throws OverflowException
     */
    public function exportBatch(User $user, Project $project, array $navigators, string $format): string
    {
        /** @var Collection<int, Document> $documents */
        $documents = $project->pages;

        return match ($format) {
            'raw' => $this->buildRawArchive($project, $navigators, $documents),
            'pdf' => $this->buildPdfArchive($user, $project, $navigators, $documents),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }

    /**
     * 导出单个文档为指定格式。
     *
     * 当前实现：pdf → 单页 PDF；raw → 单文件 zip。
     *
     * @param Document $document
     * @param string   $format 'pdf' 或 'raw'
     *
     * @return string 临时文件路径
     *
     * @throws MpdfException
     * @throws OverflowException
     */
    public function exportSingle(Document $document, string $format): string
    {
        return match ($format) {
            'pdf' => $this->buildSinglePdf($document),
            'raw' => $this->buildSingleRawArchive($document),
            default => throw new \InvalidArgumentException("Unsupported export format: {$format}"),
        };
    }

    /**
     * 将多个文件打包为 zip。
     *
     * @param array<string, string> $files       键为 zip 内路径，值为磁盘文件绝对路径
     * @param string                $archiveName 显示/下载时的归档名（不含 .zip 后缀也可）
     *
     * @return string zip 临时文件路径
     *
     * @throws OverflowException
     */
    public function packageAsZip(array $files, string $archiveName): string
    {
        $tempFile = $this->makeTempFile('zip');

        $fp = fopen($tempFile, 'w+b');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open temp file for zip: {$tempFile}");
        }

        $zip = new ZipStream(
            outputName: $archiveName,
            outputStream: $fp,
            sendHttpHeaders: false,
        );

        foreach ($files as $entryPath => $sourcePath) {
            $entry = (string)$entryPath;
            if (is_file($sourcePath)) {
                $zip->addFileFromPath($entry, $sourcePath);
            } else {
                // 兜底：源文件丢失时写入占位内容，避免整批失败
                $zip->addFile($entry, '');
            }
        }

        $zip->finish();
        fclose($fp);

        return $tempFile;
    }

    /**
     * 通知用户导出完成。
     *
     * 使用 database 通道，由前端通过现有通知中心拉取。
     * 临时文件路径一并写入 payload，便于客户端按需发起下载请求。
     */
    public function notifyExportComplete(User $user, string $filePath, ?string $archiveName = null): void
    {
        $user->notify(new ExportCompleted($filePath, $archiveName));
    }

    /**
     * 过滤导航树：保留 $filter 命中的节点及其子节点（直接返回该节点的 children）。
     *
     * @return array|mixed
     */
    private function filterNavigators(array $navigators, \Closure $filter)
    {
        foreach ($navigators as $nav) {
            if ($filter($nav)) {
                return $nav['nodes'] ?? [];
            }

            if (!empty($nav['nodes'])) {
                $sub = $this->filterNavigators($nav['nodes'], $filter);
                if (!empty($sub)) {
                    return $sub;
                }
            }
        }

        return [];
    }

    /**
     * 原始格式（md / yml / txt）批量导出为 zip。
     */
    private function buildRawArchive(Project $project, array $navigators, Collection $documents): string
    {
        set_time_limit(self::TIMEOUT);

        $tempFile = $this->makeTempFile('zip');

        $fp = fopen($tempFile, 'w+b');
        if ($fp === false) {
            throw new \RuntimeException("Cannot open temp file for zip: {$tempFile}");
        }

        $url = rtrim((string)config('app.url'), '/');

        $zip = new ZipStream(
            outputName: "{$project->name}.zip",
            outputStream: $fp,
            sendHttpHeaders: false,
        );

        $this->traverseNavigators(
            $navigators,
            function ($id, array $parents) use ($documents, $zip, $url) {
                /** @var Document|null $doc */
                $doc = $documents->firstWhere('id', $id);
                if ($doc === null) {
                    return;
                }

                [$ext, $content] = $this->renderRawDocument($doc, $url);

                $path = collect($parents)->implode('name', '/');
                $filename = "{$path}/{$doc->title}.{$ext}";

                $zip->addFile($filename, $content);
            },
            []
        );

        $zip->finish();
        fclose($fp);

        return $tempFile;
    }

    /**
     * 单个文档 → 原始格式（按类型决定 md/yml/txt）→ 写入 zip 内的单文件。
     */
    private function buildSingleRawArchive(Document $document): string
    {
        set_time_limit(self::TIMEOUT);

        $url = rtrim((string)config('app.url'), '/');
        [$ext, $content] = $this->renderRawDocument($document, $url);

        return $this->packageAsZip(
            ["{$document->title}.{$ext}" => $this->writeTempContent($content, $ext)],
            "{$document->title}.zip"
        );
    }

    /**
     * 按文档类型渲染原始内容（md / yml / txt），并修正图片链接为绝对 URL。
     *
     * @return array{0:string,1:string} [扩展名, 内容]
     */
    private function renderRawDocument(Document $doc, string $url): array
    {
        switch ($doc->type) {
            case Document::TYPE_DOC:
                $ext = 'md';
                $content = (string)$doc->content;
                break;
            case Document::TYPE_SWAGGER:
                $ext = 'yml';
                if (isJson($doc->content)) {
                    $content = Formatter::make($doc->content, Formatter::JSON)->toYaml();
                } else {
                    $content = (string)$doc->content;
                }
                break;
            default:
                $ext = 'txt';
                $content = (string)$doc->content;
        }

        $content = preg_replace(
            '/\!\[(.*?)\]\(\/storage\/(.*?).(jpg|png|jpeg|gif)(.*?)\)/',
            '![$1](' . $url . '/storage/$2.$3$4)',
            $content
        );

        return [$ext, (string)$content];
    }

    /**
     * PDF 批量导出。
     */
    private function buildPdfArchive(User $user, Project $project, array $navigators, Collection $documents): string
    {
        set_time_limit(self::TIMEOUT);

        $tempFile = $this->makeTempFile('pdf');
        $imageRoot = rtrim((string)config('filesystems.disks.public.root'), '/');

        $mpdf = $this->makeMpdf($project->name, $user->name ?? 'wizard');

        $pageNo = 1;
        $this->traverseNavigators(
            $navigators,
            function ($id, array $parents) use ($documents, $mpdf, &$pageNo, $imageRoot) {
                if ($pageNo > 1) {
                    $mpdf->AddPage();
                }

                /** @var Document|null $doc */
                $doc = $documents->firstWhere('id', $id);
                if ($doc === null) {
                    return;
                }

                $html = $this->renderDocumentHtml($doc);

                $html = preg_replace(
                    '/src\s?=\s?"\/storage\/(.*?).(jpg|png|gif|jpeg)"/',
                    "src=\"{$imageRoot}/$1.$2\"",
                    (string)$html
                );

                $mpdf->Bookmark($doc->title, count($parents));
                try {
                    $mpdf->WriteHTML($html);
                } catch (\Exception $ex) {
                    ErrorLogger::record($ex, ['context' => 'ExportService::buildPdfArchive']);
                    Log::error('html_to_pdf_failed', [
                        'error' => $ex->getMessage(),
                        'code'  => $ex->getCode(),
                        'doc'   => [
                            'id'      => $doc->id,
                            'title'   => $doc->title,
                            'content' => $html,
                        ],
                    ]);
                    $str = '';
                    if (config('app.debug')) {
                        $str = '<p>' . $ex->getTraceAsString() . '</p>';
                    }
                    $mpdf->WriteHTML('<p class="pdf-error">部分文档生成失败：' . $ex->getMessage() . '</p>' . $str);
                }

                $pageNo++;
            },
            []
        );

        $mpdf->Output($tempFile, 'F');

        return $tempFile;
    }

    /**
     * 单个文档 → 单页 PDF。
     */
    private function buildSinglePdf(Document $document): string
    {
        set_time_limit(self::TIMEOUT);

        $tempFile = $this->makeTempFile('pdf');
        $imageRoot = rtrim((string)config('filesystems.disks.public.root'), '/');

        $mpdf = $this->makeMpdf($document->title, optional($document->user)->name ?? 'wizard');

        $html = $this->renderDocumentHtml($document);
        $html = preg_replace(
            '/src\s?=\s?"\/storage\/(.*?).(jpg|png|gif|jpeg)"/',
            "src=\"{$imageRoot}/$1.$2\"",
            (string)$html
        );

        $mpdf->Bookmark($document->title, 0);
        $mpdf->WriteHTML($html);

        $mpdf->Output($tempFile, 'F');

        return $tempFile;
    }

    /**
     * 渲染单个文档为 PDF 用的 HTML 片段。
     */
    private function renderDocumentHtml(Document $doc): string
    {
        $author = optional($doc->user)->name ?? '-';
        $createdTime = optional($doc->created_at)?->__toString() ?? '-';
        $lastModifiedUser = optional($doc->lastModifiedUser)->name ?? '-';
        $updatedTime = optional($doc->updated_at)?->__toString() ?? '-';

        if ($doc->isHtml()) {
            $title = '<h1> ' . $doc->title . '</h1>';
            $intro = "<p class='wz-document-header'>该文档由 {$author} 创建于 {$createdTime} ， {$lastModifiedUser} 在 {$updatedTime} 修改了该文档。</p>";
            return $title . $intro
                . "<div class='markdown-body wz-markdown-style-fix wz-pdf-content'>{$doc->content}</div>";
        }

        if ($doc->isMarkdown()) {
            if ($doc->html_code && config('wizard.markdown.direct_save_html')) {
                $title = '<h1>* ' . $doc->title . '</h1>';
                $intro = "<p class='wz-document-header'>该文档由 {$author} 创建于 {$createdTime} ， {$lastModifiedUser} 在 {$updatedTime} 修改了该文档。</p>";
                return $title . $intro
                    . "<div class='markdown-body wz-markdown-style-fix wz-pdf-content'>{$doc->html_code}</div>";
            }

            $title = "* {$doc->title}";
            $intro = "该文档由 {$author} 创建于 {$createdTime} ， {$lastModifiedUser} 在 {$updatedTime} 修改了该文档。\n\n";
            $raw = "# {$title}\n\n{$intro}" . $doc->content;
            $html = (new \Parsedown())->text($raw);
            return "<div class='markdown-body wz-markdown-style-fix wz-pdf-content'>{$html}</div>";
        }

        $title = "* {$doc->title}";
        $intro = "该文档由 {$author} 创建于 {$createdTime} ， {$lastModifiedUser} 在 {$updatedTime} 修改了该文档。\n\n";
        $raw = "# {$title}\n\n{$intro}暂不支持该类型的文档。";
        $html = (new \Parsedown())->text($raw);
        return "<div class='markdown-body wz-markdown-style-fix wz-pdf-content'>{$html}</div>";
    }

    /**
     * 构建 Mpdf 实例。
     */
    private function makeMpdf(string $title, string $author): Mpdf
    {
        $mpdf = new Mpdf([
            'mode'              => 'utf-8',
            'tempDir'           => sys_get_temp_dir() . '/wizard/',
            'defaultfooterline' => false,
            'useSubstitutions'  => true,
            'backupSubsFont'    => ['dejavusanscondensed', 'arialunicodems', 'sun-exta'],
        ]);

        $mpdf->allow_charset_conversion = true;
        $mpdf->useAdobeCJK = true;
        $mpdf->autoLangToFont = true;
        $mpdf->autoScriptToLang = true;
        $mpdf->author = $author ?: 'wizard';

        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->SetTitle($title);

        $header = '<link href="/assets/css/normalize.css" rel="stylesheet">';
        $header .= '<link href="/assets/vendor/editor-md/css/editormd.preview.css" rel="stylesheet"/>';
        $header .= '<link href="/assets/vendor/markdown-body.css" rel="stylesheet">';
        $header .= '<link href="/assets/css/style.css" rel="stylesheet">';
        $header .= '<link href="/assets/css/pdf.css" rel="stylesheet">';
        $mpdf->WriteHTML($header);

        return $mpdf;
    }

    /**
     * 委托全局 traverseNavigators()（保持与原有递归行为一致）。
     */
    private function traverseNavigators(array $navigators, \Closure $callback, array $parents = []): void
    {
        traverseNavigators($navigators, $callback, $parents);
    }

    /**
     * 生成临时文件路径（不创建文件）。
     */
    private function makeTempFile(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'wizard_export_');
        if ($base === false) {
            throw new \RuntimeException('Cannot allocate temp file');
        }

        // tempnam 返回无后缀路径，按需补扩展名
        $path = $base . '.' . ltrim($extension, '.');
        // 若已存在同名（极端情况下），先清理
        if (file_exists($path)) {
            @unlink($path);
        }

        return $path;
    }

    /**
     * 把一段字符串写入临时文件，返回该临时文件路径。
     */
    private function writeTempContent(string $content, string $extension): string
    {
        $path = $this->makeTempFile($extension);
        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException("Cannot write temp content: {$path}");
        }

        return $path;
    }
}
