<?php
$discuz_url = 'http://127.0.0.1/discuz/';//论坛地址
$login_url = $discuz_url .'logging.php?action=login';//登录页地址


$post_fields = array();
//以下两项不需要修改
$post_fields['loginfield'] = 'username';
$post_fields['loginsubmit'] = 'true';
//用户名和密码，必须填写
$post_fields['username'] = 'tianxin';
$post_fields['password'] = '111111';
//安全提问
$post_fields['questionid'] = 0;
$post_fields['answer'] = '';
//@todo验证码
$post_fields['seccodeverify'] = '';

//获取表单FORMHASH
$ch = curl_init($login_url);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$contents = curl_exec($ch);
curl_close($ch);
preg_match('/<input\s*type="hidden"\s*name="formhash"\s*value="(.*?)"\s*\/>/i', $contents, $matches);
if(!empty($matches)) {
    $formhash = $matches[1];
} else {
    die('Not found the forumhash.');
}



//POST数据，获取COOKIE,cookie文件放在网站的temp目录下
$cookie_file = tempnam('./temp','cookie');

$ch = curl_init($login_url);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
curl_exec($ch);
curl_close($ch);

//取到了关键的cookie文件就可以带着cookie文件去模拟发帖,fid为论坛的栏目ID
$send_url = $discuz_url."post.php?action=newthread&fid=2";


$ch = curl_init($send_url);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
$contents = curl_exec($ch);
curl_close($ch);

//这里的hash码和登陆窗口的hash码的正则不太一样，这里的hidden多了一个id属性
preg_match('/<input\s*type="hidden"\s*name="formhash"\s*id="formhash"\s*value="(.*?)"\s*\/>/i', $contents, $matches);
if(!empty($matches)) {
    $formhash = $matches[1];
} else {
    die('Not found the forumhash.');
}


$post_data = array();
//帖子标题
$post_data['subject'] = 'test2';
//帖子内容
$post_data['message'] = 'test2';
$post_data['topicsubmit'] = "yes";
$post_data['extra'] = '';
//帖子标签
$post_data['tags'] = 'test';
//帖子的hash码，这个非常关键！假如缺少这个hash码，discuz会警告你来路的页面不正确
$post_data['formhash']=$formhash;


$ch = curl_init($send_url);
curl_setopt($ch, CURLOPT_REFERER, $send_url);       //伪装REFERER
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 0);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
$contents = curl_exec($ch);
curl_close($ch);

//清理cookie文件
unlink($cookie_file);

?>