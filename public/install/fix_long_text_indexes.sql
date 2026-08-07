-- 修复长文本字段的索引问题
-- 注意：执行前请备份数据库

-- 1. 删除并重新创建 qf_admin 表的索引
ALTER TABLE `qf_admin` DROP INDEX `admin_name`;
ALTER TABLE `qf_admin` DROP INDEX `admin_password`;
ALTER TABLE `qf_admin` ADD INDEX `admin_name`(`admin_name`(191)) USING BTREE;
ALTER TABLE `qf_admin` ADD INDEX `admin_password`(`admin_password`(191)) USING BTREE;

-- 2. 删除并重新创建 qf_conf 表的索引
ALTER TABLE `qf_conf` DROP INDEX `conf_key`;
ALTER TABLE `qf_conf` ADD INDEX `conf_key`(`conf_key`(191)) USING BTREE;

-- 3. 删除并重新创建 qf_node 表的索引
ALTER TABLE `qf_node` DROP INDEX `node_module`;
ALTER TABLE `qf_node` DROP INDEX `node_controller`;
ALTER TABLE `qf_node` DROP INDEX `node_action`;
ALTER TABLE `qf_node` ADD INDEX `node_module`(`node_module`(191)) USING BTREE;
ALTER TABLE `qf_node` ADD INDEX `node_controller`(`node_controller`(191)) USING BTREE;
ALTER TABLE `qf_node` ADD INDEX `node_action`(`node_action`(191)) USING BTREE;
