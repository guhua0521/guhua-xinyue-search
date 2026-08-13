<?php
$username = trim($_POST['manager']);
$password = trim($_POST['manager_pwd']);
//网站名称
$site_name = addslashes(trim($_POST['sitename']));

//更新配置信息
$mysqli->query("UPDATE `{$dbPrefix}conf` SET  `conf_value` = '$site_name' WHERE conf_key='app_name'");

if(INSTALLTYPE == 'HOST'){
	        $db_str=<<<php
APP_DEBUG = false
SYSTEM_SALT= {$site_name}

[APP]
DEFAULT_TIMEZONE = Asia/Chongqing

[DATABASE]
TYPE = mysql
HOSTNAME = {$dbHost}
DATABASE = {$dbName}
USERNAME = {$dbUser}
PASSWORD = {$dbPwd}
HOSTPORT = {$dbPort}
CHARSET = utf8mb4
DEBUG = false
PREFIX = {$dbPrefix}

[LANG]
default_lang = zh-cn
php;
        // 创建数据库链接配置文件
        file_put_contents('../../.env', $db_str);
}

//插入管理员
//生成随机认证码
$salt = genRandomString(4);
$time = time();
$ip = get_client_ip();
$password = sha1($password . $salt . $password . $salt);
// 分站版 qf_admin 表在 admin_group 之后新增了 site_id 字段，
// 必须显式指定列名，否则插入会错位（严格模式下直接失败，管理员写不进去）
$username = $mysqli->real_escape_string($username);
$url = "insert into `{$dbPrefix}admin` (`admin_id`,`admin_account`,`admin_password`,`admin_salt`,`admin_name`,`admin_idcard`,`admin_truename`,`admin_email`,`admin_money`,`admin_group`,`admin_ipreg`,`admin_status`,`admin_createtime`,`admin_updatetime`) VALUES (1,'{$username}', '{$password}', '{$salt}', '超级管理员','','超级管理员','',0.00,1,'127.0.0.1',0,'{$time}','{$time}')";
$result = $mysqli->query($url);
if (!$result) {
    $error = $mysqli->error;
    $mysqli->close();
    return array('status'=>1,'info'=>'添加管理员失败：'.$error);
}

$mysqli->close();
return array('status'=>2,'info'=>'成功添加管理员<br />成功写入配置文件<br>安装完成...');
