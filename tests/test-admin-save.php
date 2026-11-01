<?php
define('ABSPATH',__DIR__.'/');define('SSA_VERSION','fixture');define('SSA_BASENAME','solartek-sms-auth/solartek-sms-auth.php');
class WP_Error{public function __construct(public $code='',public $message=''){}public function get_error_message(){return $this->message;}}
class SaveRedirect extends RuntimeException{}
function is_wp_error($value){return $value instanceof WP_Error;}
$options=[];$invalidations=[];
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;return true;}
function delete_site_transient($key){$GLOBALS['invalidations'][]=$key;}
function set_transient(...$args){}
function wp_salt($type){return 'fixture-salt-'.$type;}
function current_user_can($cap){return $cap==='manage_options';}
function check_admin_referer(...$args){}
function get_current_user_id(){return 1;}
function wp_unslash($data){return $data;}
function wp_safe_redirect($url){throw new SaveRedirect($url);}
function wp_die(...$args){throw new RuntimeException('Unexpected wp_die');}
function admin_url($path){return 'https://fixture.test/wp-admin/'.$path;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function add_action(...$args){}function add_filter(...$args){}
require __DIR__.'/../plugin/solartek-sms-auth/includes/settings.php';
require __DIR__.'/../plugin/solartek-sms-auth/includes/admin.php';
$checks=0;
foreach([1,2,3,4,'repository-change'] as $scenario){
    $options=['ssa_settings'=>array_merge(ssa_defaults(),['all_roles'=>true])];$invalidations=[];
    ssa_store_secret('fixture-'.bin2hex(random_bytes(20)));$secret=$options['ssa_secret'];
    $_POST=['step'=>$scenario==='repository-change'?3:$scenario];
    if($scenario==='repository-change')$_POST['repository']='another-owner/sms-auth';
    try{ssa_admin_save();throw new RuntimeException('Missing redirect');}catch(SaveRedirect $redirect){}
    if($options['ssa_secret']!==$secret)throw new RuntimeException('Saved key changed');$checks++;
    if($options['ssa_settings']['all_roles']!==($scenario!==2))throw new RuntimeException('Role setting changed on wrong wizard step');$checks++;
    $expected=$scenario==='repository-change'?['update_plugins']:[];
    if($invalidations!==$expected)throw new RuntimeException('Unexpected update cache invalidation on '.$scenario);$checks++;
    if(($options['ssa_settings']['setup_step']??0)!==min(4,(int)$_POST['step']+1))throw new RuntimeException('Wizard did not advance');$checks++;
}
echo json_encode(['suite'=>'admin-save','checks'=>$checks,'ok'=>true]).PHP_EOL;
