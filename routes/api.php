<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\OAuthController;

/*
|--------------------------------------------------------------------------
| API Rate Limiting
|--------------------------------------------------------------------------
| 通用 throttle 由每个路由单独挂载,Kernel api 组不再带默认 throttle。
| 命名 limiter 在 AppServiceProvider::registerRateLimiters() 中定义。
*/

// OAuth 相关路由 (无需认证),按 IP 防爆破
Route::post('oauth/token', [OAuthController::class, 'token'])->middleware('throttle:oauth-token');

// 使用 Passport 内置的令牌路由 (防爆破)
// Route::post('oauth/token', '\Laravel\Passport\Http\Controllers\AccessTokenController@issueToken');

// 需要认证的 API 路由组:
// 每个路由显式挂载 throttle:<name>,便于阅读与覆盖。
//   GET    -> throttle:api-read  (120/min, 默认按 user/ip)
//   POST/PUT/PATCH/DELETE -> throttle:api-write  (30/min, 默认按 user/ip)
// 特定路由单独覆盖:
//   GET project/lists.{format}  -> throttle:api-lists  (300/min, 浏览器扩展高频)
//   GET search                  -> throttle:api-search (60/min)
Route::middleware('auth:api')->group(function () {
    // 用户信息
    Route::get('user', [OAuthController::class, 'user'])->middleware('throttle:api-read');
    Route::post('oauth/revoke', [OAuthController::class, 'revoke'])->middleware('throttle:api-write');

    // 项目相关,控制器:Api\ProjectController
    Route::prefix('project')->group(function () {
        // 项目详情
        Route::get('{id}', [ProjectController::class, 'view'])->middleware('throttle:api-read');
        // 项目列表 (浏览器扩展高频拉取,使用专用 limiter)
        Route::get('lists.{format}', [ProjectController::class, 'lists'])->middleware('throttle:api-lists');
        // 项目创建
        Route::post('create', [ProjectController::class, 'create'])->middleware('throttle:api-write');
        // 项目更新
        Route::put('update/{id}', [ProjectController::class, 'update'])->middleware('throttle:api-write');
        // 项目删除
        Route::delete('delete/{id}', [ProjectController::class, 'delete'])->middleware('throttle:api-write');
        // 项目文档列表
        Route::get('{id}/documents', [ProjectController::class, 'documents'])->middleware('throttle:api-read');
        // 项目成员列表
        Route::get('{id}/members', [ProjectController::class, 'members'])->middleware('throttle:api-read');
        // 添加项目成员
        Route::post('{id}/members/add', [ProjectController::class, 'addMember'])->middleware('throttle:api-write');
        // 删除项目成员
        Route::delete('{id}/members/delete/{memberId}', [ProjectController::class, 'deleteMember'])->middleware('throttle:api-write');
        // 项目操作日志
        Route::get('{id}/logs', [ProjectController::class, 'logs'])->middleware('throttle:api-read');
    });

    // 文档管理,控制器:Api\DocumentController
    Route::prefix('document')->group(function () {
        // 文档列表
        Route::get('lists', [DocumentController::class, 'lists'])->middleware('throttle:api-read');
        // 文档详情
        Route::get('{id}.{format}', [DocumentController::class, 'view'])->middleware('throttle:api-read');
        // 文档更新
        Route::patch('{id}.{format}', [DocumentController::class, 'update'])->middleware('throttle:api-write');
        // 文档删除
        Route::delete('{id}.{format}', [DocumentController::class, 'delete'])->middleware('throttle:api-write');
        // 删除文档标签
        Route::delete('{id}/tags/{tag}.{format}', [DocumentController::class, 'deleteTag'])->middleware('throttle:api-write');
        // 文档是否存在
        Route::get('exists.json', [DocumentController::class, 'exists'])->middleware('throttle:api-read');
    });
    // 文档创建
    Route::post('document.{format}', [DocumentController::class, 'create'])->middleware('throttle:api-write');
    // 搜索 (独立限速)
    Route::get('search', [ApiController::class, 'search'])->middleware('throttle:api-search');
    // 标签
    Route::get('tags.{format}', [ApiController::class, 'tags'])->middleware('throttle:api-read');
});

// 获取版本信息 (公开)
Route::get('version', [ApiController::class, 'version']);
