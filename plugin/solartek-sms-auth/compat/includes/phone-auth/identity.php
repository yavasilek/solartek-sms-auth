<?php
defined('ABSPATH') || exit;

/** Look up a contact only after the existing server-side SMS proof succeeded. */
function moto_phone_legacy_owner(string $phone) {
    global $wpdb;
    if (moto_phone_normalize($phone) !== $phone || $phone === '') return moto_phone_error('invalid_phone', 'Проверьте номер телефона.');
    // Shipping contacts can belong to a gift recipient. Only the buyer's billing
    // contact is eligible; normalization stays identical to the OTP entry point.
    $rows = $wpdb->get_results("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'billing_phone' AND meta_value <> '' ORDER BY user_id LIMIT 10001");
    if ($wpdb->last_error || count($rows) > 10000) return moto_phone_error('lookup_unavailable', 'Не удалось проверить аккаунт. Войдите по почте.', 503);
    $matches = [];
    foreach ($rows as $row) {
        $id = (int) $row->user_id;
        if (moto_phone_normalize($row->meta_value) !== $phone || get_user_meta($id, '_moto_merged_into', true) !== '') continue;
        $matches[$id] = true;
        if (count($matches) > 1) return moto_phone_error('account_conflict', 'Этот номер указан в нескольких аккаунтах. Войдите по почте или обратитесь в поддержку — новый аккаунт не создан.', 409);
    }
    if (!$matches) return null;
    $user = get_user_by('id', (int) array_key_first($matches));
    if (!moto_phone_login_allowed($user)) return moto_phone_error('password_required', 'Для этого аккаунта используйте обычный вход по паролю.', 403);
    $verified = get_user_meta($user->ID, '_moto_verified_phone', true);
    if (($verified !== '' && $verified !== $phone) || moto_phone_normalize(get_user_meta($user->ID, 'billing_phone', true)) !== $phone) {
        return moto_phone_error('account_conflict', 'Номер требует проверки. Войдите по почте или обратитесь в поддержку.', 409);
    }
    return $user;
}

/** Hints only: a matching billing contact never replaces old-account proof. */
function moto_merge_candidates() {
    // Recognizing a masked contact is read-only; preparing/committing a merge
    // still uses the default recent-login requirement in moto_merge_source().
    $source = moto_merge_source(false);
    if (is_wp_error($source)) return $source;
    global $wpdb;
    $phone = get_user_meta($source->ID, '_moto_verified_phone', true);
    $rows = $wpdb->get_results("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'billing_phone' AND meta_value <> '' ORDER BY user_id LIMIT 10001");
    if ($wpdb->last_error || count($rows) > 10000) return moto_phone_error('merge_lookup', 'Не удалось найти старый аккаунт. Повторите позже или укажите почту самостоятельно.', 503);
    $candidates = [];
    foreach ($rows as $row) {
        $id = (int)$row->user_id;
        if ($id === $source->ID || isset($candidates[$id]) || moto_phone_normalize($row->meta_value) !== $phone) continue;
        $user = get_user_by('id', $id);
        if (!moto_phone_customer($user) || !is_email($user->user_email) || get_user_meta($id, '_moto_verified_phone', true) !== '' || moto_phone_normalize(get_user_meta($id, 'billing_phone', true)) !== $phone) continue;
        $candidates[$id] = $user;
        if (count($candidates) > 5) return moto_phone_error('merge_lookup', 'По номеру найдено несколько записей. Выбрать нужный аккаунт поможет поддержка.', 409);
    }
    return array_values($candidates);
}

function moto_merge_mask_email(string $email): string {
    if (!is_email($email)) return '';
    [$local, $domain] = explode('@', $email, 2);
    $hint = strlen($local) > 4 ? substr($local, 0, 2) . '***' . substr($local, -1) : substr($local, 0, 1) . '***';
    return $hint . '@' . $domain;
}

function moto_merge_candidate_token(WP_User $source, WP_User $target, int $expires): string {
    $proof = [$source->ID, moto_phone_binding(), get_user_meta($source->ID, '_moto_verified_phone', true), moto_merge_user_fingerprint($target), $expires];
    return $expires . '.' . moto_phone_hash('merge-candidate:' . wp_json_encode($proof));
}

function moto_merge_prepare_candidate($token, $password) {
    $source = moto_merge_source();
    if (is_wp_error($source)) return $source;
    $error = static fn() => moto_phone_error('merge_candidate', 'Подсказка аккаунта устарела. Обновите страницу и выберите аккаунт заново.', 409);
    if (!is_string($token) || !preg_match('/^([0-9]{10})\.[a-f0-9]{64}$/D', $token, $match) || (int)$match[1] <= time() || (int)$match[1] > time() + 300) return $error();
    $candidates = moto_merge_candidates();
    if (is_wp_error($candidates)) return $candidates;
    foreach ($candidates as $target) {
        if (hash_equals(moto_merge_candidate_token($source, $target, (int)$match[1]), $token)) return moto_merge_prepare($target->user_email, $password);
    }
    return $error();
}
