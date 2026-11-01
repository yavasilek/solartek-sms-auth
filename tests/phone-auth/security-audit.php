<?php
// Regression of the exact audit scenarios. Isolated SQLite and mocked provider.
require __DIR__ . '/bootstrap.php';
$phone = '+79991234567'; $session = str_repeat('a', 64); $checks=[];
function audit_check($name,$ok) { global $checks; if (!$ok) throw new RuntimeException($name); $checks[]=$name; }
function audit_error($r) { return is_wp_error($r)?$r->get_error_code():''; }
$attacker=wp_insert_user(['user_login'=>'unverified-contact','role'=>'customer']);
update_user_meta($attacker,'_moto_verified_phone','+79990000001');
update_user_meta($attacker,'billing_phone','8 (999) 123-45-67');
fixture_send($session,$phone); $result=moto_phone_verify($session,'123456');
audit_check('Conflicting verified contact stops new account creation',audit_error($result)==='account_conflict');
audit_check('Foreign account never authenticated',!in_array($attacker,$fixtureLoginCalls,true));
audit_check('Foreign verified number untouched',get_user_meta($attacker,'_moto_verified_phone',true)==='+79990000001');
audit_check('Foreign billing contact untouched',get_user_meta($attacker,'billing_phone',true)==='8 (999) 123-45-67');
audit_check('Conflict never creates or authenticates another account',$fixtureLoginCalls===[]&&(int)$wpdb->db->query('SELECT COUNT(*) FROM users')->fetchColumn()===1);
$fixtureUser=0; $wpdb->db->exec('DELETE FROM options'); $fixtureCalls=[];
for($i=0;$i<100;$i++) {
    $_SERVER['REMOTE_ADDR']='198.51.100.'.(intdiv($i,10)+1);
    $result=moto_phone_send(hash('sha256','audit-browser-'.$i),'+7999000'.sprintf('%04d',$i));
    audit_check('Original distributed send without CAPTCHA denied '.$i,audit_error($result)==='captcha_required');
}
audit_check('Distributed automation makes zero provider calls',$fixtureCalls===[]);
audit_check('Distributed automation does not exhaust site budget',moto_phone_state('rate:site:86400:'.(int)floor(time()/86400))===[]);
$_SERVER['REMOTE_ADDR']='203.0.113.50';
audit_check('Legitimate CAPTCHA-backed client still allowed',!is_wp_error(fixture_send(hash('sha256','legitimate-next-browser'),'+79999999999')));
$id=wp_insert_user(['user_login'=>'custom-admin-cap','role'=>'customer']);
$wpdb->db->exec('UPDATE users SET caps='.$wpdb->db->quote(json_encode(['edit_users'])).' WHERE ID='.$id);
audit_check('Original privileged-capability PoC rejected',!moto_phone_customer(get_user_by('id',$id)));
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'formerFindingsClosed'=>3,'realSmsSent'=>0],JSON_PRETTY_PRINT).PHP_EOL;
