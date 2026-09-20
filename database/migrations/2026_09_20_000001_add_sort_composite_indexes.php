<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 项目/文档排序复合索引。
 *
 * 高频查询场景:
 *   - 项目列表: ORDER BY catalog_id, sort_level, id（含 Web 首页与 API lists）
 *   - 项目文档列表: WHERE project_id = ? ORDER BY sort_level（DocumentController / Api）
 * 现有索引:
 *   - pages: PRIMARY(id), wz_pages_project_id_index(project_id) —— 单列索引无法覆盖排序
 *   - projects: 仅 PRIMARY(id)
 */
class AddSortCompositeIndexes extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->index(['project_id', 'sort_level'], 'wz_pages_project_sort_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->index(['catalog_id', 'sort_level'], 'wz_projects_catalog_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex('wz_pages_project_sort_idx');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex('wz_projects_catalog_sort_idx');
        });
    }
}
