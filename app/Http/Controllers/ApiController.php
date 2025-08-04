<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 */

namespace App\Http\Controllers;


use App\Repositories\Catalog;
use App\Repositories\OperationLogs;
use App\Repositories\Project;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    public function handleRequest($path, Request $request)
    {
        // 根据$path动态调用不同的方法
        $method = $this->getMethodFromPath($path);
        if (method_exists($this, $method)) {
            return $this->$method($request);
        }
        return response()->json(['error' => 'Method not found'], 404);
    }

    protected function getMethodFromPath($path)
    {
        // 将路径转换为方法名
        return str_replace('/', '', ucwords($path, '/'));
    }

    private function getVersion(Request $request)
    {
        return response()->json(['version' => '1.0.0']);
    }

}