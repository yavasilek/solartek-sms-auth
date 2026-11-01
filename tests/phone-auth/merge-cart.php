<?php
require __DIR__.'/merge-bootstrap.php';
$checks=[];
function ccheck($name,$ok){global $checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
function crow($id,$cart,$extra=[]){global $wpdb;$data=array_merge(['cart'=>serialize($cart),'applied_coupons'=>serialize([])],$extra);$wpdb->replace('fixture_woocommerce_sessions',['session_key'=>(string)$id,'session_value'=>serialize($data),'session_expiry'=>time()+86400]);}
function citem($id,$qty=1,$options=[]){return ['product_id'=>$id,'variation_id'=>0,'quantity'=>$qty,'variation'=>[],'custom_options'=>$options];}
function cticket(){return moto_phone_state(moto_merge_pending_key())['ticket'];}
[$source,$target]=merge_fixture_pair();$a=['first'=>citem(10,2,['model'=>'ATV']),'second'=>citem(11)];$b=['third'=>citem(12)];
crow($source,$a,['applied_coupons'=>serialize(['SOURCE']),'payment_method'=>'source-secret','customer'=>'source-customer']);crow($target,$b,['applied_coupons'=>serialize(['TARGET']),'payment_method'=>'target-method','customer'=>'target-customer']);
$before=$wpdb->db->query('SELECT session_value FROM fixture_woocommerce_sessions ORDER BY session_key')->fetchAll(PDO::FETCH_COLUMN);
$plan=moto_merge_cart_plan($source,$target);
ccheck('Native nested serialized carts decoded',!is_wp_error($plan)&&count($plan['merged'])===3);
ccheck('Custom product options and quantities retained',$plan['merged']['first']===$a['first']);
ccheck('Coupons combined without copying source customer state',$plan['coupons']===['TARGET','SOURCE']);
ccheck('Review does not write sessions',$before===$wpdb->db->query('SELECT session_value FROM fixture_woocommerce_sessions ORDER BY session_key')->fetchAll(PDO::FETCH_COLUMN));
ccheck('Native proof permits cart review',!is_wp_error(moto_merge_prepare('buyer@example.test','fixture-password')));$ticket=cticket();$result=moto_merge_commit($ticket);
ccheck('Cart plus native orders commits atomically',!is_wp_error($result)&&wc_get_order(101)->get_customer_id()===$target);
ccheck('Both carts now in target',moto_merge_cart_snapshot($target)['cart']===$plan['merged']&&moto_merge_cart_snapshot($source)['cart']===[]);
$rows=$wpdb->db->query('SELECT session_value FROM fixture_woocommerce_sessions WHERE session_key='.$target)->fetchColumn();$data=unserialize($rows,['allowed_classes'=>false]);
ccheck('Native customer and payment state retained',$data['customer']==='target-customer'&&$data['payment_method']==='target-method');
ccheck('Written fields use native serialization',is_string($data['cart'])&&unserialize($data['cart'])===$plan['merged']);
ccheck('Persistent cart matches transferred cart',get_user_meta($target,'_woocommerce_persistent_cart_1',true)['cart']===$plan['merged']);
$fixtureUser=0;
ccheck('Guarded rollback available',!is_wp_error(moto_merge_rollback($source)));
$edited=$plan['merged'];$edited['first']['quantity']++;crow($target,$edited);
ccheck('Rollback refuses subsequent cart edits',is_wp_error(moto_merge_rollback($source,true)));
crow($target,$plan['merged'],['applied_coupons'=>serialize($plan['coupons'])]);
ccheck('Reviewed rollback restores both carts',!is_wp_error(moto_merge_rollback($source,true))&&moto_merge_cart_snapshot($source)['cart']===$a&&moto_merge_cart_snapshot($target)['cart']===$b);
[$source,$target]=merge_fixture_pair();crow($source,['same'=>citem(10,2)]);crow($target,['same'=>citem(10,3)]);$p=moto_merge_cart_plan($source,$target);
ccheck('Identical products sum quantity',$p['merged']['same']['quantity']===5);
crow($target,['same'=>citem(10,3,['model'=>'Different'])]);ccheck('Conflicting options fail closed',is_wp_error(moto_merge_cart_plan($source,$target)));
[$source,$target]=merge_fixture_pair();update_user_meta($source,'_woocommerce_persistent_cart_1',['cart'=>$a]);update_user_meta($target,'_woocommerce_persistent_cart_1',['cart'=>$b]);crow($source,[]);crow($target,[]);
ccheck('Valid empty session does not resurrect stale persistent carts',moto_merge_cart_plan($source,$target)['merged']===[]);
$wpdb->db->exec('DELETE FROM fixture_woocommerce_sessions');ccheck('Missing sessions recover persistent carts',count(moto_merge_cart_plan($source,$target)['merged'])===3);
$GLOBALS['fixtureWC']=(object)['cart'=>new class{function get_cart(){return [];}function get_applied_coupons(){return [];}}];
ccheck('Current empty loaded cart overrides stored source cart',moto_merge_cart_plan($source,$target)['source']['cart']===[]);unset($GLOBALS['fixtureWC']);
foreach(['cart-drift','order-fault','metadata-fault']as$case){
 [$source,$target]=merge_fixture_pair();crow($source,$a);crow($target,$b);moto_merge_prepare('buyer@example.test','fixture-password');$ticket=cticket();
 if($case==='cart-drift')crow($source,['new'=>citem(99)]);
 if($case==='order-fault')$fixtureMergeFailOrder=102;
 if($case==='metadata-fault')$fixtureMergeMetaFail='_moto_merged_into';
 $saved=$wpdb->db->query('SELECT session_value FROM fixture_woocommerce_sessions ORDER BY session_key')->fetchAll(PDO::FETCH_COLUMN);
 ccheck('Commit safely rejects '.$case,is_wp_error(moto_merge_commit($ticket)));
 ccheck('Failure preserves both native session rows '.$case,$saved===$wpdb->db->query('SELECT session_value FROM fixture_woocommerce_sessions ORDER BY session_key')->fetchAll(PDO::FETCH_COLUMN));
 ccheck('Failure preserves persistent cart epoch and order '.$case,get_user_meta($target,'_moto_cart_epoch',true)===''&&wc_get_order(101)->get_customer_id()===$source&&!moto_account_is_merged($source));
}
[$source,$target]=merge_fixture_pair();$session=new class{public $epoch='';function get($key,$default=''){return $key==='_moto_merge_epoch'?$this->epoch:$default;}};
moto_merge_cart_guard($session);ccheck('Cart lock is reentrant in same request',moto_merge_identity_lock($source));moto_merge_order_unlock();
update_user_meta($source,'_moto_cart_epoch','changed');$denied=false;try{moto_merge_cart_guard($session);}catch(WC_Data_Exception $e){$denied=true;}ccheck('Old loaded session cannot save after merge epoch changes',$denied);moto_merge_order_unlock();
$session->epoch='changed';moto_merge_cart_guard($session);moto_merge_order_unlock();update_user_meta($source,'_moto_merged_into',(string)$target);$denied=false;try{moto_merge_cart_guard($session);}catch(WC_Data_Exception $e){$denied=true;}ccheck('Archived source cannot save a cart',$denied);
ccheck('Unsupported object options rejected',is_wp_error((function()use($source,$target){update_user_meta($source,'_moto_merged_into','');crow($source,['bad'=>citem(10,1,['object'=>(object)['x'=>1]])]);return moto_merge_cart_plan($source,$target);})()));
ccheck('No actual SMS or production writes',count($fixtureCalls)===0);
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'realSmsSent'=>0,'realCustomerMutations'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
