<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\OAuthController;

// OAuth 相关路由 (无需认证)
Route::prefix('oauth')->group(function () {
    Route::post('token', [OAuthController::class, 'token']);
});
// 使用 Passport 内置的令牌路由
// Route::post('oauth/token', '\Laravel\Passport\Http\Controllers\AccessTokenController@issueToken');

// 需要认证的API路由组
Route::middleware('auth:api')->group(function () {
    // 用户信息
    Route::get('user', [OAuthController::class, 'user']);
    Route::post('oauth/revoke', [OAuthController::class, 'revoke']);

    // 项目相关，控制器：Api\ProjectController
    Route::prefix('project')->group(function () {
        // 项目详情
        Route::get('{id}', [ProjectController::class, 'view']);
        // 项目列表
        Route::get('lists.{format}', [ProjectController::class, 'lists']);
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
        // 文档列表
        Route::get('lists', [DocumentController::class, 'lists']);
        // 文档详情
        Route::get('{id}.{format}', [DocumentController::class, 'view']);
        // 文档更新
        Route::patch('{id}.{format}', [DocumentController::class, 'update']);
        // 文档删除
        Route::delete('{id}.{format}', [DocumentController::class, 'delete']);
        // 删除文档标签
        Route::delete('{id}/tags/{tag}.{format}', [DocumentController::class, 'deleteTag']);
        // 文档是否存在
        Route::get('exists.json', [DocumentController::class, 'exists']);
    });
    // 文档创建
    Route::post('document.{format}', [DocumentController::class, 'create']);
    // 搜索
    Route::get('search', [ApiController::class, 'search']);
    // 标签
    Route::get('tags.{format}', [ApiController::class, 'tags']);
});
// 获取版本信息
Route::get('version', [ApiController::class, 'version']);
