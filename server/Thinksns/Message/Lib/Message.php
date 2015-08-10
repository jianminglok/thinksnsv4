<?php
/**
 * Created by PhpStorm.
 * User: 伟
 * Date: 2015/7/17
 * Time: 14:23
 */

namespace Lib;

use \Lib\Util;
use \GatewayWorker\Lib\Db;
use \Config\Thinksns as TsConfig;


class Message
{

    /**
     * 用户登录
     * @param integer $client_id 客户端连接ID
     * @param integer $uid 用户ID
     * @param string $oauth_token 用户oauth_token
     * @param string $oauth_token_secret 用户oauth_token_secret
     * @return bool 登录成功返回true，否则返回false
     */
    public static function login($client_id, $uid, $oauth_token, $oauth_token_secret)
    {
        $db   = self::db();
        $data = $db->select('uid,oauth_token,oauth_token_secret')
            ->from(self::table('login'))->where(array(
                'uid= :uid',
                'oauth_token= :oauth_token',
                'oauth_token_secret= :oauth_token_secret',
                'type= :type',
            ))->bindValues(array(
                'uid' => $uid,
                'oauth_token' => $oauth_token,
                'oauth_token_secret' => $oauth_token_secret,
                'type' => 'location',
            ))->row();
        if($data){
            $user = self::getUserInfo($uid);
            if(isset($user[$uid])) {
                $data = array_merge($data, $user[$uid]);
                self::setLoggedUserInfo($data, true);
                self::addUserToClientMap($uid, $client_id);
                return true;
            }
        }
        self::setLoggedUserInfo(null);
        self::removeUserToClientMap(null, $client_id);
        return false;
    }

    /**
     * 取得已登录用户的全部信息或指定键名的信息
     * @param string|null $key 用户信息具体的键名
     * @return bool|string|null 如果用户未登录，则返回false
     */
    public static function getLoggedUserInfo($key = null)
    {
        if(self::logged()){
            $user = Util::getSession('user_info');
            if(null === $key){
                return $user;
            }elseif(isset($user[$key])){
                return $user[$key];
            }else{
                return null;
            }
        }
        return false;
    }

    /**
     * 设置登录用户的信息
     * @param string|array $spec
     * @param mixed $val
     * return void
     */
    public static function setLoggedUserInfo($spec, $val = null)
    {
        if(null === $spec){
            Util::setSession('user_info', null);
        }elseif(is_array($spec)){
            if($val !== true){
                $user = Util::getSession('user_info');
                if($user && is_array($user)){
                    $spec = array_merge($user, $spec);
                }
            }
            Util::setSession('user_info', $spec);
        }elseif(is_string($spec) || is_int($spec)){
            $user = Util::getSession('user_info');
            if(!$user || !is_array($user)){
                $user = array();
            }
            $user[$spec] = $val;
            Util::setSession('user_info', $user);
        }
    }

    /**
     * 检查当前用户是否已经登录
     * @return bool 如果已经登录返回true，否则返回false
     */
    public static function logged()
    {
        return Util::hasSession('user_info');
    }

    /**
     * 注销用户登录
     * @param integer $client_id 客户端连接ID
     * return void
     */
    public static function logout($client_id)
    {
        $uid = (int)self::getLoggedUserInfo('uid');
        self::removeUserToClientMap(null, $client_id);
        Util::setSession(null, null);
    }

    /**
     * 创建/取得私聊房间号
     * @param integer $uid 用户ID
     * @return array|integer 如果成功返回包含room_id 的数组
     * 如果失败则返回错误编号：
     * 0：无法获取当前用户uid
     * 1：聊天对象不存在
     * 2：不能自己和自己聊天
     * 3：创建room_id失败
     */
    public static function getRoom($uid)
    {
        $to_uid = intval($uid);
        $from_uid = intval(self::getLoggedUserInfo('uid'));
        if($from_uid < 1) return 0;
        if($to_uid < 1) return 1;
        if($from_uid == $to_uid) return 2;

        if($from_uid > $to_uid){
            $min_max = "{$to_uid}_{$from_uid}";
        }else{
            $min_max = "{$from_uid}_{$to_uid}";
        }
        $where = "`min_max`='{$min_max}' AND `type`=1";
        $db = self::db();
        $table = self::table('message_list');
        $room  = $db->select('`list_id`,`mtime`')->from($table)->where($where)->row();
        if(!$room){
            if(!self::hasUser($to_uid)) return 1;
            $time = time();
            $room_id = $db->insert($table)->cols(array(
                'from_uid' => $from_uid,
                'type' => 1,
                'member_num' => 2,
                'min_max' => $min_max,
                'mtime' => $time,
            ))->query();
            if($room_id){
                $table = self::table('message_member');
                $sql = "INSERT INTO `{$table}` (`list_id`,`member_uid`,`new`,`message_num`,`ctime`,`list_ctime`) ".
                "VALUES ({$room_id},{$from_uid},0,0,{$time},{$time}),({$room_id},{$to_uid},0,0,{$time},{$time})";
                try {
                    $db->query($sql);
                    return array('room_id'=>(int)$room_id, 'to_uid'=>(int)$uid, 'mtime'=>$time);
                }catch (\Exception $e){
                    // none
                }
            }
            return 3;
        }
        return array(
            'room_id' => (int)$room['list_id'],
            'to_uid' => (int)$uid,
            'mtime' => (int)$room['mtime'],
        );
    }


    /**
     * 创建一个群聊房间
     * @param string|array $uid_list 在该房间的uid列表
     * @param string $title 房间标题
     */
    public static function createGroupRoom($uid_list, $title = null)
    {
        // 取得当前用户UID，如果没有则返回错误代码0
        $from_uid = intval(self::getLoggedUserInfo('uid'));
        if($from_uid < 1) return 0;
        // 取得用户列表
        $user_list = self::getUserInfo($uid_list);
        // 添加当前用户
        if(isset($user_list[$from_uid])) {
            self::setLoggedUserInfo('uname', $user_list[$from_uid]['uname']);
            unset($user_list[$from_uid]);
        }
        $user_list = array( $from_uid => array(
                'uid'=>$from_uid,
                'uname'=>self::getLoggedUserInfo('uname')
            ))+$user_list;
        // 取得用户数量
        $count = count($user_list);
        // 如果只有自己，那么返回错误代码1
        if($count <= 1) return 1;
        // 取得一个包含UID的数组
        $uid_list  = array_keys($user_list);
        asort($uid_list); // 将UID按从小到大的顺序排列
        // 取得标题
        $title = isset($title)?Util::htmlEncode($title):null;
        // 组装数据并添加到room表中,得到room_id
        $room = array(
            'from_uid' => $from_uid,
            'type' => 2,
            'title' => $title,
            'member_num' => $count,
            'min_max' => implode('_', $uid_list),
            'mtime' => time(),
        );
        $db = self::db();
        $table = self::table('message_list');
        $room_id = $db->insert($table)->cols($room)->query();
        //如果有room_id
        if($room_id){
            // 组装数据并添加到member中
            $time  = $room['mtime'];
            $table = self::table('message_member');
            $sql = "INSERT INTO `{$table}` (`list_id`,`member_uid`,`new`,`message_num`,`ctime`,`list_ctime`) VALUES ";
            foreach($user_list as $uid => $user){
                $sql .= "({$room_id},{$uid},0,0,{$time},{$time}),";
            }
            $sql = rtrim($sql, ',');
            try{
                $db->query($sql);
                $room = array(
                    'room_id' => (int)$room_id,
                    'master_uid'  => $from_uid,
                    'is_group' => true,
                    'title'  => $title,
                    'mtime'  => $time,
                    'member_num'  => $room['member_num'],
                    'member_list' => array_values($user_list),
                );
                $result = self::sendMessage(array(
                    'room_id' => $room_id,
                    'attach' => array(
                        'notify_type' => 'create_group_room',
                        'member_list' => $room['member_list'],
                    ),
                ), true);
                if(is_array($result)){
                    return array(
                        'room' => $room,
                        'push' => $result,
                    );
                }
            }catch (\Exception $e){
                // none
            }
        }
        return 2; // 无room_id或后续操作没有成功，则返回错误代码2
    }

    /**
     * 删除群成员
     * @param $room_id
     * @param $member_uids
     * @return bool
     */
    public static function removeGroupMember($room_id, $member_uids){
        try{
            $db = self::db();

            $uid = intval(self::getLoggedUserInfo('uid'));
            // 检查群组权限
            if(!self::checkGroupPermissions($room_id, $uid, true)){
                return false;
            }
            // 处理客户端传的成员信息
            $member_uids = Util::formatIntList($member_uids);
            if(!$member_uids) return false;
            $member_uids = explode(',', $member_uids);
            if(in_array($uid, $member_uids)){
                $key = array_search($uid, $member_uids);
                if($key !== false){
                    unset($member_uids[$key]);
                }
            }
            //删除群成员
            $member_uids = implode(',', $member_uids);
            if(!$member_uids) return false;
            $where = "`member_uid` IN($member_uids) AND `list_id`=".intval($room_id);
            $db->delete(self::table('message_member'))->where($where)->query();

            //更新成员数量和min_max
            $member_num = self::refreshRoomMember($room_id);
            // 发消息 并 返回数据
            $result = self::sendMessage(array(
                'room_id' => $room_id,
                'attach' => array(
                    'notify_type' => 'remove_group_member',
                    'member_list' => array_values(self::getUserInfo($member_uids)),
                    'room_member_num' => $member_num,
                ),
            ), true);
            if(is_array($result)){
                $result['to_user_list'] = array_unique(array_merge(
                    $result['to_user_list'], explode(',', $member_uids)
                ));
            }
            return $result;
        }catch (\Exception $e){
            return false;
        }
    }

    /**
     * 添加群成员
     * @param $room_id
     * @param $member_uids
     * @return bool
     */
    public static function addGroupMember($room_id, $member_uids){
        try{
            $db = self::db();

            $uid = intval(self::getLoggedUserInfo('uid'));
            // 检查群组权限
            if(!self::checkGroupPermissions($room_id, $uid, false)){
                return false;
            }
            // 取得要添加的用户并删除自己
            $users = self::getUserInfo($member_uids);
            if(isset($users[$uid])){
                unset($users[$uid]);
            }
            // 将成员添加到房间
            $time  = time();
            $table = self::table('message_member');
            $sql_values = '';
            foreach($users as $user){
                if(!self::roomHasUser($room_id, $user['uid'], false)){
                    $sql_values .= "({$room_id},{$user['uid']},0,0,{$time},{$time}),";
                }
            }
            if(!$sql_values) return false;
            $db->query("INSERT INTO `{$table}` (`list_id`,`member_uid`,`new`,`message_num`,".
                "`ctime`,`list_ctime`) VALUES " . rtrim($sql_values, ','));
            // 更新成员数量和min_max
            $member_num = self::refreshRoomMember($room_id);
            // 发消息 并 返回数据
            return self::sendMessage(array(
                'room_id' => $room_id,
                'attach' => array(
                    'notify_type' => 'add_group_member',
                    'member_list' => array_values($users),
                    'room_member_num' => $member_num,
                ),
            ), true);
        }catch (\Exception $e){
            return false;
        }
    }


    /**
     * 主动退出群房间
     * @param integer $room_id 房间ID
     * @return array|bool|int 返回array为成功，其他失败。array为需要推送的数据
     */
    public static function quitGroupRoom($room_id)
    {
        try{
            $db = self::db();
            $room_id = intval($room_id);
            $uid = intval(self::getLoggedUserInfo('uid'));
            // 检查群组权限
            if(!self::checkGroupPermissions($room_id, $uid, false)){
                return false;
            }

            // 删除这个成员
            $where = "`list_id`={$room_id} AND `member_uid`={$uid}";
            $db->delete(self::table('message_member'))->where($where)->query();

            // 更新成员数量和min_max
            $member_num = self::refreshRoomMember($room_id, true);
            if($member_num['member_num'] > 0){
                // 发消息 并 返回数据
                return self::sendMessage(array(
                    'room_id' => $room_id,
                    'attach' => array(
                        'notify_type' => 'quit_group_room',
                        'quit_uid'   => $uid,
                        'quit_uname' => self::getLoggedUserInfo('uname'),
                        'room_member_num' => $member_num['member_num'],
                        'room_master_uid' => $member_num['master_uid'],
                    ),
                ), true);
            }else{
                // 返回数据
                return array('return'=>array(
                    'quit_uid'   => $uid,
                    'room_member_num' => 0,
                    'room_id' => $room_id
                ));
            }
        }catch (\Exception $e){
            return false;
        }
    }

    /**
     * 设置房间信息
     * @param $room_id
     * @param $data
     * @return bool
     */
    public static function setRoom($room_id, $data)
    {
        if($room_id > 0 && !isset($data['title'])){
            return false;
        }
        $uid = intval(self::getLoggedUserInfo('uid'));
        try{
            // 检查权限
            if(!self::checkGroupPermissions($room_id, $uid, false, null)){
                return false;
            }
            // 设置房间信息
            $where = '`list_id`='.intval($room_id);
            $sets = array('title'=>Util::htmlEncode(trim($data['title'])), 'mtime'=>time());
            self::db()->update(self::table('message_list'))->cols($sets)->where($where)->query();
            // 发消息
            return self::sendMessage(array(
                'room_id' => $room_id,
                'attach' => array(
                    'notify_type' => 'set_room',
                    'room_info'   => array(
                        'title' => trim($data['title']),
                        'mtime' => $sets['mtime'],
                    ),
                ),
            ), true);
        }catch (\Exception $e){
            return false;
        }
    }

    /**
     * 检查群组权限
     * @param integer $room_id 房间ID
     * @param null $uid 用户ID，null时自动获取当前用户
     * @param bool $check_master 是否检查uid为群主
     * @param int $type 群房间类型 null 为不限制
     * @return bool
     */
    public static function checkGroupPermissions($room_id, $uid = null, $check_master = false, $type = 2){
        if(null === $uid){
            $uid = self::getLoggedUserInfo('uid');
        }
        $where = '`list_id`='.intval($room_id);
        if($check_master){
            $where .= ' AND `from_uid`='.intval($uid);
        }
        if(null !== $type){
            $where .= ' AND `type`='.intval($type);
        }
        $table = self::table('message_list');
        $sql = "SELECT COUNT(`list_id`) as `count` FROM `{$table}` WHERE $where";
        if(self::db()->single($sql) > 0 && self::roomHasUser($room_id, $uid, false)){
            return true;
        }else{
            return false;
        }
    }

    /**
     * 刷新房间成员信息
     * @param integer $room_id 房间ID
     * @param bool $change_master 是否切换管理员
     * @return int 返回群成员数量
     */
    public static function refreshRoomMember($room_id, $change_master = false)
    {
        $db = self::db();
        $room_id = intval($room_id);
        $member = self::table('message_member');
        $list   = self::table('message_list');
        $members = $db->select('`member_uid`')->from($member)
            ->where("`list_id`={$room_id}")->orderByASC(array('id'))->column();
        if(!$members){ // 没有成员了，删除这个房间
            $content = self::table('message_content');
            $db->delete($list)->where("`list_id`={$room_id}")->query();
            $db->delete($content)->where("`list_id`={$room_id}")->query();
            return 0;
        }else{ // 更新群成员信息
            if($change_master){
                $save['from_uid'] = current($members);
            }
            asort($members);
            $save['member_num'] = count($members);
            $save['min_max'] = implode('_', $members);
            $db->update($list)->cols($save)->where("`list_id`={$room_id}")->query();
            if($change_master){
                return array(
                    'master_uid' => $save['from_uid'],
                    'member_num' => $save['member_num'],
                );
            }else{
                return $save['member_num'];
            }
        }
    }


    /**
     * 取得房间列表
     * @param $room_id
     * @param null $mtime
     * @param null $limit
     * @return array|bool
     */
    public static function getRoomList($room_id, $mtime = null, $limit = null){
        // 取得当前用户UID，如果没有则返回错误代码0
        $uid = intval(self::getLoggedUserInfo('uid'));
        $member = self::table('message_member');
        $list = self::table('message_list');
        $where = "`{$list}`.`list_id`=`{$member}`.`list_id` AND `{$member}`.".
            "`member_uid`={$uid} AND `{$member}`.`message_num`>0";
        if($room_id != 'all'){
            $room_id = Util::formatIntList($room_id, '0');
            if(false === strpos($room_id, ',')){
                $where = "`{$list}`.`list_id`='{$room_id}' AND $where";
            }else{
                $where = "`{$list}`.`list_id` IN({$room_id}) AND $where";
            }
            $limit = ''; // 全部
        }else{
            $mtime = intval($mtime);
            if($mtime > 0){
                $where .= " AND `{$list}`.`mtime`<{$mtime}";
            }
            $limit = intval($limit);
            if($limit <= 0){
                $limit = 100;
            }
            $limit = "LIMIT {$limit}";
        }
        $field = "`{$list}`.*";
        $order = "`{$list}`.`mtime` DESC";
        $sql = "SELECT {$field} FROM `{$list}`,`{$member}` WHERE {$where} ORDER BY {$order} {$limit}";
        try{
            $db = self::db();
            $result = $db->query($sql);
            if(!$result){
                return array();
            }
            $return = array();
            foreach($result as $key => $rs){
                $return[$key]['room_id'] = (int)$rs['list_id'];
                $return[$key]['master_uid'] = (int)$rs['from_uid'];
                $return[$key]['is_group'] = $rs['type'] != 1;
                $return[$key]['title']  = (string)Util::htmlDecode($rs['title']);
                $return[$key]['mtime'] = (int)$rs['mtime'];
                $return[$key]['self_index'] = null;
                // 取得全部用户的信息
                $users = self::getUserInfo(explode('_', $rs['min_max']));
                // 取得成员列表
                $members = $db->select('`member_uid`,`ctime`,`list_ctime`,`new`,`message_num`')
                    ->from($member)->where('list_id='.$rs['list_id'])
                    ->orderByASC(array('id'))->query();
                if(!$members){
                    $members = array();
                }
                $i = 0;
                $member_list = array();
                foreach($members as $row){
                    if(isset($users[$row['member_uid']])){
                        $member_list[$i]['uid'] = (int)$row['member_uid'];
                        $member_list[$i]['uname'] = $users[$row['member_uid']]['uname'];
                        $member_list[$i]['ctime'] = (int)$row['ctime'];
                        $member_list[$i]['mtime'] = (int)$row['list_ctime'];
                        $member_list[$i]['message_new'] = (int)$row['new'];
                        $member_list[$i]['message_num'] = (int)$row['message_num'];
                        if($row['member_uid'] == $uid){
                            $return[$key]['self_index'] = $i;
                        }
                        $i++;
                    }
                }
                $return[$key]['last_message'] = array();
                if($rs['last_message']){
                    $last_message = @unserialize($rs['last_message']);
                    if(is_array($last_message)){
                        $return[$key]['last_message']['message_id'] = isset($last_message['message_id'])?$last_message['message_id']:null;
                        $return[$key]['last_message']['content'] = isset($last_message['content'])?$last_message['content']:'';
                        $return[$key]['last_message']['type'] = isset($last_message['type'])?$last_message['type']:'text';
                        $return[$key]['last_message']['mtime'] = isset($last_message['mtime'])?$last_message['mtime']:'0';
                        if(isset($last_message['from_uid'])){
                            $return[$key]['last_message']['from_uid'] = $last_message['from_uid'];
                            $return[$key]['last_message']['from_uname'] = @(string)$users[$last_message['from_uid']]['uname'];
                        }else{
                            $return[$key]['last_message']['from_uid'] = 0;
                            $return[$key]['last_message']['from_uname'] = '';
                        }
                    }

                }
                $return[$key]['member_num'] = count($rs['member_num']);
                $return[$key]['member_list'] = $member_list;
            }
            return $return;
        }catch(\Exception $e){
            return false;
        }
    }

    /**
     * 发送消息
     * @param array $message
     * @param bool $is_notify
     * @return array|int
     */
    public static function sendMessage(array $message, $is_notify = false)
    {
        $db = self::db();

        // 取得当前用户UID，如果没有则返回错误代码0
        $from_uid = intval(self::getLoggedUserInfo('uid'));
        if($from_uid < 1) return 0;

        if($is_notify){
            $type = 'notify';
            $room_id = $message['room_id'];
        }else{
            // 检查消息类型
            $type = isset($message['message_type'])?$message['message_type']:false;
            $types = array('text','voice','image','position','card');
            if(!$type || !in_array($type, $types)){
                return 1;
            }

            // 检查房间是否存在
            $room_id = isset($message['room_id'])?intval($message['room_id']):0;
            if($room_id <= 0 || !self::hasRoom($room_id)){
                return 2;
            }
        }

        // 取得房间成员
        $table = self::table('message_member');
        $where = 'list_id='.$room_id;
        $to_uid_list = $db->select('member_uid')->from($table)->where($where)->column();
        if(!$is_notify){
            if(!$to_uid_list || !in_array($from_uid, $to_uid_list)){
                return 3;
            }
        }

        // 4 私信隐私检查
        // TODO

        // 准备消息数据
        $data['from_uid'] = $from_uid;
        $data['type'] = $type;
        $data['list_id'] = $room_id;
        $data['mtime']  = time();
        $return = $data;

        // 开始具体检查内容
        if($type == 'notify'){ // 通知动态信息，仅内部发送
            $data['content'] = @trim($message['content'])?:'[动态]';
            $return['content'] = $data['content'];
            $data['content'] = Util::htmlEncode($data['content']);
            if(@is_array($message['attach'])){
                $return = array_merge($message['attach'], $return);
                $data['attach_ids'] = @serialize($message['attach']);
            }else{
                $data['attach_ids'] = '';
            }
        }elseif($type == 'text'){ // 普通消息
            $content = @trim($message['content'])?:null;
            if(empty($content)){
                return 5;
            }
            $return['content'] = $content;
            $data['content'] = Util::htmlEncode($content);
            $data['attach_ids'] = '';
        }elseif($type == 'card'){ // 发名片消息
            if(!isset($message['uid']) || !self::hasUser($message['uid'])){
                return 6;
            }
            $data['content'] = $return['content'] = '[名片]';
            $return['uid'] = $message['uid'];
            $data['attach_ids'] = serialize(array(
                'uid'=>$message['uid'],
            ));
        }else{ // 发送 带附件的消息
            // 取得附件ID
            $attach_id = @intval(Util::desDecrypt($message['attach_id']));
            if($attach_id <= 0){
                return 7;
            }
            $return['attach_id'] = $attach_id;
            if($type == 'voice'){ //语音消息
                if(!@is_numeric($message['length'])){
                    return 8;
                }
                $data['attach_ids'] = serialize(array(
                    'attach_id' => $attach_id,
                    'length'    => $message['length']
                ));
                $return['length'] = $message['length'];
                $data['content'] = $return['content'] = '[语音]';
            }elseif($type == 'position'){ // 位置消息
                $latitude = @trim($message['latitude'])?:null;
                $longitude = @trim($message['longitude'])?:null;
                $location = @trim($message['location'])?:null;
                if(!is_numeric($latitude) || !is_numeric($longitude) || !$location){
                    return 9;
                }
                $data['attach_ids'] = serialize(array(
                    'attach_id' => $attach_id,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'location'  => Util::htmlEncode($location),
                ));
                $return['latitude'] = $latitude;
                $return['longitude'] = $longitude;
                $return['location'] = $location;
                $data['content'] = $return['content'] = '[位置]';
            }else{
                $data['attach_ids'] = serialize(array(
                    'attach_id' => $attach_id
                ));
                $data['content'] = $return['content'] = '[图片]';
            }
        }

        // 添加消息内容
        $db = self::db();
        $table = self::table('message_content');
        $message_id = $db->insert($table)->cols($data)->query();
        if($message_id){
            $return['message_id'] = $message_id;
            try{
                $time = $data['mtime'];
                $table = self::table('message_member');
                // 更新其他成员新消息数量
                $db->query("UPDATE `{$table}` SET `new`=`new`+1,`message_num`=`message_num`+1 ".
                    "WHERE `list_id`={$room_id} AND `member_uid`<>{$from_uid}");
                // 更新自己消息总数和最后发布时间
                $db->query("UPDATE `{$table}` SET `message_num`=`message_num`+1,`list_ctime`={$time} ".
                    "WHERE `list_id`={$room_id} AND `member_uid`={$from_uid}");
                // 更新最后一条消息
                $table = self::table('message_list');
                $lastMessage = array('mtime'=>$time, 'last_message'=>serialize($return));
                $db->update($table)->cols($lastMessage)->where("`list_id`={$room_id}")->query();
                // 加入推送暂存表
                $table = self::table('message_push');
                $insert_sql = "INSERT INTO `{$table}` (`message_id`,`list_id`,`uid`,`ctime`) VALUES ";
                $insert_val = '';
                foreach($to_uid_list as $to_uid){
                    if($is_notify || $to_uid != $from_uid){
                        $insert_val .= "('{$message_id}',{$room_id},{$to_uid},{$time}),";
                    }
                }
                if($insert_val){
                    $db->query($insert_sql.rtrim($insert_val, ','));
                }
                // 返回数据
                $return['room_id'] = $return['list_id'];
                if(!empty($attach_id)){
                    $return['attach_id'] = $message['attach_id'];
                }
                unset($return['list_id']);
                $return['from_uname'] = self::getLoggedUserInfo('uname');
                return array(
                    'return'  => $return,
                    'to_user_list' => $to_uid_list
                );
            }catch (\Exception $e){
                // none
            }
        }
        return 10;
    }

    /**
     * 取得需要推送的消息列表
     * @param null|integer $uid 用户ID，为null时自动获取当前用户
     * @return array 返回需要推送的消息列表
     */
    public static function getPushMessage($uid = null)
    {
        if(null === $uid){
            $uid = self::getLoggedUserInfo('uid');
        }
        $table = self::table('message_push');
        $in_sql = "SELECT `message_id` FROM `{$table}` WHERE `uid`=".intval($uid);
        $table = self::table('message_content');
        $sql = "SELECT * FROM `{$table}` WHERE `message_id` IN({$in_sql}) ORDER BY `message_id`";
        $result = self::db()->query($sql);
        return self::parseMessage($result);
    }

    /**
     * 取得一个房间的消息列表
     * @param integer $room_id 房间ID
     * @param null|integer $message_id 一个消息ID，如果指定了此ID，那么返回数据列表为小于该ID的消息
     * @param null|integer $limit 取多少条，设置小于0则为默认100条
     * @return array|int 如果失败返回一个数字代码，成功返回一个数组
     * 0、无法获取当前用户信息
     * 1、房间不存在或用户不在这个房间
     * 2、系统错误
     */
    public static function getMessageList($room_id, $message_id = null, $limit = null)
    {
        $room_id = intval($room_id);
        $message_id = intval($message_id);
        $limit = intval($limit);
        $limit = $limit > 0 ? $limit : 100;

        // 取得当前用户UID，如果没有则返回错误代码0
        $uid = intval(self::getLoggedUserInfo('uid'));
        if($uid < 1) return 0;
        //检查房间是否存在
        if(!self::hasRoom($room_id)){
            return 1;
        }
        //检查房间成员及消息数量
        $member = self::db()->select('`new`,`message_num`,`ctime`')
            ->from(self::table('message_member'))
            ->where("`list_id`={$room_id} AND `member_uid`={$uid}")
            ->row();
        if(!$member){
            return 1;
        }
        if($member['message_num'] == 0){
            return array();
        }

        // 取得消息列表
        $where = "`list_id`={$room_id}";
        if($message_id > 0){
            $where .= " AND `message_id`<{$message_id}";
        }

        if($member['ctime']>0){
            $where .= " AND `mtime`>={$member['ctime']}";
        }

        $table = self::table('message_content');
        $sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY `message_id` DESC LIMIT {$limit}";
        try{
            $result = self::db()->query($sql);
            if($result){
                return self::parseMessage(array_reverse($result));
            }
            return array();
        }catch (\Exception $e){
            return 2;
        }
    }

    /**
     * 将消息列表整理为标准的返回格式
     * @param array $list 需要整理的消息列表
     * @return array 返回整理好的消息列表
     */
    public static function parseMessage($list)
    {
        if(!$list) return array();
        $array = array();
        $users = self::getUserInfo(Util::arrayColumn($list, 'from_uid'));
        foreach($list as $key => $rs){
            $array[$key]['message_id'] = (int)$rs['message_id'];
            $array[$key]['from_uid'] = (int)$rs['from_uid'];
            $array[$key]['from_uname'] = (string)$users[$rs['from_uid']]['uname'];
            $array[$key]['type'] = $rs['type'];
            $array[$key]['content'] = Util::htmlDecode($rs['content']);
            $array[$key]['room_id'] = (int)$rs['list_id'];
            $array[$key]['mtime'] = (int)$rs['mtime'];
            if(empty($rs['attach_ids'])){
                $attach = array();
            }else{
                $attach = @unserialize($rs['attach_ids']);
            }

            if($rs['type'] == 'notify'){
                if($attach){
                    $array[$key] = array_merge($attach, $array[$key]);
                }
            }elseif($rs['type'] == 'voice'){
                $array[$key]['length'] = @$attach['length'];
                $array[$key]['attach_id'] = @Util::desEncrypt($attach['attach_id']);
            }elseif($rs['type'] == 'image') {
                $array[$key]['attach_id'] = @Util::desEncrypt($attach['attach_id']);
            }elseif($rs['type'] == 'position'){
                $array[$key]['latitude'] = @$attach['latitude'];
                $array[$key]['longitude'] = @$attach['longitude'];
                $array[$key]['location'] = @$attach['location'];
                $array[$key]['attach_id'] = @Util::desEncrypt($attach['attach_id']);
            }elseif($rs['type'] == 'card'){
                $array[$key]['uid'] = @(int)$attach['uid'];
            }else{
                $array[$key]['type'] = 'text';
            }
        }
        return $array;
    }

    /**
     * 移除推送暂存表中的数据
     * @param string $message_id 消息ID列表
     * @param null|integer $uid 用户ID
     * @param null|string $current_room_id 用户当前所在的房间
     * @return bool 如果成功返回true，否则返回false
     */
    public static function removePushMessage($message_id, $uid = null, $current_room_id = null)
    {
        if($current_room_id){
            return self::clearMessage($current_room_id, $uid, 'unread');
        }else{
            try{
                if(null === $uid){
                    $uid = self::getLoggedUserInfo('uid');
                }
                $message_id = Util::formatIntList($message_id);
                if(!$message_id) return true;
                $where = "`message_id` IN({$message_id}) AND `uid`=".intval($uid);
                $table = self::table('message_push');
                self::db()->delete($table)->where($where)->query();
                return true;
            }catch (\Exception $e){
                return false;
            }
        }
    }

    /**
     * 清理用户的消息
     * @param string $room_id 房间ID列表或字符串all
     * @param null|integer $uid 用户ID，null为当前用户
     * @param string $type 清理类型，unread|all
     */
    public static function clearMessage($room_id, $uid = null, $type = 'unread')
    {
        if(null === $uid){
            $uid = self::getLoggedUserInfo('uid');
        }
        $uid = intval($uid);
        $update = "`member_uid`={$uid}";
        $delete = "`uid`={$uid}";
        if($room_id != 'all'){
            $room_id = Util::formatIntList($room_id);
            if(!$room_id) return true;
            $update = "`list_id` IN({$room_id}) AND $update";
            $delete = "`list_id` IN({$room_id}) AND $delete";
        }
        if($type == 'unread'){
            $sets = '`new`=0';
        }else{
            $time = time();
            $sets = "`new`=0,`message_num`=0,`ctime`={$time},`list_ctime`={$time}";
        }
        try{
            $table = self::table('message_member');
            self::db()->query("UPDATE `{$table}` SET {$sets} WHERE {$update}");
            $table = self::table('message_push');
            self::db()->query("DELETE FROM `{$table}` WHERE {$delete}");
            return true;
        }catch (\Exception $e){
            return false;
        }
    }

    /**
     * 检查一个房间是否存在
     * @param integer $room_id 需要检查的房间ID
     * @return bool 如果存在返回true，否则返回false
     */
    public static function hasRoom($room_id)
    {
        $table = self::table('message_list');
        $sql = "SELECT count(`list_id`) as `count` FROM `{$table}` WHERE `list_id`=".intval($room_id);
        return self::db()->single($sql) > 0;
    }

    /**
     * 检查一个用户是否在指定的房间
     * @param integer $room_id 所在的房间ID
     * @param integer $uid 需要检查的用户ID
     * @param bool $check_room 是否同时检查房间是否存在
     * @return bool 如果存在返回true，否则返回false
     */
    public static function roomHasUser($room_id, $uid, $check_room = false)
    {
        // 检查房间是否存在
        if($check_room){
            if(!self::hasRoom($room_id)){
                return false;
            }
        }

        // 检查房间是否有这个成员
        $table = self::table('message_member');
        $where = '`list_id`='.intval($room_id).' AND `member_uid`='.intval($uid);
        $sql = "SELECT count(`member_uid`) as `count` FROM `{$table}` WHERE {$where}";
        return self::db()->single($sql) > 0;
    }

    /**
     * 检查一个用户是否存在
     * @param integer $uid 用户ID
     * @return bool 如果存在返回true，否则返回false
     */
    public static function hasUser($uid)
    {
        $db = self::db();
        $table = self::table('user');
        $sql = "SELECT count(`uid`) as `count` FROM `{$table}` WHERE `uid`=".intval($uid);
        return $db->single($sql) > 0;
    }

    /**
     * 返回一个包含指定UID的用户信息数组，key为UID
     * @param string $uid_list 用户UID列表
     * @return array
     */
    public static function getUserInfo($uid_list)
    {
        //整理UID列表为一个可用的IN LIST
        $in_list = Util::formatIntList($uid_list);
        // 没有需要查询的用户ID
        if (!$in_list) return array();
        // 取得数据库实例
        $db = self::db();
        // 取得数据表名称
        $table = self::table('user');
        // 准备用于查询的SQL语句
        $sql = "SELECT `uid`,`uname` FROM {$table} WHERE `uid` " .
            "IN({$in_list}) AND `is_del`=0 ORDER BY FIELD(`uid`,{$in_list})";
        // 查询内容，并把uid设置为键名，并返回新数组
        return Util::arrayColumn($db->query($sql) ?: array(), null, 'uid');
    }

    /**
     * 根据用户Id，获取全部客户端连接ID
     * @param string|array $uid_list 用户ID列表，逗号分隔或一个数组
     * @param bool $remove_current_user 如果为false，那么如果查询结果有当前用户将会保留
     * @return array 返回一个包含指定用户id的客户端连接Id数组
     */
    public static function getClientByUser($uid_list, $remove_current_user = true)
    {
        $in_list = Util::formatIntList($uid_list);
        if(!$in_list) return array();
        $where = "uid IN({$in_list})";
        if($remove_current_user){
            $uid  = (int)self::getLoggedUserInfo('uid');
            if($uid) $where .= " AND uid<>{$uid}";
        }
        $db = self::db();
        $table = self::table('message_ucmap');
        $result = $db->select('client_id')->from($table)->where($where)->column();
        return $result ? $result : array();
    }

    /**
     * 添加一个用户到客户端的映射记录
     * @param $uid 用户ID
     * @param $client_id 客户端连接ID
     * @return void
     */
    public static function addUserToClientMap($uid, $client_id)
    {
        $db = self::db();
        $table = self::table('message_ucmap');
        self::removeUserToClientMap(null, $client_id);
        $db->insert($table)->cols(array(
            'uid' => $uid,
            'client_id' => $client_id,
            'ctime' => time(),
            'gateway_id' => (int)GATEWAY_ID
        ))->query();
    }

    /**
     * 移除一个用户到客户端的映射关系
     * @param null|integer $uid 用户Id
     * @param null|integer $client_id 客户端连接Id
     * @return void
     */
    public static function removeUserToClientMap($uid = null, $client_id = null)
    {
        $where = array();
        if($uid > 0) $where[] = "uid=" . (int)$uid;
        if($client_id > 0) $where[] = "client_id=" . (int)$client_id;
        if(!empty($where)) {
            $db = self::db();
            $table = self::table('message_ucmap');
            $db->delete($table)->where($where)->query();
        }
    }

    /**
     * 清空用户到客户端的映射关系，一般用于服务器下线时清空映射关系
     * @param bool $clear_all 为true清空全部，默认false清空当前GATEWAY_ID
     * return void
     */
    public static function clearUserToClientMap($clear_all = false)
    {
        $db = self::db();
        $table = self::table('message_ucmap');
        if (!$clear_all) {
            $where = "gateway_id=" . intval(GATEWAY_ID);
            $db->delete($table)->where($where)->query();
        } else {
            $db->query("TRUNCATE TABLE `{$table}`");
        }
    }

    /**
     * 获取一个表的表名
     * @param $table_name 不带前缀的表名
     * @return string 返回带前缀的表名
     */
    public static function table($table_name)
    {
        return TsConfig::get('DB_PREFIX').$table_name;
    }

    /**
     * 取得db连接实例对象
     * @param string $config_name 配置名称，默认为thinksns
     * @return \GatewayWorker\Lib\DbConnection
     * @throws \Exception
     */
    public static function db($config_name = 'thinksns')
    {
        return Db::instance($config_name);
    }


}