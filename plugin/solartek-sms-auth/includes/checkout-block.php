<?php
defined('ABSPATH') || exit;

// Modern WooCommerce Checkout blocks do not emit the classic checkout hook.
// Render auth as a sibling before the block, preserving the block's own form.
add_filter('render_block_woocommerce/checkout',static function(string $content): string {
    static $rendered=false;
    if($rendered || is_user_logged_in() || !moto_phone_enabled() || !ssa_config('checkout')
        || !function_exists('is_checkout') || !is_checkout() || !function_exists('woocommerce_login_form'))return $content;
    $rendered=true;ob_start();moto_phone_render();
    woocommerce_login_form(['message'=>'','redirect'=>wc_get_checkout_url(),'hidden'=>false]);
    return (string)ob_get_clean().$content;
});
