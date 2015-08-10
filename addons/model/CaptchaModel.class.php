<?php

class CaptchaModel extends Model {

	const REGISTRATION = 1;
	const LOCKTIME = 60;
	const SMS_SERVER = 'http://121.199.16.178/webservice/sms.php?method=SendSms';

	protected $tableName = 'captcha';
	private $_error = '';
	private $_defaultHash = array(
		// 1 => '亲爱的用户，您的注册验证码为：{rand}',
		1 => '您的验证码是：{rand}。如非本人操作，可不用理会！',
		// 2 => '亲爱的用户，您的找回密码验证码：{rand}',
		2 => '您的验证码是：{rand}。如非本人操作，可不用理会！',
		// 3 => '亲爱的用户，您的密码为：{pwd}',
		3 => '{name}，您好！您的密码已被修改为{pwd}，为防止密码泄露，请确认后删除本条短信。',
		// 4 => '亲爱的用户，您的手机绑定验证码为：{rand}',
		4 => '您的验证码是：{rand}。如非本人操作，可不用理会！',
		// 5 => '亲爱的用户，您的邮箱绑定验证码为：{rand}',
		5 => '亲爱的用户，您的邮箱绑定验证码为：{rand}',
	);
	private $_mobileType = array(1, 2, 3, 4);

	public function getError() {
		return $this->_error;
	}

	private function setError($msg) {
		$this->_error = $msg;
	}

	public function send($communication, $content)
	{
		$communication = t($communication);
		if (!$communication || empty($content)) {
			return false;
		}
		$data['communication'] = $communication;
		$data['content']       = $content;

		return $this->add($data);
	}

	public function sendRegisterCode($mobile)
	{
		return $this->sendCaptcha($mobile, 1);
	}

	public function checkRegisterCode($mobile, $captcha)
	{
		return $this->checkCaptcha($mobile, $captcha, 1);
	}

	public function sendPasswordCode($mobile)
	{
		return $this->sendCaptcha($mobile, 2);
	}

	public function checkPasswordCode($mobile, $captcha)
	{
		return $this->checkCaptcha($mobile, $captcha, 2);
	}

	public function sendPassword($mobile)
	{
		return $this->sendCaptcha($mobile, 3);
	}

	public function sendLoginCode($mobile)
	{
		return $this->sendCaptcha($mobile, 4);
	}

	public function checkLoginCode($mobile, $captcha)
	{
		return $this->checkCaptcha($mobile, $captcha, 4);
	}

	public function sendEmailCode($email)
	{
		return $this->sendCaptcha($email, 5);
	}

	public function checkEmailCode($email, $captcha)
	{
		return $this->checkCaptcha($email, $captcha, 5);
	}

	private function sendCaptcha($communication, $type) {
		$key = $communication.'_'.$type;
		if (isset($_SESSION['captcha_lock_'.$key]) && $_SESSION['captcha_lock_'.$key] + self::LOCKTIME > time()) {
			$this->setError('请不要频繁发送，稍后再试。');
			return false;
		} else {
			$_SESSION['captcha_lock_'.$key] = time();
		}
		$communication = t($communication);
		if (!$communication) {
			$this->setError('联系信息有误。');
			return false;
		}
		if (!array_key_exists($type, $this->_defaultHash)) {
			$this->setError('发送类型有误。');
			return false;
		}
		$data['communication'] = $communication;
		$data['rand'] = rand(1111, 9999);
		$data['type'] = $type;
		$data['content'] = $this->formatContent($content, $data, $type);

		$result = $this->add($data);
		$result = (boolean)$result;
		// if ($result && in_array($type, $this->_mobileType)) {
		// 	$xmlstr = $this->post($data['communication'], $data['content']);
		// 	$xml = simplexml_load_string($xmlstr);
		// 	$code = (string)$xml->code;
		// 	$result = ($code == 2) ? true : false;
		// }

		if ($result and in_array($type, $this->_mobileType)) {
			$result = model('Sms')->newSendSMS($data['communication'], $data['content']);
			$result or $this->setError('发送失败');
		}

		return $result;
	}

	public function checkCaptcha($communication, $captcha, $type) {
		$communication = t($communication);
		if (!$communication) {
			$this->setError('联系信息有误。');
			return false;
		} elseif (empty($captcha)) {
			$this->setError('验证码信息有误。');
			return false;
		} elseif (!array_key_exists($type, $this->_defaultHash)) {
			$this->setError('发送类型有误。');
			return false;
		}

		$map['communication'] = $communication;
		$map['type'] = $type;
		$dbCaptcha = $this->where($map)->order('captcha_id DESC')->getField('rand');
		return ($dbCaptcha === $captcha) ? true : false;
	}

	private function formatContent($content, $data, $type) {
		switch ($type) {
			case 1:
			case 2:
			case 4:
			case 5:
				$content = str_replace('{rand}', $data['rand'], $this->_defaultHash[$type]);
				break;
			case 3:
				tsload(ADDON_PATH.'brary/String.class.php');
		        $rndstr = String::rand_string(5, 3);
		        $pwd = $rndstr.$data['rand'];
				$content = str_replace('{pwd}', $pwd, $this->_defaultHash[$type]);
				$map['login'] = $data['communication'];
				$uname = model('User')->where($map)->getField('uname');
				$content = str_replace('{name}', $uname, $content);

				$setuser['login_salt'] = rand(10000, 99999);
				$setuser['password'] = md5(md5($pwd).$setuser['login_salt']);
				model('User')->where($map)->save($setuser);
				break;
		}

		return $content;
	}

	public function post($mobile, $content) {
		// 获取配置文件，没有进行加密 Todo
		$smsConf = model('Xdata')->get('admin_Config:sms');

		$target = $smsConf['sms_server'];

		$postData = array();
		$postData['account'] = $smsConf['sms_account'];
		$postData['password'] = $smsConf['sms_password'];
		$postData['mobile'] = $mobile;
		// $postData['content'] = rawurlencode($content);
		$postData['content'] = $content;

		$postParams = http_build_query($postData);

		$gets = $this->doPost($postParams, $target);

		return $gets;
	}

	private function doPost($curlPost, $url) {
		if (function_exists('curl_init')) {
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_HEADER, false);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($curl, CURLOPT_NOBODY, true);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $curlPost);
			$return_str = curl_exec($curl);
			curl_close($curl);
			return $return_str;
		} else {
			die('需要开启curl扩展');
		}
	}
}