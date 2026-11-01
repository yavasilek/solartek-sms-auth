<?php
/**
 * Plugin Name: Мотонаклейки — существующие аккаунты и объединение
 * Description: Reuses unambiguous buyer contacts after OTP and joins proven customer accounts.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/phone-auth/identity.php';
require_once __DIR__ . '/includes/phone-auth/merge-cart.php';
require_once __DIR__ . '/includes/phone-auth/merge-preview.php';
require_once __DIR__ . '/includes/phone-auth/merge.php';
require_once __DIR__ . '/includes/phone-auth/merge-ui.php';
if (function_exists('add_filter')) add_filter('authenticate', 'moto_merge_auth_guard', PHP_INT_MAX);
if (function_exists('add_filter')) add_filter('determine_current_user', 'moto_merge_cookie_guard', PHP_INT_MAX);
add_action('woocommerce_before_checkout_process', 'moto_merge_order_write_guard', 1, 0);
add_action('woocommerce_before_pay_action', 'moto_merge_pay_guard', 1);
add_action('woocommerce_store_api_checkout_order_processed', 'moto_merge_order_write_guard', 1);
add_action('woocommerce_before_order_object_save', 'moto_merge_order_write_guard', 1);
if (function_exists('add_filter')) add_filter('woocommerce_session_handler','moto_merge_session_handler',50);
foreach (['woocommerce_cart_loaded_from_session','woocommerce_cart_updated','woocommerce_before_calculate_totals','woocommerce_add_to_cart','woocommerce_cart_item_removed','woocommerce_cart_item_restored','woocommerce_cart_item_set_quantity','woocommerce_removed_coupon','woocommerce_cart_emptied'] as $hook) add_action($hook,'moto_merge_cart_hook',0,0);
