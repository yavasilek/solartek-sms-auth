<?php
/**
 * Plugin Name: Мотонаклейки — оформление обычного входа
 * Description: Matches the native WooCommerce password form to the phone login.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

// Keep the native form, nonce, actions and authentication entirely in WooCommerce.
// Registration has its own two-column template; leave that layout to WooCommerce.
function moto_account_login_open(): void {
    if (!function_exists('moto_phone_enabled') || !moto_phone_enabled() || !is_account_page()
        || is_user_logged_in() || get_option('woocommerce_enable_myaccount_registration') === 'yes'
        || !empty($GLOBALS['moto_account_login_open'])) return;
    $GLOBALS['moto_account_login_open'] = true;
    $id = wp_unique_id('moto-password-');
    ?>
    <section class="moto-phone-auth moto-password-auth" aria-labelledby="<?php echo esc_attr($id); ?>title">
        <div class="moto-phone-auth__intro">
            <h2 id="<?php echo esc_attr($id); ?>title">Войти по паролю</h2>
            <p>Введите email или логин и пароль своего аккаунта.</p>
        </div>
    <?php
}

function moto_account_login_close(): void {
    if (empty($GLOBALS['moto_account_login_open'])) return;
    $GLOBALS['moto_account_login_open'] = false;
    echo '</section>';
}

function moto_account_login_assets(): void {
    if (!function_exists('moto_phone_enabled') || !moto_phone_enabled() || !is_account_page() || is_user_logged_in()) return;
    $file = __DIR__ . '/assets/motonaklejki-account-login.js';
    wp_enqueue_script('moto-account-login', ssa_asset_url('motonaklejki-account-login.js'), [], SSA_VERSION, true);
}

add_action('woocommerce_before_customer_login_form', 'moto_account_login_open', 20);
add_action('woocommerce_after_customer_login_form', 'moto_account_login_close', 0);
add_action('wp_enqueue_scripts', 'moto_account_login_assets', 111);
