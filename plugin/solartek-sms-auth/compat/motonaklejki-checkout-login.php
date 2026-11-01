<?php
/**
 * Plugin Name: Мотонаклейки — единый вход при оформлении заказа
 * Description: Presents the existing phone and native password forms as one checkout card.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

function moto_checkout_login_assets(): void {
    if (!function_exists('moto_phone_enabled') || !moto_phone_enabled()
        || !function_exists('is_checkout') || !is_checkout() || is_order_received_page() || is_user_logged_in()) return;
    $base = __DIR__ . '/assets/';
    wp_enqueue_style('moto-checkout-login', ssa_asset_url('motonaklejki-checkout-login.css'), ['moto-phone-auth'], SSA_VERSION);
    wp_enqueue_script('moto-checkout-login', ssa_asset_url('motonaklejki-checkout-login.js'), ['moto-phone-auth'], SSA_VERSION, true);
    wp_localize_script('moto-checkout-login', 'MotoCheckoutLogin', ['passwordSubmitted' => isset($_POST['login'])]);
    // Apply the same native-form presentation after its checkout wrapper exists.
    wp_enqueue_script('moto-account-login', ssa_asset_url('motonaklejki-account-login.js'), ['moto-checkout-login'], SSA_VERSION, true);
}
add_action('wp_enqueue_scripts', 'moto_checkout_login_assets', 112);
