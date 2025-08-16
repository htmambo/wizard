<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Controllers;

use App\Repositories\Catalog;
use App\Repositories\Document;
use App\Repositories\OperationLogs;
use App\Repositories\Project;
use App\Repositories\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiController extends Controller
{
    final function success($data = [], $message = 'Success', $meta = []){
        return response()->json([
                                    'success' => true,
                                    'data'    => $data,
                                    'message' => $message,
                                    'meta'    => $meta,
                                ]);
    }

    final function error($message = 'Error', $code = 500){
        return response()->json([
                                    'success' => false,
                                    'error'   => $message,
                                ], $code);
    }
    public function handleRequest($path, Request $request){
        // 处理路径中的参数
        $path = $this->processPathParameters($path, $request);

        // 根据$path动态调用不同的方法
        $method = $this->getMethodFromPath($path);

        if (method_exists($this, $method)) {
            return $this->$method($request);
        }
        return $this->error('Method not found', 404);
    }

    protected function getMethodFromPath($path){
        // 将路径转换为方法名，支持多级路径
        $segments = explode('/', trim($path, '/'));
        $method   = '';
        foreach ($segments as $segment) {
            // 跳过参数占位符
            if (strpos($segment, '{') === false) {
                $method .= ucfirst(str_replace('-', '', $segment));
            }
        }
        return lcfirst($method);
    }

    protected function processPathParameters($path, Request $request){
        // 处理路径中的参数，如 {id}
        $route = $request->route();
        if ($route) {
            $parameters = $route->parameters();
            foreach ($parameters as $key => $value) {
                $path = str_replace('{' . $key . '}', $value, $path);
            }
        }
        return $path;
    }

    /**
     * 获取版本信息
     */
    private function Version(Request $request){
        // 返回应用的版本信息
        return $this->success([
                                    'version'         => config('wizard.version', '1.0.0'),
                                    'app_name'        => config('app.name', '果农笔记'),
                                    'laravel_version' => app()->version(),
                                    'timestamp'       => now()->toISOString(),
                                    'environment'     => app()->environment(),
                                ]);
    }

    /**
     * 搜索功能
     */
    private function Search(Request $request){
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