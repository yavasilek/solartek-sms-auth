<?php
/**
 * Plugin Name: Solartek SMS Auth
 * Description: Вход по SMS для WooCommerce: мастер подключения, CAPTCHA и обновления из общего репозитория.
 * Version: 1.0.18
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: Solartek
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/yavasilek/solartek-sms-auth
 * Text Domain: solartek-sms-auth
 */
defined('ABSPATH') || exit;
if (defined('SSA_VERSION')) return;
if (function_exists('moto_phone_key')) {
    add_action('admin_notices',static function(){if(current_user_can('manage_options'))echo '<div class="notice notice-warning"><p>Solartek SMS Auth: обнаружена прежняя MU-интеграция SMS. Завершите её миграцию по инструкции плагина; пока продолжает работать прежняя версия.</p></div>';});
    return;
}
define('SSA_VERSION', '1.0.18');
define('SSA_FILE', __FILE__);
define('SSA_DIR', __DIR__ . '/');
define('SSA_BASENAME', 'solartek-sms-auth/solartek-sms-auth.php');
require_once SSA_DIR . 'includes/settings.php';
require_once SSA_DIR . 'includes/updater.php';
require_once SSA_DIR . 'includes/admin.php';
require_once SSA_DIR . 'includes/admin-users.php';
// Retain compatibility functions used by existing storefront integrations.
require_once SSA_DIR . 'compat/motonaklejki-phone-auth.php';
require_once SSA_DIR . 'compat/motonaklejki-account-merge.php';
if (ssa_config('enabled')) {
    require_once SSA_DIR . 'includes/checkout-block.php';
    foreach (['account-login', 'checkout-login', 'privacy-consent'] as $module) require_once SSA_DIR . 'compat/motonaklejki-' . $module . '.php';
    if (ssa_config('profile')) require_once SSA_DIR . 'compat/motonaklejki-account-profile.php';
}
register_activation_hook(__FILE__, static function () {
    if (PHP_VERSION_ID < 80100) wp_die('Solartek SMS Auth требует PHP 8.1 или новее.');
    add_option('ssa_setup_redirect', true, '', false);
});
register_deactivation_hook(__FILE__, static function () { wp_clear_scheduled_hook('moto_phone_cleanup'); wp_clear_scheduled_hook('ssa_check_updates'); });

