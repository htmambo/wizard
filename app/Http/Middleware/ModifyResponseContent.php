<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

class ModifyResponseContent
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // 只处理 Scramble 路由
        if (!$this->isScrambleRoute($request)) {
            return $response;
        }

        if ($response->headers->get('content-type') === 'text/html; charset=UTF-8') {
            $content = $response->getContent();

            // 修改内容
            $modifiedContent = $this->processContent($content);

            $response->setContent($modifiedContent);
        }

        return $response;
    }

    protected function isScrambleRoute(Request $request)
    {
        $currentRoute = Route::current();

        if (!$currentRoute) {
            Log::warning('No current route found for request', ['request' => $request->all()]);
            return false;
        }

        // 检查路由名称
        $routeName = $currentRoute->getName();
        if (!$routeName) {
            Log::warning('No route name found for current route', ['route' => $currentRoute]);
            return false;
        }
        if ($routeName && str_contains($routeName, 'scramble')) {
            return true;
        }

        // 检查控制器类名
        $action = $currentRoute->getAction();
        if (isset($action['controller']) && str_contains($action['controller'], 'Scramble')) {
            return true;
        }

        // 检查中间件（Scramble 可能有特定的中间件）
        $middleware = $currentRoute->gatherMiddleware();
        foreach ($middleware as $middlewareName) {
            if (str_contains($middlewareName, 'scramble')) {
                return true;
            }
        }

        return false;
    }

    protected function processContent($content)
    {

        // 添加自定义脚本
        $customScript = '<script>
            document.addEventListener("DOMContentLoaded", function() {
                console.log("Scramble page loaded and modified");
                // 自定义 JavaScript 逻辑
            });
        </script>';

        $content = str_replace('</body>', $customScript . '</body>', $content);

        // 修改页面标题
        $content = preg_replace('/<title>(\s*)<\/title>/', '<title>' . config('app.name') . ' API 文档</title>', $content);

        // 替换掉 unpkg 的链接
        // 注意：这里假设你已经将相关的 JS 和 CSS 文件放在了 public/assets 目录下
        // 如果没有，请先将这些文件下载并放置在 public/assets 目录下
        $search = [
            'https://unpkg.com/@stoplight/elements@8.3.4/web-components.min.js',
            'https://unpkg.com/@stoplight/elements@8.3.4/styles.min.css',
        ];
        $replace = [
            asset('/assets/js/apidoc-web-components.min.js'),
            asset('/assets/css/apidoc-styles.min.css'),
        ];
        return str_replace($search, $replace, $content);
    }

}