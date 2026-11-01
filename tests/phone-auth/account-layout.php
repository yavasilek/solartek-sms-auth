<?php
require __DIR__.'/bootstrap.php';
$accountPage=true; $registration='no';
function is_account_page() { global $accountPage; return $accountPage; }
function get_option($name) { global $registration; return $name==='woocommerce_enable_myaccount_registration'?$registration:false; }
require dirname(__DIR__,2).'/plugin/solartek-sms-auth/compat/motonaklejki-account-login.php';
function capture($callback) { ob_start(); $callback(); return ob_get_clean(); }
if (($argv[1]??'')==='--markup') {
    echo json_encode(['open'=>capture('moto_account_login_open'),'close'=>capture('moto_account_login_close')]); exit;
}
$checks=[];
function check_layout($name,$ok) { global $checks; if(!$ok)throw new RuntimeException($name);$checks[]=$name; }
$open=capture('moto_account_login_open');
check_layout('Guest native form gets one matching section',substr_count($open,'<section ')===1 && str_contains($open,'moto-phone-auth moto-password-auth'));
check_layout('Section has its own accessible heading',str_contains($open,'aria-labelledby=') && str_contains($open,'Войти по паролю'));
check_layout('Native form and nonce are not replaced',!str_contains($open,'<form') && !str_contains($open,'<input'));
check_layout('SMS initializer does not target password form',!str_contains($open,'data-moto-phone-auth'));
check_layout('Duplicate hook does not open another section',capture('moto_account_login_open')==='');
check_layout('Matching close emitted once',capture('moto_account_login_close')==='</section>' && capture('moto_account_login_close')==='');
$registration='yes'; check_layout('Registration template left intact',capture('moto_account_login_open')===''); $registration='no';
$accountPage=false; check_layout('Checkout not wrapped',capture('moto_account_login_open')===''); $accountPage=true;
$fixtureUser=1; check_layout('Logged-in account not wrapped',capture('moto_account_login_open')===''); $fixtureUser=0;
check_layout('No authentication or SMS side effects',$fixtureCalls===[] && $fixtureLoginCalls===[]);
echo json_encode(['passed'=>count($checks),'checks'=>$checks,'realSmsSent'=>0],JSON_PRETTY_PRINT).PHP_EOL;
