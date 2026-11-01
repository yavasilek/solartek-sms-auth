<?php
require __DIR__.'/bootstrap.php';
$checks=[];
function ok($name,$value) { global $checks; if (!$value) throw new RuntimeException($name); $checks[]=$name; }
function err($value) { return is_wp_error($value)?$value->get_error_code():''; }
function clean() { global $wpdb,$fixtureUser,$fixtureCalls; $wpdb->db->exec('DELETE FROM options'); $fixtureUser=0; $fixtureCalls=[]; }
function aged($session) { $s=moto_phone_state('antibot:'.$session); $s['issued_at']=microtime(true)-4; moto_phone_store('antibot:'.$session,$s); }
$a=str_repeat('a',64); $b=str_repeat('b',64); $phone='+79991234567';
$_SERVER['REMOTE_ADDR']='192.0.2.1';
foreach ([[],['id'=>str_repeat('0',32),'answer'=>'AAAAAA','work'=>'1'],['id'=>[],'answer'=>[],'work'=>[]]] as $proof) {
    ok('missing or forged proof denied',err(moto_phone_send($a,$phone,false,$proof))==='captcha_required');
}
ok('denied proof makes zero provider calls',count($fixtureCalls)===0);
ok('denied proof reserves zero SMS quota',moto_phone_state('rate:site:86400:'.(int)floor(time()/86400))===[]);
$task=moto_phone_antibot_issue($a,$phone);
ok('public task has only challenge metadata',array_keys($task)===['id','image','work','bits','expires_in','attempts_left']);
ok('server does not store clear answer',!isset(moto_phone_state('antibot:'.$a)['answer']));
$pixels=base64_decode(substr($task['image'],strlen('data:image/png;base64,')),true);
ok('raster PNG signature',substr($pixels,0,8)==="\x89PNG\r\n\x1a\n");
$size=getimagesizefromstring($pixels); ok('PNG dimensions correct',$size[0]===288 && $size[1]===96);
ok('existing pending task is stable',moto_phone_antibot_issue($a,$phone)['id']===$task['id']);
ok('refresh throttled',err(moto_phone_antibot_issue($a,$phone,true))==='captcha_rate');
aged($a); $refreshed=moto_phone_antibot_issue($a,$phone,true);
ok('refresh changes task and work',$refreshed['id']!==$task['id'] && $refreshed['work']!==$task['work']);
clean();
$proof=fixture_antibot_proof($a,$phone);
ok('another browser denied',err(moto_phone_antibot_check($b,$phone,$proof))==='captcha_required');
ok('another phone denied',err(moto_phone_antibot_check($a,'+79991234568',$proof))==='captcha_required');
$original=$proof; $proof['id']=str_repeat('0',32);
ok('other task ID denied',err(moto_phone_antibot_check($a,$phone,$proof))==='captcha_required');
$proof=$original;
$bad=$proof; $n=0; while(moto_phone_antibot_work(str_repeat('a',32),(string)$n,18)) $n++; $bad['work']=(string)$n; $bad['bits']=0;
ok('client cannot lower work difficulty',err(moto_phone_antibot_check($a,$phone,$bad))==='captcha_work');
foreach (['','-1','01','4294967296','1e5','<script>'] as $work) {
    $bad=$proof; $bad['work']=$work; ok('invalid work format denied '.$work,err(moto_phone_antibot_check($a,$phone,$bad))==='captcha_work');
}
$state=moto_phone_state('antibot:'.$a); $state['issued_at']=microtime(true); moto_phone_store('antibot:'.$a,$state);
ok('instant submission denied',err(moto_phone_antibot_check($a,$phone,$proof))==='captcha_work'); aged($a);
$fixtureUser=wp_insert_user(['user_login'=>'binding-test','role'=>'customer']);
ok('changed authentication binding denied',err(moto_phone_antibot_check($a,$phone,$proof))==='captcha_required'); $fixtureUser=0;
$bad=$proof; $bad['answer']='WRONG1';
for($i=0;$i<3;$i++) ok('wrong answer bounded '.$i,err(moto_phone_antibot_check($a,$phone,$bad))===($i===2?'captcha_required':'captcha_wrong'));
ok('attempt exhaustion rejects correct answer',err(moto_phone_antibot_check($a,$phone,$proof))==='captcha_required');
ok('failed task clears image and hash',!isset(moto_phone_state('antibot:'.$a)['answer_hash'],moto_phone_state('antibot:'.$a)['image']));
clean(); $proof=fixture_antibot_proof($a,$phone); $proof['answer']='a7k3rm';
ok('case insensitive answer works',moto_phone_antibot_check($a,$phone,$proof)===true);
ok('successful proof cannot replay',err(moto_phone_antibot_check($a,$phone,$proof))==='captcha_required');
ok('used proof cannot spend SMS',err(moto_phone_send($a,$phone,false,$proof))==='captcha_required');
clean(); $proof=fixture_antibot_proof($a,$phone); $state=moto_phone_state('antibot:'.$a); $state['expires']=time()-1; moto_phone_store('antibot:'.$a,$state);
ok('expired challenge rejected',err(moto_phone_antibot_check($a,$phone,$proof))==='captcha_required');
clean(); $proof=fixture_antibot_proof($a,$phone);
$previousOrigin=getenv('MOTO_PHONE_FIXTURE_ORIGIN'); putenv('MOTO_PHONE_FIXTURE_ORIGIN=https://different.example.test');
ok('answer proof cannot cross site origins',err(moto_phone_antibot_check($a,$phone,$proof))==='captcha_wrong');
putenv($previousOrigin===false?'MOTO_PHONE_FIXTURE_ORIGIN':'MOTO_PHONE_FIXTURE_ORIGIN='.$previousOrigin);
clean(); $proof=fixture_antibot_proof($a,$phone);
$state=moto_phone_state('antibot:'.$a); $state['issued_at']=microtime(true)-4; moto_phone_store('antibot:'.$a,$state);
$new=moto_phone_antibot_issue($a,$phone,true);
ok('old proof rejected after refresh',err(moto_phone_antibot_check($a,$phone,$proof))==='captcha_required');
clean();
for ($i=0;$i<3;$i++) { $task=moto_phone_antibot_issue($a,$phone,true); ok('browser issue permitted '.$i,!is_wp_error($task)); aged($a); }
ok('browser issuance limited',err(moto_phone_antibot_issue($a,$phone,true))==='captcha_rate');
clean();
for ($i=0;$i<5;$i++) ok('IP issuance permitted '.$i,!is_wp_error(moto_phone_antibot_issue(hash('sha256','cap-'.$i),$phone)));
$_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.99';
ok('IP issuance survives cookie and forwarded-header rotation',err(moto_phone_antibot_issue(hash('sha256','cap-6'),$phone))==='captcha_rate');
ok('issuance never spends SMS',count($fixtureCalls)===0);
clean(); $proof=fixture_antibot_proof($a,$phone); $sent=moto_phone_send($a,$phone,false,$proof);
ok('valid proof permits one send',!is_wp_error($sent) && count($fixtureCalls)===1);
ok('repeated explicit resend cannot reuse proof',err(moto_phone_send($a,'+79991234568',true,$proof))==='captcha_required');
clean(); $fixtureProviderMode='timeout'; $proof=fixture_antibot_proof($a,$phone); moto_phone_send($a,$phone,false,$proof);
ok('uncertain retry throttled',err(moto_phone_send($a,$phone))==='busy');
$state=moto_phone_state('session:'.$a); $state['next_check_at']=0; moto_phone_store('session:'.$a,$state); $fixtureProviderMode='ok';
ok('approved uncertainty recovers without new CAPTCHA',!is_wp_error(moto_phone_send($a,$phone)));
ok('uncertain exact idempotency retained',$fixtureCalls[0][1]['headers']['Idempotency-Key']===$fixtureCalls[1][1]['headers']['Idempotency-Key']);
clean();
$legacy=['phone'=>$phone,'binding'=>moto_phone_binding(),'idempotency'=>'moto-phone-legacy-fixture','status'=>'uncertain','attempts'=>0,'sent_at'=>time()-60,'expires'=>time()+300];
moto_phone_store('session:'.$a,$legacy);
ok('pre-upgrade uncertainty needs CAPTCHA',err(moto_phone_send($a,$phone))==='captcha_required');
$proof=fixture_antibot_proof($a,$phone);
ok('pre-upgrade uncertainty recovers with CAPTCHA',!is_wp_error(moto_phone_send($a,$phone,false,$proof)));
ok('pre-upgrade original idempotency preserved',$fixtureCalls[0][1]['headers']['Idempotency-Key']===$legacy['idempotency']);
ok('pre-upgrade recovery does not reserve another budget',moto_phone_state('rate:site:86400:'.(int)floor(time()/86400))===[]);
foreach (['edit_users','activate_plugins','edit_shop_orders','unfiltered_html','manage_network','upload_files'] as $i=>$cap) {
    $id=wp_insert_user(['user_login'=>'cap-test-'.$i,'role'=>'customer']);
    $wpdb->db->exec('UPDATE users SET caps='.$wpdb->db->quote(json_encode([$cap])).' WHERE ID='.$id);
    ok('privileged customer rejected '.$cap,!moto_phone_customer(get_user_by('id',$id)));
}
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'realSmsSent'=>0],JSON_PRETTY_PRINT).PHP_EOL;
