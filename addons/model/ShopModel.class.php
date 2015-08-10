<?php
/**
 * 插件模型 - 数据对象模型
 * @author jason <yangjs17@yeah.net>
 * @version TS3.0
 */
class ShopModel extends Model {
	protected $tableName = 'shop_product';
	var $api_url = 'http://www.cangm.com/index.php/openapi/sns/api';
	function GetShopInfo($member_id) {
		$param ['method'] = 'shop.GetShopInfo';
		$param ['member_id'] = $member_id;
		return $this->_deal ( $param );
	}
	function GetGoodsInfo($product_id) {
		$param ['method'] = 'goods.GetGoodsInfo';
		$param ['product_id'] = $product_id;
		return $this->_deal ( $param );
	}
	function GetMemberInfo($member_id, $member_type = 'seller') {
		$param ['method'] = 'member.GetMemberInfo';
		$param ['member_id'] = $member_id;
		return $this->_deal ( $param );
	}
	function UpdateToMemberInfo($member_id, $param) {
		// name
		// mobile
		// email
		// nickname
		$param ['method'] = 'member.UpdateToMemberInfo';
		$param ['member_id'] = $member_id;
		return $this->_deal ( $param );
	}
	function _deal($param) {
		$param ['api_version'] = '1.0';
		$param ['sign'] = 1;
		
		$url = $this->api_url . '?' . http_build_query ( $param );
		// dump($url);
		
		$content = file_get_contents ( $url );
		$content = json_decode ( $content, true );
		// dump($content);
		
		return $content;
	}
}
