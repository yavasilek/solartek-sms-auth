/* Presentation only: preserve the original WooCommerce controls and submission. */
(() => {
    'use strict';
    document.querySelectorAll('.moto-password-auth form.woocommerce-form-login').forEach(form => {
        if (form.dataset.compactReady) return;
        const submit = form.querySelector('.woocommerce-form-login__submit');
        const row = submit?.closest('p.form-row');
        const recovery = form.querySelector('.lost_password');
        const link = recovery?.querySelector('a');
        const remember = form.querySelector('.woocommerce-form-login__rememberme');
        if (!row || !link || !remember) return;
        form.dataset.compactReady = '1';
        submit.textContent = 'Войти';
        form.closest('.moto-password-auth').classList.add('moto-auth-compact');
        const fields = document.createElement('div'); fields.className = 'moto-auth-fields';
        const inputs = [...form.querySelectorAll('[name=username], [name=password]')];
        form.insertBefore(fields, inputs[0].closest('p'));
        inputs.forEach(input => { const row = input.closest('p'); row.classList.add('woocommerce-form-row'); fields.append(row); });
        const label = form.querySelector('label[for="username"]');
        if (label) {
            [...label.childNodes].filter(node => node.nodeType === Node.TEXT_NODE).forEach(node => node.remove());
            label.prepend(document.createTextNode('Email или логин '));
        }
        const options = document.createElement('div'); options.className = 'moto-auth-options';
        link.classList.add('moto-login-recovery'); options.append(remember, link);
        const actions = document.createElement('div'); actions.className = 'moto-auth-login-actions';
        actions.append(options, submit); form.append(actions);
        // Checkout uses in-field prompts while retaining the native accessible labels and controls.
        if (form.closest('.woocommerce-checkout')) {
            inputs.forEach(input => {
                const fieldLabel = input.labels?.[0];
                if (!fieldLabel) return;
                const promptLabel = fieldLabel.cloneNode(true);
                promptLabel.querySelectorAll('.screen-reader-text, .sr-only, [hidden]').forEach(note => note.remove());
                input.placeholder = promptLabel.textContent.replace(/\s+/g, ' ').trim();
                input.closest('p').classList.add('moto-auth-infield');
            });
            const password = inputs.find(input => input.name === 'password');
            const passwordRow = password?.closest('.moto-auth-infield');
            if (passwordRow) {
                passwordRow.append(link);
                link.textContent = 'Забыли пароль?';
            }
        }
        row.querySelectorAll('input[type=hidden]').forEach(input => form.append(input));
        if (!row.textContent.trim() && !row.children.length) row.remove();
        if (!recovery.textContent.trim() && !recovery.children.length) recovery.remove();
    });
})();
