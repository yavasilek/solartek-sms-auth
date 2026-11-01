<?php
defined('ABSPATH') || exit;

/** Private files keep their original owner; the committed archive grants canonical access. */
function moto_merge_preview_plan(int $source,?string $root) {
    if($root===null)return ['jobs'=>0,'fingerprint'=>''];
    $files=glob($root.'/job-*.json');
    if($files===false||count($files)>5000)return moto_phone_error('merge_preview_unavailable','Не удалось проверить историю примерок. Повторите позже.',503);
    $hashes=[];$jobIds=[];$jobs=0;
    foreach($files as $file){
        if(is_link($file)||filesize($file)>16777216)return moto_phone_error('merge_preview_unavailable','Историю примерок проверит поддержка.',409);
        $raw=file_get_contents($file);$job=json_decode($raw,true);
        if(!is_array($job)||!isset($job['owner_id']))return moto_phone_error('merge_preview_unavailable','Не удалось проверить историю примерок. Повторите позже.',503);
        if((int)($job['owner_id']??0)!==$source)continue;
        if(!in_array($job['status']??'', ['succeeded','failed','uncertain','cancelled','expired'],true))return moto_phone_error('merge_preview_busy','Сначала дождитесь завершения текущей примерки, затем повторите объединение.',409);
        $hashes[basename($file)]=hash('sha256',$raw);$jobIds[(string)$job['id']]=true;$jobs++;
    }
    if($jobs>200)return moto_phone_error('merge_preview_large','Большую историю примерок объединит поддержка.',409);
    $path=$root.'/studio-user-'.$source.'.json';$studio=solartek_image_preview_json_read($path);
    if(!empty($studio['vehicles'])||!empty($studio['board'])||!empty($studio['favorite_job_id']))return moto_phone_error('merge_studio_manual','В телефонном аккаунте есть сохранённый гараж или список сравнения. Для их переноса нужна поддержка; готовые примерки и корзина сохранены.',409);
    if($jobs && !function_exists('solartek_image_preview_effective_owner'))return moto_phone_error('merge_preview_upgrade','Для переноса примерок нужно обновить модуль личного кабинета. Обратитесь в поддержку.',409);
    if(is_file($path))$hashes[basename($path)]=hash_file('sha256',$path);
    if(is_file($root.'/ledger.json')){
        $ledger=json_decode(file_get_contents($root.'/ledger.json'),true);
        if(!is_array($ledger))return moto_phone_error('merge_preview_unavailable','Не удалось проверить историю примерок.',503);
        $owned=['requests'=>[],'days'=>[]];
        foreach($ledger['requests']??[]as$key=>$row)if((int)($row['owner_id']??0)===$source||isset($jobIds[(string)($row['id']??'')]))$owned['requests'][$key]=$row;
        foreach($ledger['days']??[]as$day=>$row)if(isset($row['users'][$source]))$owned['days'][$day]=$row['users'][$source];
        $hashes['owned-ledger']=hash('sha256',wp_json_encode($owned));
    }
    ksort($hashes);return ['jobs'=>$jobs,'fingerprint'=>moto_phone_hash(wp_json_encode($hashes))];
}
