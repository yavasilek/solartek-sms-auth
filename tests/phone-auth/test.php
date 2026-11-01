<?php
require __DIR__.'/bootstrap.php';
$checks=[];
function check(string $name,$value,$expected=true): void { global $checks; if ($value!==$expected) throw new RuntimeException($name.' failed'); $checks[]=$name; }
function error_code($result) { return is_wp_error($result)?$result->get_error_code():''; }
function reset_fixture(): void {
    global $wpdb,$fixtureUser,$fixtureSession,$fixtureProviderMode,$fixtureCalls,$fixtureLoginCalls;
    foreach(['options','users','usermeta','provider_ops','provider_calls'] as $table) $wpdb->db->exec('DELETE FROM '.$table);
    $fixtureUser=0; $fixtureSession='fixture-wordpress-session'; $fixtureProviderMode='ok'; $fixtureCalls=[]; $fixtureLoginCalls=[];
}
$session=str_repeat('a',64); $phone='+79991234567';
check('live fixture key configuration enabled',moto_phone_enabled());
foreach(['+7 (999) 123-45-67','8 999 1234567','9991234567'] as $input) check('normalize '.$input,moto_phone_normalize($input),$phone);
$variants=['+79991234567','79991234567','89991234567','9991234567',' +7 (999) 123-45-67 ',"+7\u{00A0}(999)\u{202F}123\u{2011}45\u{2013}67",'8.999.123.45.67'];
foreach($variants as $i=>$input) check('equivalent input format '.$i,moto_phone_normalize($input),$phone);
foreach([[],null,'+1 234 567 8901','+7 495 1234567','79991234567<script>','79991234567 доб. 9','7+9991234567','++79991234567',"\xFF",''] as $input) check('reject malformed or unsupported phone',moto_phone_normalize($input),'');
$_SERVER=['REQUEST_METHOD'=>'POST','HTTP_ORIGIN'=>home_url(),'REMOTE_ADDR'=>'127.0.0.1'];
check('same origin accepted',moto_phone_same_origin());
$_SERVER['HTTP_ORIGIN']='https://evil.test'; check('foreign origin denied',moto_phone_same_origin(),false);
$_SERVER['HTTP_ORIGIN']=home_url(); $_SERVER['HTTP_REFERER']='https://evil.test'; check('conflicting referer denied',moto_phone_same_origin(),false); unset($_SERVER['HTTP_REFERER']);
$_SERVER['HTTP_SEC_FETCH_SITE']='cross-site'; check('cross-site fetch denied',moto_phone_same_origin(),false); unset($_SERVER['HTTP_SEC_FETCH_SITE']);
unset($_SERVER['HTTP_ORIGIN']); check('missing origin/referer denied',moto_phone_same_origin(),false); $_SERVER['HTTP_ORIGIN']=home_url();
check('IDN origin normalized',moto_phone_origin('https://мотонаклейки.рф'),moto_phone_origin('https://'.idn_to_ascii('мотонаклейки.рф')));
$nonce=moto_phone_nonce($session);
check('browser CSRF accepted',moto_phone_valid_nonce($session,$nonce));
check('another browser CSRF denied',moto_phone_valid_nonce(str_repeat('b',64),$nonce),false);
check('old CSRF denied',moto_phone_valid_nonce($session,moto_phone_nonce($session,(int)floor(time()/600)-2)),false);
check('future CSRF denied',moto_phone_valid_nonce($session,moto_phone_nonce($session,(int)floor(time()/600)+1)),false);
$token=moto_phone_lock('test'); check('lock acquired',$token!==''); check('parallel lock excluded',moto_phone_lock('test'),'');
moto_phone_unlock('test','wrong-token'); check('wrong lock owner cannot unlock',moto_phone_lock('test'),''); moto_phone_unlock('test',$token);
moto_phone_store('lock:test',['token'=>'expired','expires'=>time()-1]); $token=moto_phone_lock('test'); check('expired lock reclaimed',$token!==''); moto_phone_unlock('test',$token);
$sent=fixture_send($session,$phone); check('challenge sent',$sent['status'],'sent');
check('provider only called once',count($fixtureCalls),1);
check('public state has no provider ID',isset($sent['challenge_id']),false);
check('public state has no idempotency',isset($sent['idempotency']),false);
check('fixed HTTPS origin',$fixtureCalls[0][0],'https://sms.solartek.ru/api/v1/auth/challenges/');
check('redirects disabled',$fixtureCalls[0][1]['redirection'],0); check('TLS verification on',$fixtureCalls[0][1]['sslverify']);
check('idempotency header present',str_starts_with($fixtureCalls[0][1]['headers']['Idempotency-Key'],'moto-phone-'));
fixture_send($session,$phone); check('duplicate send uses existing challenge',count($fixtureCalls),1);
check('phone cooldown survives another browser',error_code(fixture_send(str_repeat('b',64),$phone)),'cooldown');
foreach($variants as $i=>$input) check('format cannot bypass phone cooldown '.$i,error_code(fixture_send(hash('sha256','format-'.$i),moto_phone_normalize($input))),'cooldown');
check('format variants share single provider operation',count($fixtureCalls),1);
check('wrong OTP denied',error_code(moto_phone_verify($session,'000000')),'invalid_code');
check('wrong OTP cannot create user',(int)$wpdb->db->query('SELECT COUNT(*) FROM users')->fetchColumn(),0);
check('wrong OTP cannot log in',count($fixtureLoginCalls),0);
for($i=0;$i<4;$i++) moto_phone_verify($session,'000000');
check('sixth OTP attempt denied',error_code(moto_phone_verify($session,'123456')),'attempts');
check('attempt limit persisted',moto_phone_state('session:'.$session)['attempts'],5);
reset_fixture(); fixture_send($session,$phone); $verified=moto_phone_verify($session,'123456');
check('correct OTP logs in',count($fixtureLoginCalls),1); $id=$fixtureLoginCalls[0];
check('new customer has customer role',get_user_by('id',$id)->roles,['customer']);
check('new customer has verified phone',get_user_meta($id,'_moto_verified_phone',true),$phone);
check('no fabricated email',$wpdb->db->query('SELECT user_email FROM users')->fetchColumn(),'');
check('local replay denied',error_code(moto_phone_verify($session,'123456')),'expired');
check('replay cannot set another cookie',count($fixtureLoginCalls),1);
$fixtureUser=0; $s2=str_repeat('b',64); moto_phone_store('cooldown:'.$phone,['expires'=>time()-1]); fixture_send($s2,$phone); moto_phone_verify($s2,'123456');
check('verified existing customer reused',(int)$wpdb->db->query('SELECT COUNT(*) FROM users')->fetchColumn(),1);
check('same customer session created',$fixtureLoginCalls[1],$id);
reset_fixture(); $fixtureProviderMode='timeout'; fixture_send($session,$phone);
check('uncertain send saved before retry',moto_phone_state('session:'.$session)['status'],'uncertain');
check('uncertain cannot switch phone',error_code(fixture_send($session,'+79990000099')),'uncertain');
$first=$fixtureCalls[0][1]; $pending=moto_phone_state('session:'.$session); $pending['next_check_at']=0; moto_phone_store('session:'.$session,$pending); $fixtureProviderMode='ok'; fixture_send($session,$phone,true);
check('retry exact idempotency',$fixtureCalls[1][1]['headers']['Idempotency-Key'],$first['headers']['Idempotency-Key']);
check('retry exact body',$fixtureCalls[1][1]['body'],$first['body']);
check('provider performs one send across retry',(int)$wpdb->db->query('SELECT COUNT(*) FROM provider_ops')->fetchColumn(),1);
check('idempotent recovery does not reserve new budget',moto_phone_state('rate:phone:'.$phone.':3600:'.(int)floor(time()/3600))['count'],1);
foreach(['simulated','wrong_phone','failed','malformed'] as $mode) { reset_fixture(); $fixtureProviderMode=$mode; check('reject provider challenge '.$mode,error_code(fixture_send($session,$phone)),'provider_rejected'); check('no auth for rejected challenge',count($fixtureLoginCalls),0); }
foreach(['simulated','wrong_verify_phone'] as $mode) { reset_fixture(); fixture_send($session,$phone); $fixtureProviderMode=$mode; check('reject verify '.$mode,error_code(moto_phone_verify($session,'123456')),'invalid_code'); check('no cookie for rejected proof',count($fixtureLoginCalls),0); }
reset_fixture(); fixture_send($session,$phone); $state=moto_phone_state('session:'.$session); $state['expires']=time()-1; moto_phone_store('session:'.$session,$state);
check('expired challenge denied',error_code(moto_phone_verify($session,'123456')),'expired');
reset_fixture(); $fixtureProviderMode='four'; $sent=fixture_send($session,$phone); check('four digit contract supported',$sent['code_length'],4); check('four digit verification succeeds',is_wp_error(moto_phone_verify($session,'1234')),false);
reset_fixture(); $id=wp_insert_user(['user_login'=>'legacy','role'=>'customer']); update_user_meta($id,'billing_phone','8 (999) 123-45-67');
fixture_send($session,$phone); check('unique legacy contact accepted after OTP',is_wp_error(moto_phone_verify($session,'123456')),false);
check('legacy customer logged in after proof',in_array($id,$fixtureLoginCalls,true)); check('no duplicate account created',(int)$wpdb->db->query('SELECT COUNT(*) FROM users')->fetchColumn(),1); check('legacy contact formatting preserved',get_user_meta($id,'billing_phone',true),'8 (999) 123-45-67');
reset_fixture(); $id=wp_insert_user(['user_login'=>'legacy-link','role'=>'customer']);
$fixtureUser=$id; $s2=str_repeat('c',64); moto_phone_store('cooldown:'.$phone,['expires'=>time()-1]); fixture_send($s2,$phone); $linked=moto_phone_verify($s2,'123456');
check('signed-in customer links number',$linked['linked']); check('linking preserves current auth',count($fixtureLoginCalls),0); check('linked proof persisted',get_user_meta($id,'_moto_verified_phone',true),$phone);
reset_fixture(); $id=wp_insert_user(['user_login'=>'staff','role'=>'administrator']); update_user_meta($id,'_moto_verified_phone',$phone);
fixture_send($session,$phone); check('SMS cannot authenticate administrator',error_code(moto_phone_verify($session,'123456')),'password_required'); check('admin cookie never issued',count($fixtureLoginCalls),0);
reset_fixture(); $id=wp_insert_user(['user_login'=>'editor','role'=>'editor']); check('editor cannot link SMS',moto_phone_customer(get_user_by('id',$id)),false);
$id=wp_insert_user(['user_login'=>'extra-cap','role'=>'customer']); $wpdb->db->exec('UPDATE users SET caps=\'["manage_woocommerce"]\' WHERE ID='.(int)$id); check('custom privileged customer denied',moto_phone_customer(get_user_by('id',$id)),false);
reset_fixture(); fixture_send($session,$phone); $id=wp_insert_user(['user_login'=>'signed-in','role'=>'customer']); $fixtureUser=$id;
check('guest challenge cannot become account link',error_code(moto_phone_verify($session,'123456')),'expired');
check('guest nonce invalid after login',moto_phone_valid_nonce($session,$nonce),false);
reset_fixture(); $id=wp_insert_user(['user_login'=>'customer','role'=>'customer']); $fixtureUser=$id;
fixture_send($session,$phone); $fixtureSession='new-session'; check('challenge bound to login session',error_code(moto_phone_verify($session,'123456')),'expired');
reset_fixture();
for($i=0;$i<3;$i++) { moto_phone_store('cooldown:'.$phone,['expires'=>time()-1]); check('budget send accepted '.$i,is_wp_error(fixture_send($session,$phone,true)),false); }
moto_phone_store('cooldown:'.$phone,['expires'=>time()-1]); check('hourly phone limit',error_code(fixture_send(str_repeat('c',64),$phone,true)),'rate_limit');
check('rate-limit denies provider spend',count($fixtureCalls),3);
reset_fixture(); for($i=0;$i<6;$i++) { $r=fixture_send($session,'+7999000000'.$i,true); }
check('session quota cannot be reset by changing number',error_code($r),'rate_limit');
reset_fixture(); moto_phone_store('lock:budget',['token'=>'active','expires'=>time()+60]); check('busy budget fails closed',error_code(fixture_send($session,$phone)),'busy'); check('busy budget sends nothing',count($fixtureCalls),0);
// Active options are not autoloaded and challenges contain no OTP.
reset_fixture(); fixture_send($session,$phone); moto_phone_verify($session,'000000');
check('OTP not persisted',str_contains(moto_phone_raw('session:'.$session),'000000'),false);
check('state not autoloaded',(int)$wpdb->db->query("SELECT COUNT(*) FROM options WHERE autoload <> 'no'")->fetchColumn(),0);
// Cleanup crosses a full batch and cannot remove active locks or other options.
for($i=0;$i<550;$i++) moto_phone_store('expired:'.$i,['expires'=>time()-1]);
moto_phone_store('active',['expires'=>time()+600]); $wpdb->replace('options',['option_name'=>'unrelated_option','option_value'=>'keep','autoload'=>'no']);
moto_phone_cleanup();
check('cleanup removes expired record',moto_phone_raw('expired:1'),null);
check('cleanup crosses batch boundary',moto_phone_raw('expired:549'),null);
check('cleanup retains live state',moto_phone_state('active')!==[]);
check('cleanup leaves unrelated option',$wpdb->get_var("SELECT option_value FROM options WHERE option_name='unrelated_option'"),'keep');
reset_fixture(); $id=wp_insert_user(['user_login'=>'legacy-space','role'=>'customer']); update_user_meta($id,'billing_phone','+7 999 123-45-67');
fixture_send($session,$phone); check('legacy unicode contact accepted after proof',is_wp_error(moto_phone_verify($session,'123456')),false); check('unicode contact reuses existing account',$fixtureLoginCalls[0] === $id);
reset_fixture(); $id=wp_insert_user(['user_login'=>'legacy-dashes','role'=>'customer']); update_user_meta($id,'billing_phone',"8\u{2009}(999)\u{202F}123\u{2011}45\u{2013}67");
fixture_send($session,$phone); check('legacy typographic contact accepted after proof',is_wp_error(moto_phone_verify($session,'123456')),false); check('typographic contact reuses existing account',$fixtureLoginCalls[0] === $id);
reset_fixture(); $owner=wp_insert_user(['user_login'=>'owner','role'=>'customer']); update_user_meta($owner,'_moto_verified_phone',$phone);
$fixtureUser=wp_insert_user(['user_login'=>'other','role'=>'customer']); fixture_send($session,$phone);
check('signed-in user cannot claim another verified phone',error_code(moto_phone_verify($session,'123456')),'account_conflict');
check('verified phone owner unchanged',get_user_meta($owner,'_moto_verified_phone',true),$phone);
reset_fixture();
for($i=0;$i<11;$i++) { $_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.'.$i; $r=fixture_send(hash('sha256','ip-test-'.$i),'+799900000'.sprintf('%02d',$i)); }
check('IP quota survives browser and forwarded header changes',error_code($r),'rate_limit');
check('IP quota sends only first ten',(int)$wpdb->db->query('SELECT COUNT(*) FROM provider_ops')->fetchColumn(),10);
reset_fixture();
for($i=0;$i<101;$i++) { $_SERVER['REMOTE_ADDR']='198.51.100.'.($i+1); $r=fixture_send(hash('sha256','global-test-'.$i),'+7999000'.sprintf('%04d',$i)); }
check('global daily cost limit',error_code($r),'rate_limit');
check('global limit sends only first hundred',(int)$wpdb->db->query('SELECT COUNT(*) FROM provider_ops')->fetchColumn(),100);
echo json_encode(['passed'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
