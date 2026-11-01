<?php
defined('ABSPATH') || exit;

function moto_merge_handle(): void {
    if (!ssa_config('enabled') || !ssa_config('merge')) return;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !in_array($_POST['action'] ?? '', ['moto_merge_prepare','moto_merge_prepare_candidate','moto_merge_commit','moto_merge_cancel'], true)) return;
    nocache_headers();
    if (!moto_phone_same_origin() || !is_user_logged_in() || !is_string($_POST['moto_merge_nonce'] ?? null) || !wp_verify_nonce($_POST['moto_merge_nonce'], 'moto_account_merge')) {
        wp_die('Обновите страницу кабинета и повторите действие.', 'Объединение аккаунтов', ['response' => 403]);
    }
    $committed = false;
    try {
        $preparing = in_array($_POST['action'], ['moto_merge_prepare','moto_merge_prepare_candidate'], true);
        if ($_POST['action'] === 'moto_merge_prepare_candidate') {
            $result = moto_merge_prepare_candidate($_POST['merge_candidate'] ?? null, is_string($_POST['merge_password'] ?? null) ? wp_unslash($_POST['merge_password']) : null);
        } elseif ($_POST['action'] === 'moto_merge_prepare') {
            $result = moto_merge_prepare(is_string($_POST['merge_login'] ?? null) ? wp_unslash($_POST['merge_login']) : null, is_string($_POST['merge_password'] ?? null) ? wp_unslash($_POST['merge_password']) : null);
        } elseif ($_POST['action'] === 'moto_merge_cancel') {
            moto_phone_store(moto_merge_pending_key(), ['expires' => time() - 1]);
            $result = true;
        } else {
            $result = ($_POST['merge_consent'] ?? '') === '1' ? moto_merge_commit($_POST['merge_ticket'] ?? null) : moto_phone_error('merge_consent', 'Подтвердите объединение аккаунтов.');
            if (!is_wp_error($result)) {
                $committed = true;
                wc_set_customer_auth_cookie($result['target']);
                $target = get_user_by('id', $result['target']);
                do_action('wp_login', $target->user_login, $target);
            }
        }
        wc_add_notice(is_wp_error($result) ? $result->get_error_message() : ($_POST['action'] === 'moto_merge_commit' ? 'Аккаунты объединены. Теперь вход по телефону и почте ведёт в один кабинет с историей заказов.' : ($preparing ? 'Старый аккаунт подтверждён. Проверьте условия объединения.' : 'Объединение отменено.')), is_wp_error($result) ? 'error' : 'success');
    } catch (Throwable $error) { wc_add_notice($committed ? 'Аккаунты объединены. Для обновления входа войдите заново по телефону или почте основного аккаунта.' : 'Не удалось завершить проверку. Повторите позже или обратитесь в поддержку.', 'error'); }
    wp_safe_redirect(wc_get_page_permalink('myaccount'));
    exit;
}

function moto_merge_render(): void {
    if (!ssa_config('enabled') || !ssa_config('merge')) return;
    if (!moto_phone_customer(wp_get_current_user()) || wp_get_current_user()->user_email !== '' || !preg_match('/^moto_[a-f0-9]{32}$/D', wp_get_current_user()->user_login)) return;
    $phone = get_user_meta(get_current_user_id(), '_moto_verified_phone', true);
    if (!is_string($phone) || $phone === '' || moto_phone_normalize($phone) !== $phone) return;
    $pending = moto_phone_state(moto_merge_pending_key());
    $active = isset($pending['ticket'],$pending['plan']) && ($pending['binding'] ?? '') === moto_phone_binding();
    $candidates = $active ? [] : moto_merge_candidates();
    $hasCandidates = !is_wp_error($candidates) && count($candidates) > 0;
    $id = wp_unique_id('moto-merge-');
    ?>
    <details class="moto-account-profile moto-account-merge"<?php if ($active || $hasCandidates) echo ' open'; ?>>
        <summary><span>Объединить с аккаунтом по почте</span></summary>
        <?php if ($active): $target = get_user_by('id', (int)$pending['plan']['target']); ?>
            <p>Подтверждён аккаунт <strong><?php echo esc_html($target ? $target->user_email : ''); ?></strong>. Сохраним его почту, пароль и историю. Из текущего телефонного аккаунта перенесём заказов: <?php echo esc_html((string)count($pending['plan']['orders'])); ?>. Вход по вашему телефону будет вести в общий кабинет.</p>
            <form method="post" action="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>">
                <input type="hidden" name="action" value="moto_merge_commit">
                <input type="hidden" name="merge_ticket" value="<?php echo esc_attr($pending['ticket']); ?>">
                <?php wp_nonce_field('moto_account_merge','moto_merge_nonce'); ?>
                <label class="moto-account-merge__consent"><input type="checkbox" name="merge_consent" value="1" required><span>Подтверждаю объединение моих аккаунтов и перенос заказов.</span></label>
                <button class="moto-button" type="submit">Объединить аккаунты</button>
            </form>
            <form method="post" action="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="moto-account-merge__cancel">
                <input type="hidden" name="action" value="moto_merge_cancel">
                <?php wp_nonce_field('moto_account_merge','moto_merge_nonce'); ?>
                <button type="submit">Отменить</button>
            </form>
        <?php else: ?>
            <?php if ($hasCandidates): ?>
                <p>По вашему подтверждённому номеру нашли старый аккаунт. Узнаёте почту? Подтвердите пароль от этого аккаунта — затем сможете объединить историю заказов. Полный адрес покажем после проверки.</p>
                <form method="post" action="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="moto-account-merge__credentials moto-account-merge__candidate">
                    <input type="hidden" name="action" value="moto_merge_prepare_candidate">
                    <?php wp_nonce_field('moto_account_merge','moto_merge_nonce'); ?>
                    <div><label for="<?php echo esc_attr($id); ?>candidate">Аккаунт с вашим номером</label><select id="<?php echo esc_attr($id); ?>candidate" name="merge_candidate" required><?php foreach ($candidates as $candidate): ?><option value="<?php echo esc_attr(moto_merge_candidate_token(wp_get_current_user(), $candidate, time() + 300)); ?>"><?php echo esc_html(moto_merge_mask_email($candidate->user_email)); ?></option><?php endforeach; ?></select></div>
                    <div><label for="<?php echo esc_attr($id); ?>candidate-password">Пароль старого аккаунта</label><input id="<?php echo esc_attr($id); ?>candidate-password" name="merge_password" type="password" autocomplete="current-password" maxlength="4096" required></div>
                    <button class="moto-button" type="submit">Проверить аккаунт</button>
                </form>
                <details class="moto-account-merge__other"><summary>Указать другую почту или логин</summary><?php moto_merge_render_manual($id); ?></details>
            <?php else: ?>
                <p><?php echo esc_html(is_wp_error($candidates) ? $candidates->get_error_message() : 'Старый аккаунт с этим номером не найден. Если раньше покупали с другой почтой, укажите её и пароль.'); ?></p>
                <?php moto_merge_render_manual($id); ?>
            <?php endif; ?>
            <p class="moto-account-merge__hint"><a href="<?php echo esc_url(wc_lostpassword_url()); ?>">Не помню пароль — восстановить доступ</a></p>
            <p class="moto-account-merge__hint">Если давно вошли по телефону, перед объединением понадобится выйти и войти заново. Это защищает историю заказов.</p>
        <?php endif; ?>
    </details>
    <?php
}

function moto_merge_render_manual(string $id): void {
    ?>
    <form method="post" action="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="moto-account-merge__credentials moto-account-merge__manual">
        <input type="hidden" name="action" value="moto_merge_prepare">
        <?php wp_nonce_field('moto_account_merge','moto_merge_nonce'); ?>
        <div><label for="<?php echo esc_attr($id); ?>login">Почта или логин старого аккаунта</label><input id="<?php echo esc_attr($id); ?>login" name="merge_login" type="text" autocomplete="username" maxlength="254" required></div>
        <div><label for="<?php echo esc_attr($id); ?>password">Пароль старого аккаунта</label><input id="<?php echo esc_attr($id); ?>password" name="merge_password" type="password" autocomplete="current-password" maxlength="4096" required></div>
        <button class="moto-button" type="submit">Проверить аккаунт</button>
    </form>
    <?php
}

function moto_merge_assets(): void {
    if (!function_exists('is_account_page') || !is_account_page() || !is_user_logged_in()) return;
    $file = dirname(__DIR__,2) . '/assets/motonaklejki-account-merge.css';
    wp_enqueue_style('moto-account-merge', ssa_asset_url('motonaklejki-account-merge.css'), [], SSA_VERSION);
}

add_action('template_redirect','moto_merge_handle',25);
add_action('woocommerce_account_dashboard','moto_merge_render',30);
add_action('wp_enqueue_scripts','moto_merge_assets',125);
