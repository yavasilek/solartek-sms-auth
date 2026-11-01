<?php
/**
 * Plugin Name: Мотонаклейки — профиль покупателя
 * Description: Verified phone status and a small customer-name form after SMS login.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

function moto_account_profile_customer(): bool {
    return function_exists('WC') && function_exists('moto_phone_customer') && is_user_logged_in() && moto_phone_customer(wp_get_current_user());
}

function moto_account_profile_verified_phone(int $id): string {
    $value = get_user_meta($id, '_moto_verified_phone', true);
    return is_string($value) && $value !== '' && function_exists('moto_phone_normalize') && moto_phone_normalize($value) === $value ? $value : '';
}

function moto_account_profile_name(int $id): string {
    foreach (['first_name', 'billing_first_name'] as $key) {
        $value = get_user_meta($id, $key, true);
        if (is_string($value) && trim($value) !== '') return trim($value);
    }
    return '';
}

function moto_account_profile_name_value($value) {
    if (!is_string($value) || strlen($value) > 512 || !preg_match('//u', $value) || strpbrk($value, '<>') !== false) {
        return new WP_Error('invalid_name', 'Введите имя обычным текстом, до 80 символов.');
    }
    $name = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', sanitize_text_field($value)));
    if ($name === '' || preg_match_all('/./us', $name) > 80 || !preg_match('/\p{L}/u', $name)) {
        return new WP_Error('invalid_name', 'Введите имя, до 80 символов.');
    }
    return $name;
}

function moto_account_profile_save_name($value, $nonce) {
    if (!moto_account_profile_customer()) return new WP_Error('forbidden', 'Войдите в аккаунт покупателя.');
    if (!is_string($nonce) || !wp_verify_nonce($nonce, 'moto_account_name')) return new WP_Error('csrf', 'Обновите страницу и повторите сохранение.');
    $name = moto_account_profile_name_value($value);
    if (is_wp_error($name)) return $name;
    $user = wp_get_current_user();
    $oldName = get_user_meta($user->ID, 'first_name', true);
    $data = ['ID' => $user->ID, 'first_name' => $name];
    if (in_array($user->display_name, ['', 'Покупатель', $user->user_login, $oldName], true)) $data['display_name'] = $name;
    $updated = wp_update_user($data);
    if (is_wp_error($updated)) return new WP_Error('save_failed', 'Не удалось сохранить имя. Попробуйте ещё раз.');
    update_user_meta($user->ID, 'billing_first_name', $name);
    if (get_user_meta($user->ID, 'first_name', true) !== $name || get_user_meta($user->ID, 'billing_first_name', true) !== $name) {
        return new WP_Error('save_failed', 'Имя сохранилось не полностью. Повторите сохранение.');
    }
    // WooCommerce can retain an older billing name in the current checkout session.
    $customer = WC()->customer ?? null;
    if ($customer instanceof WC_Customer && (int) $customer->get_id() === (int) $user->ID) {
        try {
            $customer->set_first_name($name);
            $customer->set_billing_first_name($name);
            if (isset($data['display_name'])) $customer->set_display_name($data['display_name']);
            $customer->save();
        } catch (Exception $e) {
            return new WP_Error('save_failed', 'Имя сохранено. Обновите страницу и повторите сохранение для оформления заказа.');
        }
    }
    return true;
}

function moto_account_profile_handle_name(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['action'] ?? '') !== 'moto_account_save_name') return;
    $result = moto_account_profile_save_name(
        isset($_POST['first_name']) && is_string($_POST['first_name']) ? wp_unslash($_POST['first_name']) : null,
        $_POST['moto_account_name_nonce'] ?? null
    );
    if (is_wp_error($result) && in_array($result->get_error_code(), ['forbidden', 'csrf'], true)) {
        wp_die(esc_html($result->get_error_message()), 'Профиль покупателя', ['response' => 403]);
    }
    wc_add_notice(is_wp_error($result) ? $result->get_error_message() : 'Имя покупателя сохранено.', is_wp_error($result) ? 'error' : 'success');
    wp_safe_redirect(wc_get_page_permalink('myaccount'));
    exit;
}

function moto_account_profile_render_name(): void {
    if (!moto_account_profile_customer()) return;
    $name = moto_account_profile_name(get_current_user_id());
    $id = wp_unique_id('moto-customer-name-');
    ?>
    <details class="moto-account-profile"<?php if ($name === '') echo ' open'; ?>>
        <summary><span>Имя покупателя<?php if ($name !== '') echo ': ' . esc_html($name); ?></span><?php if ($name !== ''): ?><span class="moto-account-profile__change">Изменить</span><?php endif; ?></summary>
        <form method="post" action="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="moto-account-profile__form">
            <div class="moto-account-profile__field">
                <label for="<?php echo esc_attr($id); ?>">Как к вам обращаться?</label>
                <input id="<?php echo esc_attr($id); ?>" name="first_name" type="text" autocomplete="given-name" maxlength="80" value="<?php echo esc_attr($name); ?>" required>
            </div>
            <input type="hidden" name="action" value="moto_account_save_name">
            <?php wp_nonce_field('moto_account_name', 'moto_account_name_nonce'); ?>
            <button type="submit" class="button">Сохранить имя</button>
        </form>
    </details>
    <?php
}

function moto_account_profile_render_phone(): void {
    if (!function_exists('moto_phone_render')) return;
    $verified = moto_account_profile_customer() ? moto_account_profile_verified_phone(get_current_user_id()) : '';
    if ($verified === '') { moto_phone_render(); return; }
    $masked = substr($verified, 0, 2) . ' ••• ••• ' . substr($verified, -4);
    ?>
    <details class="moto-account-profile moto-account-phone-change">
        <summary><span>Телефон подтверждён: <?php echo esc_html($masked); ?></span><span class="moto-account-profile__change">Изменить номер</span></summary>
        <p class="moto-account-phone-change__hint">Для смены телефона введите новый номер и подтвердите его кодом из SMS.</p>
        <?php moto_phone_render(); ?>
    </details>
    <?php
}

function moto_account_profile_hooks(): void {
    remove_action('woocommerce_account_dashboard', 'moto_phone_render', 20);
    remove_action('woocommerce_after_edit_account_form', 'moto_phone_render', 20);
    add_action('woocommerce_account_dashboard', 'moto_account_profile_render_name', 10);
    add_action('woocommerce_account_dashboard', 'moto_account_profile_render_phone', 20);
    add_action('woocommerce_after_edit_account_form', 'moto_account_profile_render_phone', 20);
}

function moto_account_profile_assets(): void {
    if (!function_exists('is_account_page') || !is_account_page() || !moto_account_profile_customer()) return;
    $file = __DIR__ . '/assets/motonaklejki-account-profile.css';
    wp_enqueue_style('moto-account-profile', ssa_asset_url('motonaklejki-account-profile.css'), [], SSA_VERSION);
}

add_action('init', 'moto_account_profile_hooks', 40);
add_action('template_redirect', 'moto_account_profile_handle_name', 20);
add_action('wp_enqueue_scripts', 'moto_account_profile_assets', 120);
