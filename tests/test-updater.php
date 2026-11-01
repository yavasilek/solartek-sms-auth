<?php
define('ABSPATH',__DIR__.'/');define('SSA_VERSION','1.0.0');define('SSA_BASENAME','solartek-sms-auth/solartek-sms-auth.php');define('HOUR_IN_SECONDS',3600);
class WP_Error{function __construct(public $code='',public $message=''){}function get_error_code(){return $this->code;}}
function is_wp_error($v){return $v instanceof WP_Error;}
$repository='owner/module';$transients=[];$http=[];$calls=[];
function ssa_config($key){return $key==='repository'?$GLOBALS['repository']:true;}
function get_site_transient($key){return $GLOBALS['transients'][$key]??false;}function set_site_transient($key,$value,$ttl){$GLOBALS['transients'][$key]=$value;}
function wp_safe_remote_get($url,$args){$GLOBALS['calls'][]=[$url,$args];return $GLOBALS['http'][$url]??new WP_Error('missing');}
function wp_remote_retrieve_response_code($reply){return $reply['status'];}function wp_remote_retrieve_body($reply){return $reply['body'];}
function add_filter(...$args){}function esc_url($s){return $s;}
require __DIR__.'/../plugin/solartek-sms-auth/includes/updater.php';
$checks=0;function check($name,$ok){global $checks;if(!$ok)throw new RuntimeException($name);$checks++;}
function fixture_release($version='1.0.1',$digest=null){return ['tag_name'=>'v'.$version,'draft'=>false,'prerelease'=>false,'assets'=>[['name'=>'solartek-sms-auth.zip','browser_download_url'=>'https://github.com/owner/module/releases/download/v'.$version.'/solartek-sms-auth.zip','size'=>123,'digest'=>$digest??('sha256:'.str_repeat('a',64))]]];}
$url='https://api.github.com/repos/owner/module/releases/latest';$http[$url]=['status'=>200,'body'=>json_encode(fixture_release())];
$release=ssa_release_info();check('stable release parsed',!is_wp_error($release)&&$release['version']==='1.0.1');
check('digest read',strlen($release['sha256'])===64);check('TLS and no redirects',$calls[0][1]['sslverify']===true&&$calls[0][1]['redirection']===0);
$count=count($calls);ssa_release_info();check('metadata cached',count($calls)===$count);
$t=(object)['checked'=>[SSA_BASENAME=>'1.0.0'],'response'=>['unrelated'=>(object)['new_version'=>'9']]];$t=ssa_update_transient($t);
check('WP update offered',isset($t->response[SSA_BASENAME])&&$t->response[SSA_BASENAME]->new_version==='1.0.1');check('other plugin untouched',isset($t->response['unrelated']));
$t->checked[SSA_BASENAME]='1.0.1';$t=ssa_update_transient($t);check('updated disk metadata clears stale offer',!isset($t->response[SSA_BASENAME])&&isset($t->no_update[SSA_BASENAME]));
$http[$url]['body']=json_encode(array_merge(fixture_release(),['prerelease'=>true]));check('prerelease rejected',is_wp_error(ssa_release_info(null,true)));
$bad=fixture_release();$bad['assets'][0]['browser_download_url']='https://evil.example/module.zip';$http[$url]['body']=json_encode($bad);check('cross-origin asset rejected',is_wp_error(ssa_release_info(null,true)));
$repository='../evil';check('bad repo rejected',is_wp_error(ssa_release_info(null,true)));$repository='owner/module';
check('other package not intercepted',ssa_pre_download(false,'https://downloads.wordpress.org/plugin/other.zip',null,['plugin'=>'other/other.php'])===false);
check('own wrong source blocked',is_wp_error(ssa_pre_download(false,'https://evil.example/plugin.zip',null,['plugin'=>SSA_BASENAME])));
$temp=tempnam(sys_get_temp_dir(),'ssa-zip-');$zip=new ZipArchive();$zip->open($temp,ZipArchive::OVERWRITE);$zip->addFromString('solartek-sms-auth/solartek-sms-auth.php',"<?php\n/**\n * Version: 1.0.1\n */\n");$zip->close();
$info=['version'=>'1.0.1','size'=>filesize($temp),'sha256'=>hash_file('sha256',$temp)];check('valid package accepted',ssa_verify_package($temp,$info)===true);
$info['sha256']=str_repeat('b',64);check('tampered hash refused',is_wp_error(ssa_verify_package($temp,$info)));
$zip=new ZipArchive();$zip->open($temp,ZipArchive::OVERWRITE);$zip->addFromString('../outside.php','bad');$zip->close();$info=['version'=>'1.0.1','size'=>filesize($temp),'sha256'=>hash_file('sha256',$temp)];check('traversal package refused',is_wp_error(ssa_verify_package($temp,$info)));unlink($temp);
echo json_encode(['suite'=>'updater','checks'=>$checks,'ok'=>true]).PHP_EOL;
