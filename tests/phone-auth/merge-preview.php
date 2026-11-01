<?php
require __DIR__.'/merge-bootstrap.php';
$previewRoot=sys_get_temp_dir().'/ssa-preview-merge-'.bin2hex(random_bytes(10));mkdir($previewRoot);
function solartek_image_preview_root(){return $GLOBALS['previewRoot'];}
function solartek_image_preview_json_read($path){return is_file($path)?json_decode(file_get_contents($path),true):[];}
function solartek_image_preview_effective_owner($id){return (int)(get_user_meta($id,'_moto_merged_into',true)?:$id);}
function solartek_image_preview_lock($callback){return $callback(solartek_image_preview_root());}
$checks=[];function pcheck($name,$ok){global$checks;if(!$ok)throw new RuntimeException($name);$checks[]=$name;}
try{
 [$source,$target]=merge_fixture_pair();$job=['id'=>str_repeat('a',32),'owner_id'=>$source,'status'=>'succeeded','result'=>'original-paid-job'];$path=$previewRoot.'/job-'.$job['id'].'.json';file_put_contents($path,json_encode($job));
 $ledger=['requests'=>['source'=>['id'=>$job['id'],'owner_id'=>$source]],'days'=>['2026-11-01'=>['users'=>[$source=>1,$target=>2],'global'=>3]]];file_put_contents($previewRoot.'/ledger.json',json_encode($ledger));
 $hash=hash_file('sha256',$path);$plan=moto_merge_preview_plan($source,$previewRoot);pcheck('Completed preview is supported',$plan['jobs']===1);
 $ledger['days']['2026-11-01']['users'][999]=1;$ledger['days']['2026-11-01']['global']++;file_put_contents($previewRoot.'/ledger.json',json_encode($ledger));
 pcheck('Unrelated buyer quota activity does not invalidate plan',moto_merge_preview_plan($source,$previewRoot)['fingerprint']===$plan['fingerprint']);
 pcheck('Native proof prepares completed preview merge',!is_wp_error(moto_merge_prepare('buyer@example.test','fixture-password')));$ticket=moto_phone_state(moto_merge_pending_key())['ticket'];
 pcheck('Completed preview source can commit',!is_wp_error(moto_merge_commit($ticket)));
 pcheck('Original private job remains unchanged',hash_file('sha256',$path)===$hash);
 pcheck('Committed alias points preview to canonical target',solartek_image_preview_effective_owner($source)===$target);
 $fixtureUser=0;pcheck('Unchanged original job permits operator rollback',!is_wp_error(moto_merge_rollback($source)));
 $job['result']='edited';file_put_contents($path,json_encode($job));pcheck('Changed private job prevents unsafe rollback',is_wp_error(moto_merge_rollback($source,true)));
 $job['result']='original-paid-job';file_put_contents($path,json_encode($job));pcheck('Rollback restores original preview access',!is_wp_error(moto_merge_rollback($source,true))&&solartek_image_preview_effective_owner($source)===$source);
 $job['status']='running';file_put_contents($path,json_encode($job));pcheck('Active provider job must finish before merge',moto_merge_preview_plan($source,$previewRoot)->get_error_code()==='merge_preview_busy');
 $job['status']='succeeded';file_put_contents($path,json_encode($job));file_put_contents($previewRoot.'/studio-user-'.$source.'.json',json_encode(['vehicles'=>[['id'=>'saved']]]));pcheck('Garage has precise manual guard',moto_merge_preview_plan($source,$previewRoot)->get_error_code()==='merge_studio_manual');
 pcheck('No SMS sent',count($fixtureCalls)===0);
 echo json_encode(['passed'=>count($checks),'checks'=>$checks,'realSmsSent'=>0,'realCustomerMutations'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
}finally{foreach(glob($previewRoot.'/*')as$file)unlink($file);rmdir($previewRoot);}
