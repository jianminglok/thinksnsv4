CREATE TABLE IF NOT EXISTS `ts_application_slide` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '标题',
  `image` int(10) NOT NULL COMMENT '图片ID',
  `type` varchar(20) NOT NULL DEFAULT 'false' COMMENT '类型',
  `data` varchar(255) NOT NULL DEFAULT '' COMMENT '数据',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 COMMENT='客户端幻灯设置表' AUTO_INCREMENT=1 ;

INSERT INTO `ts_system_config` (`list`, `key`, `value`, `mtime`) VALUES
('pageKey', 'admin_Application_index', 'a:4:{s:3:"key";a:5:{s:5:"title";s:5:"title";s:5:"image";s:5:"image";s:4:"type";s:4:"type";s:4:"data";s:4:"data";s:8:"doAction";s:8:"doAction";}s:8:"key_name";a:5:{s:5:"title";s:6:"标题";s:5:"image";s:6:"图片";s:4:"type";s:6:"类型";s:4:"data";s:6:"数据";s:8:"doAction";s:6:"操作";}s:10:"key_hidden";a:5:{s:5:"title";s:1:"0";s:5:"image";s:1:"0";s:4:"type";s:1:"0";s:4:"data";s:1:"0";s:8:"doAction";s:1:"0";}s:14:"key_javascript";a:5:{s:5:"title";s:0:"";s:5:"image";s:0:"";s:4:"type";s:0:"";s:4:"data";s:0:"";s:8:"doAction";s:0:"";}}', '2015-07-31 09:05:01'),
('pageKey', 'admin_Application_addSlide', 'a:6:{s:3:"key";a:4:{s:5:"title";s:5:"title";s:5:"image";s:5:"image";s:4:"type";s:4:"type";s:4:"data";s:4:"data";}s:8:"key_name";a:4:{s:5:"title";s:12:"轮播标题";s:5:"image";s:12:"轮播图片";s:4:"type";s:12:"跳转类型";s:4:"data";s:12:"类型参数";}s:8:"key_type";a:4:{s:5:"title";s:4:"text";s:5:"image";s:5:"image";s:4:"type";s:6:"select";s:4:"data";s:4:"text";}s:11:"key_default";a:4:{s:5:"title";s:0:"";s:5:"image";s:0:"";s:4:"type";s:0:"";s:4:"data";s:0:"";}s:9:"key_tishi";a:4:{s:5:"title";s:30:"幻灯片显示的文字信息";s:5:"image";s:0:"";s:4:"type";s:63:"选择轮播点击后跳转位置，默认仅展示轮播图片";s:4:"data";s:300:"当存在跳转类型的时候必须设置，否则APP会出错，举例：选择“url地址”,则这里填写url地址，app上点击会跳转到该地址，例如：选择“微吧”，那么类型参数则填写一个微吧的ID，app上点击后直接进入该微吧，其他不一一列举！";}s:14:"key_javascript";a:4:{s:5:"title";s:0:"";s:5:"image";s:0:"";s:4:"type";s:0:"";s:4:"data";s:0:"";}}', '2015-07-31 07:53:09');