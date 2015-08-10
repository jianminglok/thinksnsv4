<?php
/**
 * 
 * @author jason
 *
 */
class UserApi extends Api{

	/**
	 * 按用户UID或昵称返回用户资料，同时也将返回用户的最新发布的分享
	 * 
	 */
	function show(){
		
		//$this->user_id = empty($this->user_id) ? $this->mid : $this->user_id;
		//用户基本信息
		if(empty($this->user_id) && empty($this->user_name)){
			return false;
		}
		if(empty($this->user_id)){
			$data = model('User')->getUserInfoByName($this->user_name);	
			$this->user_id = $data['uid'];
		}else{
			$data = model('User')->getUserInfo($this->user_id);	
		}
		if(empty($data)){
			return false;
		}
		$data['sex'] = $data['sex'] ==1 ? '男':'女';
		
		$data['profile'] = model('UserProfile')->getUserProfileForApi($this->user_id);

		$profileHash = model('UserProfile')->getUserProfileSetting();
		$data['profile']['email'] = array('name'=>'邮箱','value'=>$data['email']);
		foreach(UserProfileModel::$sysProfile as $k){
			if(!isset($data['profile'][$k])){
				$data['profile'][$k] = array('name'=>$profileHash[$k]['field_name'],'value'=>'');
			}
		}

		//用户统计信息
		$defaultCount =  array('following_count'=>0,'follower_count'=>0,'feed_count'=>0,'favorite_count'=>0,'unread_atme'=>0,'weibo_count'=>0);

		$defaultCount['video_count'] = model('Feed')->where(array('uid'=>$this->user_id,'type'=>'postvideo','is_del'=>0))->count();
		$count   = model('UserData')->getUserData($this->user_id);
		if(empty($count)){
			$count = array();	
		}
		$data['count_info'] = array_merge($defaultCount,$count);
		$pmap['uid'] = $this->user_id ;
		$pmap['type'] = 'postimage';
		$pmap['is_del'] = 0 ;
		$verified = D('user_verified')->where('uid='.$this->user_id.' and verified=1')->find();
		$data['verified'] = $verified ? $verified : '';
		$data['count_info']['photo_count'] = D('feed')->where($pmap)->count();;
		
		//用户标签
		$data['user_tag'] = model('Tag')->setAppName('User')->setAppTable('user')->getAppTags($this->user_id);
		$data['user_tag'] = empty($data['user_tag']) ? '' : implode('、',$data['user_tag']);
		//关注情况
		$followState  = model('Follow')->getFollowState($this->mid,$this->user_id); 
		$data['follow_state'] = $followState;
		
		//最后一条分享
		$lastFeed = model('Feed')->getLastFeed($this->user_id);
		$data['last_feed'] = $lastFeed;

		// 判断用户是否被登录用户收藏通讯录
		$data['isMyContact'] = 0;
		if($this->user_id != $this->mid) {
			$cmap['uid'] = $this->mid;
			$cmap['contact_uid'] = $this->user_id;
			$myCount = D('Contact', 'contact')->where($cmap)->count();
			if($myCount == 1) {
				$data['isMyContact'] = 1;
			}
		}

		return $data;
	}
		
	/**
	 * 上传头像 API
	 * 传入的头像变量 $_FILES['Filedata']
	 */
	function upload_face(){
		$dAvatar = model('Avatar');
		$dAvatar->init($this->mid); // 初始化Model用户id
		$res = $dAvatar->upload(true);
		//Log::write(var_export($res,true));
		if($res['status'] == 1){
			model('User')->cleanCache($this->mid);
			$data['picurl'] = $res['data']['picurl'];
			$data['picwidth'] = $res['data']['picwidth'];
			$scaling = 5;
			$data['w'] = $res['data']['picwidth'] * $scaling;
			$data['h'] = $res['data']['picheight'] * $scaling;
			$data['x1'] = $data['y1'] = 0;
			$data['x2'] = $data['w'];
			$data['y2'] = $data['h'];
			$r = $dAvatar->dosave($data);
		}else{
			return '0';
		}
	}
	/**
	 * 修改用户信息
	 */
	public function save_user_info(){
		$save = array();
		if (isset($this->data['sex'])) {
			$save['sex'] = (1 == intval($this->data['sex'])) ? 1 : 2;
		}
		if (isset($this->data['intro'])) {
			$save['intro'] = t($this->data['intro']);
		}
		// if (isset($this->data['location']) || isset($this->data['province']) || isset($this->data['city'])) {
		// 	if(isset($this->data['location']) && isset($this->data['province']) && isset($this->data['city'])) {
		// 		$save['location'] = t($this->data['location']);
		// 		$save['province'] = intval($this->data['province']);
		// 		$save['city'] = intval($this->data['city']);
		// 	}else{
		// 		return array('status'=>0, 'info'=>'请选择完整地区');
		// 	}
		// 添加地区信息
		if($this->data['city_names'] && $this->data['city_ids']){
			$save['location'] = t($this->data['city_names']);
			$cityIds = t($this->data['city_ids']);
			$cityIds = explode(',', $cityIds);
			if(!$cityIds[0] || !$cityIds[1] || !$cityIds[2]) {
				return array('status'=>0, 'info'=>'请选择完整地区');
			}
			isset($cityIds[0]) && $save['province'] = intval($cityIds[0]);
			isset($cityIds[1]) && $save['city'] = intval($cityIds[1]);
			isset($cityIds[2]) && $save['area'] = intval($cityIds[2]);
		}
		if (isset($this->data['uname'])) {
			// 修改用户昵称
			$uname = t($this->data['uname']);
			$save['uname'] = filter_keyword($uname);
			$oldName = t($this->data['old_name']);
			$res = model('Register')->isValidName($uname);
			if (!$res) {
				$error = model('Register')->getLastError();
				return array('status'=>0, 'info'=>$error);
			}
			// 如果包含中文将中文翻译成拼音
			if (preg_match('/[\x7f-\xff]+/', $save['uname'])) {
				// 昵称和呢称拼音保存到搜索字段
				$save['search_key'] = $save['uname'].' '.model('PinYin')->Pinyin($save['uname']);
			} else {
				$save['search_key'] = $save['uname'];
			}
		}

		if ( $this->data['password'] ){
			$regmodel = model('Register');
			if (!$regmodel->isValidPassword($this->data['password'], $this->data['password'])) {
				$msg = $regmodel->getLastError();
				$return = array('status'=>0, 'info'=>$msg);
				return $return;
			}
			if($this->data['password'] == $this->data['old_password']) {		
				$return = array('status'=>0, 'info'=>L('PUBLIC_PASSWORD_SAME'));	// 新密码与旧密码相同
				return $return;
			}
			$user = model('User')->where('`uid`='.$this->mid)->find();
		 	if ( md5(md5($this->data['old_password']).$user['login_salt']) != $user['password'] ){
		 		$return = array('status'=>0, 'info'=>L('PUBLIC_ORIGINAL_PASSWORD_ERROR'));  //原始密码错误
				return $return;
		 	}
		 	$login_salt = rand(11111, 99999);
		 	$save['login_salt'] = $login_salt;
		 	$save['password'] = md5(md5($this->data['password']).$login_salt);
		}

		if (!empty($save)) {
			$res = model('User')->where('`uid`='.$this->mid)->save($save);
			$res && model('User')->cleanCache($this->mid);	
			$user_feeds = model('Feed')->where('uid='.$this->mid)->field('feed_id')->findAll();
			if ($user_feeds) {
				$feed_ids = getSubByKey($user_feeds, 'feed_id');
				model('Feed')->cleanCache($feed_ids,$this->mid);
			}
		}

		if (isset($this->data['user_tags'])) {
			if (empty($this->data['user_tags'])) {
				return array('status'=>0, 'info'=>L('PUBLIC_TAG_NOEMPTY'));
			}
			$nameList = t($this->data['user_tags']);
			$nameList = explode(',', $nameList);
			$tagIds = array();
			foreach ($nameList as $name) {
				$tagIds[] = model('Tag')->setAppName('public')->setAppTable('user')->getTagId($name);
			}
			$rowId = intval($this->mid);
			if (!empty($rowId)) {
				$registerConfig = model('Xdata')->get('admin_Config:register');
				if (count($tagIds) > $registerConfig['tag_num']) {
					return array('status'=>0, 'info'=>'最多只能设置'.$registerConfig['tag_num'].'个标签');
				}
				model('Tag')->setAppName('public')->setAppTable('user')->updateTagData($rowId, $tagIds);
			}
		}

		return array('status'=>1, 'info'=>'用户信息修改成功');
	}
	/**
	 * 返回用户积分接口
	 * @return unknown
	 */
	public function user_score(){
		//用户积分
		$data['credit'] = model('Credit')->getUserCredit( $this->mid );
		$data['list'] = model('Credit')->getLevel();
		return $data;
	}
	/**
	 * 返回用户隐私接口
	 * @return unknown
	 */
	public function user_privacy(){
		$user_privacy = model('UserPrivacy')->getUserSet($this->mid);
		$data['message'] = $user_privacy['message'] ? $user_privacy['message'] : 0;
		$data['space'] = $user_privacy['space'] ? $user_privacy['space'] : 0;
		$data['follow'] = $user_privacy['follow'] ? $user_privacy['follow'] : 0;
		return $data;
	}
	/**
	 * 保存用户隐私
	 * @return number
	 */
	public function save_user_privacy(){
		$map['uid'] = $this->mid;
		if ( isset( $this->data['message'] ) ){
			$map['key'] = 'message';
			$key = 'message';
			$value = intval( $this->data['message'] );
		} else if ( isset( $this->data['follow'] ) ){
			$map['key'] = 'follow';
			$key = 'follow';
			$value = intval( $this->data['follow'] );
		} else {
			$map['key'] = 'space';
			$key = 'space';
			$value = intval( $this->data['space'] );
		}
		$sql[] = "({$this->mid},'{$key}',{$value})";
		D('user_privacy')->where($map)->delete();
		$map['value'] = $value;
		$res = D('user_privacy')->add($map);
		if($res){
			return 1;
		}else{
			return 0;
		}
	}
	/**
	 *	关注一个用户
	 */
	public function follow_create(){
		if(empty($this->mid) || empty($this->user_id)){
			return 0;
		}

		$r = model('Follow')->doFollow($this->mid,$this->user_id);
		if(!$r){
			return model('Follow')->getFollowState($this->mid,$this->user_id);
			//return 0;
		}
		return $r;
	}

	/**
	 * 取消关注
	 */
	public function follow_destroy(){
		if(empty($this->mid) || empty($this->user_id)){
			return 0;
		}
		
		$r = model('Follow')->unFollow($this->mid,$this->user_id);
		if(!$r){
			return model('Follow')->getFollowState($this->mid,$this->user_id);
		}
		return $r;
	}

	/**
	 * 用户粉丝列表
	 */
	public function user_followers(){
		$this->user_id = empty($this->user_id) ? $this->mid : $this->user_id;
		// 清空新粉丝提醒数字
		if($this->user_id == $this->mid){
			$udata = model('UserData')->getUserData($this->mid);
			$udata['new_folower_count'] > 0 && model('UserData')->setKeyValue($this->mid,'new_folower_count',0);	
		}
		return model('Follow')->getFollowerListForApi($this->mid,$this->user_id,$this->since_id,$this->max_id,$this->count,$this->page);
	}

	/**
	 * 获取用户关注的人列表
 	 */
	public function user_following(){
		$this->user_id = empty($this->user_id) ? $this->mid : $this->user_id;
		return model('Follow')->getFollowingListForApi($this->mid,$this->user_id,$this->since_id,$this->max_id,$this->count,$this->page);
	}

	/**
	 * 获取用户关注的人列表按字母
 	 */
	public function user_following_by_letter(){
		$this->user_id = empty($this->user_id) ? $this->mid : $this->user_id;
		$user_following =  model('Follow')->getFollowingListForApi($this->mid,$this->user_id,$this->since_id,$this->max_id,500,$this->page);
		return $this->formatByFirstLetter($user_following);
	}

	/**
	 * 获取用户的朋友列表
	 * 
	 */
	public function user_friends(){
		$this->user_id = empty($this->user_id) ? $this->mid : $this->user_id;
		return model('Follow')->getFriendsForApi($this->mid, $this->user_id, $this->since_id, $this->max_id, $this->count, $this->page);
	}

	// 按名字搜索用户
	public function wap_search_user(){
		$key = t($this->data['key']);
		$map['uname'] = array('LIKE',$key);
		$map['email'] = array('LIKE',$key);
		$map['login'] = array('LIKE',$key);
		$map['_logic'] = 'or';
		$userlist = M('user')->where($map)->findAll();
		return $userlist;
	}

	/**
	 * 获取用户相关信息
	 * @param array $uids 用户ID数组
	 * @return array 用户相关数组
	 */
	public function getUserInfos($uids, $data, $type = 'basic')
	{
		// 获取用户基本信息
		$userInfos = model('User')->getUserInfoByUids($uids);
		$userDataInfo = model('UserData')->getUserKeyDataByUids('follower_count',$uids);

		if($type=='all'){
		// 获取其他用户统计数据
			// 获取关注信息
			$followStatusInfo = model('Follow')->getFollowStateByFids($GLOBALS['ts']['mid'], $uids);
			// 获取用户组信息
			$userGroupInfo = model('UserGroupLink')->getUserGroupData($uids);
		}
        if(empty($data)){
            foreach($uids as $k=>$v){
                $data[$k]['uid'] = $v;
            }
		}

		// 组装数据
		foreach($data as &$value) {
			$value = array_merge($value, $userInfos[$value['uid']]);
			$value['user_data'] = $userDataInfo[$value['uid']];
			if($type=='all'){	
				$value['follow_state'] = $followStatusInfo[$value['uid']];
				$value['user_group'] = $userGroupInfo[$value['uid']];
			}
		}
	
		return $data;
	}
	// 按标签搜索用户
	public function search_by_tag()
	{
		$limit = 20;
		$this->data['limit'] && $limit = intval( $this->data['limit'] );
		$tagid = intval ( $this->data['tagid'] );
		if ( !$tagid ){
			return 0;
		}
		$data = model('UserCategory')->getUidsByCid($tagid, null ,$limit);
		$data['data'] = $this->getUserInfos ( getSubByKey( $data['data'] , 'uid' ) , $data['data'],'basic');
		return $data['data'] ? $data : 0;
	}

	// 按地区搜索用户
	public function search_by_area($value='')
	{
		$_REQUEST['p'] = $_REQUEST['page'] = $this->page;
		$limit = 20;
		$this->data['limit'] && $limit = intval( $this->data['limit'] );
		$areaid = intval ( $this->data['areaid'] );
		if ( !$areaid && $this->data['areaname']){
			$amap['title'] = t( $this->data['areaname'] );
			$areaid = D('area')->where($amap)->getField('area_id');
		}
		if ( !$areaid ){
			return 0;
		}
		
		$pid1 = model('Area')->where('area_id='.$areaid)->getField('pid');
		$level = 1;
		if($pid1 != 0){
			$level = $level +1;
			$pid2 = model('Area')->where('area_id='.$pid1)->getField('pid');
			if($pid2 != 0){
				$level = $level +1;
			}
		}
		switch ($level) {
			case 1:
				$map['province'] = $areaid;
				break;
			case 2:
				$map['city'] = $areaid;
				break;
			case 3:
				$map['area'] = $areaid;
				break;
		}
		
		$map['is_del'] = 0;
		$map['is_active'] = 1;
		$map['is_audit'] = 1;
		$map['is_init'] = 1;
		
		$data = D('user')->where($map)->field('uid')->order("uid desc")->findPage($limit);
		$data['data'] = $this->getUserInfos ( getSubByKey( $data['data'] , 'uid' ) , $data['data'],'basic');
		
		return $data['data'] ? $data : 0;
	}

	// 按认证分类搜索用户
	public function search_by_verify_category($value='')
	{
		$limit = 20;
		$this->data['limit'] && $limit = intval( $this->data['limit'] );
		$verifyid = intval ( $this->data['verifyid'] );
		if ( !$verifyid && $this->data['verifyname']){
			$amap['title'] = t( $this->data['verifyname'] );
			$verifyid = D('user_verify_category')->where($amap)->getField('user_verified_category_id');
		}
		if ( !$verifyid ){
			return 0;
		}
		$maps['user_verified_category_id'] = $verifyid;
		$maps['verified'] = 1;
		$data = D('user_verified')->where($maps)->field('uid, info AS verify_info')->findPage($limit);
		$data['data'] = $this->getUserInfos ( getSubByKey( $data['data'] , 'uid' ) , $data['data'],'basic');
		return $data['data'] ? $data : 0;
	}

	// 按官方推荐分类搜索用户
	public function search_by_uesr_category($value='')
	{
		$limit = 20;
		$this->data['limit'] && $limit = intval( $this->data['limit'] );
		$cateid = intval ( $this->data['cateid'] );
		if ( !$cateid && $this->data['catename']){
			$amap['title'] = t( $this->data['catename'] );
			$cateid = D('user_official_category')->where($amap)->getField('user_official_category_id');
		}
		if ( !$cateid ){
			return 0;
		}
	 	$maps['user_official_category_id'] = $cateid;
		$data = D('user_official')->where($maps)->field('uid, info AS verify_info')->findPage($limit);
		$data['data'] = $this->getUserInfos ( getSubByKey( $data['data'] , 'uid' ) , $data['data'],'basic');
		return $data['data'] ? $data : 0;
	} 

	public function get_user_category()
	{
		$type = t ( $this->data['type'] );
		switch ($type) {
			//地区分类 最多只列出二级
			case 'area':
				$category = model('CategoryTree')->setTable('area')->getNetworkList();
				break;

			//认证分类 最多只列出二级
			case 'verify_category':
				$category = model('UserGroup')->where('is_authenticate=1')->findAll();
				foreach($category as $k=>$v){
					$category[$k]['child'] = D('user_verified_category')->where('pid='.$v['user_group_id'])->findAll();
				}
				break;

			//推荐分类 最多只列出二级
			case 'user_category':
				$category = model('CategoryTree')->setTable('user_official_category')->getNetworkList();
				break;

			//标签 tag 最多只列出二级
			default:
				$category = model('UserCategory')->getNetworkList();
				break;
		}
		return $category;
	}
	/**
	 * 粉丝最多
	 * @return Ambigous <number, 返回新的一维数组, boolean, multitype:Ambigous <array, string> >
	 */
	public function get_user_follower(){
		$limit = 20;
		$this->data['limit'] && $limit = intval( $this->data['limit'] );
		$page = $this->data['page'] ? intval($this->data['page']) : 1;
		$limit = ($page - 1) * $limit.', '.$limit;
		
		$followermap['key'] = 'follower_count';
		$followeruids = model('UserData')->where($followermap)->field('uid')->order('`value`+0 desc,uid')->limit($limit)->findAll();
		$followeruids = $this->getUserInfos ( getSubByKey( $followeruids , 'uid' ) , $followeruids,'basic');
		return $followeruids ? $followeruids : 0;
	}

	// 按地理位置搜索邻居
	public function neighbors(){
		//经度latitude 
		//纬度longitude
		//距离distance
		$latitude = floatval ( $this->data['latitude'] );
		$longitude = floatval( $this->data['longitude'] );
		//根据经度、纬度查询周边用户 1度是 111 公里
		//根据ts_mobile_user 表查找，经度和纬度在一个范围内。  
		//latitude < ($latitude + 1) AND latitude > ($latitude - 1)
		//longitude < ($longitude + 1) AND longitude > ($longitude - 1)
		$limit = 20;
		$this->data['limit'] && $limit = intval( $this->data['limit'] );
		$map['last_latitude'] = array( 'between' , ($latitude - 1).','.($latitude + 1) );
		$map['last_longitude'] = array( 'between' , ($longitude - 1).','.($longitude + 1) );
		
		$data = D('mobile_user')->where($map)->field('uid')->findpage($limit);
		$data['data'] = $this->getUserInfos ( getSubByKey( $data['data'] , 'uid' ) , $data['data'],'basic');
		return $data['data'] ? $data : 0;
	}

	// 记录用户的最后活动位置
	public function checkin(){
		$latitude = floatval ( $this->data['latitude'] );
		$longitude = floatval ( $this->data['longitude'] );
		//记录用户的UID、经度、纬度、checkin_time、checkin_count
		//如果没有记录则写入，如果有记录则更新传过来的字段包括：sex\nickname\infomation（用于对周边人进行搜索）
		$checkin_count = D('mobile_user')->where('uid='.$this->mid)->getField('checkin_count');
		$data['last_latitude'] = $latitude;
		$data['last_longitude'] = $longitude;
		$data['last_checkin'] = time();
		dump(444);
		if ( $checkin_count ){
			$data['checkin_count'] = $checkin_count + 1;
			$res = D('mobile_user')->where('uid='.$this->mid)->save($data);
		} else {
			
			$user = model('User')->where('uid='.$this->mid)->field('uname,intro,sex')->find();
			$data['nickname'] = $user['uname'];
			$data['infomation'] = $user['intro'];
			$data['sex'] = $user['sex'];
			
			$data['checkin_count'] = 1;
			$data['uid'] = $this->mid;
			$res = D('mobile_user')->add($data);
			
			dump($data);
			dump(D('mobile_user')->getLastSql());
			dump($res);
		}
		return $res ? 1 : 0;
	}

	public function update_profile() {
		$save = array();
		if (isset($this->data['sex'])) {
			$save['sex'] = (1 == intval($this->data['sex'])) ? 1 : 2;
		}
		if (isset($this->data['intro'])) {
			$save['intro'] = t($this->data['intro']);
		}
		if (isset($this->data['city_names']) && isset($this->data['city_ids'])) {
			// 添加地区信息
			$save['location'] = t($this->data['city_names']);
			$cityIds = t($this->data['city_ids']);
			$cityIds = explode(',', $cityIds);
			if(!$cityIds[0] || !$cityIds[1] || !$cityIds[2]) {
				return array('status'=>0, 'info'=>'请选择完整地区');
			}
			isset($cityIds[0]) && $save['province'] = intval($cityIds[0]);
			isset($cityIds[1]) && $save['city'] = intval($cityIds[1]);
			isset($cityIds[2]) && $save['area'] = intval($cityIds[2]);
		}
		if (isset($this->data['uname']) && isset($this->data['old_name'])) {
			// 修改用户昵称
			$uname = t($this->data['uname']);
			$save['uname'] = filter_keyword($uname);
			$oldName = t($this->data['old_name']);
			$res = model('Register')->isValidName($uname, $oldName);
			if (!$res) {
				$error = model('Register')->getLastError();
				return array('status'=>0, 'info'=>$error);
			}
			// 如果包含中文将中文翻译成拼音
			if (preg_match('/[\x7f-\xff]+/', $save['uname'])) {
				// 昵称和呢称拼音保存到搜索字段
				$save['search_key'] = $save['uname'].' '.model('PinYin')->Pinyin($save['uname']);
			} else {
				$save['search_key'] = $save['uname'];
			}
		}
		if (!empty($save)) {
			$res = model('User')->where('`uid`='.$this->mid)->save($save);
			$res && model('User')->cleanCache($this->mid);	
			$user_feeds = model('Feed')->where('uid='.$this->mid)->field('feed_id')->findAll();
			if ($user_feeds) {
				$feed_ids = getSubByKey($user_feeds, 'feed_id');
				model('Feed')->cleanCache($feed_ids,$this->mid);
			}
		}
		if (isset($this->data['user_tags'])) {
			if (empty($this->data['user_tags'])) {
				return array('status'=>0, 'info'=>L('PUBLIC_TAG_NOEMPTY'));
			}
			$nameList = t($this->data['user_tags']);
			$nameList = explode(',', $nameList);
			$tagIds = array();
			foreach ($nameList as $name) {
				$tagIds[] = model('Tag')->setAppName('public')->setAppTable('user')->getTagId($name);
			}
			$rowId = intval($this->mid);
			if (!empty($rowId)) {
				$registerConfig = model('Xdata')->get('admin_Config:register');
				if (count($tagIds) > $registerConfig['tag_num']) {
					return array('status'=>0, 'info'=>'最多只能设置'.$registerConfig['tag_num'].'个标签');
				}
				model('Tag')->setAppName('public')->setAppTable('user')->updateTagData($rowId, $tagIds);
			}
		}

		return array('status'=>1, 'info'=>'用户信息修改成功');
	}

	public function formatByFirstLetter($list){
		$peoplelist = array('#'=>array(),'A'=>array(),'B'=>array(),'C'=>array(),'D'=>array(),'E'=>array(),'F'=>array(),'G'=>array(),'H'=>array(),'I'=>array(),'J'=>array(),'K'=>array(),'L'=>array(),'M'=>array(),'N'=>array(),'O'=>array(),'P'=>array(),'Q'=>array(),'R'=>array(),'S'=>array(),'T'=>array(),'U'=>array(),'V'=>array(),'W'=>array(),'X'=>array(),'Y'=>array(),'Z'=>array());
		foreach ($list as $k => $v) {
			$first_letter = getFirstLetter($v['uname']);
			switch ($first_letter) {
				case '#':
					$peoplelist['#'][] = $list[$k];
					break;
				case 'A':
					$peoplelist['A'][] = $list[$k];
					break;
				case 'B':
					$peoplelist['B'][] = $list[$k];
					break;
				case 'C':
					$peoplelist['C'][] = $list[$k];
					break;
				case 'D':
					$peoplelist['D'][] = $list[$k];
					break;
				case 'E':
					$peoplelist['E'][] = $list[$k];
					break;
				case 'F':
					$peoplelist['F'][] = $list[$k];
					break;
				case 'G':
					$peoplelist['G'][] = $list[$k];
					break;
				case 'H':
					$peoplelist['H'][] = $list[$k];
					break;
				case 'I':
					$peoplelist['I'][] = $list[$k];
					break;
				case 'J':
					$peoplelist['J'][] = $list[$k];
					break;
				case 'K':
					$peoplelist['K'][] = $list[$k];
					break;
				case 'L':
					$peoplelist['L'][] = $list[$k];
					break;
				case 'M':
					$peoplelist['M'][] = $list[$k];
					break;
				case 'N':
					$peoplelist['N'][] = $list[$k];
					break;
				case 'O':
					$peoplelist['O'][] = $list[$k];
					break;
				case 'P':
					$peoplelist['P'][] = $list[$k];
					break;
				case 'Q':
					$peoplelist['Q'][] = $list[$k];
					break;
				case 'R':
					$peoplelist['R'][] = $list[$k];
					break;
				case 'S':
					$peoplelist['S'][] = $list[$k];
					break;
				case 'T':
					$peoplelist['T'][] = $list[$k];
					break;
				case 'U':
					$peoplelist['U'][] = $list[$k];
					break;
				case 'V':
					$peoplelist['V'][] = $list[$k];
					break;
				case 'W':
					$peoplelist['W'][] = $list[$k];
					break;
				case 'X':
					$peoplelist['X'][] = $list[$k];
					break;
				case 'Y':
					$peoplelist['Y'][] = $list[$k];
					break;
				case 'Z':
					$peoplelist['Z'][] = $list[$k];
					break;
			}
			unset($first_letter);
		}
		foreach ($peoplelist as $k => $v) {
			if(count($v)<1){
				unset($peoplelist[$k]);
			}
		}
		return $peoplelist;
	}

	public function getAreaList(){
		$pid = $this->data['area_id']?intval($this->data['area_id']):0;
		return D('area')->where('pid='.$pid)->order('sort ASC')->findAll();
	}
	
}