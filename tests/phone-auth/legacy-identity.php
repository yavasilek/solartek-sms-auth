<?php
require __DIR__.'/bootstrap.php';
$checks=[];
function lcheck($name,$ok){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function lreset(){global $wpdb,$fixtureUser,$fixtureCalls,$fixtureLoginCalls;foreach(['users','usermeta','options','provider_ops','provider_calls'] as $t)$wpdb->db->exec('DELETE FROM '.$t);$fixtureUser=0;$fixtureCalls=[];$fixtureLoginCalls=[];}
function luser($name,$phone,$role='customer'){$id=wp_insert_user(['user_login'=>$name,'role'=>$role]);update_user_meta($id,'billing_phone',$phone);return $id;}
foreach(['+79991234567','79991234567','89991234567','9991234567',"+7\u{00a0}(999) 123–45–67"] as $variant){
 lreset();$id=luser('old',$variant);$user=moto_phone_resolve_user('+79991234567');lcheck('Existing customer reused '.count($checks),!is_wp_error($user)&&$user->ID===$id);lcheck('Only OTP resolver confirms legacy contact '.count($checks),get_user_meta($id,'_moto_verified_phone',true)==='+79991234567');
}
lreset();$a=luser('old','89991234567');$b=luser('other','9991234567');
lcheck('Equivalent duplicates block new registration',is_wp_error(moto_phone_resolve_user('+79991234567'))&&(int)$wpdb->db->query('SELECT COUNT(*) FROM users')->fetchColumn()===2);
lcheck('Ambiguous contacts remain unverified',get_user_meta($a,'_moto_verified_phone',true)===''&&get_user_meta($b,'_moto_verified_phone',true)==='');
update_user_meta($b,'_moto_verified_phone','+79991234567');lcheck('Confirmed owner wins over unverified duplicate',moto_phone_resolve_user('+79991234567')->ID===$b);
foreach(['administrator','shop_manager','editor'] as $role){lreset();$id=luser('staff','89991234567',$role);lcheck('Privileged legacy account denied '.$role,is_wp_error(moto_phone_resolve_user('+79991234567'))&&get_user_meta($id,'_moto_verified_phone',true)==='');}
lreset();$id=luser('custom','89991234567');$wpdb->db->exec("UPDATE users SET caps='[\"manage_options\"]' WHERE ID=".$id);lcheck('Custom privileged capability denied',is_wp_error(moto_phone_resolve_user('+79991234567')));
lreset();$id=luser('old','89991234567');update_user_meta($id,'_moto_verified_phone','+79990000099');lcheck('Different verified marker prevents reassignment',is_wp_error(moto_phone_resolve_user('+79991234567')));
lreset();$id=luser('gift','');update_user_meta($id,'shipping_phone','89991234567');lcheck('Shipping recipient is not an identity',moto_phone_legacy_owner('+79991234567')===null);
lreset();$id=luser('archived','89991234567');update_user_meta($id,'_moto_merged_into','123');lcheck('Archived contact skipped',moto_phone_legacy_owner('+79991234567')===null);
lreset();$id=luser('old','89991234567');$fixtureUser=luser('current','+79990000099');lcheck('Cannot bind another legacy account number',is_wp_error(moto_phone_resolve_user('+79991234567')));$fixtureUser=$id;lcheck('Own legacy contact can be confirmed',moto_phone_resolve_user('+79991234567')->ID===$id);
lreset();$id=luser('old','89991234567');$session=str_repeat('a',64);fixture_send($session,'+79991234567');$result=moto_phone_verify($session,'000000');lcheck('Incorrect OTP cannot claim legacy account',is_wp_error($result)&&get_user_meta($id,'_moto_verified_phone',true)===''&&$fixtureLoginCalls===[]);
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'realSmsSent'=>0,'realCustomerMutations'=>0],JSON_PRETTY_PRINT).PHP_EOL;
