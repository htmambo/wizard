<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProjectController;
use App\Repositories\Document;

// 需要认证的API路由组
Route::middleware('auth:api')->group(function () {
    // 用户相关，控制器：Api\UserController
    Route::prefix('user')->group(function () {
        // 获取用户信息
        Route::get('profile', [UserController::class, 'profile']);
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
        // 文档列表
        Route::get('lists', [DocumentController::class, 'lists']);
        // 文档详情
        Route::get('{id}.{format}', [DocumentController::class, 'view']);
        // 文档更新
        Route::patch('{id}.{format}', [DocumentController::class, 'update']);
        // 文档删除
        Route::delete('{id}.{format}', [DocumentController::class, 'delete']);
        // 文档是否存在
        Route::get('exists.json', [DocumentController::class, 'exists']);
    });
    // 文档创建
    Route::post('document.{format}', [DocumentController::class, 'create']);
});
// 获取API Token
Route::post('token', [ApiController::class, 'token']);
// 获取版本信息
Route::get('version', [ApiController::class, 'version']);
// 搜索
Route::get('search', [ApiController::class, 'search']);
// 标签
Route::get('tags.{format}', [ApiController::class, 'tags']);
