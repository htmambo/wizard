<?php
/**
 * Wizard
 *
 * @link      https://aicode.cc/
 * @copyright 管宜尧 <mylxsw@aicode.cc>
 *
 * 提示性 Migration：wz_operation_logs 当前使用 ARCHIVE 引擎，
 * ARCHIVE 引擎不支持索引与复杂查询，高频场景下查询性能不佳。
 *
 * 建议（按推荐顺序）：
 *   1) 将 wz_operation_logs 引擎改为 InnoDB，添加复合索引：
 *        (user_id, created_at)
 *        (project_id, created_at)
 *        (page_id, created_at)
 *        (created_at)
 *   2) 拆分：近 30 天数据存 InnoDB 热表，历史数据归档至 ARCHIVE 冷表。
 *   3) 引入独立审计服务（如 ELK），MySQL 仅保留最近数据。
 *
 * 本 migration 留空 — 由 DBA 评估后单独 PR 处理，避免破坏现有生产数据。
 *
 * ⚠️ 如果 DBA 决定添加索引，请在新的 migration 文件中执行（不要修改本文件）：
 *
 *   Schema::table('wz_operation_logs', function (Blueprint $table) {
 *       // 注意：ARCHIVE 引擎不支持索引！需先 ALTER TABLE wz_operation_logs ENGINE=InnoDB
 *       $table->index(['user_id', 'created_at']);
 *       $table->index(['project_id', 'created_at']);
 *       $table->index(['page_id', 'created_at']);
 *       $table->index('created_at');
 *   });
 *
 * 本文件作为索引决策的"提醒占位"，不参与 migrate 命令。
 */

use Illuminate\Database\Migrations\Migration;

class AddOperationLogsIndexHint extends Migration
{
    public function up(): void
    {
        // intentionally empty — see file header
    }

    public function down(): void
    {
        // intentionally empty
    }
}