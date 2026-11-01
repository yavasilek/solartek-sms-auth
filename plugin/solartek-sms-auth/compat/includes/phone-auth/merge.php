<?php
defined('ABSPATH') || exit;

function moto_account_is_merged(int $id): bool {
    if ($id < 1) return false;
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = '_moto_merged_into' LIMIT 1", $id));
    if ($wpdb->last_error) throw new RuntimeException('Account state unavailable');
    return $value !== null && $value !== '';
}

function moto_merge_source() {
    $user = wp_get_current_user();
    if (!moto_phone_customer($user) || $user->user_email !== '' || !preg_match('/^moto_[a-f0-9]{32}$/D', $user->user_login)) return moto_phone_error('merge_forbidden', 'Объединение доступно из телефонного аккаунта покупателя.', 403);
    $phone = get_user_meta($user->ID, '_moto_verified_phone', true);
    if (!is_string($phone) || $phone === '' || moto_phone_normalize($phone) !== $phone) return moto_phone_error('merge_forbidden', 'Сначала войдите по подтверждённому телефону.', 403);
    $token = wp_get_session_token();
    $session = $token !== '' ? WP_Session_Tokens::get_instance($user->ID)->get($token) : null;
    if (!is_array($session) || (int)($session['login'] ?? 0) < time() - 900 || (int)($session['login'] ?? 0) > time() + 60) return moto_phone_error('merge_reauth', 'Для безопасного объединения выйдите и заново войдите по телефону, затем вернитесь к этой форме.', 403);
    return $user;
}

function moto_merge_rate(string $login) {
    $lock = moto_phone_lock('merge-rate');
    if (!$lock) return moto_phone_error('busy', 'Повторите через несколько секунд.', 409);
    try {
        $now = time(); $updates = [];
        foreach ([['user:' . get_current_user_id(), 900, 5], ['login:' . strtolower($login), 900, 5], ['ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 3600, 15]] as [$identity, $window, $limit]) {
            $key = 'merge-rate:' . $identity . ':' . (int)floor($now / $window);
            $state = moto_phone_state($key);
            if (($state['count'] ?? 0) >= $limit) return moto_phone_error('merge_rate', 'Слишком много попыток. Повторите позже.', 429);
            $updates[$key] = ['count' => ($state['count'] ?? 0) + 1, 'expires' => ((int)floor($now / $window) + 1) * $window];
        }
        foreach ($updates as $key => $state) moto_phone_store($key, $state);
    } finally { moto_phone_unlock('merge-rate', $lock); }
    return true;
}

function moto_merge_target($login, $password, int $source) {
    if (!is_string($login) || strlen($login) > 254 || trim($login) === '' || !is_string($password) || $password === '' || strlen($password) > 4096) return moto_phone_error('merge_credentials', 'Проверьте почту и пароль старого аккаунта.', 403);
    $rate = moto_merge_rate(trim($login));
    if (is_wp_error($rate)) return $rate;
    // Native authentication retains login restrictions and second-factor plugins.
    $target = wp_authenticate(trim($login), $password);
    if (is_wp_error($target) || !moto_phone_customer($target) || $target->ID === $source || !is_email($target->user_email)) return moto_phone_error('merge_credentials', 'Не удалось подтвердить старый аккаунт покупателя. Проверьте почту и пароль.', 403);
    return $target;
}

function moto_merge_storage_ready(): bool {
    global $wpdb;
    $tables = [$wpdb->users, $wpdb->usermeta, $wpdb->options, $wpdb->posts, $wpdb->postmeta];
    foreach (['wc_orders','wc_order_addresses','wc_order_operational_data','wc_orders_meta','woocommerce_downloadable_product_permissions'] as $name) $tables[] = $wpdb->prefix . $name;
    $placeholders = implode(',', array_fill(0, count($tables), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)", ...$tables));
    if ($wpdb->last_error || count($rows) < 5) return false;
    foreach ($rows as $row) if (strtoupper($row->ENGINE) !== 'INNODB') return false;
    return true;
}

function moto_merge_private_busy(int $id, ?string $root): bool {
    if ($root === null) return false;
    $files = glob($root . '/job-*.json');
    if ($files === false || count($files) > 5000) return true;
    foreach ($files as $file) {
        $job = solartek_image_preview_json_read($file);
        if ((int)($job['owner_id'] ?? 0) === $id) return true;
    }
    $studio = solartek_image_preview_json_read($root . '/studio-user-' . $id . '.json');
    return !empty($studio['vehicles']) || !empty($studio['board']) || !empty($studio['favorite_job_id']);
}

function moto_merge_plan(WP_User $source, WP_User $target, ?string $root) {
    global $wpdb;
    if (!moto_merge_storage_ready()) return moto_phone_error('merge_storage', 'Объединение временно недоступно. Обратитесь в поддержку.', 503);
    $phone = get_user_meta($source->ID, '_moto_verified_phone', true);
    $owners = get_users(['meta_key' => '_moto_verified_phone', 'meta_value' => $phone, 'number' => 2, 'count_total' => false]);
    $otherPhone = get_user_meta($target->ID, '_moto_verified_phone', true);
    if (count($owners) !== 1 || (int)$owners[0]->ID !== $source->ID || ($otherPhone !== '' && $otherPhone !== $phone)) return moto_phone_error('merge_phone_conflict', 'У аккаунтов разные подтверждённые номера. Нужна проверка поддержки.', 409);
    $persistentCart = get_user_meta($source->ID, '_woocommerce_persistent_cart_' . get_current_blog_id(), true);
    if ((is_array($persistentCart) && !empty($persistentCart['cart'])) || (isset(WC()->cart) && !WC()->cart->is_empty()) || !empty(get_user_meta($source->ID, '_application_passwords', true)) || (class_exists('WC_Payment_Tokens') && WC_Payment_Tokens::get_customer_tokens($source->ID)) || moto_merge_private_busy($source->ID, $root)) return moto_phone_error('merge_manual', 'В телефонном аккаунте есть корзина, примерки или дополнительные способы доступа. Для сохранения всех данных обратитесь в поддержку — аккаунты не изменены.', 409);
    foreach (['woocommerce_api_keys', 'wc_webhooks'] as $name) {
        $table = $wpdb->prefix . $name;
        if ((int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id = %d", $source->ID)) > 0 || $wpdb->last_error) return moto_phone_error('merge_manual', 'Для объединения этого аккаунта нужна проверка поддержки.', 409);
    }
    $orders = wc_get_orders(['customer' => $source->ID, 'type' => 'shop_order', 'limit' => 201, 'return' => 'ids', 'orderby' => 'ID', 'order' => 'ASC']);
    $downloads = $wpdb->get_results($wpdb->prepare("SELECT permission_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE user_id = %d ORDER BY permission_id LIMIT 201", $source->ID));
    if ($wpdb->last_error || count($orders) > 200 || count($downloads) > 200) return moto_phone_error('merge_manual', 'Большую историю аккаунта объединит поддержка. Данные не изменены.', 409);
    if (function_exists('wcs_get_users_subscriptions') && wcs_get_users_subscriptions($source->ID)) return moto_phone_error('merge_manual', 'Аккаунт с подписками объединит поддержка.', 409);
    $keys = ['first_name','last_name','billing_first_name','billing_last_name','billing_company','billing_address_1','billing_address_2','billing_city','billing_state','billing_postcode','billing_country','billing_email','shipping_first_name','shipping_last_name','shipping_company','shipping_address_1','shipping_address_2','shipping_city','shipping_state','shipping_postcode','shipping_country'];
    $fill = []; $before = [];
    foreach ($keys as $key) {
        $value = get_user_meta($source->ID, $key, true); $old = get_user_meta($target->ID, $key, true);
        if (is_string($value) && $value !== '' && $old === '') { $fill[$key] = $value; $before[$key] = $old; }
    }
    $plan = ['source' => $source->ID, 'target' => $target->ID, 'phone' => $phone, 'orders' => array_map('intval', $orders), 'downloads' => array_map(static fn($r) => (int)$r->permission_id, $downloads), 'fill' => $fill, 'before_fill' => $before];
    $fingerprint = [$plan, $source->user_email, $target->user_email, $target->user_pass, $source->roles, $target->roles, $source->caps, $target->caps, $otherPhone];
    $plan['fingerprint'] = moto_phone_hash(wp_json_encode($fingerprint));
    return $plan;
}

function moto_merge_pending_key(): string { return 'merge-pending:' . moto_phone_binding(); }

function moto_merge_user_fingerprint(WP_User $user): string {
    return moto_phone_hash(wp_json_encode([$user->ID,$user->user_login,$user->user_email,$user->user_pass,$user->roles,$user->caps,$user->user_status]));
}

function moto_merge_clear_caches(array $users, array $orders): void {
    foreach ($users as $id) { clean_user_cache($id); if (function_exists('wp_cache_delete')) wp_cache_delete($id, 'user_meta'); }
    foreach ($orders as $id) {
        if (function_exists('clean_post_cache')) clean_post_cache($id);
        if (function_exists('wc_delete_shop_order_transients')) wc_delete_shop_order_transients($id);
        if (function_exists('wc_get_container') && class_exists('Automattic\\WooCommerce\\Caches\\OrderCache')) wc_get_container()->get(Automattic\WooCommerce\Caches\OrderCache::class)->remove($id);
    }
}

function moto_merge_order_owner(int $id): int {
    global $wpdb;
    $hpos = class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil') && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $query = $hpos ? $wpdb->prepare("SELECT customer_id FROM {$wpdb->prefix}wc_orders WHERE id = %d", $id) : $wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_customer_user' LIMIT 1", $id);
    $owner = $wpdb->get_var($query);
    if ($wpdb->last_error || $owner === null) throw new RuntimeException('Order ownership verification failed');
    return (int)$owner;
}

function moto_merge_prepare($login, $password) {
    $source = moto_merge_source(); if (is_wp_error($source)) return $source;
    $target = moto_merge_target($login, $password, $source->ID); if (is_wp_error($target)) return $target;
    $callback = static function ($root = null) use ($source, $target) { return moto_merge_plan($source, $target, $root); };
    $plan = function_exists('solartek_image_preview_lock') ? solartek_image_preview_lock($callback) : $callback();
    if (is_wp_error($plan)) return $plan;
    $ticket = bin2hex(random_bytes(24));
    $state = ['ticket' => $ticket, 'binding' => moto_phone_binding(), 'plan' => $plan, 'expires' => time() + 300];
    moto_phone_store(moto_merge_pending_key(), $state);
    return ['orders' => count($plan['orders']), 'target_email' => $target->user_email, 'expires' => $state['expires']];
}

function moto_merge_commit($ticket) {
    global $wpdb;
    $source = moto_merge_source(); if (is_wp_error($source)) return $source;
    $pendingKey = moto_merge_pending_key(); $pending = moto_phone_state($pendingKey);
    if (!is_string($ticket) || !preg_match('/^[a-f0-9]{48}$/D', $ticket) || !is_string($pending['ticket'] ?? null) || !is_array($pending['plan'] ?? null) || !hash_equals($pending['ticket'], $ticket) || ($pending['binding'] ?? '') !== moto_phone_binding()) return moto_phone_error('merge_expired', 'Подтверждение истекло. Проверьте старый аккаунт ещё раз.', 410);
    $oldPlan = $pending['plan']; $target = get_user_by('id', (int)$oldPlan['target']);
    if (!$target) return moto_phone_error('merge_expired', 'Аккаунт изменён. Начните проверку заново.', 409);
    if (!moto_phone_customer($target) || !is_email($target->user_email)) return moto_phone_error('merge_expired', 'Аккаунт изменён. Начните проверку заново.', 409);
    $locks = []; $phoneKey = 'phone:' . $oldPlan['phone'];
    $keys = [$phoneKey]; $ids = [$source->ID, $target->ID]; sort($ids, SORT_NUMERIC);
    foreach ($ids as $id) $keys[] = 'identity-user:' . $id;
    foreach ($keys as $key) {
        $token = moto_phone_lock($key);
        if ($token === '') { foreach ($locks as $k => $v) moto_phone_unlock($k, $v); return moto_phone_error('busy', 'Аккаунт сейчас обновляется. Повторите через несколько секунд.', 409); }
        $locks[$key] = $token;
    }
    try {
        $callback = static function ($root = null) use ($source, $target, $oldPlan, $pendingKey, $ticket) {
            global $wpdb;
            $started = false; $orders = [];
            try {
                if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException('Transaction unavailable');
                $started = true;
                $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID IN (%d,%d) ORDER BY ID FOR UPDATE", $source->ID, $target->ID));
                $wpdb->get_results($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE user_id IN (%d,%d) ORDER BY user_id FOR UPDATE", $source->ID, $target->ID));
                if ($wpdb->last_error) throw new RuntimeException('Account lock failed');
                moto_merge_clear_caches([$source->ID,$target->ID], $oldPlan['orders']);
                $freshPending = moto_phone_state($pendingKey);
                if (!isset($freshPending['ticket']) || !hash_equals($freshPending['ticket'], $ticket) || ($freshPending['binding'] ?? '') !== moto_phone_binding()) throw new RuntimeException('Merge proof no longer active');
                if (is_wp_error(moto_merge_source())) throw new RuntimeException('Source session no longer valid');
                $freshSource = get_user_by('id', $source->ID); $freshTarget = get_user_by('id', $target->ID);
                if (!moto_phone_customer($freshSource) || !moto_phone_customer($freshTarget)) throw new RuntimeException('Account eligibility changed');
                $plan = moto_merge_plan($freshSource, $freshTarget, $root);
                if (is_wp_error($plan) || !hash_equals($oldPlan['fingerprint'], $plan['fingerprint'])) throw new RuntimeException('Merge plan changed');
                $journal = ['source' => $source->ID, 'target' => $target->ID, 'orders' => $plan['orders'], 'downloads' => [], 'target_meta' => $plan['before_fill'], 'source_meta' => [], 'source_fingerprint' => moto_merge_user_fingerprint($freshSource), 'target_fingerprint' => moto_merge_user_fingerprint($freshTarget), 'created_at' => time(), 'expires' => time() + 90 * DAY_IN_SECONDS];
                foreach (['_moto_verified_phone','_moto_phone_verified_at','billing_phone','_moto_last_merge_audit'] as $key) $journal['target_meta'][$key] = get_user_meta($target->ID, $key, true);
                foreach (['_moto_verified_phone','_moto_phone_verified_at','billing_phone','shipping_phone','_moto_merged_into','_moto_merge_audit_key'] as $key) $journal['source_meta'][$key] = get_user_meta($source->ID, $key, true);
                foreach ($plan['orders'] as $id) {
                    $order = wc_get_order($id);
                    if (!$order || (int)$order->get_customer_id() !== $source->ID) throw new RuntimeException('Order ownership changed');
                    $order->set_customer_id($target->ID); $order->save(); $orders[] = $id;
                }
                foreach ($plan['downloads'] as $id) {
                    $download = new WC_Customer_Download($id);
                    if ((int)$download->get_user_id() !== $source->ID) throw new RuntimeException('Download ownership changed');
                    $journal['downloads'][$id] = $download->get_user_email();
                    $download->set_user_id($target->ID); $download->set_user_email($target->user_email); $download->save();
                }
                foreach ($plan['fill'] as $key => $value) update_user_meta($target->ID, $key, $value);
                update_user_meta($source->ID, '_moto_merged_into', (string)$target->ID);
                update_user_meta($source->ID, '_moto_verified_phone', '');
                update_user_meta($source->ID, '_moto_phone_verified_at', '');
                foreach (['billing_phone','shipping_phone'] as $key) if (moto_phone_normalize(get_user_meta($source->ID, $key, true)) === $plan['phone']) update_user_meta($source->ID, $key, '');
                update_user_meta($target->ID, '_moto_verified_phone', $plan['phone']);
                update_user_meta($target->ID, '_moto_phone_verified_at', gmdate('c'));
                update_user_meta($target->ID, 'billing_phone', $plan['phone']);
                if (get_user_meta($target->ID, '_moto_verified_phone', true) !== $plan['phone'] || get_user_meta($target->ID, 'billing_phone', true) !== $plan['phone'] || get_user_meta($source->ID, '_moto_verified_phone', true) !== '' || get_user_meta($source->ID, '_moto_merged_into', true) !== (string)$target->ID) throw new RuntimeException('Identity transfer failed');
                foreach ($plan['fill'] as $key => $value) if (get_user_meta($target->ID, $key, true) !== $value) throw new RuntimeException('Profile transfer incomplete');
                foreach ($plan['orders'] as $id) if (moto_merge_order_owner($id) !== $target->ID) throw new RuntimeException('Order transfer incomplete');
                foreach ($plan['downloads'] as $id) if ((int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->prefix}woocommerce_downloadable_product_permissions WHERE permission_id = %d", $id)) !== $target->ID) throw new RuntimeException('Download transfer incomplete');
                $auditOption = moto_phone_name('merge-audit:' . $ticket);
                update_user_meta($source->ID, '_moto_merge_audit_key', $auditOption);
                update_user_meta($target->ID, '_moto_last_merge_audit', $auditOption);
                if (get_user_meta($source->ID, '_moto_merge_audit_key', true) !== $auditOption || get_user_meta($target->ID, '_moto_last_merge_audit', true) !== $auditOption) throw new RuntimeException('Recovery journal pointer failed');
                foreach ($journal['source_meta'] as $key => $_) $journal['after_source_meta'][$key] = get_user_meta($source->ID, $key, true);
                foreach ($journal['target_meta'] as $key => $_) $journal['after_target_meta'][$key] = get_user_meta($target->ID, $key, true);
                moto_phone_store('merge-audit:' . $ticket, $journal);
                moto_phone_store($pendingKey, ['status' => 'consumed', 'expires' => time() + 300]);
                WP_Session_Tokens::get_instance($source->ID)->destroy_all();
                if ($wpdb->query('COMMIT') === false) throw new RuntimeException('Commit failed');
                $started = false;
                return ['target' => $target->ID, 'moved_orders' => count($orders)];
            } catch (Throwable $error) {
                if ($started) $wpdb->query('ROLLBACK');
                moto_merge_clear_caches([$source->ID,$target->ID], $oldPlan['orders']);
                return moto_phone_error('merge_failed', 'Объединение не выполнено: данные изменились или операция не завершилась. Проверьте аккаунты заново.', 409);
            }
        };
        return function_exists('solartek_image_preview_lock') ? solartek_image_preview_lock($callback) : $callback();
    } finally { foreach (array_reverse($locks, true) as $key => $token) moto_phone_unlock($key, $token); }
}

function moto_merge_auth_guard($user) {
    return $user instanceof WP_User && moto_account_is_merged($user->ID) ? new WP_Error('account_merged', 'Аккаунты объединены. Войдите по телефону или почте основного аккаунта.') : $user;
}

function moto_merge_cookie_guard($id) { return (int)$id > 0 && moto_account_is_merged((int)$id) ? 0 : $id; }

/** Serialize customer checkout/payment writes with a merge in another tab. */
function moto_merge_order_write_guard($order = null): void {
    $user = wp_get_current_user();
    if ($user->ID < 1 || $user->user_email !== '' || !preg_match('/^moto_[a-f0-9]{32}$/D', $user->user_login)) return;
    if (moto_account_is_merged($user->ID)) throw new WC_Data_Exception('moto_account_merged', 'Аккаунты объединены. Войдите заново перед оформлением заказа.', 409);
    // A merge itself saves source orders with the target customer ID.
    if ($order && (int)$order->get_customer_id() !== $user->ID) return;
    if (isset($GLOBALS['moto_merge_order_locks'][$user->ID])) return;
    $key = 'identity-user:' . $user->ID; $token = moto_phone_lock($key);
    if (!$token) throw new WC_Data_Exception('moto_identity_busy', 'Аккаунт сейчас объединяется. Повторите оформление через несколько секунд.', 409);
    $GLOBALS['moto_merge_order_locks'][$user->ID] = $token;
    register_shutdown_function('moto_merge_order_unlock');
    if (moto_account_is_merged($user->ID)) {
        moto_merge_order_unlock();
        throw new WC_Data_Exception('moto_account_merged', 'Аккаунты объединены. Войдите заново перед оформлением заказа.', 409);
    }
}

function moto_merge_order_unlock(): void {
    foreach ($GLOBALS['moto_merge_order_locks'] ?? [] as $id => $token) moto_phone_unlock('identity-user:' . $id, $token);
    $GLOBALS['moto_merge_order_locks'] = [];
}

function moto_merge_pay_guard($order): void {
    try { moto_merge_order_write_guard($order); }
    catch (WC_Data_Exception $error) { wp_die(esc_html($error->getMessage()), 'Обновление аккаунта', ['response' => 409, 'back_link' => true]); }
}

/** Operator-only recovery. Default is read-only; changed records fail closed. */
function moto_merge_rollback(int $sourceId, bool $apply = false) {
    if (PHP_SAPI !== 'cli') return moto_phone_error('forbidden', 'Операция недоступна.', 403);
    global $wpdb;
    $option = get_user_meta($sourceId, '_moto_merge_audit_key', true);
    if (!is_string($option) || !preg_match('/^_moto_sms_[a-f0-9]{64}$/D', $option)) return moto_phone_error('merge_audit', 'Журнал объединения не найден.', 404);
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
    $journal = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($journal) || ($journal['source'] ?? 0) !== $sourceId || ($journal['expires'] ?? 0) <= time() || isset($journal['reverted_at'])) return moto_phone_error('merge_audit', 'Журнал недоступен или уже применён.', 409);
    $targetId = (int)$journal['target'];
    $check = static function () use ($journal,$sourceId,$targetId) {
        moto_merge_clear_caches([$sourceId,$targetId],$journal['orders']);
        foreach ([$sourceId => 'source', $targetId => 'target'] as $id => $kind) {
            $user = get_user_by('id',$id);
            if (!$user || !hash_equals($journal[$kind . '_fingerprint'],moto_merge_user_fingerprint($user))) return false;
            foreach ($journal['after_' . $kind . '_meta'] as $key => $value) if (get_user_meta($id,$key,true) !== $value) return false;
        }
        foreach ($journal['orders'] as $id) if (moto_merge_order_owner($id) !== $targetId) return false;
        foreach ($journal['downloads'] as $id => $_) { $d = new WC_Customer_Download((int)$id); if ((int)$d->get_user_id() !== $targetId) return false; }
        return true;
    };
    if (!$check()) return moto_phone_error('merge_drift', 'После объединения данные изменились. Нужна ручная проверка.', 409);
    $result = ['source'=>$sourceId,'target'=>$targetId,'orders'=>count($journal['orders']),'downloads'=>count($journal['downloads']),'ready'=>true,'applied'=>false];
    if (!$apply) return $result;
    $locks = []; $ids = [$sourceId,$targetId];sort($ids,SORT_NUMERIC);
    $keys = ['phone:' . $journal['after_target_meta']['_moto_verified_phone']];foreach($ids as $id)$keys[]='identity-user:'.$id;
    foreach ($keys as $key) { $token=moto_phone_lock($key); if(!$token){foreach($locks as $k=>$v)moto_phone_unlock($k,$v);return moto_phone_error('busy','Аккаунт обновляется.',409);} $locks[$key]=$token; }
    try {
        if($wpdb->query('START TRANSACTION')===false)throw new RuntimeException('Transaction failed');
        $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->users} WHERE ID IN (%d,%d) ORDER BY ID FOR UPDATE",$sourceId,$targetId));
        if($wpdb->last_error)throw new RuntimeException('Account lock failed');
        $wpdb->get_results($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE user_id IN (%d,%d) ORDER BY user_id FOR UPDATE",$sourceId,$targetId));
        if($wpdb->last_error)throw new RuntimeException('Metadata lock failed');
        if(!$check())throw new RuntimeException('Drift');
        foreach($journal['orders'] as $id){$o=wc_get_order($id);$o->set_customer_id($sourceId);$o->save();}
        foreach($journal['downloads'] as $id=>$email){$d=new WC_Customer_Download((int)$id);$d->set_user_id($sourceId);$d->set_user_email($email);$d->save();}
        foreach([$sourceId=>'source',$targetId=>'target'] as $id=>$kind)foreach($journal[$kind.'_meta'] as $key=>$value)update_user_meta($id,$key,$value);
        foreach($journal['orders'] as $id)if(moto_merge_order_owner($id)!==$sourceId)throw new RuntimeException('Restore failed');
        foreach([$sourceId=>'source',$targetId=>'target'] as $id=>$kind)foreach($journal[$kind.'_meta'] as $key=>$value)if(get_user_meta($id,$key,true)!==$value)throw new RuntimeException('Metadata restore failed');
        foreach($journal['downloads'] as $id=>$email){$d=new WC_Customer_Download((int)$id);if((int)$d->get_user_id()!==$sourceId||$d->get_user_email()!==$email)throw new RuntimeException('Download restore failed');}
        WP_Session_Tokens::get_instance($sourceId)->destroy_all();WP_Session_Tokens::get_instance($targetId)->destroy_all();
        $journal['reverted_at']=time();
        if($wpdb->replace($wpdb->options,['option_name'=>$option,'option_value'=>wp_json_encode($journal),'autoload'=>'no'])===false||$wpdb->query('COMMIT')===false)throw new RuntimeException('Commit failed');
        $result['applied']=true;return $result;
    } catch(Throwable $error) { $wpdb->query('ROLLBACK');return moto_phone_error('merge_rollback_failed','Восстановление не выполнено.',409); }
    finally { moto_merge_clear_caches([$sourceId,$targetId],$journal['orders']);foreach(array_reverse($locks,true) as $key=>$token)moto_phone_unlock($key,$token); }
}
