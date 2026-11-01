<?php
defined('ABSPATH') || exit;

function ssa_defaults(): array {
    return ['enabled'=>false, 'service_url'=>'https://sms.solartek.ru', 'api_path'=>'/api/v1/auth/challenges/',
        'request_timeout'=>15, 'phone_hourly_limit'=>3, 'phone_daily_limit'=>10, 'ip_hourly_limit'=>10,
        'ip_daily_limit'=>30, 'daily_limit'=>100, 'captcha_ttl'=>180, 'captcha_attempts'=>3, 'captcha_bits'=>18,
        'captcha_ip_minute'=>5, 'captcha_ip_hour'=>30, 'captcha_browser_minute'=>3, 'captcha_browser_hour'=>10,
        'account'=>true, 'checkout'=>true, 'profile'=>true, 'tryon'=>true, 'merge'=>true, 'privacy_url'=>'',
        'repository'=>'yavasilek/solartek-sms-auth', 'automatic_updates'=>true, 'setup_step'=>1, 'configured'=>false];
}
function ssa_config(string $key) {
    $settings = get_option('ssa_settings', []);
    return (is_array($settings) && array_key_exists($key, $settings)) ? $settings[$key] : (ssa_defaults()[$key] ?? null);
}
function ssa_numeric_ranges(): array {
    return ['request_timeout'=>[5,30], 'phone_hourly_limit'=>[1,100], 'phone_daily_limit'=>[1,500],
        'ip_hourly_limit'=>[1,500], 'ip_daily_limit'=>[1,2000], 'daily_limit'=>[1,100000],
        'captcha_ttl'=>[60,600], 'captcha_attempts'=>[1,5], 'captcha_bits'=>[16,22],
        'captcha_ip_minute'=>[1,30], 'captcha_ip_hour'=>[1,300],
        'captcha_browser_minute'=>[1,10], 'captcha_browser_hour'=>[1,100]];
}
function ssa_validate_service_url($value) {
    if (!is_string($value) || strlen($value)>2048) return new WP_Error('ssa_url', 'Укажите HTTPS-адрес сервиса.');
    $value = rtrim(trim($value), '/');
    if (!preg_match('~^https://~i', $value)) $value = 'https://' . $value;
    $parts = wp_parse_url($value);
    if (!$parts || ($parts['scheme']??'')!=='https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\s\\\\]/', $value)
        || !preg_match('/^[a-z0-9.-]+$/iD', $parts['host']) || !str_contains($parts['host'], '.')
        || (isset($parts['port']) && $parts['port']!==443)
        || (filter_var($parts['host'], FILTER_VALIDATE_IP) && !filter_var($parts['host'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))) {
        return new WP_Error('ssa_url', 'Нужен публичный HTTPS-адрес без логина, параметров и перенаправлений.');
    }
    return esc_url_raw($value, ['https']);
}
function ssa_validate_settings(array $input) {
    $out = array_merge(ssa_defaults(), (array)get_option('ssa_settings', []));
    foreach (['service_url','api_path','privacy_url','repository'] as $key) {
        if (!array_key_exists($key,$input)) continue;
        if (!is_string($input[$key])) return new WP_Error('ssa_setting','Некорректное значение настройки.');
        $value=trim($input[$key]);
        if ($key==='service_url') { $value=ssa_validate_service_url($value); if(is_wp_error($value))return $value; }
        if ($key==='api_path') {
            if (!preg_match('~^/[a-zA-Z0-9/_-]+/$~D',$value) || str_contains($value,'//')) return new WP_Error('ssa_path','Путь API должен начинаться и заканчиваться / и содержать только части пути.');
        }
        if ($key==='repository' && !preg_match('~^[a-zA-Z0-9][a-zA-Z0-9-]*/[a-zA-Z0-9][a-zA-Z0-9_.-]*$~D',$value)) return new WP_Error('ssa_repo','Укажите репозиторий GitHub в формате владелец/имя.');
        if ($key==='privacy_url' && $value!=='') {
            $value=esc_url_raw($value,['https','http']);
            if (!$value || !wp_parse_url($value,PHP_URL_HOST))return new WP_Error('ssa_policy','Укажите полный адрес политики конфиденциальности.');
        }
        $out[$key]=$value;
    }
    foreach (ssa_numeric_ranges() as $key=>[$min,$max]) {
        if (!array_key_exists($key,$input))continue;
        if (!is_scalar($input[$key]) || !preg_match('/^[0-9]+$/D',(string)$input[$key]) || (int)$input[$key]<$min || (int)$input[$key]>$max) return new WP_Error('ssa_limit','Значение «'.$key.'» должно быть от '.$min.' до '.$max.'.');
        $out[$key]=(int)$input[$key];
    }
    foreach (['enabled','account','checkout','profile','tryon','merge','automatic_updates'] as $key) if(array_key_exists($key,$input))$out[$key]=in_array($input[$key],[true,1,'1','on'],true);
    if($out['phone_daily_limit']<$out['phone_hourly_limit'] || $out['ip_daily_limit']<$out['ip_hourly_limit'])return new WP_Error('ssa_limits','Суточный лимит не может быть меньше часового.');
    return $out;
}
function ssa_encrypt_secret(string $secret) {
    if (!function_exists('openssl_encrypt'))return new WP_Error('ssa_crypto','Для хранения ключа нужен PHP OpenSSL.');
    $iv=random_bytes(12);$tag='';$key=hash('sha256',wp_salt('auth').'|'.wp_salt('secure_auth').'|ssa-secret-v1',true);
    $cipher=openssl_encrypt($secret,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'solartek-sms-auth',16);
    if(!is_string($cipher))return new WP_Error('ssa_crypto','Не удалось защитить ключ.');
    return ['v'=>1,'iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'cipher'=>base64_encode($cipher)];
}
function ssa_secret_get(): string {
    $stored=get_option('ssa_secret',[]);
    if(!is_array($stored) || ($stored['v']??0)!==1 || !function_exists('openssl_decrypt'))return '';
    $iv=base64_decode($stored['iv']??'',true);$tag=base64_decode($stored['tag']??'',true);$cipher=base64_decode($stored['cipher']??'',true);
    if(!is_string($iv)||strlen($iv)!==12||!is_string($tag)||strlen($tag)!==16||!is_string($cipher))return '';
    $key=hash('sha256',wp_salt('auth').'|'.wp_salt('secure_auth').'|ssa-secret-v1',true);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'solartek-sms-auth');
    return is_string($plain)?$plain:'';
}
function ssa_store_secret(string $secret) {
    if(strlen($secret)<16 || strlen($secret)>512 || preg_match('/[\x00-\x20\x7f]/',$secret))return new WP_Error('ssa_key','Ключ должен содержать от 16 до 512 символов без пробелов.');
    $encrypted=ssa_encrypt_secret($secret);if(is_wp_error($encrypted))return $encrypted;
    update_option('ssa_secret',$encrypted,false);return true;
}
function ssa_service_url(string $path): string { return rtrim((string)ssa_config('service_url'),'/').ssa_config('api_path').ltrim($path,'/'); }
function ssa_asset_url(string $path): string { return plugins_url('compat/assets/'.ltrim($path,'/'),SSA_FILE); }
function ssa_privacy_url(): string { return ssa_config('privacy_url') ?: (get_privacy_policy_url() ?: home_url('/privacy-policy/')); }
function ssa_form_enabled(string $context=''): bool {
    if($context==='tryon')return (bool)ssa_config('tryon');
    if(function_exists('is_user_logged_in')&&is_user_logged_in())return (bool)ssa_config('profile');
    if(function_exists('is_checkout')&&is_checkout())return (bool)ssa_config('checkout');
    return (bool)ssa_config('account');
}

