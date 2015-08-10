<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

use \GatewayWorker\Lib\Gateway;
use \Lib\Message;

/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 *
 * GatewayWorker开发参见手册：
 * @link http://gatewayworker-doc.workerman.net/
 */
class Event
{
    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     *
     * @param int $client_id 连接id
     * @link http://gatewayworker-doc.workerman.net/gateway-worker-development/onconnect.html
     */
    public static function onConnect($client_id)
    {
        self::sendDataToCurrentClient('connect', $client_id, 0, 'Please log in');
    }

    /**
     * 当客户端发来消息时触发
     * @param int $client_id 连接id
     * @param string $message 具体消息
     * @return void
     */
    public static function onMessage($client_id, $message)
    {
        // debug
        //echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} ".
        //     "gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  ".
        //     "client_id:$client_id session:".json_encode($_SESSION)." onMessage:".$message."\n";
echo $message,PHP_EOL.PHP_EOL;
        // 对客户端发送的json数据转换为php的数组
        $message_array = @json_decode($message, true)?:array();

        // 如果数据中指定了数据类型
        if(isset($message_array['type'])) {
            // 取得请求的类型
            $type = preg_replace_callback('/_([a-z])/', function($match){
                return strtoupper($match[1]);
            }, strtolower($message_array['type']));

            // 处理这个类型的类方法名称
            $method = $type. 'Message';
            // 如果有这个方法
            if(is_callable(__CLASS__.'::'.$method)) {
                // 没有设置packid则设置为null
                if(!isset($message_array['packid'])){
                    $message_array['packid'] = null;
                }
                // 如果不在白名单中的，那么需要检查登录
                if(!in_array($type, array('login'))){
                    // 检查登录并提醒
                    if(!self::logged($message_array['packid'])) {
                        return; // 如果没有登录就终止
                    }
                }
                unset($message_array['type']); //删除数据中type字段
                // 调用type对应的类方法进行处理
                //try{
                    self::$method($client_id, $message_array, $message);
                /*}catch (\Exception $e) {
                    echo $e->getMessage();
                    $msg = 'Server internal exception';
                    self::sendDataToCurrentClient('send_message', null, 1000, $msg);
                }*/
            }else{
                // 类方法不存在或未被支持，则发送错误提示信息到客户端
                $msg = "Message type is not supported: {$message_array['type']}";
                self::sendDataToCurrentClient('send_message', null, 1002, $msg);
            }
        }else{
            // 没有指定类型，无法继续后续工作，发送错误描述
            $msg = 'Message type is not specified';
            self::sendDataToCurrentClient('send_message', null, 1001, $msg);
        }
    }

    /**
     * 当用户断开连接时触发
     * @param int $client_id 连接id
     * @return void
     */
    public static function onClose($client_id)
    {
        Message::logout($client_id); //退出用户
    }

    /**
     * 客户端pong服务端接口
     * @param int $client_id 客户端Id
     * @return void
     */
    protected static function pongMessage($client_id)
    {
        // none
    }

    /**
     * 用户登录接口
     * @param int $client_id 客户端Id
     * @param array $message 客户端消息
     * $message = array(
     *     'uid'=> ':integer', //用户uid
     *     'oauth_token' => 'string', // 用户oauth_token
     *     'oauth_token_secret' => ':string', // 用户oauth_token_secret
     * );
     * @return void
     */
    protected static function loginMessage($client_id, array $message = array()){
        // 取得客户端数据包各参数
        $uid = isset($message['uid'])?(int)$message['uid']:null;
        $oauth_token = isset($message['oauth_token'])?(string)$message['oauth_token']:null;
        $oauth_token_secret = isset($message['oauth_token_secret'])?(string)$message['oauth_token_secret']:null;
        // 如果都正确传递，那么尝试登陆用户
        if($uid && $oauth_token && $oauth_token_secret){
            // 调用登陆接口尝试登陆，如果能正确登陆，则发送登陆成功的提示
            if(Message::login($client_id, $uid, $oauth_token, $oauth_token_secret)){
                self::sendDataToCurrentClient('login', (int)Message::getLoggedUserInfo('uid'));
                self::pushMessageDataToCurrent();
                return ; // 登录已经成功，后面不在执行，所以直接返回
            }
        }
        // 无法登陆或数据包参数不完整，则发送登陆错误信息
        self::sendDataToCurrentClient('login', $uid, 1004, 'Login failed');
    }

    /**
     * 用户注销登录接口
     * @param int $client_id 客户端Id
     * @param array $message 客户端消息
     * @param string $raw_message 客户端原始消息
     * @return void
     */
    protected static function logoutMessage($client_id, array $message = array(), $raw_message = ''){
        Message::logout($client_id); // 退出登录
    }

    /**
     * 用户创建一对一聊天接口
     * @param int $client_id 客户端Id
     * @param array $message 客户端消息
     * $message = array(
     *     'uid'=> ':integer', //对方用户uid
     * );
     * @return void
     */
    protected static function getRoomMessage($client_id, array $message = array())
    {
        if(empty($message['uid'])){
            $result = 1;
        }else{
            $result = Message::getRoom($message['uid']);
        }
        if(is_int($result)){
            $uid = isset($message['uid'])?$message['uid']:null;
            $data = isset($message['packid'])?$message['packid']:$uid;
            $array = array(
                'Can\'t get the current user',
                'User does not exist: uid='.$uid,
                'Can\'t use the same uid and their own',
                'Create or get the room_id failure'
            );
            if(!isset($array[$result])){
                $result = 3;
            }
            self::sendDataToCurrentClient('get_room', $data, 1050+$result, $array[$result]);
        }else{
            isset($message['packid']) && $result['packid'] = $message['packid'];
            self::sendDataToCurrentClient('get_room', $result);
        }
    }

    /**
     * 创建群房间
     * @param $client_id
     * @param array $message
     */
    protected static function createGroupRoomMessage($client_id, array $message = array()){
        $uid_list = empty($message['uid_list'])?null:$message['uid_list'];
        $title = isset($message['title'])?$message['title']:null;
        if(empty($uid_list)){
            $result = 1;
        }else{
            $result = Message::createGroupRoom($uid_list, $title);
        }
        if(is_int($result)){
            $array = array(
                'Can\'t get the current user',
                'Users does not exist: uid_list='.$message['uid_list'],
                'Create group room failed'
            );
            if(!isset($array[$result])) {
                $result = 2;
            }
            self::sendDataToCurrentClient('create_group_room', $message['packid'], 1070+$result, $array[$result]);
        }else{
            isset($message['packid']) && $result['room']['packid'] = $message['packid'];
            self::sendDataToCurrentClient('create_group_room', $result['room']);
            self::pushMessageData(array($result['push']['return']), $result['push']['to_user_list'], false);
        }
    }

    /**
     * 移除群房间成员
     * @param $client_id
     * @param array $message
     */
    public static function removeGroupMemberMessage($client_id, array $message = array()){
        $room_id = isset($message['room_id'])?$message['room_id']:null;
        $member_uids = isset($message['member_uids'])?$message['member_uids']:null;
        if($room_id && $member_uids){
            $result = Message::removeGroupMember($room_id, $member_uids);
            if(is_array($result)){
                $return['member_list'] = $result['return']['member_list'];
                if(isset($message['packid'])){
                    $return['packid'] = $message['packid'];
                }
                self::sendDataToCurrentClient('remove_group_member', $return);
                if($result['return']['room_member_num'] > 0){
                    unset($result['return']['room_member_num']);
                    self::pushMessageData(array($result['return']), $result['to_user_list'], false);
                }
                return ; // success
            }
        }
        // failure
        self::sendDataToCurrentClient('remove_group_member', $message['packid'], 1089, 'remove group member failure');
    }

    /**
     * 退出群
     * @param $client_id
     * @param array $message
     */
    public static function quitGroupRoomMessage($client_id, array $message = array()){
        $room_id = isset($message['room_id'])?$message['room_id']:null;
        if($room_id){
            $result = Message::quitGroupRoom($room_id);//print_r($result);return;
            if(is_array($result)){
                $return['quit_uid'] = $result['return']['quit_uid'];
                $return['room_id'] = $result['return']['room_id'];
                if(isset($message['packid'])){
                    $return['packid'] = $message['packid'];
                }
                self::sendDataToCurrentClient('quit_group_room', $return);
                if($result['return']['room_member_num'] > 0){
                    unset($result['return']['room_member_num']);
                    self::pushMessageData(array($result['return']), $result['to_user_list'], false);
                }
                return ; // success
            }
        }
        // failure
        self::sendDataToCurrentClient('quit_group_room', $message['packid'], 1096, 'quit group room failure');
    }

    /**
     * 添加群房间成员
     * @param $client_id
     * @param array $message
     */
    public static function addGroupMemberMessage($client_id, array $message = array()){
        $room_id = isset($message['room_id'])?$message['room_id']:null;
        $member_uids = isset($message['member_uids'])?$message['member_uids']:null;
        if($room_id && $member_uids){
            $result = Message::addGroupMember($room_id, $member_uids);
            if(is_array($result)){
                $return['member_list'] = $result['return']['member_list'];
                if(isset($message['packid'])){
                    $return['packid'] = $message['packid'];
                }
                self::sendDataToCurrentClient('add_group_member', $return);
                if($result['return']['room_member_num'] > 0){
                    unset($result['return']['room_member_num']);
                    self::pushMessageData(array($result['return']), $result['to_user_list'], false);
                }
                return ; // success
            }

        }
        // failure
        self::sendDataToCurrentClient('add_group_member', $message['packid'], 1092, 'add group member failure');
    }

    /**
     * 设置房间信息
     * @param $client_id
     * @param array $message
     */
    public static function setRoomMessage($client_id, array $message = array()){
        if(isset($message['room_id'])){
            $room_id = $message['room_id'];
            unset($message['room_id']);
            $result = Message::setRoom($room_id, $message);
            if(is_array($result)){
                $return = $result['return']['room_info'];
                if(isset($message['packid'])){
                    $return['packid'] = $message['packid'];
                }
                self::sendDataToCurrentClient('set_room', $return);
                self::pushMessageData(array($result['return']), $result['to_user_list'], false);
                return ; // success
            }
        }
        // failure
        self::sendDataToCurrentClient('set_room', $message['packid'], 1085,'set room failure');
    }

    /**
     * 取得房间列表
     * @param $client_id
     * @param array $message
     */
    public static function getRoomListMessage($client_id, array $message = array()){
        $room_id = isset($message['room_id'])?$message['room_id']:null;
        $mtime = isset($message['mtime'])?$message['mtime']:null;
        $limit = isset($message['limit'])?$message['limit']:null;
        $result = Message::getRoomList($room_id, $mtime, $limit);
        if(false !== $result){
            if(isset($message['packid'])){
                $data['packid'] = $message['packid'];
            }
            $data['length'] = count($result);
            $data['list'] = $result;
            self::sendDataToCurrentClient('get_room_list', $data);
        }else{
            self::sendDataToCurrentClient('get_room_list', $message['packid'], 1080, 'get room list failure');
        }
    }

    /**
     * 发送消息
     * @param $client_id
     * @param array $message
     */
    public static function sendMessageMessage($client_id, array $message = array()){
        $data = Message::sendMessage($message);
        if(is_int($data)){
            $array = array(
                'Can\'t get the current user',
                'Unsupported type',
                'Without this room',
                'Current user not in the room',
                'Permission denied',
                'Content is required',
                'User does not exist',
                'attach_id exception',
                'Length must be numeric',
                'Position parameter exception',
                'Server internal error',
            );
            if(!isset($array[$data])){
                $data = 10;
            }
            self::sendDataToCurrentClient('send_message', $message['packid'], 1100+$data, $array[$data]);
        }else{
            $result = array(
                'packid'=>$message['packid'],
                'message_id'=>$data['return']['message_id'],
                'mtime' => $data['return']['mtime'],
            );
            self::sendDataToCurrentClient('send_message', $result);

            self::pushMessageData(array($data['return']), $data['to_user_list'], true);
        }
    }

    /**
     * 移除推送
     * @param $client_id
     * @param array $message
     */
    public static function removePushMessageMessage($client_id, array $message = array()){
        $message_ids = isset($message['message_ids'])?$message['message_ids']:null;
        $current_room_id = isset($message['current_room_id'])?$message['current_room_id']:null;
        if($message_ids || $current_room_id){
            if(Message::removePushMessage($message_ids, null, $current_room_id)){
                self::sendDataToCurrentClient('clear_message', $message['packid']);
                return ; // success
            }
        }
        // failure
        self::sendDataToCurrentClient('clear_message', $message['packid'], 1140,'remove push message failure');
    }

    /**
     * 获取消息列表
     * @param $client_id
     * @param array $message
     */
    public static function getMessageListMessage($client_id, array $message = array()){
        $room_id = isset($message['room_id'])?$message['room_id']:null;
        $message_id = isset($message['message_id'])?$message['message_id']:null;
        $limit = isset($message['limit'])?$message['limit']:null;
        $data = Message::getMessageList($room_id, $message_id, $limit);
        if(is_int($data)){
            $array = array(
                'Can\'t get the current user',
                'Current user not in the room',
                'Server internal error',
            );
            if(!isset($array[$data])){
                $data = 2;
            }
            self::sendDataToCurrentClient('get_message_list', $message['packid'], 1130+$data, $array[$data]);
        }else{
            $data = array('length'=>count($data), 'list'=>$data);
            isset($message['packid']) && $data['packid'] = $message['packid'];
            self::sendDataToCurrentClient('get_message_list', $data);
        }
    }

    /**
     * 清理消息
     * @param $client_id
     * @param array $message
     */
    public static function clearMessageMessage($client_id, array $message = array()){
        $message['room_id'] = isset($message['room_id'])?$message['room_id']:null;
        $message['clear_type'] = isset($message['clear_type'])?$message['clear_type']:null;
        if($message['room_id'] && in_array($message['clear_type'], array('unread', 'all'))){
            if(Message::clearMessage($message['room_id'], null, $message['clear_type'])){
                self::sendDataToCurrentClient('clear_message', $message['packid']);
                return ; // success
            }
        }
        // failure
        self::sendDataToCurrentClient('clear_message', $message['packid'], 1141,'clear message failure');
    }

    /**
     * 向当前连接用户发送数据包
     * @param string $type 消息类型
     * @param mixed $result 消息数据
     * @param int $status 数据状态
     * @param string $msg 数据描述消息
     * @return bool
     */
    protected static function sendDataToCurrentClient($type, $result = null, $status = 0, $msg = '')
    {
        $data = json_encode(array(
            'type' => $type,
            'result' => $result,
            'status' => $status,
            'msg' => $msg,
        ));
        return Gateway::sendToCurrentClient($data);
    }

    /**
     * 向当前客户端推送消息
     * @param array|null $data 需要推送的消息列表，为null时自动获取
     * @return void
     */
    protected static function pushMessageDataToCurrent($data = null){
        if(null === $data){
            $data = Message::getPushMessage();
        }
        if(!$data) return ;
        $data = array('length'=>count($data), 'list'=>$data);
        self::sendDataToCurrentClient('push_message', $data);
    }

    /**
     * 向客户端推送消息
     * @param array $data 需要推送的消息列表
     * @param array|string $users 需要推送到的用户UID列表
     * @param bool $remove_current_user 是否包含当前用户
     * @throws Exception
     */
    protected static function pushMessageData($data, $users, $remove_current_user = true)
    {
        $client_ids = Message::getClientByUser($users, $remove_current_user);
        $message = json_encode(array(
            'type' => 'push_message',
            'result' => array(
                'length' => count($data),
                'list' => $data,
            ),
            'status' => 0,
            'msg' => '',
        ));
        Gateway::sendToAll($message, $client_ids);
    }

    /**
     * 用户注销登录
     * @param string $packid 客户端的包ID
     * @param bool $send_notice 是否发送登录通知
     * @return bool 如果已登录返回true，否则返回false
     */
    protected static function logged($packid, $send_notice = true){
        $logged = Message::logged();
        if(!$logged && $send_notice) {
            self::sendDataToCurrentClient('login', $packid, 1003, 'Not logged in');
        }
        return $logged;
    }
}
