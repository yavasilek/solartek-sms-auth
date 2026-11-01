<?php
define('MOTO_SMS_ALL_ROLES',true);
require __DIR__.'/bootstrap.php';
$checks=[];
function rcheck($label,$ok){global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;}
function rreset(){global $wpdb,$fixtureUser,$fixtureCalls,$fixtureLoginCalls;foreach(['users','usermeta','options','provider_ops','provider_calls'] as $table)$wpdb->db->exec('DELETE FROM '.$table);$fixtureUser=0;$fixtureCalls=[];$fixtureLoginCalls=[];}
function ruser($role,$phone=''){$id=wp_insert_user(['user_login'=>'fixture_'.bin2hex(random_bytes(5)),'role'=>$role]);if($phone!=='')update_user_meta($id,'billing_phone',$phone);return $id;}
$phone='+79991234567';$session=str_repeat('d',64);
foreach(['administrator','shop_manager','editor','author','contributor','customer','subscriber','dealer','premium_dealer','installer','installer_manager','custom_role'] as $role){
    rreset();$id=ruser($role,'8 (999) 123-45-67');$before=get_user_by('id',$id);
    rcheck('Login allowed for '.$role,moto_phone_login_allowed($before));
    rcheck('Phone challenge for '.$role,!is_wp_error(fixture_send($session,$phone)));
    $result=moto_phone_verify($session,'000000');
    rcheck('Wrong OTP cannot log in '.$role,is_wp_error($result)&&$fixtureLoginCalls===[]&&get_user_meta($id,'_moto_verified_phone',true)==='');
    $result=moto_phone_verify($session,'123456');
    rcheck('Successful live OTP resolves original '.$role,!is_wp_error($result)&&$fixtureLoginCalls===[$id]&&get_user_meta($id,'_moto_verified_phone',true)===$phone);
    $after=get_user_by('id',$id);rcheck('Roles and capabilities unchanged for '.$role,$before->roles===$after->roles&&$before->caps===$after->caps);
    if(!in_array($role,['customer','subscriber'],true))rcheck('Merge remains forbidden for '.$role,!moto_phone_customer($after)&&is_wp_error(moto_merge_source(false)));
    $fixtureUser=0;$owner=moto_phone_resolve_user($phone);rcheck('Already verified owner reused for '.$role,!is_wp_error($owner)&&$owner->ID===$id);
}
rreset();$id=ruser('administrator',$phone);$wpdb->db->exec('UPDATE users SET user_status=1 WHERE ID='.$id);
rcheck('Disabled admin denied',!moto_phone_login_allowed(get_user_by('id',$id))&&is_wp_error(moto_phone_resolve_user($phone)));
rreset();$id=ruser('administrator',$phone);update_user_meta($id,'_moto_merged_into','999');
rcheck('Merged admin denied',!moto_phone_login_allowed(get_user_by('id',$id)));
rreset();$id=ruser('administrator',$phone);$wpdb->db->exec("UPDATE users SET roles='[]' WHERE ID=".$id);
rcheck('Role-less account denied',!moto_phone_login_allowed(get_user_by('id',$id)));
rreset();$id=ruser('customer',$phone);$wpdb->db->exec("UPDATE users SET caps='[\"edit_users\"]' WHERE ID=".$id);
rcheck('Explicit permission covers direct privileged capabilities',moto_phone_login_allowed(get_user_by('id',$id))&&!moto_phone_customer(get_user_by('id',$id)));
rreset();$a=ruser('administrator',$phone);$b=ruser('customer',$phone);
rcheck('Duplicate legacy phone never chooses privileged account',is_wp_error(moto_phone_resolve_user($phone)));
update_user_meta($a,'_moto_verified_phone',$phone);
rcheck('Confirmed admin owner wins over unverified duplicate',moto_phone_resolve_user($phone)->ID===$a);
update_user_meta($b,'_moto_verified_phone',$phone);
rcheck('Two confirmed owners remain conflict',is_wp_error(moto_phone_resolve_user($phone)));
rreset();$a=ruser('administrator');$fixtureUser=$a;
fixture_send($session,$phone);$result=moto_phone_verify($session,'123456');
rcheck('Logged-in admin can bind phone after OTP',!is_wp_error($result)&&$result['linked']===true&&$fixtureLoginCalls===[]&&get_user_meta($a,'_moto_verified_phone',true)===$phone);
$fixtureUser=0;$b=ruser('editor');$fixtureUser=$b;
rcheck('Staff cannot bind phone owned by another account',is_wp_error(moto_phone_resolve_user($phone)));
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'realSmsSent'=>0,'realCustomerMutations'=>0],JSON_PRETTY_PRINT).PHP_EOL;
