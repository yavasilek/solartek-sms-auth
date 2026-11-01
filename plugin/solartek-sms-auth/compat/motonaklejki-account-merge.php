<?php
/**
 * Plugin Name: Мотонаклейки — существующие аккаунты и объединение
 * Description: Reuses unambiguous buyer contacts after OTP and joins proven customer accounts.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;
require_once __DIR__ . '/includes/phone-auth/identity.php';
require_once __DIR__ . '/includes/phone-auth/merge.php';
require_once __DIR__ . '/includes/phone-auth/merge-ui.php';
if (function_exists('add_filter')) add_filter('authenticate', 'moto_merge_auth_guard', PHP_INT_MAX);
if (function_exists('add_filter')) add_filter('determine_current_user', 'moto_merge_cookie_guard', PHP_INT_MAX);
add_action('woocommerce_before_checkout_process', 'moto_merge_order_write_guard', 1, 0);
add_action('woocommerce_before_pay_action', 'moto_merge_pay_guard', 1);
add_action('woocommerce_store_api_checkout_order_processed', 'moto_merge_order_write_guard', 1);
add_action('woocommerce_before_order_object_save', 'moto_merge_order_write_guard', 1);
