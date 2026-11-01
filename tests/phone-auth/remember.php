<?php
require __DIR__.'/bootstrap.php';

// WordPress boundary recorders only; no real cookie, credential, network or SMS.
$rememberCookies = [];
function wp_set_current_user($id) { $GLOBALS['fixtureUser'] = (int)$id; return get_user_by('id', $id); }
function wp_set_auth_cookie($id, $remember = false, $secure = '', $token = '') {
    $GLOBALS['rememberCookies'][] = ['id'=>(int)$id, 'remember'=>$remember];
    $GLOBALS['fixtureLoginCalls'][] = (int)$id;
}
class RememberSession {
    public int $initializations = 0;
    public function init_session_cookie(): void { $this->initializations++; }
}
function reset_remember_fixture(): void {
    global $wpdb,$fixtureUser,$fixtureSession,$fixtureProviderMode,$fixtureCalls,$fixtureLoginCalls,$rememberCookies,$fixtureWC;
    foreach(['options','users','usermeta','provider_ops','provider_calls'] as $table) $wpdb->db->exec('DELETE FROM '.$table);
    $fixtureUser=0; $fixtureSession='fixture-wordpress-session'; $fixtureProviderMode='ok';
    $fixtureCalls=[]; $fixtureLoginCalls=[]; $rememberCookies=[];
    $fixtureWC=(object)['session'=>new RememberSession()];
}
$checks=[];
function remember_check($name,$ok): void { global $checks; if(!$ok)throw new RuntimeException($name);$checks[]=$name; }
$_SERVER=['REQUEST_METHOD'=>'POST','HTTP_ORIGIN'=>home_url(),'REMOTE_ADDR'=>'127.0.0.1','REQUEST_URI'=>'/checkout/'];
ob_start();moto_phone_render('checkout');$markup=ob_get_clean();
preg_match('/<label class="moto-phone-auth__remember"[^>]*>.*?<\/label>/s',$markup,$match);
$rememberMarkup=$match[0]??'';
if (($argv[1]??'')==='--markup') { echo json_encode(['rememberMarkup'=>$rememberMarkup]); exit; }
remember_check('Checkout renders one optional remember choice',substr_count($markup,'name="remember"')===1 && !str_contains($rememberMarkup,' required') && !str_contains($rememberMarkup,' checked'));
remember_check('Remember choice has label and unique id',str_contains($rememberMarkup,'Запомнить меня') && preg_match('/for="([^"]+)".*id="\1"/s',$rememberMarkup)===1);
$session=str_repeat('d',64);$phone='+79991234567';
reset_remember_fixture();fixture_send($session,$phone);$result=moto_phone_verify($session,'123456',false);$id=$fixtureUser;
remember_check('Unchecked valid OTP creates a session login',!is_wp_error($result) && $id>0 && $rememberCookies===[['id'=>$id,'remember'=>false]]);
remember_check('Session login initializes WooCommerce session',$fixtureWC->session->initializations===1);
remember_check('Session proof consumed before replay',moto_phone_state('session:'.$session)['status']==='consumed');
$replay=moto_phone_verify($session,'123456',true);
remember_check('Replay cannot extend persistence',is_wp_error($replay) && $replay->get_error_code()==='expired' && count($rememberCookies)===1);
reset_remember_fixture();fixture_send($session,$phone);$result=moto_phone_verify($session,'123456',true);
remember_check('Checked valid OTP keeps WooCommerce persistent login',!is_wp_error($result) && count($fixtureLoginCalls)===1 && $rememberCookies===[]);
reset_remember_fixture();fixture_send($session,$phone);$result=moto_phone_verify($session,'123456');
remember_check('Legacy caller keeps its persistent login behavior',!is_wp_error($result) && count($fixtureLoginCalls)===1 && $rememberCookies===[]);
reset_remember_fixture();fixture_send($session,$phone);$result=moto_phone_verify($session,'000000',false);
remember_check('Wrong OTP issues no login cookie',is_wp_error($result) && $fixtureLoginCalls===[] && $rememberCookies===[] && $fixtureWC->session->initializations===0);
reset_remember_fixture();$fixtureUser=wp_insert_user(['user_login'=>'remember-link','role'=>'customer']);$current=$fixtureUser;fixture_send($session,$phone);$result=moto_phone_verify($session,'123456',false);
remember_check('Linking preserves existing authentication',!is_wp_error($result) && $result['linked'] && $fixtureUser===$current && $rememberCookies===[] && $fixtureLoginCalls===[]);
reset_remember_fixture();$fixtureWC->session=null;fixture_send($session,$phone);$result=moto_phone_verify($session,'123456',false);
remember_check('Session login tolerates unavailable WooCommerce handler',!is_wp_error($result) && count($rememberCookies)===1 && $rememberCookies[0]['remember']===false);
echo json_encode(['suite'=>'remember','passed'=>count($checks),'checks'=>$checks,'realSmsSent'=>0],JSON_PRETTY_PRINT).PHP_EOL;
