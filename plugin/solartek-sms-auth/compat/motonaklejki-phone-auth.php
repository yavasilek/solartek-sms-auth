<?php
/**
 * Plugin Name: Мотонаклейки — вход по SMS
 * Description: Server-side Solartek challenges for verified customer phone numbers.
 * Version: 1.1.0
 */

defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/phone-auth/antibot.php';

function moto_phone_error(string $code, string $message, int $status = 400): WP_Error {
    return new WP_Error($code, $message, ['status' => $status]);
}

function moto_phone_private_file(string $constant): string {
    if (!defined($constant) || !is_string(constant($constant))) return '';
    $file = realpath(constant($constant));
    if (!$file || !is_file($file) || !is_readable($file) || filesize($file) > 4096) return '';
    // The secret must be outside both WordPress and the web server's document root.
    foreach ([ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ''] as $root) {
        $root = $root !== '' ? realpath($root) : false;
        if ($root && strncasecmp(str_replace('\\', '/', $file), rtrim(str_replace('\\', '/', $root), '/') . '/', strlen(rtrim(str_replace('\\', '/', $root), '/')) + 1) === 0) return '';
    }
    return trim((string) file_get_contents($file));
}

function moto_phone_key(): string { return ssa_secret_get(); }

function moto_phone_enabled(): bool {
    return ssa_config('enabled') === true && function_exists('WC') && moto_phone_key() !== '';
}

function moto_phone_normalize($value): string {
    if (!is_string($value) || strlen($value) > 128) return '';
    // Accept copied typography as well as ordinary phone punctuation. Reject
    // letters/extensions and misplaced plus signs instead of stripping them.
    $compact = preg_replace('/[\p{Z}\s().\-\x{2010}-\x{2015}\x{2212}]/u', '', $value);
    if (!is_string($compact) || !preg_match('/^\+?[0-9]{10,11}$/D', $compact)) return '';
    $digits = ltrim($compact, '+');
    if (strlen($digits) === 10) $digits = '7' . $digits;
    if (strlen($digits) === 11 && $digits[0] === '8') $digits[0] = '7';
    return preg_match('/^79[0-9]{9}$/D', $digits) ? '+' . $digits : '';
}

function moto_phone_hash(string $value): string {
    return hash_hmac('sha256', $value, wp_salt('auth'));
}

function moto_phone_name(string $key): string {
    return '_moto_sms_' . moto_phone_hash($key);
}

// Ephemeral, non-autoloaded records in the existing options table. Direct SQL
// deliberately avoids stale object-cache reads for locks and single-use proofs.
function moto_phone_raw(string $key): ?string {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", moto_phone_name($key)));
    if ($wpdb->last_error) throw new RuntimeException('SMS state read failed');
    return is_string($value) ? $value : null;
}

function moto_phone_state(string $key): array {
    $value = json_decode(moto_phone_raw($key) ?? '', true);
    return is_array($value) && ($value['expires'] ?? 0) > time() ? $value : [];
}

function moto_phone_store(string $key, array $value): void {
    global $wpdb;
    if ($wpdb->replace($wpdb->options, ['option_name' => moto_phone_name($key), 'option_value' => wp_json_encode($value), 'autoload' => 'no'], ['%s', '%s', '%s']) === false) {
        throw new RuntimeException('SMS state write failed');
    }
}

function moto_phone_delete_if(string $key, string $value): void {
    global $wpdb;
    if ($wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", moto_phone_name($key), $value)) === false) {
        throw new RuntimeException('SMS state delete failed');
    }
}

function moto_phone_lock(string $key): string {
    global $wpdb;
    $key = 'lock:' . $key;
    $value = wp_json_encode(['token' => bin2hex(random_bytes(24)), 'expires' => time() + 120]);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", moto_phone_name($key), $value));
        if ($inserted === false) throw new RuntimeException('SMS lock failed');
        if ($inserted === 1) return $value;
        $old = moto_phone_raw($key);
        $record = json_decode($old ?? '', true);
        if ($old !== null && is_array($record) && ($record['expires'] ?? PHP_INT_MAX) <= time()) {
            moto_phone_delete_if($key, $old);
        } else break;
    }
    return '';
}

function moto_phone_unlock(string $key, string $token): void {
    if ($token !== '') moto_phone_delete_if('lock:' . $key, $token);
}

function moto_phone_customer($user): bool {
    if (!$user instanceof WP_User || $user->ID <= 0 || (int) $user->user_status !== 0
        || empty($user->roles) || array_diff($user->roles, ['customer', 'subscriber']) || is_super_admin($user->ID)
        || (function_exists('moto_account_is_merged') ? moto_account_is_merged($user->ID) : get_user_meta($user->ID, '_moto_merged_into', true) !== '')) return false;
    // A role label is not sufficient: plugins can grant capabilities directly.
    foreach (['manage_options', 'manage_woocommerce', 'edit_users', 'create_users', 'delete_users', 'promote_users', 'remove_users', 'list_users',
        'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'edit_themes', 'switch_themes', 'install_themes',
        'update_themes', 'delete_themes', 'edit_theme_options', 'manage_network', 'manage_sites', 'manage_network_users', 'manage_network_plugins',
        'manage_network_themes', 'manage_network_options', 'upgrade_network', 'setup_network', 'edit_posts', 'edit_pages', 'edit_others_posts',
        'edit_others_pages', 'publish_posts', 'publish_pages', 'upload_files', 'unfiltered_html', 'unfiltered_upload', 'edit_products',
        'edit_shop_orders', 'edit_shop_coupons', 'view_woocommerce_reports', 'manage_categories', 'moderate_comments', 'export', 'import',
        'read_private_posts', 'read_private_pages', 'read_private_products', 'read_private_shop_orders'] as $capability) {
        if (user_can($user, $capability)) return false;
    }
    return true;
}

function moto_phone_binding(): string {
    return (string) get_current_user_id() . ':' . moto_phone_hash(wp_get_session_token());
}

function moto_phone_origin(string $url): string {
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return '';
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return '';
    $host = strtolower($parts['host']);
    // This site's production PHP has no intl extension. Its exact canonical
    // alias is enough; never accept arbitrary subdomains or a suffix match.
    if ($host === 'мотонаклейки.рф') $host = 'xn--80akjddcefjja1c.xn--p1ai';
    elseif (function_exists('idn_to_ascii')) $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $host;
    return $scheme . '://' . $host . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
}

function moto_phone_same_origin(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') return false;
    $allowed = array_filter([moto_phone_origin(home_url('/')), moto_phone_origin(site_url('/'))]);
    $source = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
    if (!is_string($source) || !in_array(moto_phone_origin($source), $allowed, true)) return false;
    if (isset($_SERVER['HTTP_REFERER']) && !in_array(moto_phone_origin((string) $_SERVER['HTTP_REFERER']), $allowed, true)) return false;
    return true;
}

function moto_phone_session(bool $create = false): string {
    // HTTPS host-only prefix prevents fixation by a sibling subdomain.
    $secure = is_ssl() || (wp_parse_url(home_url('/'))['scheme'] ?? '') === 'https';
    $name = $secure ? '__Host-moto_sms_session' : 'moto_sms_session';
    $cookie = $_COOKIE[$name] ?? '';
    if (is_string($cookie) && preg_match('/^[a-f0-9]{64}$/D', $cookie)) return $cookie;
    if (!$create || headers_sent()) return '';
    $cookie = bin2hex(random_bytes(32));
    if (!setcookie($name, $cookie, ['expires' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict'])) return '';
    $_COOKIE[$name] = $cookie;
    return $cookie;
}

function moto_phone_nonce(string $session, ?int $bucket = null): string {
    $bucket = $bucket ?? (int) floor(time() / 600);
    return $bucket . '.' . moto_phone_hash('csrf:' . $session . ':' . moto_phone_binding() . ':' . $bucket);
}

function moto_phone_valid_nonce(string $session, $nonce): bool {
    if ($session === '' || !is_string($nonce) || !preg_match('/^([0-9]+)\.[a-f0-9]{64}$/D', $nonce, $match)) return false;
    $bucket = (int) $match[1];
    $now = (int) floor(time() / 600);
    return ($bucket === $now || $bucket === $now - 1) && hash_equals(moto_phone_nonce($session, $bucket), $nonce);
}

function moto_phone_limit(string $constant, int $default): int {
    return (int) ssa_config(strtolower(str_replace('MOTO_SMS_', '', $constant)));
}

function moto_phone_budget(string $phone, string $session) {
    // Only REMOTE_ADDR is trusted. A public X-Forwarded-For header is not a quota identity.
    $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    $limits = [
        ['phone:' . $phone, 3600, moto_phone_limit('MOTO_SMS_PHONE_HOURLY_LIMIT', 3)],
        ['phone:' . $phone, 86400, moto_phone_limit('MOTO_SMS_PHONE_DAILY_LIMIT', 10)],
        ['ip:' . $ip, 3600, moto_phone_limit('MOTO_SMS_IP_HOURLY_LIMIT', 10)],
        ['ip:' . $ip, 86400, moto_phone_limit('MOTO_SMS_IP_DAILY_LIMIT', 30)],
        ['browser:' . $session, 3600, 5],
        ['site', 86400, moto_phone_limit('MOTO_SMS_DAILY_LIMIT', 100)],
    ];
    $lock = moto_phone_lock('budget');
    if (!$lock) return moto_phone_error('busy', 'Попробуйте ещё раз через несколько секунд.', 409);
    try {
        $updates = [];
        foreach ($limits as [$identity, $window, $maximum]) {
            $bucket = (int) floor(time() / $window);
            $key = 'rate:' . $identity . ':' . $window . ':' . $bucket;
            $state = moto_phone_state($key);
            if (($state['count'] ?? 0) >= $maximum) return moto_phone_error('rate_limit', 'Лимит SMS исчерпан. Попробуйте позже или войдите по паролю.', 429);
            $updates[$key] = ['count' => ($state['count'] ?? 0) + 1, 'expires' => ($bucket + 1) * $window];
        }
        foreach ($updates as $key => $value) moto_phone_store($key, $value);
    } finally { moto_phone_unlock('budget', $lock); }
    return true;
}

function moto_phone_api(string $path, array $body, string $idempotency = '') {
    // Fixed HTTPS origin, no redirects, and no transport/body data in public errors.
    $headers = ['Authorization' => 'Bearer ' . moto_phone_key(), 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
    if ($idempotency !== '') $headers['Idempotency-Key'] = $idempotency;
    $response = wp_safe_remote_post(ssa_service_url($path), [
        'headers' => $headers, 'body' => wp_json_encode($body), 'timeout' => (int) ssa_config('request_timeout'),
        'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 32768,
    ]);
    if (is_wp_error($response)) return moto_phone_error('provider_unavailable', 'Сервис SMS временно недоступен. Попробуйте ещё раз.', 503);
    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) return moto_phone_error('provider_unavailable', 'Не удалось подтвердить операцию в сервисе SMS. Попробуйте позже.', 503);
    $json = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($json) ? $json : moto_phone_error('provider_unavailable', 'Сервис SMS вернул неполный ответ. Попробуйте ещё раз.', 503);
}

function moto_phone_public_state(array $state): array {
    if (!$state || !in_array($state['status'] ?? '', ['sent', 'uncertain', 'verified'], true)) return [];
    return [
        'phone' => $state['phone'], 'status' => $state['status'],
        'code_length' => $state['code_length'] ?? 6,
        'retry_without_antibot' => ($state['status'] === 'uncertain' && ($state['antibot_passed'] ?? false) === true),
        'expires_in' => max(0, $state['expires'] - time()),
        'retry_after' => max(0, ($state['sent_at'] ?? 0) + 60 - time()),
    ];
}

function moto_phone_send(string $session, string $phone, bool $resend = false, array $proof = []) {
    if ($phone === '') return moto_phone_error('invalid_phone', 'Укажите мобильный номер России: +7 9XX XXX-XX-XX.');
    $key = 'session:' . $session;
    $lock = moto_phone_lock($key);
    if (!$lock) return moto_phone_error('busy', 'Предыдущий запрос ещё выполняется.', 409);
    $phoneLock = '';
    try {
        $state = moto_phone_state($key);
        if ($state && ($state['binding'] ?? '') !== moto_phone_binding()) $state = [];
        if (($state['status'] ?? '') === 'uncertain' && $state['phone'] !== $phone) return moto_phone_error('uncertain', 'Сначала повторите запрос для прежнего номера: статус отправки пока неизвестен.', 409);
        if (($state['status'] ?? '') === 'sent' && $state['phone'] === $phone && !$resend) return moto_phone_public_state($state);
        $recovering = ($state['status'] ?? '') === 'uncertain' && $state['phone'] === $phone;
        $phoneLock = moto_phone_lock('phone:' . $phone);
        if (!$phoneLock) return moto_phone_error('busy', 'Запрос для этого номера ещё выполняется.', 409);
        if (!$recovering) {
            $cooldown = moto_phone_state('cooldown:' . $phone);
            if ($cooldown) return moto_phone_error('cooldown', 'Следующий код можно запросить через минуту.', 429);
        }
        if (!$recovering || ($state['antibot_passed'] ?? false) !== true) {
            $antibot = moto_phone_antibot_check($session, $phone, $proof);
            if (is_wp_error($antibot)) return $antibot;
        }
        if (!$recovering) {
            $budget = moto_phone_budget($phone, $session);
            if (is_wp_error($budget)) return $budget;
            $operation = 'moto-phone-' . wp_generate_uuid4();
            $state = ['phone' => $phone, 'binding' => moto_phone_binding(), 'idempotency' => $operation,
                'status' => 'uncertain', 'antibot_passed' => true, 'attempts' => 0, 'sent_at' => time(), 'expires' => time() + 600];
            moto_phone_store($key, $state); // Persist before any possibly billable call.
            moto_phone_store('cooldown:' . $phone, ['expires' => time() + 60]);
        }
        $state['antibot_passed'] = true;
        if (($state['next_check_at'] ?? 0) > time()) return moto_phone_error('busy', 'Повторите проверку отправки через несколько секунд.', 409);
        $state['next_check_at'] = time() + 3;
        moto_phone_store($key, $state);
        $response = moto_phone_api('', ['phone' => $phone, 'channel' => 'sms', 'client_reference' => $state['idempotency']], $state['idempotency']);
        if (is_wp_error($response)) return $response; // Next explicit request reuses this exact operation.
        $expires = isset($response['expires_at']) && is_string($response['expires_at']) ? strtotime($response['expires_at']) : false;
        if (($response['simulated'] ?? null) !== false || ($response['error_code'] ?? null) !== ''
            || moto_phone_normalize($response['phone'] ?? '') !== $phone || ($response['channel'] ?? '') !== 'sms'
            || !in_array($response['code_length'] ?? null, [4, 6], true)
            || !is_string($response['challenge_id'] ?? null) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $response['challenge_id'])
            || !is_string($response['status'] ?? null) || $response['status'] === ''
            || in_array($response['status'], ['failed', 'expired', 'cancelled', 'canceled', 'rejected', 'verified'], true)
            || !$expires || $expires <= time()) {
            $state['status'] = 'failed'; moto_phone_store($key, $state);
            return moto_phone_error('provider_rejected', 'Сервис не подтвердил отправку рабочего SMS-кода. Попробуйте позже.', 503);
        }
        $state['challenge_id'] = $response['challenge_id'];
        $state['code_length'] = $response['code_length'];
        $state['expires'] = min($expires, $state['expires']);
        $state['status'] = 'sent';
        moto_phone_store($key, $state);
        return moto_phone_public_state($state);
    } finally {
        moto_phone_unlock('phone:' . $phone, $phoneLock);
        moto_phone_unlock($key, $lock);
    }
}

function moto_phone_resolve_user(string $phone) {
    $users = get_users(['meta_key' => '_moto_verified_phone', 'meta_value' => $phone, 'number' => 2, 'count_total' => false]);
    if (count($users) > 1) return moto_phone_error('account_conflict', 'Этот номер требует проверки. Войдите по паролю или обратитесь в поддержку.', 409);
    $owner = $users[0] ?? null;
    $legacy = !$owner && function_exists('moto_phone_legacy_owner') ? moto_phone_legacy_owner($phone) : null;
    if (is_wp_error($legacy)) return $legacy;
    $current = get_current_user_id();
    if ($current) {
        $user = wp_get_current_user();
        if (!moto_phone_customer($user) || ($owner && $owner->ID !== $current) || ($legacy && $legacy->ID !== $current)) return moto_phone_error('account_conflict', 'Номер нельзя привязать к этому аккаунту.', 409);
    } elseif ($owner) {
        if (!moto_phone_customer($owner)) return moto_phone_error('password_required', 'Для этого аккаунта используйте обычный вход по паролю.', 403);
        return $owner;
    } elseif ($legacy) {
        // This path is reached under the phone lock, after live OTP verification.
        $user = $legacy;
    } else {
        if (!get_role('customer')) return moto_phone_error('registration_unavailable', 'Регистрация временно недоступна.', 503);
        $id = wp_insert_user(['user_login' => 'moto_' . substr(moto_phone_hash('user:' . $phone), 0, 32),
            'user_pass' => wp_generate_password(64, true, true), 'user_email' => '',
            'display_name' => 'Покупатель', 'role' => 'customer']);
        if (is_wp_error($id)) return moto_phone_error('registration_unavailable', 'Не удалось создать аккаунт. Обратитесь в поддержку.', 503);
        $user = get_user_by('id', $id);
        if (!moto_phone_customer($user)) return moto_phone_error('registration_unavailable', 'Не удалось подготовить аккаунт.', 503);
        update_user_meta($id, 'billing_phone', $phone);
    }
    update_user_meta($user->ID, '_moto_verified_phone', $phone);
    update_user_meta($user->ID, '_moto_phone_verified_at', gmdate('c'));
    if (get_user_meta($user->ID, '_moto_verified_phone', true) !== $phone) return moto_phone_error('save_failed', 'Не удалось сохранить подтверждение номера.', 503);
    return $user;
}

function moto_phone_verify(string $session, string $code) {
    $key = 'session:' . $session;
    $lock = moto_phone_lock($key);
    if (!$lock) return moto_phone_error('busy', 'Предыдущий запрос ещё выполняется.', 409);
    $phoneLock = ''; $userLock = ''; $userLockId = 0;
    $phone = '';
    try {
        $state = moto_phone_state($key);
        if (!$state || ($state['binding'] ?? '') !== moto_phone_binding() || !in_array($state['status'] ?? '', ['sent', 'verified'], true)) return moto_phone_error('expired', 'Код больше не действует. Запросите новый.', 410);
        $phone = $state['phone'];
        if (($state['status'] ?? '') !== 'verified') {
            if (($state['attempts'] ?? 0) >= 5) return moto_phone_error('attempts', 'Попытки закончились. Запросите новый код.', 429);
            if (!preg_match('/^[0-9]{' . (int) $state['code_length'] . '}$/D', $code)) return moto_phone_error('invalid_code', 'Введите код из SMS целиком.');
            $state['attempts']++;
            moto_phone_store($key, $state); // Count before calling the provider, including timeouts.
            $response = moto_phone_api($state['challenge_id'] . '/verify/', ['code' => $code]);
            if (is_wp_error($response)) return $response;
            if (($response['verified'] ?? null) !== true || ($response['simulated'] ?? null) !== false
                || (isset($response['phone']) && moto_phone_normalize($response['phone']) !== $phone)) {
                return moto_phone_error('invalid_code', 'Код не подошёл или истёк. Проверьте SMS и попробуйте ещё раз.');
            }
            $state['status'] = 'verified';
            moto_phone_store($key, $state);
        }
        $phoneLock = moto_phone_lock('phone:' . $phone);
        if (!$phoneLock) return moto_phone_error('busy', 'Номер подтверждён. Повторите вход через несколько секунд.', 409);
        $userLockId = get_current_user_id();
        if ($userLockId) {
            $userLock = function_exists('moto_merge_identity_lock') ? (moto_merge_identity_lock($userLockId) ? 'request' : '') : moto_phone_lock('identity-user:' . $userLockId);
            if (!$userLock) return moto_phone_error('busy', 'Аккаунт сейчас обновляется. Повторите через несколько секунд.', 409);
        }
        $user = moto_phone_resolve_user($phone);
        if (is_wp_error($user)) return $user;
        $linking = get_current_user_id() > 0;
        $state['status'] = 'consumed';
        unset($state['challenge_id'], $state['phone'], $state['idempotency']);
        moto_phone_store($key, $state); // Consume under lock before issuing any auth cookie.
        if (!$linking) {
            wc_set_customer_auth_cookie($user->ID);
            do_action('wp_login', $user->user_login, $user);
        }
        return ['redirect' => wc_get_page_permalink('myaccount'), 'linked' => $linking];
    } finally {
        if ($userLockId && $userLock !== 'request') moto_phone_unlock('identity-user:' . $userLockId, $userLock);
        moto_phone_unlock('phone:' . $phone, $phoneLock);
        moto_phone_unlock($key, $lock);
    }
}

function moto_phone_ajax(): void {
    nocache_headers();
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    try {
        if (!moto_phone_enabled()) { wp_send_json_error(['message' => 'Вход по SMS пока недоступен.'], 503); return; }
        if (!moto_phone_same_origin()) { wp_send_json_error(['message' => 'Обновите страницу и повторите запрос.'], 403); return; }
        if (get_current_user_id() && !moto_phone_customer(wp_get_current_user())) { wp_send_json_error(['message' => 'Для этого аккаунта используйте обычные настройки безопасности.'], 403); return; }
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? $_POST['operation'] : '';
        $session = moto_phone_session($operation === 'context');
        if (!$session) { wp_send_json_error(['message' => 'Разрешите cookie и обновите страницу.'], 403); return; }
        if ($operation === 'context') {
            $state = moto_phone_state('session:' . $session);
            if (($state['binding'] ?? '') !== moto_phone_binding()) $state = [];
            wp_send_json_success(['nonce' => moto_phone_nonce($session), 'state' => moto_phone_public_state($state), 'passwordNonce' => wp_create_nonce('woocommerce-login')]); return;
        }
        if (!moto_phone_valid_nonce($session, $_POST['nonce'] ?? null)) { wp_send_json_error(['code' => 'csrf', 'message' => 'Время ожидания истекло. Обновите страницу.'], 403); return; }
        if ($operation === 'send' || $operation === 'captcha') {
            if (($_POST['consent'] ?? '') !== '1') $result = moto_phone_error('consent', 'Подтвердите согласие на обработку персональных данных.');
            else {
                $phone = moto_phone_normalize(isset($_POST['phone']) ? wp_unslash($_POST['phone']) : '');
                $result = $operation === 'captcha' ? moto_phone_antibot_issue($session, $phone, ($_POST['refresh'] ?? '') === '1')
                    : moto_phone_send($session, $phone, ($_POST['resend'] ?? '') === '1',
                        ['id' => $_POST['captcha_id'] ?? null, 'answer' => $_POST['captcha_answer'] ?? null, 'work' => $_POST['captcha_work'] ?? null]);
            }
        } elseif ($operation === 'verify') {
            $code = isset($_POST['code']) && is_string($_POST['code']) ? trim(wp_unslash($_POST['code'])) : '';
            $result = moto_phone_verify($session, $code);
            if (!is_wp_error($result) && !$result['linked'] && ($_POST['destination'] ?? '') === 'checkout') $result['redirect'] = wc_get_checkout_url();
            if (!is_wp_error($result) && !$result['linked'] && ($_POST['destination'] ?? '') !== 'checkout') $result['redirect'] = apply_filters('moto_phone_auth_redirect', $result['redirect']);
        } else $result = moto_phone_error('operation', 'Неизвестная операция.');
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $state = moto_phone_state('session:' . $session);
            if (($state['binding'] ?? '') !== moto_phone_binding()) $state = [];
            wp_send_json_error(['code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'state' => moto_phone_public_state($state)], $data['status'] ?? 400); return;
        }
        wp_send_json_success($result);
    } catch (Throwable $error) {
        // Never expose an exception or HTTP transport object containing the key/code.
        wp_send_json_error(['message' => 'Не удалось завершить вход. Попробуйте позже или войдите по паролю.'], 503);
    }
}

function moto_phone_render($context = '', $product_id = 0): void {
    static $rendered = false;
    if ($rendered || !ssa_form_enabled($context) || !moto_phone_enabled() || (get_current_user_id() && !moto_phone_customer(wp_get_current_user()))) return;
    $rendered = true;
    $linking = get_current_user_id() > 0;
    $tryon = $context === 'tryon' && !$linking;
    $id = wp_unique_id('moto-phone-');
    $verified = $linking ? get_user_meta(get_current_user_id(), '_moto_verified_phone', true) : '';
    $privacy = ssa_privacy_url();
    if (!$privacy) $privacy = home_url('/privacy-policy/');
    ?>
    <section class="moto-phone-auth" data-moto-phone-auth<?php if ($tryon): ?> data-context="tryon" data-lazy="1" data-sip-product="<?php echo esc_attr((string) $product_id); ?>"<?php endif; ?> data-worker="<?php echo esc_url(ssa_asset_url('motonaklejki-phone-antibot-worker.js') . '?ver=' . SSA_VERSION); ?>" data-linking="<?php echo $linking ? '1' : '0'; ?>" data-destination="<?php echo function_exists('is_checkout') && is_checkout() ? 'checkout' : 'account'; ?>" data-endpoint="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" aria-labelledby="<?php echo esc_attr($id); ?>title">
        <div class="moto-phone-auth__intro">
            <h2 id="<?php echo esc_attr($id); ?>title"><?php echo $tryon ? 'Войдите для примерки' : ($linking ? 'Телефон для входа' : 'Войти по телефону'); ?></h2>
            <p><?php echo $tryon ? 'Примерка сохранится в вашем аккаунте. Подтвердите номер — и перейдём к фото техники.' : ($linking ? 'Подтвердите номер кодом из SMS. После этого сможете входить без пароля.' : 'Получите код из SMS. Если вы здесь впервые, создадим аккаунт покупателя.'); ?></p>
            <?php if ($verified): ?><p>Подтверждён номер <?php echo esc_html(substr($verified, 0, 2) . ' ••• ••• ' . substr($verified, -4)); ?>.</p><?php endif; ?>
            <?php if (!$linking && !$tryon): ?><p class="moto-phone-auth__hint">Если этот номер уже указан в вашем аккаунте, войдём в него. При совпадении номеров используйте вход по почте.</p><?php endif; ?>
        </div>
        <form class="moto-phone-auth__form" method="post" novalidate>
            <div class="moto-phone-auth__field">
                <label for="<?php echo esc_attr($id); ?>phone">Номер телефона</label>
                <input id="<?php echo esc_attr($id); ?>phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+7 999 123-45-67" maxlength="32" required aria-describedby="<?php echo esc_attr($id); ?>phone-hint">
                <span id="<?php echo esc_attr($id); ?>phone-hint" class="moto-phone-auth__hint">Мобильный номер России</span>
            </div>
            <div class="moto-phone-auth__field" data-captcha-field hidden>
                <label for="<?php echo esc_attr($id); ?>captcha">Символы с картинки</label>
                <img data-captcha-image width="288" height="96" alt="Проверочная картинка с шестью символами">
                <button type="button" data-captcha-refresh>Другая картинка</button>
                <input id="<?php echo esc_attr($id); ?>captcha" name="captcha_answer" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="6" aria-describedby="<?php echo esc_attr($id); ?>captcha-hint">
                <span id="<?php echo esc_attr($id); ?>captcha-hint" class="moto-phone-auth__hint">6 латинских букв и цифр. Регистр не важен.</span>
            </div>
            <div class="moto-phone-auth__field" data-code-field hidden>
                <label for="<?php echo esc_attr($id); ?>code">Код из SMS</label>
                <input id="<?php echo esc_attr($id); ?>code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{4}([0-9]{2})?" aria-describedby="<?php echo esc_attr($id); ?>status">
            </div>
            <label class="moto-phone-auth__consent"><input name="consent" type="checkbox" required><span><?php echo ssa_consent_statement($context); ?></span></label>
            <button type="submit" class="button moto-phone-auth__submit" disabled>Пройти проверку</button>
            <div class="moto-phone-auth__actions" hidden><button type="button" data-resend>Отправить код ещё раз</button><button type="button" data-change>Изменить номер</button></div>
            <p id="<?php echo esc_attr($id); ?>status" class="moto-phone-auth__status" role="status" aria-live="polite" aria-atomic="true">Подготавливаем безопасный вход…</p>
            <noscript><p>Для входа по SMS включите JavaScript. Обычный вход по паролю доступен ниже.</p></noscript>
        </form>
    </section>
    <?php
}

function moto_phone_assets(): void {
    $tryon = function_exists('is_product') && is_product() && !is_user_logged_in() && function_exists('solartek_image_preview_guest_entry');
    if (!moto_phone_enabled() || !($tryon || (function_exists('is_account_page') && is_account_page()) || (function_exists('is_checkout') && is_checkout() && !is_order_received_page()))) return;
    foreach (['css', 'js'] as $extension) {
        $file = __DIR__ . '/assets/motonaklejki-phone-auth.' . $extension;
        $url = ssa_asset_url('motonaklejki-phone-auth.' . $extension);
        if ($extension === 'css') wp_enqueue_style('moto-phone-auth', $url, [], SSA_VERSION);
        else wp_enqueue_script('moto-phone-auth', $url, [], SSA_VERSION, true);
    }
}

function moto_phone_cleanup(): void {
    global $wpdb;
    $after = '';
    // Bounded batches, ordered cursor: active records cannot starve expired ones.
    for ($batch = 0; $batch < 10; $batch++) {
        $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name > %s ORDER BY option_name LIMIT 500", $wpdb->esc_like('_moto_sms_') . '%', $after));
        if (!$rows) break;
        foreach ($rows as $row) {
            $after = $row->option_name;
            $value = json_decode($row->option_value, true);
            if (is_array($value) && ($value['expires'] ?? PHP_INT_MAX) <= time()) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $row->option_name, $row->option_value));
            }
        }
        if (count($rows) < 500) break;
    }
}

add_action('wp_ajax_nopriv_moto_phone_auth', 'moto_phone_ajax');
add_action('wp_ajax_moto_phone_auth', 'moto_phone_ajax');
add_action('woocommerce_before_customer_login_form', 'moto_phone_render', 5);
add_action('woocommerce_before_checkout_form', static function () { if (!is_user_logged_in()) moto_phone_render(); }, 5);
add_action('woocommerce_account_dashboard', 'moto_phone_render', 20);
add_action('woocommerce_after_edit_account_form', 'moto_phone_render', 20);
add_action('wp_enqueue_scripts', 'moto_phone_assets', 110);
add_action('moto_phone_cleanup', 'moto_phone_cleanup');
add_action('init', static function () {
    if (moto_phone_enabled() && !wp_next_scheduled('moto_phone_cleanup')) wp_schedule_event(time() + 3600, 'hourly', 'moto_phone_cleanup');
});
