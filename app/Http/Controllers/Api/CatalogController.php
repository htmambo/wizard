<?php
namespace App\Http\Controllers\Api;

use App\Repositories\Catalog;
use Dedoc\Scramble\Attributes\Group;

#[Group('目录相关', '目录相关接口', 2)]
class CatalogController extends Controller
{
    /**
     * 获取目录列表
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function lists(){
        try {
            $catalogs = Catalog::select('id', 'name')
                   ->orderBy('sort_level', 'ASC')
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