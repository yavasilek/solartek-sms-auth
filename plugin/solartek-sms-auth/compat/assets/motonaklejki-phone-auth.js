(() => {
    'use strict';
    const initialize = root => {
        if (root.dataset.initialized) return;
        root.dataset.initialized = '1';
        const form = root.querySelector('form');
        const phone = form.elements.phone;
        const code = form.elements.code;
        const consent = form.elements.consent;
        const submit = form.querySelector('[type=submit]');
        const field = form.querySelector('[data-code-field]');
        const actions = form.querySelector('.moto-phone-auth__actions');
        const resend = form.querySelector('[data-resend]');
        const change = form.querySelector('[data-change]');
        const status = form.querySelector('[role=status]');
        const captchaField = form.querySelector('[data-captcha-field]');
        const captchaInput = form.elements.captcha_answer;
        const refresh = form.querySelector('[data-captcha-refresh]');
        const compact = root.dataset.context !== 'tryon' && root.dataset.linking !== '1';
        const captchaNote = document.createElement('p'), captchaNoteLead = document.createElement('strong');
        captchaNote.className = 'moto-phone-auth__verification-note'; captchaNote.id = phone.id + '-captcha-note';
        captchaNoteLead.textContent = 'SMS ещё не отправлено.';
        captchaNote.append(captchaNoteLead, document.createTextNode(' Введите символы с картинки и нажмите «Получить SMS».'));
        captchaField.prepend(captchaNote);
        captchaInput.setAttribute('aria-describedby', [captchaInput.getAttribute('aria-describedby'), captchaNote.id].filter(Boolean).join(' '));
        let login = null, codeControl = null, captchaControl = null;
        if (compact) {
            root.classList.add('moto-auth-compact');
            const fields = document.createElement('div'); fields.className = 'moto-auth-fields';
            const phoneField = phone.closest('.moto-phone-auth__field');
            form.insertBefore(fields, phoneField); fields.append(phoneField, field);
            const control = document.createElement('div'); control.className = 'moto-auth-code-control';
            codeControl = control;
            field.append(control); control.append(code, submit, resend);
            captchaControl = document.createElement('div'); captchaControl.className = 'moto-auth-code-control';
            captchaInput.before(captchaControl); captchaControl.append(captchaInput);
            submit.classList.add('moto-auth-request-code'); resend.classList.add('moto-auth-request-code');
            login = document.createElement('button'); login.type = 'submit';
            login.className = 'button moto-phone-auth__submit moto-auth-login'; login.textContent = 'Войти'; login.disabled = true;
            const footer = document.createElement('div'); footer.className = 'moto-auth-login-actions';
            footer.append(actions, login); form.append(footer);
            field.hidden = false; code.disabled = true; code.placeholder = 'Код из SMS';
            resend.hidden = true;
            // The controller owns the request button; mirror its busy state on login.
            const syncLogin = () => { login.disabled = submit.disabled || !['sent', 'verified'].includes(state.status); };
            new MutationObserver(syncLogin).observe(submit, {attributes: true, attributeFilter: ['disabled']});
        }
        let captcha = null, worker = null, work = '', captchaUntil = 0, repeatSend = false;
        let nonce = '', state = {}, busy = false, retryAt = 0, expiresAt = 0;
        const show = (message, error = false) => { status.textContent = message; status.dataset.error = error ? '1' : '0'; };
        const consentRow = consent.closest('.moto-phone-auth__consent');
        const consentError = document.createElement('span');
        consentError.className = 'moto-phone-auth__consent-error';
        consentError.id = phone.id + '-consent-error';
        consentError.setAttribute('role', 'alert'); consentError.hidden = true;
        consentRow.querySelector('span').append(consentError);
        const clearConsentError = () => {
            consentRow.classList.remove('is-invalid');
            consent.removeAttribute('aria-invalid');
            const descriptions = (consent.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => id && id !== consentError.id);
            if (descriptions.length) consent.setAttribute('aria-describedby', descriptions.join(' '));
            else consent.removeAttribute('aria-describedby');
            consentError.hidden = true; consentError.textContent = '';
        };
        const requireConsent = () => {
            if (consent.checked) { clearConsentError(); return true; }
            show('');
            consentRow.classList.add('is-invalid'); consent.setAttribute('aria-invalid', 'true');
            const descriptions = new Set((consent.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
            descriptions.add(consentError.id); consent.setAttribute('aria-describedby', [...descriptions].join(' '));
            consentError.hidden = false;
            const action = ['sent', 'verified'].includes(state.status) ? (root.dataset.linking === '1' ? 'подтвердить номер' : 'войти') : 'получить код';
            consentError.textContent = 'Поставьте галочку согласия, чтобы ' + action + '.';
            consent.focus(); consentRow.scrollIntoView({block: 'nearest', behavior: 'auto'});
            return false;
        };
        consent.addEventListener('change', () => { if (consent.checked) clearConsentError(); });
        const clearCaptcha = () => {
            if (worker) worker.terminate();
            worker = null; captcha = null; work = ''; captchaInput.value = ''; captchaField.hidden = true;
            submit.textContent = 'Пройти проверку';
            if (compact) codeControl.insertBefore(submit, resend);
        };
        const tick = () => {
            const seconds = Math.max(0, Math.ceil((retryAt - Date.now()) / 1000));
            resend.disabled = busy || seconds > 0;
            resend.textContent = seconds ? (compact ? `Ещё через ${seconds} с` : `Новый код через ${seconds} с`) : (compact ? 'Новый код' : 'Отправить код ещё раз');
            if (!busy && state.status === 'sent' && expiresAt <= Date.now()) {
                state.status = 'expired';
                code.disabled = true;
                submit.disabled = true;
                show('Время действия кода истекло. Запросите новый.', true);
            }
            if (state.status === 'expired') submit.disabled = true;
            if (captcha && !busy && captchaUntil <= Date.now()) {
                clearCaptcha(); submit.disabled = !nonce; submit.textContent = 'Пройти проверку';
                show('Время проверки истекло. Получите новую картинку.', true);
            }
        };
        const render = (next, focus = false) => {
            state = next && typeof next === 'object' ? next : {};
            const active = ['sent', 'verified'].includes(state.status);
            const uncertain = state.status === 'uncertain';
            if (active || (uncertain && state.retry_without_antibot)) clearCaptcha();
            if (state.phone) phone.value = state.phone;
            phone.readOnly = active || uncertain;
            field.hidden = compact ? false : !active;
            code.disabled = !active;
            code.required = active;
            code.maxLength = state.code_length === 4 ? 4 : 6;
            code.pattern = `[0-9]{${code.maxLength}}`;
            actions.hidden = !active;
            submit.textContent = captcha ? 'Получить SMS' : uncertain ? 'Проверить отправку кода' : active ? (root.dataset.linking === '1' ? 'Подтвердить номер' : 'Войти') : 'Пройти проверку';
            submit.disabled = busy || !nonce || !!(captcha && !work);
            if (compact) {
                submit.hidden = active || state.status === 'expired';
                resend.hidden = !(active || state.status === 'expired');
                login.disabled = submit.disabled || !active;
            }
            retryAt = Date.now() + (Number(state.retry_after) || 0) * 1000;
            expiresAt = Date.now() + (Number(state.expires_in) || 0) * 1000;
            tick();
            if (focus && active) code.focus();
        };
        const request = async (operation, extra = {}) => {
            const body = new URLSearchParams({action: 'moto_phone_auth', operation, nonce, ...extra});
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 22000);
            try {
                const response = await fetch(root.dataset.endpoint, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body, signal: controller.signal});
                const result = await response.json();
                if (!result || typeof result.success !== 'boolean' || !result.data) throw new Error('bad_response');
                return result;
            } finally { clearTimeout(timer); }
        };
        const prepareCaptcha = async (repeat = false, replace = false) => {
            busy = true; submit.disabled = true; refresh.disabled = true; repeatSend = repeat;
            captchaNoteLead.textContent = repeat ? 'Новое SMS ещё не отправлено.' : 'SMS ещё не отправлено.';
            form.setAttribute('aria-busy', 'true'); show('Подготавливаем проверку… SMS ещё не отправлено.');
            try {
                const result = await request('captcha', {phone:phone.value, consent:'1', refresh:replace ? '1' : '0'});
                if (!result.success) { show(result.data.message, true); return; }
                clearCaptcha(); captcha = result.data; captchaUntil = Date.now() + captcha.expires_in * 1000;
                captchaField.hidden = false; field.hidden = !compact; actions.hidden = true; state = {};
                if (compact) { captchaControl.append(submit); code.disabled = true; code.required = false; submit.hidden = false; resend.hidden = true; login.disabled = true; }
                form.querySelector('[data-captcha-image]').src = captcha.image;
                submit.textContent = 'Получить SMS'; captchaInput.focus();
                const challengeId = captcha.id;
                const readyAt = Date.now() + 900;
                worker = new Worker(root.dataset.worker);
                worker.onmessage = ({data}) => {
                    if (!captcha || data.id !== captcha.id) return;
                    worker.terminate(); worker = null;
                    if (data.error || !/^[0-9]{1,10}$/.test(data.nonce)) { clearCaptcha(); show('Не удалось подготовить проверку. Обновите картинку или войдите по паролю.', true); submit.disabled = !nonce; return; }
                    setTimeout(() => {
                        if (!captcha || captcha.id !== challengeId) return;
                        work = data.nonce; submit.disabled = busy || !nonce;
                        show('');
                    }, Math.max(0, readyAt - Date.now()));
                };
                worker.onerror = () => {
                    if (!captcha || captcha.id !== challengeId) return;
                    clearCaptcha(); submit.disabled = !nonce; show('Проверка недоступна в этом браузере. Используйте вход по паролю ниже.', true);
                };
                worker.postMessage({id:captcha.id,work:captcha.work,bits:captcha.bits,expires_in:captcha.expires_in});
                show('Подготавливаем проверку… SMS ещё не отправлено.');
            } catch (_) { clearCaptcha(); show('Не удалось получить проверку. Повторите запрос или войдите по паролю.', true); }
            finally { busy = false; refresh.disabled = false; submit.disabled = !nonce || !!(captcha && !work); form.removeAttribute('aria-busy'); }
        };
        const send = async (repeat = false) => {
            if (busy || (repeat && retryAt > Date.now())) return;
            if (!requireConsent()) return;
            if (!phone.value.trim()) { show('Укажите номер телефона.', true); phone.focus(); return; }
            if (!captcha && !(state.status === 'uncertain' && state.retry_without_antibot)) { await prepareCaptcha(repeat); return; }
            if (captcha && (!work || !/^[a-z0-9]{6}$/i.test(captchaInput.value.trim()))) { show('Введите все 6 символов с картинки и дождитесь подготовки проверки.', true); captchaInput.focus(); return; }
            busy = true; submit.disabled = true; resend.disabled = true; change.disabled = true;
            form.setAttribute('aria-busy', 'true');
            show(state.status === 'uncertain' ? 'Уточняем статус отправки…' : 'Запрашиваем SMS-код…');
            try {
                const result = await request('send', {phone: phone.value, consent: '1', resend: (repeat || repeatSend) ? '1' : '0',
                    captcha_id:captcha?.id || '', captcha_answer:captchaInput.value.trim(), captcha_work:work});
                busy = false;
                if (!result.success) {
                    if (!['captcha_wrong', 'captcha_work'].includes(result.data.code)) clearCaptcha();
                    render(result.data.state || state); show(result.data.message || 'Не удалось отправить код.', true); return;
                }
                repeatSend = false;
                code.value = ''; render(result.data, true); consent.checked = true;
                show(`Код отправлен на ${state.phone}. Введите ${code.maxLength} цифр из SMS.`);
            } catch (_) {
                busy = false;
                clearCaptcha();
                try { const recovered = await request('context'); if (recovered.success) { nonce = recovered.data.nonce; render(recovered.data.state); } } catch (_) {}
                show('Ответ об отправке не получен. Повторите запрос для этого номера, чтобы проверить результат.', true);
            } finally {
                busy = false; submit.disabled = !nonce || !!(captcha && !work); change.disabled = false; form.removeAttribute('aria-busy'); tick();
            }
        };
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy || !nonce) return;
            if (!['sent', 'verified'].includes(state.status)) { await send(); return; }
            if (!requireConsent()) return;
            if (!new RegExp(`^[0-9]{${code.maxLength}}$`).test(code.value)) { show(`Введите ${code.maxLength} цифр из SMS.`, true); code.focus(); return; }
            busy = true; submit.disabled = true; resend.disabled = true; change.disabled = true; form.setAttribute('aria-busy', 'true'); show('Проверяем код…');
            try {
                const result = await request('verify', {code: code.value, destination: root.dataset.destination || 'account',
                    sip_product: root.dataset.sipProduct || new URLSearchParams(location.search).get('sip_product') || ''});
                if (!result.success) { busy = false; render(result.data.state || state); show(result.data.message || 'Не удалось проверить код.', true); code.focus(); return; }
                const target = new URL(result.data.redirect, location.href);
                if (target.origin !== location.origin) throw new Error('unsafe_redirect');
                code.value = ''; show(root.dataset.context === 'tryon' ? 'Вы вошли. Открываем примерку…' : result.data.linked ? 'Номер подтверждён. Открываем аккаунт…' : 'Вы вошли. Открываем аккаунт…');
                if (root.dataset.context === 'tryon' && target.pathname === location.pathname && target.hash === '#sip-start') {
                    target.search = location.search;
                    history.replaceState(history.state, '', target.href);
                    location.reload();
                } else location.assign(target.href);
            } catch (_) { show('Ответ о входе не получен. Обновите страницу, чтобы проверить состояние аккаунта.', true); }
            finally { busy = false; submit.disabled = !nonce; change.disabled = false; form.removeAttribute('aria-busy'); tick(); }
        });
        resend.addEventListener('click', () => send(true));
        change.addEventListener('click', () => { if (busy) return; clearCaptcha(); repeatSend = false; code.value = ''; render({}); show('Укажите номер для нового кода.'); phone.focus(); });
        refresh.addEventListener('click', () => { if (!busy && consent.checked) prepareCaptcha(repeatSend, true); });
        phone.addEventListener('input', () => { if (!busy && captcha) { clearCaptcha(); render({}); } });
        window.addEventListener('pagehide', clearCaptcha);
        code.addEventListener('input', () => { code.value = code.value.replace(/[^0-9]/g, '').slice(0, code.maxLength); });
        setInterval(tick, 1000);
        let loadingContext = false;
        const loadContext = async () => {
            if (loadingContext) return;
            loadingContext = true;
            try {
                const result = await request('context');
                if (!result.success) throw new Error('context');
                nonce = result.data.nonce; render(result.data.state);
                if (state.phone) consent.checked = true;
                show(state.status === 'uncertain' ? 'Статус отправки неизвестен. Повторите запрос для этого номера.' : state.status === 'sent' ? `Введите код из SMS, отправленного на ${state.phone}.` : '');
                root.dispatchEvent(new CustomEvent('moto:phone-auth-ready', {bubbles: true, detail: {passwordNonce: result.data.passwordNonce}}));
            } catch (_) {
                show('Не удалось подготовить вход. Повторите попытку.', true);
                root.dispatchEvent(new Event('moto:phone-auth-error', {bubbles: true}));
            } finally { loadingContext = false; }
        };
        root.addEventListener('moto:phone-auth-refresh', loadContext);
        loadContext();
    };
    document.querySelectorAll('[data-moto-phone-auth]').forEach(root => { if (root.dataset.lazy !== '1') initialize(root); });
    document.addEventListener('moto:phone-auth-open', event => {
        if (event.target.matches('[data-moto-phone-auth]')) initialize(event.target);
    });
})();
