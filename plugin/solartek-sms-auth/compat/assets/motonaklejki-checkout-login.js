/* Native WooCommerce form, moved intact into the same card used on My Account. */
(() => {
    'use strict';
    const phone = document.querySelector('[data-moto-phone-auth][data-destination="checkout"]');
    const form = document.querySelector('form.woocommerce-form-login');
    if (!phone || !form || phone.dataset.checkoutLoginReady) return;
    phone.dataset.checkoutLoginReady = '1';
    const card = document.createElement('section');
    card.className = 'moto-phone-auth moto-password-auth';
    card.setAttribute('aria-labelledby', 'moto-checkout-password-title');
    const intro = document.createElement('div'); intro.className = 'moto-phone-auth__intro';
    const title = document.createElement('h2'); title.id = 'moto-checkout-password-title'; title.textContent = 'Войти по паролю';
    const description = document.createElement('p'); description.textContent = 'Введите email или логин и пароль своего аккаунта.';
    intro.append(title, description); card.append(intro, form); phone.after(card);
    form.style.removeProperty('display'); form.hidden = false;
    form.querySelector(':scope > p:not([class])')?.remove();
    form.querySelectorAll('[name=username], [name=password]').forEach(input => input.closest('p')?.classList.add('woocommerce-form-row'));
    document.querySelector('.woocommerce-form-login-toggle')?.remove();
})();
