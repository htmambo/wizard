<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dashboard SQL 性能优化索引。
 *
 * 现有索引:PRIMARY(id), wz_pages_project_id_index(project_id)
 * DashboardController 涉及查询:
 *   - Document::groupBy('type') → 需 scan + group
 *   - Document::groupBy(date_format(created_at, '%Y-%m')) → 全表扫 + filesort
 * 新增索引:
 *   - (type, created_at): 加速 type 聚合 + 按月分组
 *   - (created_at): 单独按 created_at 范围扫描(Dashboard 限定 10 个月)
 */
class AddPagesCreatedAtTypeIdx extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->index(['type', 'created_at'], 'wz_pages_type_created_at_idx');
            $table->index('created_at', 'wz_pages_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex('wz_pages_type_created_at_idx');
            $table->dropIndex('wz_pages_created_at_idx');
        });
    }
}
