<?php
namespace App\Http\Api;

use App\Http\Controllers\ApiController;
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
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Exception\CommonMarkException;
use SoapBox\Formatter\Formatter;

class DocumentController extends ApiController
{

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
            return response()->json(['error' => 'Document not found'], 404);
        }

        // 检查权限
        if (!Auth::user()->can('project-view', $document->project)) {
            return response()->json(['error' => 'Unauthorized'], 403);
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

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'content' => $document->content,
            'created_at' => $document->created_at->toIso8601String(),
            'updated_at' => $document->updated_at->toIso8601String(),
        ]);
    }
}