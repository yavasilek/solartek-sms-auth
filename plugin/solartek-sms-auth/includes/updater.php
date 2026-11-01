<?php
defined('ABSPATH') || exit;

function ssa_release_info(?string $version=null,bool $force=false) {
    $repo=(string)ssa_config('repository');
    if(!preg_match('~^[a-zA-Z0-9][a-zA-Z0-9-]*/[a-zA-Z0-9][a-zA-Z0-9_.-]*$~D',$repo))return new WP_Error('ssa_repo','Некорректный репозиторий.');
    if($version!==null&&!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$version))return new WP_Error('ssa_version','Некорректная версия.');
    $cache='ssa_release_'.md5($repo.'|'.($version??'latest'));
    if(!$force){$saved=get_site_transient($cache);if(is_array($saved))return $saved;}
    $endpoint='https://api.github.com/repos/'.$repo.'/releases/'.($version===null?'latest':'tags/v'.$version);
    $reply=wp_safe_remote_get($endpoint,['timeout'=>12,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>262144,'headers'=>['Accept'=>'application/vnd.github+json','User-Agent'=>'Solartek-SMS-Auth/'.SSA_VERSION]]);
    if(is_wp_error($reply)||wp_remote_retrieve_response_code($reply)!==200)return new WP_Error('ssa_release','Релиз недоступен.');
    $json=json_decode(wp_remote_retrieve_body($reply),true);
    if(!is_array($json)||!empty($json['draft'])||!empty($json['prerelease'])||!preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+)$/D',$json['tag_name']??'',$match))return new WP_Error('ssa_release','Нет стабильного релиза.');
    $releaseVersion=$match[1];if($version!==null&&$version!==$releaseVersion)return new WP_Error('ssa_release','Релиз не совпал с запрошенной версией.');
    $prefix='https://github.com/'.$repo.'/releases/download/v'.$releaseVersion.'/';$asset=null;
    foreach($json['assets']??[] as $item)if(($item['name']??'')==='solartek-sms-auth.zip')$asset=$item;
    if(!$asset||($asset['browser_download_url']??'')!==$prefix.'solartek-sms-auth.zip'||($asset['size']??0)<1||$asset['size']>20971520)return new WP_Error('ssa_asset','Архив релиза отсутствует или некорректен.');
    $digest=$asset['digest']??'';
    if(preg_match('/^sha256:([a-f0-9]{64})$/D',$digest,$hash))$sha=$hash[1];
    else{
        $checksum=wp_safe_remote_get($prefix.'SHA256SUMS',['timeout'=>12,'redirection'=>5,'sslverify'=>true,'limit_response_size'=>8192]);
        if(is_wp_error($checksum)||wp_remote_retrieve_response_code($checksum)!==200||!preg_match('/^([a-f0-9]{64})  solartek-sms-auth\.zip\r?$/m',wp_remote_retrieve_body($checksum),$hash))return new WP_Error('ssa_checksum','Не удалось получить контрольную сумму.');
        $sha=$hash[1];
    }
    $data=['version'=>$releaseVersion,'package'=>$prefix.'solartek-sms-auth.zip','sha256'=>$sha,'size'=>(int)$asset['size'],'url'=>'https://github.com/'.$repo.'/releases/tag/v'.$releaseVersion,'requires'=>'6.5','requires_php'=>'8.1','tested'=>''];
    set_site_transient($cache,$data,6*HOUR_IN_SECONDS);return $data;
}
function ssa_update_transient($transient) {
    if(!is_object($transient))return $transient;
    if(!isset($transient->checked[SSA_BASENAME]))return $transient;
    $release=ssa_release_info();if(is_wp_error($release))return $transient;
    $info=(object)['id'=>'https://github.com/'.ssa_config('repository'),'slug'=>'solartek-sms-auth','plugin'=>SSA_BASENAME,
        'new_version'=>$release['version'],'url'=>$release['url'],'package'=>$release['package'],
        'requires'=>$release['requires'],'requires_php'=>$release['requires_php'],'tested'=>$release['tested']];
    if(version_compare($release['version'],(string)$transient->checked[SSA_BASENAME],'>')){$transient->response[SSA_BASENAME]=$info;unset($transient->no_update[SSA_BASENAME]);}
    else{$transient->no_update[SSA_BASENAME]=$info;unset($transient->response[SSA_BASENAME]);}
    return $transient;
}
function ssa_plugin_information($result,string $action,$args) {
    if($action!=='plugin_information'||($args->slug??'')!=='solartek-sms-auth')return $result;
    $release=ssa_release_info();if(is_wp_error($release))return $result;
    return (object)['name'=>'Solartek SMS Auth','slug'=>'solartek-sms-auth','version'=>$release['version'],
        'author'=>'Solartek','homepage'=>'https://github.com/'.ssa_config('repository'),'download_link'=>$release['package'],
        'requires'=>$release['requires'],'requires_php'=>$release['requires_php'],
        'sections'=>['description'=>'SMS-вход для WooCommerce с мастером настройки, CAPTCHA и общими обновлениями. Настройки каждого сайта сохраняются отдельно.',
            'changelog'=>'Описание выпуска: <a href="'.esc_url($release['url']).'">GitHub Releases</a>.']];
}
function ssa_verify_package(string $file,array $release) {
    if(!is_file($file)||filesize($file)!==$release['size']||!hash_equals($release['sha256'],hash_file('sha256',$file)))return new WP_Error('ssa_integrity','Контрольная сумма архива не совпала. Установка остановлена.');
    if(!class_exists('ZipArchive'))return new WP_Error('ssa_zip','Для проверки обновления нужен PHP ZIP.');
    $zip=new ZipArchive();if($zip->open($file)!==true)return new WP_Error('ssa_zip','Не удалось открыть архив.');
    $total=0;$header=false;
    try{
        for($i=0;$i<$zip->numFiles;$i++){
            $entry=$zip->statIndex($i);$name=$entry['name'];$total+=(int)$entry['size'];
            if(!str_starts_with($name,'solartek-sms-auth/')||str_contains($name,'..')||str_contains($name,'\\')||$total>52428800)return new WP_Error('ssa_zip','Небезопасная структура архива.');
            if($name==='solartek-sms-auth/solartek-sms-auth.php'){
                $source=$zip->getFromIndex($i);$header=is_string($source)&&preg_match('/^ \* Version: '.preg_quote($release['version'],'/').'$/m',$source);
            }
        }
        return $header?true:new WP_Error('ssa_zip','Версия внутри архива не совпала с релизом.');
    }finally{$zip->close();}
}
function ssa_pre_download($reply,string $package,$upgrader,array $extra=[]) {
    if($reply!==false)return $reply;
    $own=($extra['plugin']??'')===SSA_BASENAME||in_array(SSA_BASENAME,$extra['plugins']??[],true);
    $prefix='https://github.com/'.ssa_config('repository').'/releases/download/v';
    if(!$own&&!str_starts_with($package,$prefix))return $reply;
    if(!preg_match('~^'.preg_quote($prefix,'~').'([0-9]+\.[0-9]+\.[0-9]+)/solartek-sms-auth\.zip$~D',$package,$match))return new WP_Error('ssa_package','Источник обновления не совпал с настроенным репозиторием.');
    $release=ssa_release_info($match[1]);if(is_wp_error($release))return $release;
    if($release['package']!==$package)return new WP_Error('ssa_package','Архив не соответствует релизу.');
    if(!function_exists('download_url'))require_once ABSPATH.'wp-admin/includes/file.php';
    $file=download_url($package,120);if(is_wp_error($file))return $file;
    $valid=ssa_verify_package($file,$release);if(is_wp_error($valid)){unlink($file);return $valid;}return $file;
}
add_filter('pre_set_site_transient_update_plugins','ssa_update_transient');
add_filter('plugins_api','ssa_plugin_information',10,3);
add_filter('upgrader_pre_download','ssa_pre_download',10,4);
add_filter('auto_update_plugin',static function($update,$item){return ($item->plugin??'')===SSA_BASENAME?(bool)ssa_config('automatic_updates'):$update;},10,2);

function ssa_schedule_updates(): void {
    if (!ssa_config('configured') || !ssa_config('automatic_updates')) {
        wp_clear_scheduled_hook('ssa_check_updates');
        return;
    }
    if (!wp_next_scheduled('ssa_check_updates')) wp_schedule_event(time()+120,'twicedaily','ssa_check_updates');
}
function ssa_background_update(): void {
    if (!ssa_config('configured') || !ssa_config('automatic_updates')) return;
    require_once ABSPATH.'wp-admin/includes/admin.php';
    require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
    $auto=new WP_Automatic_Updater();
    if ($auto->is_disabled() || !is_main_network() || !is_main_site() || !WP_Upgrader::create_lock('auto_updater')) return;
    try {
        if (is_wp_error(ssa_release_info(null,true))) return;
        wp_update_plugins();
        $updates=get_site_transient('update_plugins');
        $offer=$updates->response[SSA_BASENAME]??null;
        // Use WordPress eligibility, integrity hook and automatic fatal-error rollback.
        // This task never installs updates for unrelated plugins, themes or WordPress.
        if ($offer) {
            // The wizard explicitly opts this installed module into release updates,
            // including sites whose parent directory is a Git checkout.
            $vcs=static function($checkout,$context){return $context===WP_PLUGIN_DIR?false:$checkout;};
            add_filter('automatic_updates_is_vcs_checkout',$vcs,PHP_INT_MAX,2);
            try { $auto->update('plugin',$offer); }
            finally { remove_filter('automatic_updates_is_vcs_checkout',$vcs,PHP_INT_MAX); }
        }
    } finally {
        WP_Upgrader::release_lock('auto_updater');
    }
}
add_action('init','ssa_schedule_updates');
add_action('ssa_check_updates','ssa_background_update');
