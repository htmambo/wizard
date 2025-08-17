<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Controllers;

use App\Policies\ProjectPolicy;
use App\Repositories\Document;
use App\Repositories\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use Gotenberg\Gotenberg;
use Gotenberg\Exceptions\GotenbergApiErrored;

class ExportController extends Controller
{
    /**
     * 直接将内容导出为下载文件
     *
     * @param Request $request
     * @param         $filename
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function download(Request $request, $filename)
    {
        return response()->streamDownload(function () use ($request) {
            $url = rtrim(config('app.url', '/'));
            echo preg_replace('/!\[(.*?)]\(\/storage\/(.*?).(jpg|png|jpeg|gif)(.*?)\)/',
                '![$1](' . $url . '/storage/$2.$3$4)', $request->input('content'));
        }, $filename);
    }

    /**
     * 将HTML转换为PDF文档
     *
     * @param Request $request
     * @param         $type
     *
     * @throws \Mpdf\MpdfException
     */
    public function pdf(Request $request, $type)
    {
        $content = $request->input('html');
        $title   = $request->input('title');
        $author  = $request->input('author');
        $tags    = $request->input('tags');

        // 修正 Docker 运行模式下，导出pdf图片无法展示的问题
        $imageRoot = rtrim(config('filesystems.disks.public.root'), '/');
        $content   = preg_replace('/src\s?=\s?"\/storage\/(.*?).(jpg|png|gif|jpeg)"/', "src=\"{$imageRoot}/$1.$2\"", $content);

        $mpdf = new Mpdf([
            'mode'             => 'utf-8',
            'tempDir'          => sys_get_temp_dir() . '/wizard/',
            'useSubstitutions' => true,
            'backupSubsFont'   => ['dejavusanscondensed', 'arialunicodems', 'sun-exta'],
        ]);
        $mpdf->SetHeader($title);
        $mpdf->SetFooter('{PAGENO} / {nbpg}');
        $mpdf->SetTitle($title);
        $mpdf->SetCreator(config('app.name') ?: 'Wizard');
        $mpdf->SetSubject($title);
        if($tags) {
            $mpdf->SetKeywords($tags);
        } else {
            $mpdf->SetKeywords('wizard, documentation, pdf');
        }

        $mpdf->allow_charset_conversion = true;
        $mpdf->useAdobeCJK              = true;
        $mpdf->autoLangToFont           = true;
        $mpdf->autoScriptToLang         = true;
        $mpdf->author                   = $author ?? \Auth::user()->name ?? 'wizard';

        $header = '<link href="/assets/css/normalize.css" rel="stylesheet">';
        switch ($type) {
            case 'html':
            case 'markdown':
                $header .= '<link href="/assets/vendor/editor-md/css/editormd.preview.css" rel="stylesheet"/>';
                $header .= '<link href="/assets/vendor/markdown-body.css" rel="stylesheet">';
                /**
                 * 修复一下有可能会出现的导出错误
                 */
                $content = preg_replace('@li\s+class=[\'"]L[^>]+>@i', 'li>', $content);
                $content = preg_replace('@<code[^>]*>@i', '', $content);
                $content = preg_replace('@</code[^>]*>@i', '', $content);
                break;
            case 'swagger':
                $header .= '<link href="/assets/vendor/swagger-ui/swagger-ui.css" rel="stylesheet">';
                break;
        }

        $header .= '<link href="/assets/css/style.css" rel="stylesheet">';
        $header .= '<link href="/assets/css/pdf.css" rel="stylesheet">';
        $mpdf->WriteHTML($header);

        $html = "<div class='markdown-body wz-markdown-style-fix'>{$content}</div>";
        $mpdf->Bookmark($title, 0);
        try {
            $pages = explode('<hr style="page-break-after:always;" class="page-break editormd-page-break">', $html);
            foreach ($pages as $index => $page) {
                if ($index>0) {
                    $mpdf->AddPage();
                }
                $mpdf->WriteHTML($page);
            }
        } catch (\Exception $ex) {
            Log::error('html_to_pdf_failed', [
                'error' => $ex->getMessage(),
                'code'  => $ex->getCode(),
                'doc'   => [
                    'content' => $html,
                ]
            ]);
            $str = '';
            if(config('app.debug')) {
                $str = '<p>' . $ex->getTraceAsString() . '</p>';
            }
            $mpdf->WriteHTML('<p class="pdf-error">部分文档生成失败：' . $ex->getMessage() . '</p>' . $str);
        }

        $mpdf->Output($title . '.pdf', 'I');
    }

    /**
     * 使用Gotenberg导出PDF
     *
     * @param Request $request
     * @param $id
     * @param $page_id
     *
     * @return mixed|string
     */
    public function gotenberg(Request $request, $id, $page_id)
    {
        $gotenbergUrl = trim(config('wizard.gotenberg_url'));
        if(!$gotenbergUrl) {
            abort(403, '未配置Gotenberg服务地址');
        }
        /** @var Project $project */
        $project = Project::query()->findOrFail($id);
        $policy  = new ProjectPolicy();
        if(!$policy->view(\Auth::user(), $project)) {
            abort(403, '您没有访问该项目的权限');
        }
        $token = genReadToken($id, $page_id);
        $url = wzRoute('project:doc:read', ['id' => $id, 'page_id' => $page_id, 'token' => $token, 'topdf' => 1]);
        $url = rtrim(config('app.url'), '/') . $url;
        // $url = 'https://doc.imzhp.com' . $url; // TODO: 临时使用外网地址，后续需要改为内网地址
        // 获取页面标题
        $doc = Document::query()->where('project_id', $id)->where('id', $page_id)->firstOrFail();
        $tags = $doc->tags()->pluck('name')->toArray();
        $tags = implode(',', $tags);
        $title = $doc->title;
        $title = preg_replace('/[^\p{L}\p{N}\-]+/u', '', $title);
        // 移除连续的破折号
        $title = preg_replace('/-+/', '-', $title);
        // 移除首尾的破折号
        $title = trim($title, '-');
        // 如果标题为空，则使用页面ID作为标题
        if(empty($title)) {
            $title = 'page-'. $page_id;
        }
        // 限制标题长度
        $title = mb_substr($title, 0, 100, 'utf-8');
        $metadata = [
            'Title' => $title,
            'author' => $doc->user->name,
            'subject' => $title,
            'Producer' => 'Gotenberg PHP Client',
            'creator' => config('app.name') ?: 'Wizard',
            'CreateDate' => $doc->created_at->format('Y-m-d H:i:s'),
            'ModifyDate' => $doc->updated_at->format('Y-m-d H:i:s')
        ];
        if($tags) {
            $metadata['keywords'] = $tags;
        }
        try {
            // 调用Gotenberg导出PDF
            return Gotenberg::send(
                Gotenberg::chromium($gotenbergUrl)
                    ->pdf()
                    ->metadata($metadata)
                    ->outputFilename($title)
                    ->url($url)
            );
        } catch (GotenbergApiErrored $e) {
            return $e->getResponse();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
