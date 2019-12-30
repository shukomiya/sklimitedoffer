<?php
/*
 * Plugin Name: SkLimitedOffer
 * Plugin URI: http://devdiary.komish.com/
 * Description: 期間限定ページの作成支援
 * Author: Komiya Shuuichi
 * version: 0.3.3
 * Author URI: http://devdiary.komish.com/
 */

/*
version 0.3.3	2019/11/24
	修正）パラメーターを指定したときのロジックが狂っていた。
version 0.3.2	2018/11/10
	修正）初期化していないフィールドを初期化に加えた。
	修正）dが未定義の時、基準値を今日の日付にした。
verison 0.3.1
	修正）カスタムフィールドが未設定時、is_campaignはfalseを返すように修正。
version 0.3
	修正) magidを削除。
version 0.2
	修正）uidを削除。uidがあるとautobiz以外へ対応できないため。
	
version 0.1.1
	追加）デバッグ用パラメータ

version 0.1.2
	修正）期限切れにならない
*/

//date_default_timezone_set('Asia/Tokyo');

class sklimitedoffer_t {
	private $is_campin = false;
	private $interval = 0;
	private $db_version = '1.6'; 
	private $start_date = '';
	private $limit_date = '';
	private $table_name = '';
	private $is_url_drive = false;
	private $one_time = false;
	
	public function __construct() {
		global $wpdb;

		$this->table_name = $wpdb->prefix . 'sklimited_offer';
        $this->is_campin = false;
        $this->interval = 0;
        $this->limit_date = '';
		$this->is_url_drive = false;
		$this->one_time = false;
		self::install();
	}
	
	public function get_url_drive() {
		return $this->is_url_drive;
	}
	
	function is_campaign($begin, $end) {

	    if ( $begin === 0 && $end === 0 ) {
			$begin_val = "camp_begin";
			$end_val = "camp_end";
			
			$begin = get_post_meta(get_the_ID(), $begin_val , true);
			$end = get_post_meta(get_the_ID(), $end_val, true);
			if (empty($begin) || empty($end)){
				return false;
			}
		} else {
			if ($begin === 0) {
				$now = date( "Y/m/d H:i:s" );
				$close = date( "Y/m/d H:i:s", strtotime( $end . ' +1 day' ) );
			    if ( strtotime($now) <= strtotime($close) ) {
					return true;
				}else{
					return false;
				}
			} else if ($end === 0){
				$now = date( "Y/m/d H:i:s" );
				$open = date( "Y/m/d H:i:s", strtotime( $begin ) );
			    if ( strtotime($open) <= strtotime($now) ) {
					return true;
				}else{
					return false;
				}
			}
		}
		
		$now = date( "Y/m/d H:i:s" );
		$open = date( "Y/m/d H:i:s", strtotime( $begin ) );
		$close = date( "Y/m/d H:i:s", strtotime( $end . ' +1 day' ) );
		
	    if ( strtotime($open) <= strtotime($now) && strtotime($now) <= strtotime($close) ) {
			return true;
		}else{
			return false;
		}
	}
	
	public function get_is_campin($begin, $end) {
		if ( $this->is_url_drive ) {
			return $this->is_campin;
		} else {
			return self::is_campaign($begin, $end);
		}
	}

	public function get_limit_date() {
		if ( $this->is_url_drive ) {
			return $this->limit_date;
		}else{
			return get_post_meta(get_the_ID(), 'camp_end', true);
		}
	}
	
	function install() {
		global $wpdb;
	
		$installed_ver = get_option( 'sklimited_offer_db_version', '0' );

		if ( $installed_ver != $this->db_version ) {
		
			$table_name = $this->table_name;
			$charset_collate = $wpdb->get_charset_collate();
		
			$sql = "CREATE TABLE $table_name (
				id mediumint(9) NOT NULL AUTO_INCREMENT,
				postid mediumint(9) NOT NULL,
				email varchar(55) DEFAULT '' NOT NULL,
				limit_date date DEFAULT '0000-00-00' NOT NULL,
				reg_date date DEFAULT '0000-00-00' NOT NULL,
				UNIQUE KEY id (id),
				KEY postid (postid),
				KEY email (email)
			) $charset_collate;";
		
			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );

			update_option( 'sklimited_offer_db_version', $installed_ver );
		}
	}
	
	function find_email($postid, $email) {
		global $wpdb;
		
		$query = 'SELECT * FROM `' . $this->table_name . '` WHERE `postid` = %d AND `email` = %s;';
		$prepared = $wpdb->prepare($query, $postid, $email);
		$rows = $wpdb->get_results($prepared);
		if (count($rows) === 1){
			if ($email === $rows[0]->email){
				$line = array();
				$line['id'] = $rows[0]->id;
				$line['postid'] = $rows[0]->postid;
				$line['email'] = $rows[0]->email;
				$line['limit_date'] = $rows[0]->limit_date;
				return $line;
			}
		}
		return false;
	}
	
	function email_exitst($line) {
		global $wpdb;
		
		$row = self::find_email($line['postid'], $line['email']);
		if ($row !== false){
			$this->limit_date = $row['limit_date'];
			
			if ($this->one_time)
				return false;
				
			$end_date = new DateTime($row['limit_date'], new DateTimeZone('Asia/Tokyo'));
			$now = new DateTime( date('Y/m/d'), new DateTimeZone('Asia/Tokyo') );
			
			if ($now <= $end_date){
				return true;
			}
		} else {
			$str = sk_get_url_param('t');
			if ($str != '') {
				$this->interval = intval($str) - 1;
			}
			
			$current_date_str = sk_get_url_param('d');
			if ($current_date_str != '') {
				$current_date = new DateTime( $current_date_str, new DateTimeZone('Asia/Tokyo') );
			} else {
				//日付が未設定なら今日を基準にする
				$current_date = new DateTime(date('Y/m/d'), new DateTimeZone('Asia/Tokyo'));
			}
			$end_date = $current_date->modify("+$this->interval day");
			$this->limit_date = $end_date->format('Y/m/d');

			$line['limit_date'] = $this->limit_date;
			$end_date = new DateTime($line['limit_date'], new DateTimeZone('Asia/Tokyo'));
			$now = new DateTime( date('Y/m/d'), new DateTimeZone('Asia/Tokyo') );

			$query = 'INSERT INTO `' . $this->table_name 
					. '` (`id`, `postid`, `email`, `limit_date`, `reg_date`) VALUES (NULL, %d, %s, %s, %s);';
			$prepared = $wpdb->prepare($query, $line['postid'], $line['email'], $line['limit_date'], $now->format('Y/m/d'));
			$wpdb->query($prepared);

			if ($now <= $end_date){
				return true;
			}
		}
		return false;
	}
	
	public function init($postid, $interval = 0, $one_time = 0){
        $this->is_campin = false;
        $this->interval = 0;
        $this->limit_date = '';
		$this->is_url_drive = false;
		$this->one_time = false;
		$line = array();

		if ($postid === '')
			return;
		
		$line['postid'] = $postid;
		
		if ($one_time == 0) {
			$this->one_time = false;
		}else{
			$this->one_time = true;
		}

		$this->is_url_drive = false;
		$this->is_campin = false;
		$this->interval = $interval;

		$str = sk_get_url_param('dbg');
		if ($str === '1'){
			$this->is_url_drive = true;
			$this->interval = 1;
			$this->limit_date = date('Y/m/d');
			$this->is_campin = true;
			return;
		}


		$line['email'] = sk_get_url_param('email');
		if ($line['email'] === '')
			return;
		
		$this->is_url_drive = true;
		
		if (self::email_exitst($line)) {
			$this->is_campin = true;
		}else{
			$this->is_campin = false;
		}
	}

}

$sklimitedoffer = new sklimitedoffer_t;

function set_limited_offer($atts, $content = null ) {
	global $sklimitedoffer;

    extract( shortcode_atts( array(
    	'interval' => 1,
    	'one_time' => 0
    	), $atts ));
    	
    $interval = intval($interval) - 1;

	$sklimitedoffer->init(get_the_ID(), $interval, $one_time);
}
add_shortcode('limited_offer', 'set_limited_offer');

function is_limited_offer_campin($atts, $content = null){
	global $sklimitedoffer;
	
    extract( shortcode_atts( array(
    	'begin' => 0,
    	'end' => 0
    	), $atts ));
    
	if ($sklimitedoffer->get_is_campin($begin, $end)){
	    return do_shortcode( $content );
	} else {

		return '';
	}

}
add_shortcode('limit_in', 'is_limited_offer_campin');

function is_limited_offer_campout($atts, $content = null){
	global $sklimitedoffer;
	
    extract( shortcode_atts( array(
    	'begin' => 0,
    	'end' => 0
    	), $atts ));
    	
	if (!$sklimitedoffer->get_is_campin($begin, $end)){
	    return do_shortcode( $content );
	} else {
		return '';
	}

}
add_shortcode('limit_out', 'is_limited_offer_campout');

function sk_get_limit_date( $atts, $content = null ){
	global $sklimitedoffer;

	
    extract( shortcode_atts( array(
    	'yb' => 1
    	), $atts ));

	$end = $sklimitedoffer->get_limit_date();

	if ( empty( $end) )
		return '';
		
	$close = date( "Y/m/d", strtotime( $end ) );
	$s = date( 'n月j日', strtotime( $close ) );
	if ($yb === 1){
		$week = array( "日", "月", "火", "水", "木", "金", "土" );
		$w = '(' . $week[date("w", strtotime( $close ))] . ')';
	}else{
		$w ='';
	}
	return mb_convert_kana($s, 'A', "utf-8") . $w;
	
}
add_shortcode('limit_date', 'sk_get_limit_date');


?>