<?php
defined('ABSPATH') || exit;

function ssa_admin_url(int $step=1): string { return add_query_arg(['page'=>'solartek-sms-auth','step'=>$step],admin_url('options-general.php')); }
function ssa_require_admin(): void { if(!current_user_can('manage_options'))wp_die('Недостаточно прав.', '', ['response'=>403]); }
function ssa_notice(string $message,string $type='success'): void { set_transient('ssa_notice_'.get_current_user_id(),['message'=>$message,'type'=>$type],60); }
function ssa_requirements(): array {
    return ['PHP 8.1 или новее'=>PHP_VERSION_ID>=80100,'WooCommerce включён'=>function_exists('WC'),
        'OpenSSL для защиты ключа'=>function_exists('openssl_encrypt')&&in_array('aes-256-gcm',openssl_get_cipher_methods(),true),
        'GD и FreeType для CAPTCHA'=>function_exists('imagecreatetruecolor')&&function_exists('imagettftext'),
        'ZIP для проверки обновлений'=>class_exists('ZipArchive'),
        'Шрифт CAPTCHA доступен'=>is_readable(SSA_DIR.'compat/assets/fonts/DejaVuSans-Bold.ttf')];
}
function ssa_admin_save(): void {
    ssa_require_admin();check_admin_referer('ssa_save');
    $previousRepository=(string)ssa_config('repository');
    $step=max(1,min(4,(int)($_POST['step']??1)));$posted=wp_unslash($_POST);$changes=[];
    $fields=[1=>['service_url','api_path','request_timeout'],2=>array_merge(array_keys(ssa_numeric_ranges()),['privacy_url','account','checkout','profile','tryon','merge']),3=>['repository','automatic_updates'],4=>['enabled']];
    foreach($fields[$step] as $key){if(in_array($key,['account','checkout','profile','tryon','merge','automatic_updates','enabled'],true))$changes[$key]=$posted[$key]??'0';elseif(isset($posted[$key]))$changes[$key]=$posted[$key];}
    $settings=ssa_validate_settings($changes);
    if(!is_wp_error($settings)&&$step===1){
        $secret=$posted['api_key']??'';
        if(!is_string($secret))$settings=new WP_Error('ssa_key','Укажите корректный ключ сервиса.');
        elseif($secret!==''){ $stored=ssa_store_secret(trim($secret));if(is_wp_error($stored))$settings=$stored; }
        elseif(ssa_secret_get()==='')$settings=new WP_Error('ssa_key','Введите ключ API, выданный вашим SMS-сервисом.');
    }
    if(!is_wp_error($settings)&&$step===4&&$settings['enabled']){
        if(in_array(false,ssa_requirements(),true))$settings=new WP_Error('ssa_requirements','Выполните требования к серверу перед включением входа.');
        elseif(ssa_secret_get()==='')$settings=new WP_Error('ssa_key','Сначала сохраните ключ API на шаге подключения.');
        else{$url=ssa_validate_service_url($settings['service_url']);if(is_wp_error($url))$settings=$url;}
    }
    if(is_wp_error($settings)){ssa_notice($settings->get_error_message(),'error');wp_safe_redirect(ssa_admin_url($step));exit;}
    $settings['setup_step']=min(4,$step+1);
    if($step===4)$settings['configured']=true;
    update_option('ssa_settings',$settings,false);
    // Ordinary wizard edits must not force every installed plugin to contact its update server.
    if($settings['repository']!==$previousRepository)delete_site_transient('update_plugins');
    ssa_notice($step===4?'Настройки сохранены. Модуль готов к работе.':'Шаг сохранён.');
    wp_safe_redirect(ssa_admin_url($step===4?4:$step+1));exit;
}
function ssa_admin_connection(): void {
    ssa_require_admin();check_admin_referer('ssa_connection');
    $valid=ssa_validate_service_url(ssa_config('service_url'));
    if(is_wp_error($valid))ssa_notice($valid->get_error_message(),'error');
    else{
        // Availability only: do not create a challenge or send a paid SMS.
        $reply=wp_safe_remote_get(ssa_service_url(''),['timeout'=>8,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>2048]);
        $code=is_wp_error($reply)?0:wp_remote_retrieve_response_code($reply);
        $ok=$code>=200&&$code<500&&$code!==301&&$code!==302&&$code!==307&&$code!==308;
        update_option('ssa_connection_status',['checked_at'=>time(),'http'=>$code,'reachable'=>$ok],false);
        ssa_notice($ok?'Сервис отвечает по HTTPS (HTTP '.$code.'). SMS не отправляли. Ключ будет проверен сервисом при первом рабочем запросе.':'Не удалось подтвердить доступность API. Проверьте адрес и путь. SMS не отправляли.',$ok?'success':'error');
    }
    wp_safe_redirect(ssa_admin_url(1));exit;
}
function ssa_admin_check_updates(): void {
    ssa_require_admin();check_admin_referer('ssa_updates');
    $release=ssa_release_info(null,true);
    if(is_wp_error($release))ssa_notice('Не удалось проверить релизы. Попробуйте позже.','error');
    else{delete_site_transient('update_plugins');if(function_exists('wp_update_plugins'))wp_update_plugins();ssa_notice('Последняя опубликованная версия: '.$release['version'].'. Установлена: '.SSA_VERSION.'.');}
    wp_safe_redirect(ssa_admin_url(3));exit;
}
function ssa_admin_input(string $key,string $label,string $type='text',string $help=''): void {
    echo '<div class="ssa-field"><label for="ssa-'.esc_attr($key).'">'.esc_html($label).'</label><div>';
    echo '<input id="ssa-'.esc_attr($key).'" name="'.esc_attr($key).'" type="'.esc_attr($type).'" value="'.esc_attr((string)ssa_config($key)).'"';
    if(isset(ssa_numeric_ranges()[$key])){[$min,$max]=ssa_numeric_ranges()[$key];echo ' min="'.(int)$min.'" max="'.(int)$max.'" step="1"';}
    echo ' class="regular-text">';if($help!=='')echo '<p class="description">'.esc_html($help).'</p>';echo '</div></div>';
}
function ssa_admin_checkbox(string $key,string $label): void {
    echo '<label class="ssa-checkbox"><input type="checkbox" name="'.esc_attr($key).'" value="1" '.checked((bool)ssa_config($key),true,false).'> <span>'.esc_html($label).'</span></label>';
}
function ssa_admin_page(): void {
    ssa_require_admin();$step=max(1,min(4,(int)($_GET['step']??ssa_config('setup_step'))));
    $labels=[1=>'Подключение сервиса',2=>'Формы и ограничения',3=>'Обновления',4=>'Проверка и запуск'];
    echo '<div class="wrap ssa-admin" data-ui><h1>Solartek SMS Auth</h1><p>Настройте SMS-вход для сайта '.esc_html(wp_parse_url(home_url('/'),PHP_URL_HOST)).'. Версия '.esc_html(SSA_VERSION).'.</p>';
    $notice=get_transient('ssa_notice_'.get_current_user_id());if(is_array($notice)){delete_transient('ssa_notice_'.get_current_user_id());echo '<div class="notice notice-'.esc_attr($notice['type']).'" role="status"><p>'.esc_html($notice['message']).'</p></div>';}
    echo '<nav aria-label="Шаги мастера"><ol class="ssa-steps">';foreach($labels as $n=>$label)echo '<li><a href="'.esc_url(ssa_admin_url($n)).'" '.($n===$step?'aria-current="step"':'').'>'.esc_html($label).'</a></li>';echo '</ol></nav>';
    echo '<section class="ssa-sheet"><h2>'.esc_html($labels[$step]).'</h2><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    wp_nonce_field('ssa_save');echo '<input type="hidden" name="action" value="ssa_save"><input type="hidden" name="step" value="'.(int)$step.'">';
    if($step===1){
        echo '<p>Адрес и ключ выдаёт ваш SMS-сервис. Он должен поддерживать API создания и подтверждения SMS-challenge.</p>';
        ssa_admin_input('service_url','Адрес сервиса','text','Например: https://sms.solartek.ru. Можно указать другой совместимый сервис.');
        ssa_admin_input('api_path','Путь API','text','По умолчанию /api/v1/auth/challenges/');
        echo '<div class="ssa-field"><label for="ssa-api-key">Ключ API</label><div><input class="regular-text" id="ssa-api-key" type="password" name="api_key" value="" autocomplete="new-password" spellcheck="false"><p class="description">'.(ssa_secret_get()!==''?'Ключ сохранён. Оставьте поле пустым, чтобы сохранить его.':'Введите ключ. Он хранится в зашифрованном виде и не попадает в Git или архив плагина.').'</p></div></div>';
        ssa_admin_input('request_timeout','Ожидание ответа, секунд','number');
    }elseif($step===2){
        echo '<p>Поддерживаются мобильные номера России (+7 9…). CAPTCHA и подтверждение SMS обязательны; вход администратора по SMS закрыт.</p><div class="ssa-checks">';
        foreach(['account'=>'Вход в личном кабинете','checkout'=>'Вход при оформлении заказа','profile'=>'Подтверждение телефона и имя в профиле','tryon'=>'Вход во встроенном окне примерки, если сайт его использует','merge'=>'Объединение телефонного и почтового аккаунта после проверки владения'] as $k=>$l)ssa_admin_checkbox($k,$l);echo '</div>';
        ssa_admin_input('privacy_url','Политика конфиденциальности','url','Пустое поле использует политику, выбранную в настройках WordPress.');
        echo '<h3>Лимиты отправки SMS</h3><div class="ssa-numbers">';
        foreach(['phone_hourly_limit'=>'На номер за час','phone_daily_limit'=>'На номер за сутки','ip_hourly_limit'=>'На IP за час','ip_daily_limit'=>'На IP за сутки','daily_limit'=>'На сайт за сутки'] as $k=>$l)ssa_admin_input($k,$l,'number');echo '</div>';
        echo '<details><summary>Параметры CAPTCHA и защиты от перебора</summary><div class="ssa-numbers">';
        foreach(['captcha_ttl'=>'Действие CAPTCHA, секунд','captcha_attempts'=>'Попыток ввода CAPTCHA','captcha_bits'=>'Сложность браузерной проверки','captcha_ip_minute'=>'CAPTCHA на IP за минуту','captcha_ip_hour'=>'CAPTCHA на IP за час','captcha_browser_minute'=>'CAPTCHA на браузер за минуту','captcha_browser_hour'=>'CAPTCHA на браузер за час'] as $k=>$l)ssa_admin_input($k,$l,'number');echo '</div></details>';
    }elseif($step===3){
        echo '<p>Сайты получают общие выпуски из GitHub. Изменения разрабатываются в репозитории, проходят проверки и публикуются новым релизом. Настройки этого сайта при обновлении сохраняются.</p>';
        ssa_admin_input('repository','Репозиторий GitHub','text','Общий источник: yavasilek/solartek-sms-auth. Меняйте только при использовании собственного форка.');
        ssa_admin_checkbox('automatic_updates','Автоматически устанавливать новые стабильные версии через WordPress');
        echo '<p class="description">Проверяем релизы дважды в сутки. Нужны работающий WP-Cron или системный cron и права WordPress на обновление плагинов. При включении заменяем код этого модуля опубликованным релизом, в том числе на сайте внутри Git. Локальные правки установленного модуля будут заменены. Остальные плагины эта задача не обновляет.</p>';
    }else{
        echo '<ul class="ssa-requirements">';foreach(ssa_requirements() as $name=>$ok)echo '<li><strong>'.($ok?'Готово: ':'Требуется: ').'</strong>'.esc_html($name).'</li>';echo '</ul>';
        echo '<dl class="ssa-summary"><dt>Сервис</dt><dd>'.esc_html(ssa_config('service_url').ssa_config('api_path')).'</dd><dt>Ключ</dt><dd>'.(ssa_secret_get()!==''?'Сохранён и зашифрован':'Не настроен').'</dd><dt>Релизы</dt><dd>'.esc_html(ssa_config('repository')).'</dd><dt>Лимит SMS на сайт</dt><dd>'.(int)ssa_config('daily_limit').' за сутки</dd></dl>';
        ssa_admin_checkbox('enabled','Включить вход по SMS на этом сайте');
    }
    submit_button($step===4?'Сохранить и завершить':'Сохранить и продолжить');echo '</form>';
    if($step===1||$step===3){$action=$step===1?'ssa_connection':'ssa_updates';echo '<form class="ssa-secondary" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr($action).'">';wp_nonce_field($action);submit_button($step===1?'Проверить доступность сохранённого адреса':'Проверить обновления','secondary','ssa_secondary',false);echo '</form>';}
    echo '</section></div>';
}
add_action('admin_menu',static function(){add_options_page('Solartek SMS Auth','SMS-вход','manage_options','solartek-sms-auth','ssa_admin_page');});
add_action('admin_enqueue_scripts',static function($hook){if($hook==='settings_page_solartek-sms-auth')wp_enqueue_style('ssa-admin',plugins_url('assets/admin.css',SSA_FILE),[],SSA_VERSION);});
add_action('admin_init',static function(){if(get_option('ssa_setup_redirect')&&current_user_can('manage_options')&&!wp_doing_ajax()){delete_option('ssa_setup_redirect');wp_safe_redirect(ssa_admin_url());exit;}});
add_action('admin_post_ssa_save','ssa_admin_save');add_action('admin_post_ssa_connection','ssa_admin_connection');add_action('admin_post_ssa_updates','ssa_admin_check_updates');
add_filter('plugin_action_links_'.SSA_BASENAME,static function($links){array_unshift($links,'<a href="'.esc_url(ssa_admin_url()).'">Настроить</a>');return $links;});

