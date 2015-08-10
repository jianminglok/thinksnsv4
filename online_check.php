<?php
//exit;
define('SITE_PATH',dirname(__FILE__));
date_default_timezone_set('PRC');
error_reporting(0);
//session 设置
ini_set('session.cookie_httponly', 1);
//设置session路径到本地
if( strtolower(ini_get("session.save_handler")) == "files"){
 	$session_dir = dirname(__FILE__).'/data/session';
 	if(!is_dir($session_dir)) 
 		mkdir($session_dir,0777,true);
 	session_save_path($session_dir);
}
session_start();
//$encrypt	=	1;
//exit;
/* ===================================== 配置部分 ========================================== */

$check_time		=	300;	//10分钟检查一次
$online_time	=	1800;	//统计30分钟的在线用户

$app			=	t($_GET['app'])?t($_GET['app']):'square';
$mod			=	t($_GET['mod'])?t($_GET['mod']):'Index';
$act			=	t($_GET['act'])?t($_GET['act']):'index';
$action			=	$app."/".$mod."/".$act;
$uid			=	isset($_GET['uid'])?intval($_GET['uid']):0;
$uname			=	t($_GET['uname'])?t($_GET['uname']):'guest';
$agent			=	getBrower();
$ip				=	getClientIp();
$refer			=	addslashes($_SERVER['HTTP_REFERER']);
$isGuest		=	($uid==-1 || $uid==0)?1:0;
$isIntranet		=	(substr($ip,0,2)=='10.')?1:0;
$cTime			=	time();
$ext			=	'';

//加载数据库查询类
require(SITE_PATH.'/addons/library/SimpleDB.class.php');

//全局配置
$config		=	require(SITE_PATH.'/config/config.inc.php');

//数据库配置
$dbconfig   =	!empty($config['ONLINE_DB']) ? array_merge($config,$config['ONLINE_DB']) : $config;
$db =   new SimpleDB($dbconfig);

//记录在线统计.
if($_GET['action']=='trace'){
    
    
	/* ===================================== step 1 record track ========================================== */
		
	$sql	=	"INSERT INTO ".$config['DB_PREFIX']."online_logs 
				(day,uid,uname,action,refer,isGuest,isIntranet,ip,agent,ext)
				VALUES ( CURRENT_DATE,'$uid','$uname','$action','$refer','$isGuest','$isIntranet','$ip','$agent','$ext');";
    
              
	$result	=	$db->execute("$sql");
	

	/* ===================================== step 2 update hits ========================================== */
	
	//memcached更新.写入全局点击量.每个应用的点击量.每个版块的点击量.

	/* ===================================== step 3 update heartbeat ========================================== */
	

	if( ( cookie('online_update') + $check_time ) < $cTime ){
	   
	//刷新用户在线时间
		//设置10分钟过期
		cookie('online_update',$cTime,7200);

		//$_SESSION['online_pageviews']	=	0;

		//判断是否存在记录.
		if($uid>0){
			$where	=	"WHERE (uid='$uid')";
		}else{
			$where	=	"WHERE (uid=0 AND ip='$ip')";
		}
		$sql	=	"SELECT uid FROM ".$config['DB_PREFIX']."online ".$where;
       
		$result	=	$db->query("$sql");
		//如果没有记录.添加记录.
		if($result){

			$sql	=	"UPDATE ".$config['DB_PREFIX']."online SET activeTime=$cTime,ip='$ip' ".$where;
			$result	=	$db->execute("$sql");	
		}else{

			$sql	=	"INSERT INTO ".$config['DB_PREFIX']."online (uid,uname,app,ip,agent,activeTime) VALUES ('$uid','{$uname}','$app','$ip','$agent',$cTime);";
			$result	=	$db->execute("$sql");
		}
     
	}
    if($result){
       echo 'var onlineclick = "ok";';
    }
}

/* ===================================== 公共部分 ========================================== */
// 获取客户端IP地址
function getClientIp() {
	if (getenv ( "HTTP_CLIENT_IP" ) && strcasecmp ( getenv ( "HTTP_CLIENT_IP" ), "unknown" ))
		$ip = getenv ( "HTTP_CLIENT_IP" );
	else if (getenv ( "HTTP_X_FORWARDED_FOR" ) && strcasecmp ( getenv ( "HTTP_X_FORWARDED_FOR" ), "unknown" ))
		$ip = getenv ( "HTTP_X_FORWARDED_FOR" );
	else if (getenv ( "REMOTE_ADDR" ) && strcasecmp ( getenv ( "REMOTE_ADDR" ), "unknown" ))
		$ip = getenv ( "REMOTE_ADDR" );
	else if (isset ( $_SERVER ['REMOTE_ADDR'] ) && $_SERVER ['REMOTE_ADDR'] && strcasecmp ( $_SERVER ['REMOTE_ADDR'], "unknown" ))
		$ip = $_SERVER ['REMOTE_ADDR'];
	else
		$ip = "unknown";
	return addslashes($ip);
}

// 过滤非法html标签
function t($text) {
    //过滤标签
    $text = nl2br($text);
    $text = real_strip_tags($text);
    $text = addslashes($text);
    $text = trim($text);
    return addslashes($text);
}

function real_strip_tags($str, $allowable_tags="") {
    $str = stripslashes(htmlspecialchars_decode($str));
    return strip_tags($str, $allowable_tags);
}

// 获取用户浏览器型号。新加浏览器，修改代码，增加特征字符串.把IE加到12.0 可以使用5-10年了.
function getBrower(){
	if (strpos($_SERVER['HTTP_USER_AGENT'], 'Maxthon')) {  
	    $browser = 'Maxthon'; 
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 12.0')) {  
	    $browser = 'IE12.0';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 11.0')) {  
	    $browser = 'IE11.0';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 10.0')) {  
	    $browser = 'IE10.0';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 9.0')) {  
	    $browser = 'IE9.0';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 8.0')) {  
	    $browser = 'IE8.0';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 7.0')) {  
	    $browser = 'IE7.0';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 6.0')) {  
	    $browser = 'IE6.0';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'NetCaptor')) {  
	    $browser = 'NetCaptor';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'Netscape')) {  
	    $browser = 'Netscape';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'Lynx')) {  
	    $browser = 'Lynx';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'Opera')) {  
	    $browser = 'Opera';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'Chrome')) {  
	    $browser = 'Google';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'Firefox')) {  
	    $browser = 'Firefox';  
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'Safari')) {  
	    $browser = 'Safari'; 
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'iphone') || strpos($_SERVER['HTTP_USER_AGENT'], 'ipod')) {  
	    $browser = 'iphone';
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'ipad')) {  
	    $browser = 'iphone';
	} elseif(strpos($_SERVER['HTTP_USER_AGENT'], 'android')) {  
	    $browser = 'android';
	} else {  
	    $browser = 'other';  
	}
	return addslashes($browser);
}

// 浏览器友好的变量输出
function dump($var) {
	ob_start();
	var_dump($var);
	$output = ob_get_clean();
	if(!extension_loaded('xdebug')) {
		$output = preg_replace("/\]\=\>\n(\s+)/m", "] => ", $output);
		$output = '<pre style="text-align:left">'. $label. htmlspecialchars($output, ENT_QUOTES). '</pre>';
	}
	echo '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
    echo($output);
}

// 设置cookie
function cookie($name,$value='',$option=null)
{
    // 默认设置
    $config = array(
        'prefix' => $GLOBALS['config']['COOKIE_PREFIX'], // cookie 名称前缀
        'expire' => $GLOBALS['config']['COOKIE_EXPIRE'], // cookie 保存时间
        'path'   => '/',   // cookie 保存路径
        'domain' => '', // cookie 有效域名
    );

    // 参数设置(会覆盖黙认设置)
    if (!empty($option)) {
        if (is_numeric($option)) {
            $option = array('expire'=>$option);
        }else if( is_string($option) ) {
            parse_str($option,$option);
        }
        $config =   array_merge($config,array_change_key_case($option));
    }

    // 清除指定前缀的所有cookie
    if (is_null($name)) {
       if (empty($_COOKIE)) return;
       // 要删除的cookie前缀，不指定则删除config设置的指定前缀
       $prefix = empty($value)? $config['prefix'] : $value;
       if (!empty($prefix))// 如果前缀为空字符串将不作处理直接返回
       {
           foreach($_COOKIE as $key=>$val) {
               if (0 === stripos($key,$prefix)){
                    setcookie($_COOKIE[$key],'',time()-3600,$config['path'],$config['domain']);
                    unset($_COOKIE[$key]);
               }
           }
       }
       return;
    }
    $name = $config['prefix'].$name;

 
    if (''===$value){
        //return isset($_COOKIE[$name]) ? unserialize($_COOKIE[$name]) : null;// 获取指定Cookie
        return isset($_COOKIE[$name]) ? ($_COOKIE[$name]) : null;// 获取指定Cookie
    }else {
        if (is_null($value)) {
            setcookie($name,'',time()-3600,$config['path'],$config['domain']);
            unset($_COOKIE[$name]);// 删除指定cookie
        }else {
            // 设置cookie
            $expire = !empty($config['expire'])? time()+ intval($config['expire']):0;
            //setcookie($name,serialize($value),$expire,$config['path'],$config['domain']);
           
            setcookie($name,($value),$expire,$config['path'],$config['domain'],false,true);

            //$_COOKIE[$name] = ($value);
        }
    }
}