<?php
if (!defined('SITE_PATH')) exit();
$conf = include dirname(__FILE__) . '/thinksns.conf.php';
return array_merge(array(
	// 数据库常用配置
	'DB_TYPE'       => 'mysql',       // 数据库类型

	'DB_HOST'       => 'localhost',    // 数据库服务器地址
	'DB_NAME'       => 'ts4test',    // 数据库名
	'DB_USER'       => 'root',// 数据库用户名
	'DB_PWD'        => '',// 数据库密码

	'DB_PORT'       => 3306,        // 数据库端口
	'DB_PREFIX'     => 'ts_',// 数据库表前缀（因为漫游的原因，数据库表前缀必须写在本文件）
	'DB_CHARSET'    => 'utf8',      // 数据库编码
	'SECURE_CODE'   => '615355b23ee549bd8',  // 数据加密密钥
	'COOKIE_PREFIX' => 'TS4_',      // # cookie
), $conf);