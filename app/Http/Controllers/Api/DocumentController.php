<?php
namespace App\Http\Controllers\Api;

use Illuminate\Validation\ValidationException;
use App\Components\Readability\Readability;
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
use App\Repositories\Tag;

/**
 * 文档相关API
 *
 * @package App\Http\Api
 */
#[Group('文档相关', '文档相关API', 4)]
class DocumentController extends Controller
{

    /**
     * 删除文档标签
     *
     * @param Request $request
     * @param         $id
     * @param         $tag_id
     *
     * @return JsonResponse
     */
    public function deletetag(Request $request, $id, $tag_id){
        $document = Document::find($id);
        if (!$document) {
            return $this->error('Document not found', 404);
        }
        // 检查权限
        if (!Auth::user()->can('project-edit', $document->project)) {
            return $this->error('Unauthorized', 403);
        }
        $tag = Tag::find($tag_id);
        if (!$tag) {
            return $this->error('Tag not found', 404);
        }
        $document->tags()->detach($tag_id);
        // 触发文档标签修改事件
        event(new DocumentMarkModified($document));
        return $this->success($this->returnDocumentInfo($document), 'Document tag deleted successfully');
    }
    /**
     * 检查文档是否存在
     *
     * @param Request $request
     *
     * @return JsonResponse
     */
    public function exists(Request $request){
        $url = $request->input('url');
        $exists = Document::exists($url);
        return response()->json(['exists' => $exists]);
    }

    /**
     * 删除文档
     *
     * @param Request $request
     * @param         $id
     *
     * @return JsonResponse
     */
    public function delete(Request $request, $id){
        $document = Document::find($id);
        if (!$document) {
            return $this->error('Document not found', 404);
        }
        // 检查权限
        if (!Auth::user()->can('project-edit', $document->project)) {
            return $this->error('Unauthorized', 403);
        }
        // 检查文档是否被引用
        $referenced = PageShare::where('page_id', $document->id)->exists();
        if ($referenced) {
            return $this->error('Document is referenced and cannot be deleted', 400);
        }
        // 执行删除操作
        $document->delete();
        // 触发文档删除事件
        event(new DocumentDeleted($document));
        return $this->success($document->toArray());
    }

    /**
     * 更新文档
     *
     * @param Request $request
     * @param         $id
     *
     * @return JsonResponse
     */
    public function update(Request $request, $id){
        $document = Document::find($id);
        if (!$document) {
            return $this->error('Document not found', 404);
        }

        // 检查权限
        if (!Auth::user()->can('project-edit', $document->project)) {
            return $this->error('Unauthorized', 403);
        }
        $title = $request->input('title');
        if($title) {
            $document->title = $title;
        }
        $tags = $request->input('tags');
        if($tags) {
            $names = array_filter(array_map(function ($val) {
                return trim($val);
            }, explode(',', $request->input('tags'))), function ($val) {
                return !empty($val);
            });

            /** @var Collection $tagsExisted */
            $tagsExisted     = Tag::whereIn('name', $names)->get();
            $tagNamesExisted = array_values($tagsExisted->pluck('name')->map(function ($tag) {
                return strtolower($tag);
            })->toArray());

            $tagsNewCreated = collect($names)->filter(function ($tag) use ($tagNamesExisted) {
                return !in_array(strtolower($tag), $tagNamesExisted);
            })->map(function ($name) {
                return Tag::create(['name' => $name]);
            });

            $tags = $tagsExisted->concat($tagsNewCreated);

            $document->tags()->detach();
            $document->tags()->attach($tags->pluck('id'));
        }
        if($document->isDirty()) {
            $document->updated_at = Carbon::now();
            $document->last_sync_at = Carbon::now();
            $document->last_modified_uid = Auth::id();
            $document->save();
            DocumentHistory::write($document);

            // 触发文档更新事件
            event(new DocumentModified($document));
        }

        return $this->success($this->returnDocumentInfo($document), 'Document updated successfully');
    }

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
        $origContent = $content;
        // 将内容转换为Markdown格式
        if ($request->input('format', 'html') === 'html') {
            $converter = new HtmlConverter([
                'strip_tags' => true,
                'hard_break' => true,
                // 'preserve_comments' => true,
            ]);
            $content = $converter->convert($content);
            $content = preg_replace('/<[a-z\/][^>]*>/iu', '  ' . PHP_EOL, $content);


            // $content = preg_replace('/\s*\n\s*\n/', '  ' . PHP_EOL, $content);
        }

        // 根据project_id、sync_url来检查是否已经存在该文档
        $document = Document::where('sync_url', $url)
            ->where('project_id', 1) // 假设项目ID为1
            ->whereNull('deleted_at')
            ->first();
        if (!$document) {
            $document = new Document();
            $document->project_id = 1;
            $document->type = Document::TYPE_DOC; // 默认类型为HTML
            $document->user_id = Auth::id();
            $document->created_at = Carbon::now();
            $document->updated_at = Carbon::now();
            $document->status = Document::STATUS_NORMAL;
        }
        $document->title = $request->input('title');
        $document->content = $content;
        $document->html_code = $origContent;
        if($document->isDirty()) {
            if($url) {
                $document->last_sync_at = Carbon::now();
                $document->sync_url = $url;
            }
            $document->updated_at = Carbon::now();
            $document->last_modified_uid = Auth::id();
            $document->save();
            DocumentHistory::write($document);

            // 触发文档创建事件
            event(new DocumentCreated($document));
        }
        return $this->success($this->returnDocumentInfo($document), 'Document created or updated successfully');
    }

    private function returnDocumentInfo(Document $document){
        return [
            'id' => $document->id,
            'is_starred' => false,
            'is_archived' => false,
            'title' => $document->title,
            'url' => '/project/' . $document->project_id . '?p=' . $document->id,
            'tags' => $document->tags->select('id', 'name')->toArray(),
            'domain_name' => parse_url($document->sync_url, PHP_URL_HOST),
            'preview_picture' => null,
            'sync_url' => $document->sync_url,
        ];
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
        }
        return $this->success($document->toArray());;
    }
}