<?php
require __DIR__.'/merge-bootstrap.php';
$checks=[];function icheck($name,$ok){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function ierr($v){return is_wp_error($v)?$v->get_error_code():'';}
function iticket(){return moto_phone_state(moto_merge_pending_key())['ticket'];}
[$source,$target]=merge_fixture_pair();
icheck('Phone source accepted',moto_merge_source()->ID===$source);
icheck('Bad credentials denied',ierr(moto_merge_prepare('buyer@example.test','wrong'))==='merge_credentials');
$fixtureMergeAuthFailure=true;icheck('Native second-factor restrictions retained',ierr(moto_merge_prepare('buyer@example.test','fixture-password'))==='merge_credentials');$fixtureMergeAuthFailure=false;
icheck('Correct old-account proof prepares review',!is_wp_error(moto_merge_prepare('buyer@example.test','fixture-password')));
$ticket=iticket();$raw=moto_phone_raw(moto_merge_pending_key());
icheck('Password never stored',!str_contains($raw,'fixture-password'));
icheck('Preparation does not transfer orders',(int)wc_get_order(101)->get_customer_id()===$source);
$result=moto_merge_commit($ticket);
icheck('Confirmed merge succeeds',!is_wp_error($result)&&$result['target']===$target&&$result['moved_orders']===2);
icheck('All source orders transferred',wc_get_order(101)->get_customer_id()===$target&&wc_get_order(102)->get_customer_id()===$target);
icheck('Old order retained',wc_get_order(201)->get_customer_id()===$target);
icheck('Amounts statuses and currency unchanged',$wpdb->db->query('SELECT total||"|"||status||"|"||currency FROM fixture_orders WHERE ID=101')->fetchColumn()==='1250.50|completed|RUB');
icheck('Phone proof moved once',get_user_meta($target,'_moto_verified_phone',true)==='+79991234567'&&get_user_meta($source,'_moto_verified_phone',true)==='');
icheck('Archived contact cleared',get_user_meta($source,'billing_phone',true)==='');
icheck('Missing name filled',get_user_meta($target,'first_name',true)==='Анна');
icheck('Existing address preserved',get_user_meta($target,'billing_city',true)==='Москва');
icheck('Email and password remain native',!is_wp_error(wp_authenticate('buyer@example.test','fixture-password')));
icheck('Source archived without deleting user',moto_account_is_merged($source)&&get_user_by('id',$source)!==false);
icheck('Archived password login blocked',is_wp_error(wp_authenticate('moto_'.str_repeat('a',32),'fixture-password')));
icheck('Archived cookies blocked',moto_merge_cookie_guard($source)===0&&moto_merge_cookie_guard($target)===$target);
icheck('Source sessions invalidated',WP_Session_Tokens::get_instance($source)->get(wp_get_session_token())===null);
icheck('Downloads follow owner',$wpdb->db->query('SELECT user_id FROM fixture_woocommerce_downloadable_product_permissions WHERE permission_id=1')->fetchColumn()===$target);
icheck('Download quota retained',(int)$wpdb->db->query('SELECT remaining FROM fixture_woocommerce_downloadable_product_permissions WHERE permission_id=1')->fetchColumn()===4);
icheck('Audit retained without password',str_contains(moto_phone_raw('merge-audit:'.$ticket),'created_at')&&!str_contains(moto_phone_raw('merge-audit:'.$ticket),'fixture-password'));
icheck('Replay cannot repeat transfer',is_wp_error(moto_merge_commit($ticket)));
$fixtureUser=0;icheck('Next phone login resolves old account',moto_phone_resolve_user('+79991234567')->ID===$target);
icheck('Merge does not create users',(int)$wpdb->db->query('SELECT COUNT(*) FROM users')->fetchColumn()===2);
$preview=moto_merge_rollback($source);
icheck('Operator rollback defaults to dry run',!is_wp_error($preview)&&$preview['applied']===false&&$preview['orders']===2&&wc_get_order(101)->get_customer_id()===$target);
update_user_meta($target,'first_name','Изменено');
icheck('Rollback refuses subsequent customer edits',is_wp_error(moto_merge_rollback($source,true))&&wc_get_order(101)->get_customer_id()===$target);
update_user_meta($target,'first_name','Анна');$fixtureMergeMetaFail='_moto_merged_into';
icheck('Failed restore rolls back ownership atomically',is_wp_error(moto_merge_rollback($source,true))&&wc_get_order(101)->get_customer_id()===$target&&moto_account_is_merged($source));$fixtureMergeMetaFail='';
$undo=moto_merge_rollback($source,true);
icheck('Reviewed rollback restores ownership',!is_wp_error($undo)&&$undo['applied']&&wc_get_order(101)->get_customer_id()===$source&&wc_get_order(201)->get_customer_id()===$target);
icheck('Rollback restores phone and archive state',get_user_meta($source,'_moto_verified_phone',true)==='+79991234567'&&!moto_account_is_merged($source)&&get_user_meta($target,'_moto_verified_phone',true)==='');

foreach(['expired','session','target-password','source-phone','role','new-order','failure','metadata-failure','replaced-ticket','other-user'] as $case){
 [$source,$target]=merge_fixture_pair();moto_merge_prepare('buyer@example.test','fixture-password');$ticket=iticket();
 if($case==='expired'){$p=moto_phone_state(moto_merge_pending_key());$p['expires']=time()-1;moto_phone_store(moto_merge_pending_key(),$p);}
 if($case==='session')$fixtureSession='another-session';
 if($case==='target-password')$wpdb->db->exec("UPDATE users SET user_pass='changed' WHERE ID=".$target);
 if($case==='source-phone')update_user_meta($source,'_moto_verified_phone','+79990000099');
 if($case==='role')$wpdb->db->exec("UPDATE users SET roles='[\"administrator\"]' WHERE ID=".$target);
 if($case==='new-order')$wpdb->db->exec('INSERT INTO fixture_orders VALUES(103,'.$source.',"10.00","pending","RUB")');
 if($case==='failure')$fixtureMergeFailOrder=102;
 if($case==='metadata-failure')$fixtureMergeMetaFail='_moto_merged_into';
 if($case==='replaced-ticket')moto_merge_prepare('buyer@example.test','fixture-password');
 if($case==='other-user'){$fixtureUser=$target;$fixtureMergeSessions[$target]=[wp_get_session_token()=>['login'=>time()]];}
 icheck('Unsafe commit rejected '.$case,is_wp_error(moto_merge_commit($ticket)));
 icheck('Order owner intact after failure '.$case,wc_get_order(101)->get_customer_id()===$source&&wc_get_order(102)->get_customer_id()===$source);
 icheck('No archive on failure '.$case,!moto_account_is_merged($source));
 $fixtureSession='fixture-wordpress-session';
}
[$source,$target]=merge_fixture_pair();$fixtureMergeSessions[$source][wp_get_session_token()]['login']=time()-901;
icheck('Old source session requires fresh login',ierr(moto_merge_prepare('buyer@example.test','fixture-password'))==='merge_reauth');
[$source,$target]=merge_fixture_pair();for($i=0;$i<5;$i++)moto_merge_prepare('buyer@example.test','wrong');
icheck('Password attempts bounded',ierr(moto_merge_prepare('buyer@example.test','fixture-password'))==='merge_rate');
[$source,$target]=merge_fixture_pair();update_user_meta($target,'_moto_verified_phone','+79990000099');
icheck('Different confirmed target phone rejected',ierr(moto_merge_prepare('buyer@example.test','fixture-password'))==='merge_phone_conflict');
[$source,$target]=merge_fixture_pair();update_user_meta($source,'_application_passwords','present');
icheck('Additional credentials require manual review',ierr(moto_merge_prepare('buyer@example.test','fixture-password'))==='merge_manual');
[$source,$target]=merge_fixture_pair();moto_merge_prepare('buyer@example.test','fixture-password');$ticket=iticket();moto_merge_order_write_guard();
icheck('Checkout holds identity lock through payment',moto_phone_lock('identity-user:'.$source)==='');
icheck('Concurrent checkout blocks merge without moving orders',is_wp_error(moto_merge_commit($ticket))&&wc_get_order(101)->get_customer_id()===$source);moto_merge_order_unlock();
$lock=moto_phone_lock('identity-user:'.$source);$denied=false;try{wc_get_order(101)->save();}catch(WC_Data_Exception $e){$denied=true;}
icheck('Merge lock blocks new customer order writes',$denied);moto_phone_unlock('identity-user:'.$source,$lock);
update_user_meta($source,'_moto_merged_into',(string)$target);$denied=false;try{moto_merge_order_write_guard();}catch(WC_Data_Exception $e){$denied=true;}
icheck('Stale archived checkout cannot continue payment',$denied);
icheck('No SMS provider calls while merging',count($fixtureCalls)===0);
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'realSmsSent'=>0,'realCustomerMutations'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
