-- 修改数据库字符集为 utf8mb4
-- 注意：执行前请备份数据库

-- 1. 修改 qf_admin 表字符集
ALTER TABLE `qf_admin` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- 2. 修改 qf_conf 表字符集
ALTER TABLE `qf_conf` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- 3. 修改 qf_feedback 表字符集
ALTER TABLE `qf_feedback` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- 4. 修改 qf_group 表字符集
ALTER TABLE `qf_group` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- 5. 修改 qf_node 表字符集
ALTER TABLE `qf_node` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

-- 6. 修改 qf_source_log 表字符集
ALTER TABLE `qf_source_log` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
