<?php
namespace App\Http\Api;

use App\Repositories\Catalog;
use Dedoc\Scramble\Attributes\Group;

#[Group('目录','目录相关API',1)]
class CatalogController extends ApiController
{
    /**
     * 获取目录列表
     *
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