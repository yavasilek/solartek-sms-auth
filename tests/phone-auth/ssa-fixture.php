<?php
if(!defined("SSA_VERSION"))define("SSA_VERSION","1.0.0");
// Existing security tests exercise the shared core with a mock gateway only.
function ssa_config(string $key) {
    $defaults=['enabled'=>true,'account'=>true,'checkout'=>true,'profile'=>true,'tryon'=>true,'merge'=>true,
        'request_timeout'=>15,'phone_hourly_limit'=>3,'phone_daily_limit'=>10,'ip_hourly_limit'=>10,'ip_daily_limit'=>30,'daily_limit'=>100,
        'captcha_ttl'=>180,'captcha_attempts'=>3,'captcha_bits'=>18,'captcha_ip_minute'=>5,'captcha_ip_hour'=>30,'captcha_browser_minute'=>3,'captcha_browser_hour'=>10];
    $constant='MOTO_SMS_'.strtoupper($key);
    return defined($constant)?constant($constant):($defaults[$key]??null);
}
function ssa_secret_get(): string { return trim((string)file_get_contents(MOTO_SMS_API_KEY_FILE)); }
function ssa_service_url(string $path): string { return 'https://sms.solartek.ru/api/v1/auth/challenges/'.ltrim($path,'/'); }
function ssa_asset_url(string $path): string { return '/plugin/solartek-sms-auth/compat/assets/'.$path; }
function ssa_privacy_url(): string { return get_privacy_policy_url(); }
function ssa_consent_statement(string $context='account'): string { return 'Согласен на обработку персональных данных по <a href="'.esc_url(ssa_privacy_url()).'" target="_blank" rel="noopener">политике конфиденциальности</a>.'; }
function ssa_form_enabled(string $context=''): bool { return true; }
function wp_safe_remote_post($url,$args) { return wp_remote_post($url,$args); }
