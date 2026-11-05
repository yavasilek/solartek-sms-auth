<?php
define('MOTO_SMS_PROFILE', false);
require __DIR__.'/bootstrap.php';

$checks=[];
$emailAttempts=[];
function email_check(string $name,bool $ok):void {
    global $checks;
    if(!$ok)throw new RuntimeException($name);
    $checks[]=$name;
}
// Record the WordPress notification boundary without delivering any mail.
function email_change_fixture($oldEmail,string $newEmail,$send=true):void {
    global $emailAttempts;
    $user=['ID'=>get_current_user_id(),'user_email'=>$oldEmail];
    $updated=['ID'=>$user['ID'],'user_email'=>$newEmail];
    if($oldEmail!==$newEmail && apply_filters('send_email_change_email',$send,$user,$updated)) {
        $emailAttempts[]=['to'=>$user['user_email'],'new_email'=>$updated['user_email']];
    }
}

$phone='+79991234567';$session=str_repeat('e',64);
fixture_send($session,$phone);$login=moto_phone_verify($session,'123456');
email_check('Mock SMS registration creates an account without email',!is_wp_error($login)&&get_current_user_id()>0&&wp_get_current_user()->user_email==='');
email_change_fixture(wp_get_current_user()->user_email,'first@example.invalid');
email_check('Adding the first email never attempts a notification to an empty address',$emailAttempts===[]);
email_check('The guard remains active with the optional profile form disabled',ssa_config('profile')===false&&isset($fixtureActions['send_email_change_email']));
foreach(['   ','broken-address',null,[]] as $oldEmail) {
    email_change_fixture($oldEmail,'first@example.invalid');
    email_check('Missing or invalid previous address never reaches mail delivery',$emailAttempts===[]);
}
email_change_fixture('old@example.invalid','new@example.invalid');
email_check('An ordinary email change still notifies the original address',$emailAttempts===[['to'=>'old@example.invalid','new_email'=>'new@example.invalid']]);
email_change_fixture('old@example.invalid','new@example.invalid',false);
email_check('An earlier decision to disable the notification is preserved',count($emailAttempts)===1);
email_check('A valid old address preserves the exact incoming decision',apply_filters('send_email_change_email',0,['user_email'=>'old@example.invalid'],['user_email'=>'new@example.invalid'])===0);
email_change_fixture('old@example.invalid','');
email_check('Removing an email still allows notifying the valid former address',count($emailAttempts)===2&&end($emailAttempts)['to']==='old@example.invalid');
email_check('Password-change notifications are unaffected',apply_filters('send_password_change_email',true,['user_email'=>'old@example.invalid'],[])===true);
echo json_encode(['suite'=>'email-change','passed'=>count($checks),'checks'=>$checks,'realEmailsSent'=>0,'realSmsSent'=>0],JSON_PRETTY_PRINT).PHP_EOL;
