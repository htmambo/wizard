<?php
namespace App\Http\Api;

use App\Http\Controllers\ApiController;
use App\Repositories\Catalog;

class CatalogController extends ApiController
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * 获取目录列表
     *
     * @param int $projectId
     * @return \Illuminate\Http\JsonResponse
     */
    public function lists(){
        try {
            $catalogs = Catalog::select('id', 'name', 'description', 'created_at')
                   ->orderBy('name')
                   ->get();
            if ($catalogs->isEmpty()) {
                return $this->error('No catalogs found', 404);
            }
            return $this->success($catalogs);
        } catch (\Exception $e) {
            // 捕获异常并返回错误信息
            return $this->error('Failed to fetch catalogs', 500);
        }
    }
}