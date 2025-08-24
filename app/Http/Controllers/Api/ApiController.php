<?php
/**
 * 基础接口
 */

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Dedoc\Scramble\Attributes\Group;
use App\Repositories\Document;
use App\Repositories\Tag;
use App\Repositories\PageTag;


#[Group('基础接口', 'API相关接口', 1)]
class ApiController extends Controller
{

    public function tags(Request $request)
    {
        // 使用 LEFT JOIN 和 COUNT 合并查询，避免 N+1 查询问题
        $tags = Tag::query()
            ->select('id', 'name')
            ->get()
            ->toArray();
        return response()->json($tags);
    }

    /**
     * 获取版本信息
     * @unauthenticated
     */
    public function version(Request $request){
        return $this->success([
            'version' => config('wizard.version', '1.0.0'),
            'api_version' => config('wizard.api_version', '1.0.0'),
            'name'    => config('app.name', 'Wizard'),
            'env'     => config('app.env', 'production'),
            'debug'   => config('app.debug', false),
            'url'     => config('app.url', 'https://wzard.com'),
            'timezone' => config('app.timezone', 'UTC'),
            'PHP_VERSION' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ]);
    }

    /**
     * 搜索功能
     */
    public function Search(Request $request){
        $query = $request->get('q', '');
        $type  = $request->get('type', 'all'); // all, project, document
        $limit = min($request->get('limit', 20), 100);

        if (empty($query)) {
            return $this->error('Search query is required', 400);
        }

        try {
            $results = [];
            if ($type === 'all' || $type === 'document') {
                $documents = Document::where('title', 'like', "%{$query}%")
                                     ->orWhere('content', 'like', "%{$query}%")
                                     ->limit($limit)
                                     ->get(['id', 'title', 'project_id', 'created_at']);

                $results['documents'] = $documents;
            }
            return $this->success($results, 'Search results', [
                'query' => $query,
                'type'  => $type,
                'limit' => $limit,
            ]);
        } catch (\Exception $e) {
            return $this->error(config('app.debug')?$e->getMessage():'Search failed', 500);
        }
    }
}
