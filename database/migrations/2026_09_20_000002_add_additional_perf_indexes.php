<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 补充业务高频查询索引（除 2026_09_20_000001 与 wz_operation_logs 之外的瓶颈点）。
 *
 * 现有索引基线（database/database.sqlite 实测，2026-09-20）:
 *   - pages: PRIMARY(id), pages_project_id_index(project_id),
 *            wz_pages_project_sort_idx(project_id,sort_level),
 *            wz_pages_type_created_at_idx(type,created_at),
 *            wz_pages_created_at_idx(created_at)
 *   - comments: 仅 PRIMARY(id) —— 全表扫描
 *   - page_histories: 仅 PRIMARY(id), page_histories_page_id_index(page_id)
 *   - attachments: 仅 PRIMARY(id), attachments_page_id_index(page_id)
 *   - page_share: 仅 PRIMARY(id), page_share_code_unique(code)
 *   - page_score: 仅 PRIMARY(id), idx_page_score_page_id(page_id)
 *   - projects: 仅 PRIMARY(id), wz_projects_catalog_sort_idx(catalog_id,sort_level)
 *
 * 本次新增索引（与 wz_operation_logs 无关 —— 该表已由 DBA 决策本次不动）:
 *
 *   1. wz_pages_sync_url_idx                pages(sync_url)
 *      业务路径:
 *        - App\Repositories\Document::exists()                       sync_url 唯一性预检
 *        - App\Http\Controllers\Api\DocumentController::create()    抓取入库前 sync_url 去重
 *
 *   2. wz_pages_user_id_updated_at_idx      pages(user_id, updated_at)
 *      业务路径:
 *        - App\Http\Controllers\SearchController::search()           range=my 时按用户筛 + 按 updated_at 倒序
 *
 *   3. wz_pages_is_blog_updated_at_idx      pages(is_blog, updated_at)
 *      业务路径:
 *        - App\Http\Controllers\BlogController::home()               博客首页 is_blog=1 + updated_at DESC
 *        - App\Http\Controllers\BlogController::getRecentBlogPosts() 最新博客 is_blog=1 + updated_at DESC
 *        - App\Http\Controllers\BlogController::post()               文章详情 is_blog=1
 *
 *   4. wz_comments_page_id_created_at_idx   comments(page_id, created_at)
 *      业务路径:
 *        - App\Services\CommentService::listForDocument()            文档下评论分页
 *        - App\Http\Controllers\ProjectController::project()         评论按 created_at desc 预加载
 *
 *   5. wz_comments_user_id_created_at_idx   comments(user_id, created_at)
 *      业务路径:
 *        - App\Services\CommentService::listByUser()                 "我发表的评论"分页
 *
 *   6. wz_page_histories_page_id_created_at_idx  page_histories(page_id, created_at)
 *      业务路径:
 *        - App\Http\Controllers\HistoryController::pages()           文档历史分页
 *
 *   7. wz_attachments_page_id_created_at_idx      attachments(page_id, created_at)
 *      业务路径:
 *        - App\Http\Controllers\AttachmentController::page()         文档附件列表
 *
 *   8. wz_page_share_project_id_page_id_idx       page_share(project_id, page_id)
 *      业务路径:
 *        - App\Http\Controllers\ShareController::create()            创建分享（按 project+page 查重）
 *        - App\Http\Controllers\ShareController::delete()            取消分享
 *        - App\Http\Controllers\ProjectController::project()         渲染当前页分享信息
 *
 *   9. wz_page_share_page_id_idx                  page_share(page_id)
 *      业务路径:
 *        - App\Services\DocumentService::move()                     文档移动时级联更新 page_share.project_id
 *        - App\Http\Controllers\Api\DocumentController::delete()     删除前判断 page_share 是否引用
 *        - App\Listeners\DocumentDeletedListener::handle()           删除通知分发（whereHas histories）
 *
 *  10. wz_page_score_page_id_user_id_idx           page_score(page_id, user_id)
 *      业务路径:
 *        - App\Services\DocumentService::updateScore()              评分 upsert（按 page+user 定位）
 *
 *  11. wz_projects_user_id_sort_level_idx          projects(user_id, sort_level)
 *      业务路径:
 *        - App\Http\Controllers\ProjectController::home()            用户个人首页（按 user_id 筛 + sort_level 排序）
 *        - App\Http\Controllers\UserController::projectsCanWrite()   用户可写项目列表（where user_id）
 *
 * 命名约定: wz_{table}_{col1}_{col2}_idx（单列时省略第二个 _col）
 */
class AddAdditionalPerfIndexes extends Migration
{
    public function up(): void
    {
        // 1. pages.sync_url 唯一性预检
        Schema::table('pages', function (Blueprint $table) {
            $table->index('sync_url', 'wz_pages_sync_url_idx');
        });

        // 2. pages(user_id, updated_at) — 搜索 “我的文档” 倒序
        Schema::table('pages', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at'], 'wz_pages_user_id_updated_at_idx');
        });

        // 3. pages(is_blog, updated_at) — 博客列表
        Schema::table('pages', function (Blueprint $table) {
            $table->index(['is_blog', 'updated_at'], 'wz_pages_is_blog_updated_at_idx');
        });

        // 4. comments(page_id, created_at) — 文档下评论
        Schema::table('comments', function (Blueprint $table) {
            $table->index(['page_id', 'created_at'], 'wz_comments_page_id_created_at_idx');
        });

        // 5. comments(user_id, created_at) — 我的评论
        Schema::table('comments', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'wz_comments_user_id_created_at_idx');
        });

        // 6. page_histories(page_id, created_at) — 文档历史分页
        Schema::table('page_histories', function (Blueprint $table) {
            $table->index(['page_id', 'created_at'], 'wz_page_histories_page_id_created_at_idx');
        });

        // 7. attachments(page_id, created_at) — 文档附件列表
        Schema::table('attachments', function (Blueprint $table) {
            $table->index(['page_id', 'created_at'], 'wz_attachments_page_id_created_at_idx');
        });

        // 8. page_share(project_id, page_id) — 分享 CRUD
        Schema::table('page_share', function (Blueprint $table) {
            $table->index(['project_id', 'page_id'], 'wz_page_share_project_id_page_id_idx');
        });

        // 9. page_share(page_id) — 移动 / 删除 / 通知 引用查询
        Schema::table('page_share', function (Blueprint $table) {
            $table->index('page_id', 'wz_page_share_page_id_idx');
        });

        // 10. page_score(page_id, user_id) — 评分 upsert
        Schema::table('page_score', function (Blueprint $table) {
            $table->index(['page_id', 'user_id'], 'wz_page_score_page_id_user_id_idx');
        });

        // 11. projects(user_id, sort_level) — 个人项目列表
        Schema::table('projects', function (Blueprint $table) {
            $table->index(['user_id', 'sort_level'], 'wz_projects_user_id_sort_level_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex('wz_pages_sync_url_idx');
            $table->dropIndex('wz_pages_user_id_updated_at_idx');
            $table->dropIndex('wz_pages_is_blog_updated_at_idx');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropIndex('wz_comments_page_id_created_at_idx');
            $table->dropIndex('wz_comments_user_id_created_at_idx');
        });

        Schema::table('page_histories', function (Blueprint $table) {
            $table->dropIndex('wz_page_histories_page_id_created_at_idx');
        });

        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex('wz_attachments_page_id_created_at_idx');
        });

        Schema::table('page_share', function (Blueprint $table) {
            $table->dropIndex('wz_page_share_project_id_page_id_idx');
            $table->dropIndex('wz_page_share_page_id_idx');
        });

        Schema::table('page_score', function (Blueprint $table) {
            $table->dropIndex('wz_page_score_page_id_user_id_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('wz_projects_user_id_sort_level_idx');
        });
    }
}
