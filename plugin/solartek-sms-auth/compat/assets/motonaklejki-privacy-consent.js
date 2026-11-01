/* Keep the user's current choice when WooCommerce replaces its payment fragment. */
(() => {
    'use strict';
    const selector = '[name="moto_checkout_personal_consent"]';
    let accepted = null;
    document.addEventListener('change', event => {
        if (event.target.matches(selector)) accepted = event.target.checked;
    });
    if (window.jQuery) window.jQuery(document.body).on('updated_checkout', () => {
        if (accepted !== null) document.querySelectorAll(selector).forEach(field => { field.checked = accepted; });
    });
})();
