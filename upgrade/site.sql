-- ============================================================
-- 分站系统升级脚本
-- 适用版本：xinyue-search v3.6 升级为「分站系统版」
-- 使用方式：在数据库执行本文件（整段执行即可，可重复执行）
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- 1. 分站表
-- ----------------------------
DROP TABLE IF EXISTS `qf_site`;
CREATE TABLE `qf_site` (
  `site_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '分站ID',
  `site_name` varchar(255) NOT NULL DEFAULT '' COMMENT '分站名称',
  `site_domain` varchar(255) NOT NULL DEFAULT '' COMMENT '绑定域名',
  `site_key` varchar(64) NOT NULL DEFAULT '' COMMENT '分站标识(测试/备用访问)',
  `site_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0正常 1禁用 2到期',
  `site_expire` int(11) NOT NULL DEFAULT 0 COMMENT '到期时间(时间戳)',
  `site_remark` varchar(500) NOT NULL DEFAULT '' COMMENT '备注',
  `create_time` int(11) NOT NULL DEFAULT 0 COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT 0 COMMENT '修改时间',
  PRIMARY KEY (`site_id`),
  UNIQUE KEY `site_domain` (`site_domain`),
  UNIQUE KEY `site_key` (`site_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='分站表' ROW_FORMAT=Dynamic;

-- ----------------------------
-- 2. 分站配置表（分站可编辑的配置独立存储）
-- ----------------------------
DROP TABLE IF EXISTS `qf_site_conf`;
CREATE TABLE `qf_site_conf` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `site_id` int(11) NOT NULL DEFAULT 0 COMMENT '分站ID',
  `conf_key` varchar(255) NOT NULL DEFAULT '' COMMENT '配置键',
  `conf_value` text COMMENT '配置值',
  `create_time` int(11) NOT NULL DEFAULT 0,
  `update_time` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_conf` (`site_id`, `conf_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COMMENT='分站配置表' ROW_FORMAT=Dynamic;

-- ----------------------------
-- 3. 管理员表增加所属分站字段
-- ----------------------------
ALTER TABLE `qf_admin` ADD COLUMN `site_id` int(11) NOT NULL DEFAULT 0 COMMENT '所属分站 0=主站' AFTER `admin_group`;

-- ----------------------------
-- 4. 新增广告位配置（主站与分站共用，分站可独立编辑）
-- ----------------------------
INSERT INTO `qf_conf` (`conf_id`, `conf_key`, `conf_value`, `conf_title`, `conf_desc`, `conf_int`, `conf_spec`, `conf_content`, `conf_type`, `conf_status`, `conf_sort`, `conf_system`, `conf_createtime`, `conf_updatetime`) VALUES
(71, 'ad_home_top', '', '首页顶部广告', '显示在首页搜索框上方，可填图片或HTML代码', 0, 1, NULL, 0, 1, 86, 1, 1754380800, 1754380800),
(72, 'ad_home_bottom', '', '首页底部广告', '显示在首页最底部，可填图片或HTML代码', 0, 1, NULL, 0, 1, 85, 1, 1754380800, 1754380800),
(73, 'ad_list_top', '', '搜索列表顶部广告', '显示在搜索列表页顶部，可填图片或HTML代码', 0, 1, NULL, 0, 1, 84, 1, 1754380800, 1754380800),
(74, 'ad_list_bottom', '', '搜索列表底部广告', '显示在搜索列表页底部，可填图片或HTML代码', 0, 1, NULL, 0, 1, 83, 1, 1754380800, 1754380800),
(75, 'ad_detail_top', '', '详情页顶部广告', '显示在资源详情页顶部，可填图片或HTML代码', 0, 1, NULL, 0, 1, 82, 1, 1754380800, 1754380800),
(76, 'ad_detail_bottom', '', '详情页底部广告', '显示在资源详情页底部，可填图片或HTML代码', 0, 1, NULL, 0, 1, 81, 1, 1754380800, 1754380800),
(77, 'ad_footer', '', '页脚广告', '显示在页脚上方，可填图片或HTML代码', 0, 1, NULL, 0, 1, 80, 1, 1754380800, 1754380800);

-- ----------------------------
-- 5. 分站管理员用户组
-- ----------------------------
INSERT INTO `qf_group` (`group_id`, `group_name`, `group_desc`, `group_status`, `group_createtime`, `group_updatetime`) VALUES
(2, '分站管理员', '分站管理员，仅可修改分站基础设置，无法操作接口/资源', 0, 1754380800, 1754380800);

-- ----------------------------
-- 6. 分站管理菜单节点（主站后台 - 系统 - 分站管理）
-- ----------------------------
INSERT INTO `qf_node` (`node_id`, `node_title`, `node_desc`, `node_module`, `node_controller`, `node_action`, `node_pid`, `node_order`, `node_show`, `node_icon`, `node_extend`, `node_status`, `node_createtime`, `node_updatetime`) VALUES
(120, '分站管理', '分站开通与管理', 'qfadmin', 'site', 'index', 3, 4, 1, 'el-icon-office-building', NULL, 0, 1754380800, 1754380800);

-- ----------------------------
-- 7. 分站管理员组授权（概况 + 基础设置）
-- ----------------------------
INSERT INTO `qf_auth` (`auth_group`, `auth_node`, `auth_status`, `auth_createtime`, `auth_updatetime`) VALUES
(2, 1, 0, 1754380800, 1754380800),
(2, 107, 0, 1754380800, 1754380800);

SET FOREIGN_KEY_CHECKS = 1;
