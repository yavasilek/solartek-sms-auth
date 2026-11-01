<?php
define('ABSPATH',__DIR__.'/');
class WP_Error{function __construct(public $code='',public $message=''){}function get_error_code(){return $this->code;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
$options=[];$salt='fixture-salt';
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,$autoload=false){$GLOBALS['options'][$key]=$value;return true;}
function wp_salt($key){return $GLOBALS['salt'].$key;}
function wp_parse_url($url,$component=-1){return parse_url($url,$component);}
function esc_url_raw($url,$protocols=[]){return preg_match('~^https?://~',$url)?$url:'';}
function esc_url($url){return htmlspecialchars(esc_url_raw($url),ENT_QUOTES,'UTF-8');}
function apply_filters($name,$value,...$args){return $name==='ssa_consent_document_url'?($GLOBALS['consent_url']??''):$value;}
function get_privacy_policy_url(){return 'https://shop.example.test/privacy/';}function home_url($path){return 'https://shop.example.test'.$path;}
function plugins_url($path,$file){return 'https://shop.example.test/wp-content/plugins/solartek-sms-auth/'.$path;}
define('SSA_FILE',__FILE__);
require __DIR__.'/../plugin/solartek-sms-auth/includes/settings.php';
$checks=0;function check($name,$ok){global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
check('hostname normalized',ssa_validate_service_url('sms.example.test')==='https://sms.example.test');
foreach(['http://example.test','https://user:pass@example.test','https://example.test/?token=x','https://127.0.0.1','https://10.0.0.1','https://localhost','https://example.test:8443'] as $url)check('unsafe URL rejected',is_wp_error(ssa_validate_service_url($url)));
check('reverse proxy subpath allowed',ssa_validate_service_url('https://api.example.test/sms/')==='https://api.example.test/sms');
check('invalid path',is_wp_error(ssa_validate_settings(['api_path'=>'//evil.test/'])));
check('invalid repo',is_wp_error(ssa_validate_settings(['repository'=>'owner/../other'])));
check('negative limit rejected',is_wp_error(ssa_validate_settings(['daily_limit'=>'-1'])));
check('attempt bound enforced',is_wp_error(ssa_validate_settings(['captcha_attempts'=>100])));
check('daily >= hourly',is_wp_error(ssa_validate_settings(['phone_hourly_limit'=>15,'phone_daily_limit'=>10])));
$config=ssa_validate_settings(['service_url'=>'gateway.example.test','phone_daily_limit'=>20,'phone_hourly_limit'=>4,'enabled'=>'1']);
check('typed settings',!is_wp_error($config)&&$config['enabled']===true&&$config['phone_hourly_limit']===4);
$options['ssa_settings']=$config;check('custom endpoint used',ssa_service_url('abc/verify/')==='https://gateway.example.test/api/v1/auth/challenges/abc/verify/');
$secret='fixture-key-'.bin2hex(random_bytes(20));check('key stored',ssa_store_secret($secret)===true);
check('key encrypted in options',!str_contains(json_encode($options),$secret)&&$options['ssa_secret']['v']===1);
check('key round trip',hash_equals($secret,ssa_secret_get()));
$snapshot=$options['ssa_secret'];$options['ssa_settings']['phone_hourly_limit']=5;check('settings update retains key',ssa_secret_get()===$secret);
$options['ssa_secret']['tag']=base64_encode(str_repeat('x',16));check('tamper fails closed',ssa_secret_get()==='');$options['ssa_secret']=$snapshot;
$salt='rotated-salt';check('different site salts cannot decrypt',ssa_secret_get()==='');$salt='fixture-salt';
check('short key refused',is_wp_error(ssa_store_secret('short')));check('whitespace key refused',is_wp_error(ssa_store_secret(str_repeat('x',20)."\n")));
check('privacy defaults to site policy',ssa_privacy_url()==='https://shop.example.test/privacy/');
check('standalone consent is opt in',str_contains(ssa_consent_statement(),'политике конфиденциальности'));
$GLOBALS['consent_url']='https://shop.example.test/consent/account/';
check('standalone consent has own URL',str_contains(ssa_consent_statement(),'/consent/account/'));
check('standalone consent retains policy',str_contains(ssa_consent_statement(),'https://shop.example.test/privacy/'));
$GLOBALS['consent_url']='javascript:alert(1)';
check('unsafe consent URL falls back',!str_contains(ssa_consent_statement(),'javascript:'));
echo json_encode(['suite'=>'settings','checks'=>$checks,'ok'=>true]).PHP_EOL;

