<?php
/**
 * Plugin Name: Мотонаклейки — согласие в формах входа и заказа
 * Description: Explicit consent in native WooCommerce login and checkout forms.
 * Version: 1.0.0
 */
defined('ABSPATH') || exit;

function moto_privacy_consent_field(string $name, string $id): void {
    $privacy = ssa_privacy_url();
    if (!$privacy) $privacy = home_url('/privacy-policy/');
    ?>
    <p class="form-row form-row-wide moto-privacy-consent-row">
        <label class="moto-privacy-consent" for="<?php echo esc_attr($id); ?>">
            <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="1" required>
            <span><?php echo ssa_consent_statement($name === 'moto_checkout_personal_consent' ? 'checkout' : 'account'); ?></span>
        </label>
    </p>
    <?php
}

function moto_privacy_login_field(): void {
    moto_privacy_consent_field('sip_personal_consent', wp_unique_id('moto-login-consent-'));
}
add_action('woocommerce_login_form', 'moto_privacy_login_field', 25);

function moto_privacy_login_validate($errors) {
    if (($_POST['sip_personal_consent'] ?? '') !== '1' && !$errors->get_error_code()) {
        return new WP_Error('moto_personal_consent', 'Подтвердите согласие на обработку персональных данных.');
    }
    return $errors;
}
add_filter('woocommerce_process_login_errors', 'moto_privacy_login_validate', 25);

function moto_privacy_checkout_field(): void {
    if (!apply_filters('ssa_checkout_consent_required', true)) {
        echo '<p class="form-row form-row-wide">Данные используются для оформления и исполнения заказа. <a href="' . esc_url(ssa_privacy_url()) . '" target="_blank" rel="noopener">Политика обработки персональных данных</a>.</p>';
        return;
    }
    moto_privacy_consent_field('moto_checkout_personal_consent', 'moto-checkout-consent');
}
add_action('woocommerce_review_order_before_submit', 'moto_privacy_checkout_field', 15);

function moto_privacy_checkout_validate($data, $errors): void {
    if (!apply_filters('ssa_checkout_consent_required', true)) return;
    if (($_POST['moto_checkout_personal_consent'] ?? '') !== '1') {
        $errors->add('moto_checkout_personal_consent', 'Подтвердите согласие на обработку персональных данных перед оформлением заказа.');
    }
}
add_action('woocommerce_after_checkout_validation', 'moto_privacy_checkout_validate', 20, 2);

function moto_privacy_consent_assets(): void {
    $account = function_exists('is_account_page') && is_account_page();
    $checkout = function_exists('is_checkout') && is_checkout() && !is_order_received_page();
    if (!$account && !$checkout) return;
    $base = __DIR__ . '/assets/';
    wp_enqueue_style('moto-privacy-consent', ssa_asset_url('motonaklejki-privacy-consent.css'), [], SSA_VERSION);
    if ($checkout) wp_enqueue_script('moto-privacy-consent', ssa_asset_url('motonaklejki-privacy-consent.js'), ['jquery'], SSA_VERSION, true);
}
add_action('wp_enqueue_scripts', 'moto_privacy_consent_assets', 120);
