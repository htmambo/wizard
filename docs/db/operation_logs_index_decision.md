# wz_operation_logs 索引优化决策文档

**Status**: ⏳ 待 DBA 评估 (2026-06-28)

## 1. 背景

Wizard 系统的操作日志表 `wz_operation_logs` 当前使用 MySQL **ARCHIVE 引擎**。ARCHIVE 引擎是 MySQL 5.0+ 提供的只支持 INSERT/SELECT 的归档引擎，**不支持索引、UPDATE、DELETE**。

当前存在的问题：
- 高频查询（按用户、项目、页面、时间范围）全表扫描
- 数据量增长后查询性能急剧下降
- 当前迁移文件 `2026_06_28_000001_add_operation_logs_index_hint.php` 已删除（是占位文件，未真正执行 DDL）

## 2. 决策

**本次加固不修改 wz_operation_logs 表结构**，决策权下放 DBA 评估。理由：
- 改引擎涉及 ALTER TABLE 大表操作，生产风险高
- ARCHIVE 引擎的特性导致即使是"先备份再迁移"也需要评估停机窗口
- 项目当前无任何监控/告警，盲目操作风险不可控

## 3. 考虑的替代方案

### 方案 A：改为 InnoDB + 添加复合索引（推荐）

适用场景：DBA 评估有可接受的 ALTER TABLE 窗口。

**步骤**：
1. `ALTER TABLE wz_operation_logs ENGINE=InnoDB;`（ALTER TABLE 期间表加写锁）
2. 添加复合索引：
   ```sql
   ALTER TABLE wz_operation_logs
       ADD INDEX idx_user_created (user_id, created_at),
       ADD INDEX idx_project_created (project_id, created_at),
       ADD INDEX idx_page_created (page_id, created_at),
       ADD INDEX idx_created (created_at);
   ```

**优点**：实现简单，所有现有查询自动走索引
**缺点**：大表 ALTER 期间不可写；ARCHIVE → InnoDB 磁盘占用大幅增加（InnoDB 有事务日志）

### 方案 B：冷热数据分离

适用场景：历史数据查询需求低，主要查询近 30 天。

**步骤**：
1. 新建 `wz_operation_logs_hot` 表（InnoDB，存近 30 天）
2. 新建 `wz_operation_logs_cold` 表（保留 ARCHIVE 或迁至对象存储）
3. 应用层写入 hot 表；定时任务迁移 cold 表
4. 查询时 UNION ALL 跨表

**优点**：热数据查询快；冷数据保留 ARCHIVE 的压缩优势
**缺点**：应用层需改造；维护成本高

### 方案 C：引入独立审计服务（ELK/Loki）

适用场景：操作日志主要需求是审计而非查询统计。

**步骤**：
1. 部署 ELK 或 Loki
2. 应用层异步写入审计服务，MySQL 仅保留最近 7-30 天数据
3. 跨天查询走审计服务 API

**优点**：审计能力扩展；MySQL 减负
**缺点**：引入新基础设施；运维成本高

## 4. 影响

- **本次加固不影响** wz_operation_logs 表
- **本次加固不修改** 任何 DDL
- **生产环境无需停机**

## 5. 回滚方式

- 本次未执行任何 DDL，**无需回滚**
- 若 DBA 后续采纳方案 A 并执行 ALTER TABLE，回滚方式：
  - `ALTER TABLE wz_operation_logs ENGINE=ARCHIVE;`（需确保无索引依赖）
  - 极端情况下从备份恢复

## 6. 关联工单

- 待 DBA 评估后填入工单号
- 当前 Owner：待指派
- 评估截止日期：待定

---

**附录**：原占位 migration 文件 `2026_06_28_000001_add_operation_logs_index_hint.php` 已删除。如该 migration 已在生产环境执行过（即 `wz_migrations` 表中存在该记录），需执行：
```sql
DELETE FROM wz_migrations
WHERE migration = '2026_06_28_000001_add_operation_logs_index_hint';
```