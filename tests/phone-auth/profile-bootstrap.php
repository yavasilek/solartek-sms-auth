<?php
require __DIR__.'/bootstrap.php';
$fixtureProfileFail=false;
class WC_Customer {
    public string $first_name='', $billing_first_name='', $display_name='Покупатель', $billing_address='Unchanged address';
    public int $save_calls=0;
    public bool $save_failure=false;
    public function __construct(private int $id) {}
    public function get_id() { return $this->id; }
    public function set_first_name($value) { $this->first_name=$value; }
    public function set_billing_first_name($value) { $this->billing_first_name=$value; }
    public function set_display_name($value) { $this->display_name=$value; }
    public function save() { if($this->save_failure)throw new RuntimeException('Fixture session error');$this->save_calls++;return $this->id; }
}
class ProfileHttpError extends RuntimeException { public function __construct(public int $status) { parent::__construct('Fixture HTTP error'); } }
class ProfileRedirect extends RuntimeException { public function __construct(public string $url) { parent::__construct('Fixture redirect'); } }
function remove_action($name,$callback,...$args) { global $fixtureActions; $fixtureActions[$name]=array_values(array_filter($fixtureActions[$name]??[],fn($v)=>$v!==$callback)); }
function sanitize_text_field($value) { return trim(preg_replace('/[\x00-\x1F\x7F]+/',' ',strip_tags($value))); }
function fixture_profile_nonce() { return hash_hmac('sha256',get_current_user_id().':'.wp_get_session_token().':moto_account_name','fixture-profile-salt'); }
function wp_verify_nonce($value,$action) { return is_string($value)&&$action==='moto_account_name'&&hash_equals(fixture_profile_nonce(),$value); }
function wp_nonce_field($action,$name) { echo '<input type="hidden" name="'.esc_attr($name).'" value="'.esc_attr(fixture_profile_nonce()).'">'; }
function wp_update_user($data) {
    global $wpdb,$fixtureProfileFail;
    if($fixtureProfileFail)return new WP_Error('db_failure','fixture failure');
    if(isset($data['display_name'])) { $q=$wpdb->db->prepare('UPDATE users SET display_name=? WHERE ID=?');$q->execute([$data['display_name'],$data['ID']]); }
    if(isset($data['first_name']))update_user_meta($data['ID'],'first_name',$data['first_name']);
    return $data['ID'];
}
function wp_die($message,$title='',$args=[]) { throw new ProfileHttpError($args['response']??500); }
function wc_add_notice($message,$type) { if(PHP_SAPI==='cli-server')setcookie('fixture_notice',$type,['path'=>'/','httponly'=>true,'samesite'=>'Strict']); }
function wp_safe_redirect($url) { throw new ProfileRedirect($url); }
require dirname(__DIR__,2).'/plugin/solartek-sms-auth/compat/motonaklejki-account-profile.php';
