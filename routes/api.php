
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Api\ApiController;
use App\Http\Api\DocumentController;
use App\Http\Api\CatalogController;
use App\Http\Api\UserController;
use App\Http\Api\ProjectController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// 需要认证的用户信息路由（保持原有的）
Route::middleware('auth:api')
     ->get('/user', function (Request $request) {
         return $request->user();
     });

// 需要认证的API路由组
Route::middleware('auth:api')->group(function () {
    // 用户相关，控制器：Api\UserController
    Route::prefix('user')->group(function () {
        // 获取用户信息
        Route::get('info', [UserController::class, 'info']);
        // 更新用户信息
        Route::put('update', [UserController::class, 'update']);
        // 修改密码
        Route::put('password', [UserController::class, 'changePassword']);
        // 获取用户列表
        Route::get('lists', [UserController::class, 'lists']);
        // 创建用户
        Route::post('create', [UserController::class, 'create']);
        // 更新用户
        Route::put('update/{id}', [UserController::class, 'update']);
        // 删除用户
        Route::delete('delete/{id}', [UserController::class, 'delete']);
        // 获取用户操作日志
        Route::get('logs', [UserController::class, 'logs']);
    });

    // 目录相关，控制器：Api\CatalogController
    Route::prefix('catalog')->group(function () {
        // 目录列表
        Route::get('lists', [CatalogController::class, 'lists']);
        // 目录详情
        Route::get('{id}', [CatalogController::class, 'view']);
        // 创建目录
        Route::post('create', [CatalogController::class, 'create']);
        // 更新目录
        Route::put('update/{id}', [CatalogController::class, 'update']);
        // 删除目录
        Route::delete('delete/{id}', [CatalogController::class, 'delete']);
    });

    // 项目相关，控制器：Api\ProjectController
    Route::prefix('project')->group(function () {
        // 项目详情
        Route::get('{id}', [ProjectController::class, 'view']);
        // 项目列表
        Route::get('lists', [ProjectController::class, 'lists']);
        // 项目创建
        Route::post('create', [ProjectController::class, 'create']);
        // 项目更新
        Route::put('update/{id}', [ProjectController::class, 'update']);
        // 项目删除
        Route::delete('delete/{id}', [ProjectController::class, 'delete']);
        // 项目成员列表
        Route::get('{id}/members', [ProjectController::class, 'members']);
        // 添加项目成员
        Route::post('{id}/members/add', [ProjectController::class, 'addMember']);
        // 删除项目成员
        Route::delete('{id}/members/delete/{memberId}', [ProjectController::class, 'deleteMember']);
        // 项目操作日志
        Route::get('{id}/logs', [ProjectController::class, 'logs']);
    });

    // 文档管理，控制器：Api\DocumentController
    Route::prefix('document')->group(function () {
        // 文档详情
        Route::get('{id}', [DocumentController::class, 'view']);
        // 文档列表
        Route::get('lists', [DocumentController::class, 'lists']);
        // 文档创建
        Route::post('create', [DocumentController::class, 'create']);
        // 文档更新
        Route::put('update/{id}', [DocumentController::class, 'update']);
        // 文档删除
        Route::delete('delete/{id}', [DocumentController::class, 'delete']);
    });
});
// 获取API Token
Route::post('token', [ApiController::class, 'token']);
// 获取版本信息
Route::get('version', [ApiController::class, 'version']);
// 搜索
Route::get('search', [ApiController::class, 'search']);