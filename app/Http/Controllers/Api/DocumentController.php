<?php
namespace App\Http\Controllers\Api;

use Illuminate\Validation\ValidationException;
use Readability\Readability;
use App\Events\DocumentCreated;
use App\Events\DocumentDeleted;
use App\Events\DocumentMarkModified;
use App\Events\DocumentModified;
use App\Policies\ProjectPolicy;
use App\Repositories\Document;
use App\Repositories\DocumentHistory;
use App\Repositories\DocumentScore;
use App\Repositories\PageShare;
use App\Repositories\Project;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SoapBox\Formatter\Formatter;
use Dedoc\Scramble\Attributes\Group;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * 文档相关API
 *
 * @package App\Http\Api
 */
#[Group('文档相关', '文档相关API', 4)]
class DocumentController extends Controller
{

    /**
     * 创建或更新文档
     *
     * @param Request $request
     *
     * @return JsonResponse
     * @throws ValidationException
     */
    public function create(Request $request){
        $this->validate($request, [
            // 标题
            'title' => 'required|string|max:255',
            // 内容
            'content' => 'required|string',
            // 来源网址
            'url' => 'required|url',
            // 格式，raw或markdown
            'format' => 'in:html,markdown',
            // 'project_id' => 'required|integer|exists:projects,id',
        ]);
        $url = $request->input('url');
        $content = $request->input('content');
        $readability = new Readability($content, $url, 'libxml', false);
        $result = $readability->init();

        if ($result) {
            $content = $readability->getContent()->innerHTML;
        }
        // 将内容转换为Markdown格式
        if ($request->input('format', 'html') === 'html') {
            $converter = new HtmlConverter();
            $content = $converter->convert($content);
        }

        // 根据project_id、sync_url来检查是否已经存在该文档
        $document = Document::where('sync_url', $url)
            ->where('project_id', 1) // 假设项目ID为1
            ->first();
        if ($document) {
            // 如果文档已存在，更新内容和标题
            $document->title = $request->input('title');
            $document->content = $content;
            $document->updated_at = Carbon::now();
        } else {
            $document = new Document();
            $document->title = $request->input('title');
            $document->content = $content;
            $document->project_id = 1;
            $document->type = Document::TYPE_DOC; // 默认类型为HTML
            $document->user_id = Auth::id();
            $document->created_at = Carbon::now();
            $document->updated_at = Carbon::now();
            $document->status = Document::STATUS_NORMAL;
            $document->sync_url = $url ?: null;
        }
        if($url) {
            $document->last_sync_at = Carbon::now();
        }
        $document->save();
        DocumentHistory::write($document);

        // 触发文档创建事件
        event(new DocumentCreated($document));
        $result = [
            'id' => $document->id,
            'is_starred' => false,
            'is_archived' => false,
            'title' => $document->title,
            'url' => '/project/1?p=' . $document->id,
            'tags' => [],
            'domain_name' => parse_url($document->url, PHP_URL_HOST),
            'preview_picture' => null,
        ];
        return $this->success($result);
    }

    /**
     * 获取文档详情
     *
     * @param Request $request
     * @param int     $id
     *
     * @return JsonResponse
     * @throws CommonMarkException
     */
    public function view(Request $request, $id){
        $document = Document::find($id);
        if (!$document) {
            return $this->error('Document not found', 404);
        }

        // 检查权限
        if (!Auth::user()->can('project-view', $document->project)) {
            return $this->error('Unauthorized', 403);
        }
        if($document->isMarkdown() && $request->input('format', 'raw') === 'html') {
            $parser = new CommonMarkConverter([
                                                  'html_input' => 'strip',
                                                  'allow_unsafe_links' => false,
                                              ]);
            $document->content = $parser->convert($document->content)->getContent();
        } elseif ($document->isSwagger() && $request->input('format', 'raw') === 'json') {
            // 如果是Swagger文档，并且请求格式为JSON，则转换为JSON
            $document->content = Formatter::make($document->content, Formatter::JSON)->toArray();
        } elseif ($document->isTable() && $request->input('format', 'raw') === 'csv') {
            // 如果是表格文档，并且请求格式为CSV，则转换为CSV
            $document->content = Formatter::make($document->content, Formatter::CSV)->toArray();
        // } elseif ($document->isFlow() && $request->input('format', 'raw') === 'xml') {
        //     // 如果是流程图文档，并且请求格式为XML，则转换为XML
        //     $document->content = Formatter::make($document->content, Formatter::XML)->toArray();
        }
        $document = [
            'id' => $document->id,
            'title' => $document->title,
            'content' => $document->content,
            'created_at' => $document->created_at->toIso8601String(),
            'updated_at' => $document->updated_at->toIso8601String(),
        ];
        return $this->success($document);
    }
}