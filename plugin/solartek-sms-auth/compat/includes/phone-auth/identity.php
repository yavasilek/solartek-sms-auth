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
    if (!moto_phone_customer($user)) return moto_phone_error('password_required', 'Для этого аккаунта используйте обычный вход по паролю.', 403);
    $verified = get_user_meta($user->ID, '_moto_verified_phone', true);
    if (($verified !== '' && $verified !== $phone) || moto_phone_normalize(get_user_meta($user->ID, 'billing_phone', true)) !== $phone) {
        return moto_phone_error('account_conflict', 'Номер требует проверки. Войдите по почте или обратитесь в поддержку.', 409);
    }
    return $user;
}
