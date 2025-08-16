
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiController;
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
        Route::any('{path}', [UserController::class, 'handleRequest'])
             ->where('path', '.*');
    });

    // 目录相关，控制器：Api\CatalogController
    Route::prefix('catalog')->group(function () {
        Route::any('{path}', [CatalogController::class, 'handleRequest'])
             ->where('path', '.*');
    });

    // 项目相关，控制器：Api\ProjectController
    Route::prefix('project')->group(function () {
        Route::get('{id}', [ProjectController::class, 'documents']);
        Route::any('{path}', [ProjectController::class, 'handleRequest'])
             ->where('path', '.*');
    });

    // 文档管理，控制器：Api\DocumentController
    Route::prefix('document')->group(function () {
        // 文档详情
        Route::get('{id}', [DocumentController::class, 'view']);
        Route::any('{path}', [DocumentController::class, 'handleRequest'])
             ->where('path', '.*');
    });

    // 操作日志
    Route::get('operation/logs', [ApiController::class, 'handleRequest'])->defaults('path', 'operation/logs');
});

// 动态路由处理器（作为后备选项）
Route::any('{path}', [ApiController::class, 'handleRequest'])
     ->where('path', '.*');
