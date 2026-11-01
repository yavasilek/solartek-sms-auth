<?php
require __DIR__.'/merge-bootstrap.php';
function wp_nonce_field($action,$name){echo '<input type="hidden" name="'.esc_attr($name).'" value="fixture-nonce">';}
$checks=[];
function dcheck($name,$ok){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function dcode($v){return is_wp_error($v)?$v->get_error_code():'';}
function dtoken($source,$target,$expires=null){return moto_merge_candidate_token(get_user_by('id',$source),get_user_by('id',$target),$expires??time()+300);}
function dhtml(){ob_start();moto_merge_render();return ob_get_clean();}
[$source,$target]=merge_fixture_pair();
$before=(int)$wpdb->db->query('SELECT COUNT(*) FROM options')->fetchColumn();
$users=moto_merge_candidates();dcheck('Old account found by normalized phone',count($users)===1&&$users[0]->ID===$target);
$html=dhtml();dcheck('Recognizable email mask rendered',str_contains($html,'bu***r@example.test'));
dcheck('Found account shown immediately',str_contains($html,'class="moto-account-profile moto-account-merge" open'));
dcheck('Full email login and order history hidden before proof',!str_contains($html,'buyer@example.test')&&!str_contains($html,'old-buyer')&&!str_contains($html,'1250')&&!str_contains($html,'name="target_id"'));
dcheck('Candidate values are expiring opaque tokens',preg_match('/<option value="[0-9]{10}\.[a-f0-9]{64}">bu\*\*\*r@example.test<\/option>/',$html)===1);
dcheck('Manual alternative remains collapsed',str_contains($html,'class="moto-account-merge__other"><summary>')&&str_contains($html,'name="merge_login"'));
dcheck('Recovery link has no target email',str_contains($html,'/my-account/lost-password/')&&!str_contains($html,'login=buyer'));
dcheck('Discovery does not write account state or options',(int)$wpdb->db->query('SELECT COUNT(*) FROM options')->fetchColumn()===$before&&!moto_account_is_merged($source)&&wc_get_order(101)->get_customer_id()===$source);
dcheck('Short email still masked',moto_merge_mask_email('ab@host.test')==='a***@host.test');
dcheck('Invalid email omitted',moto_merge_mask_email('bad')==='');
$token=dtoken($source,$target);
dcheck('Candidate still requires native password',dcode(moto_merge_prepare_candidate($token,'wrong'))==='merge_credentials');
dcheck('Correct password prepares explicit review',!is_wp_error(moto_merge_prepare_candidate($token,'fixture-password')));
dcheck('Selection does not merge before consent',!moto_account_is_merged($source)&&wc_get_order(101)->get_customer_id()===$source);
$html=dhtml();dcheck('Full email shown only after native proof',str_contains($html,'buyer@example.test')&&str_contains($html,'name="merge_consent"'));
$pending=moto_phone_state(moto_merge_pending_key());dcheck('Selected old account merge keeps history',!is_wp_error(moto_merge_commit($pending['ticket']))&&wc_get_order(101)->get_customer_id()===$target&&wc_get_order(201)->get_customer_id()===$target);

foreach(['tamper','expired','future','session','phone','target-password','target-email','role','archived','contact','other-user','array'] as $case){
 [$source,$target]=merge_fixture_pair();$token=dtoken($source,$target);
 if($case==='tamper')$token=substr($token,0,-1).(substr($token,-1)==='a'?'b':'a');
 if($case==='expired')$token=dtoken($source,$target,time()-1);
 if($case==='future')$token=dtoken($source,$target,time()+500);
 if($case==='session'){$fixtureSession='other-session';$fixtureMergeSessions[$source]=[$fixtureSession=>['login'=>time()]];}
 if($case==='phone')update_user_meta($source,'_moto_verified_phone','+79990000099');
 if($case==='target-password')$wpdb->db->exec("UPDATE users SET user_pass='changed' WHERE ID=".$target);
 if($case==='target-email')$wpdb->db->exec("UPDATE users SET user_email='other@example.test' WHERE ID=".$target);
 if($case==='role')$wpdb->db->exec("UPDATE users SET roles='[\"administrator\"]' WHERE ID=".$target);
 if($case==='archived')update_user_meta($target,'_moto_merged_into','999');
 if($case==='contact')update_user_meta($target,'billing_phone','+79990000099');
 if($case==='other-user')$fixtureUser=$target;
 if($case==='array')$token=[];
 dcheck('Unsafe candidate rejected '.$case,is_wp_error(moto_merge_prepare_candidate($token,'fixture-password')));
 dcheck('Rejected selection leaves orders intact '.$case,wc_get_order(101)->get_customer_id()===$source&&!moto_account_is_merged($source));
 $fixtureSession='fixture-wordpress-session';
}
[$source,$target]=merge_fixture_pair();$fixtureMergeSessions[$source][wp_get_session_token()]['login']=time()-901;
dcheck('Stale phone session gets no hints',dcode(moto_merge_candidates())==='merge_reauth'&&!str_contains(dhtml(),'bu***r@example.test'));
[$source,$target]=merge_fixture_pair();$fixtureUser=0;dcheck('Guest gets no hints',is_wp_error(moto_merge_candidates())&&dhtml()==='');
[$source,$target]=merge_fixture_pair();update_user_meta($target,'billing_phone','');update_user_meta($target,'shipping_phone','89991234567');
dcheck('Shipping-only recipient is not suggested',moto_merge_candidates()===[]);
dcheck('No match leaves useful manual fallback',str_contains(dhtml(),'Старый аккаунт с этим номером не найден')&&!str_contains(dhtml(),'moto-account-merge__candidate'));
[$source,$target]=merge_fixture_pair();update_user_meta($target,'_moto_verified_phone','+79990000099');dcheck('Other verified phone not suggested',moto_merge_candidates()===[]);
[$source,$target]=merge_fixture_pair();update_user_meta($target,'_moto_verified_phone','+79991234567');dcheck('Conflicting verified owner requires support',moto_merge_candidates()===[]);
[$source,$target]=merge_fixture_pair();$wpdb->db->exec("UPDATE users SET caps='[\"manage_options\"]' WHERE ID=".$target);dcheck('Special capabilities not disclosed',moto_merge_candidates()===[]);
[$source,$target]=merge_fixture_pair();$other=merge_fixture_user('second-buyer','second@example.test');update_user_meta($other,'billing_phone','9991234567');$html=dhtml();
dcheck('Several matches selectable with masks',count(moto_merge_candidates())===2&&str_contains($html,'se***d@example.test')&&!str_contains($html,'second@example.test'));
dcheck('Second matching account selectable',!is_wp_error(moto_merge_prepare_candidate(dtoken($source,$other),'fixture-password')));
foreach(range(1,5) as $i){$id=merge_fixture_user('extra'.$i,'extra'.$i.'@example.test');update_user_meta($id,'billing_phone','89991234567');}
dcheck('Large ambiguous lists fail closed',dcode(moto_merge_candidates())==='merge_lookup');
dcheck('Discovery and selection send no SMS',count($fixtureCalls)===0);
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'realSmsSent'=>0,'realCustomerMutations'=>0],JSON_PRETTY_PRINT).PHP_EOL;
