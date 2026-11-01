<?php
defined('ABSPATH') || exit;

/** Locks are reentrant in this request and live through WooCommerce shutdown saves. */
function moto_merge_identity_lock(int $id): bool {
    if (isset($GLOBALS['moto_merge_order_locks'][$id])) return true;
    $token = moto_phone_lock('identity-user:' . $id);
    if (!$token) return false;
    $GLOBALS['moto_merge_order_locks'][$id] = $token;
    static $registered = false;
    if (!$registered) { register_shutdown_function('moto_merge_order_unlock'); $registered = true; }
    return true;
}

function moto_merge_cart_epoch(int $id): string {
    global $wpdb;
    $value = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key='_moto_cart_epoch' LIMIT 1", $id));
    if ($wpdb->last_error) throw new RuntimeException('Cart identity unavailable');
    return is_string($value) ? $value : '';
}

function moto_merge_cart_guard($session = null): void {
    $id = get_current_user_id();
    if (!$id || !moto_phone_customer(wp_get_current_user())) {
        if ($id && moto_account_is_merged($id)) throw new WC_Data_Exception('moto_account_merged','Аккаунты объединены. Обновите страницу корзины.',409);
        return;
    }
    $session = $session ?: (WC()->session ?? null);
    if (!$session || !is_callable([$session,'get'])) return;
    if (!moto_merge_identity_lock($id)) throw new WC_Data_Exception('moto_cart_busy','Аккаунт сейчас объединяется. Повторите действие через несколько секунд.',409);
    if (moto_merge_cart_epoch($id) !== (string)$session->get('_moto_merge_epoch','')) throw new WC_Data_Exception('moto_cart_changed','Корзина изменилась после объединения аккаунтов. Обновите страницу.',409);
}

function moto_merge_session_handler($class) {
    if ($class !== 'WC_Session_Handler' || !class_exists('WC_Session_Handler')) return $class;
    if (!class_exists('Moto_Merge_Session_Handler',false)) {
        class Moto_Merge_Session_Handler extends WC_Session_Handler {
            public function save_data($old_session_key = '') {
                try { moto_merge_cart_guard($this); }
                catch (WC_Data_Exception $error) { return; } // A stale request must not overwrite the merged cart.
                parent::save_data($old_session_key);
            }
        }
    }
    return 'Moto_Merge_Session_Handler';
}

function moto_merge_cart_hook(): void { moto_merge_cart_guard(); }

function moto_merge_cart_array($cart): array {
    if ($cart === null || $cart === '') return [];
    if (!is_array($cart) || count($cart)>200) throw new RuntimeException('Unsupported cart');
    foreach ($cart as $key => &$item) {
        if (!is_string($key) || strlen($key)>128 || !is_array($item)) throw new RuntimeException('Unsupported cart item');
        unset($item['data']);
        if (empty($item['product_id']) || !is_numeric($item['quantity']??null) || $item['quantity']<=0 || $item['quantity']>1000000) throw new RuntimeException('Invalid cart item');
    }
    unset($item);
    $walk = static function($value,$depth=0) use (&$walk) { if ($depth>12 || is_object($value) || is_resource($value)) throw new RuntimeException('Unsupported cart data');if(is_array($value))foreach($value as $v)$walk($v,$depth+1); };
    $walk($cart);
    if (strlen(serialize($cart))>1048576) throw new RuntimeException('Cart too large');
    return $cart;
}

function moto_merge_cart_intent(array $cart): array {
    foreach ($cart as &$item) foreach (['line_total','line_tax','line_subtotal','line_subtotal_tax','line_tax_data','data_hash','key'] as $field) unset($item[$field]);
    unset($item);return $cart;
}

/** Native Woo session fields are individually serialized inside the session array. */
function moto_merge_session_field($value) {
    if (is_string($value) && preg_match('/^(?:a|O|C):/', $value)) {
        $value = unserialize($value, ['allowed_classes'=>false]);
        if (!is_array($value)) throw new RuntimeException('Unsupported serialized session');
    }
    return $value;
}

/** Fresh session rows are used, bypassing Redis while holding the merge transaction. */
function moto_merge_cart_snapshot(int $id): array {
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare("SELECT session_value,session_expiry FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=%s",(string)$id));
    if ($wpdb->last_error) throw new RuntimeException('Cart storage unavailable');
    $data=isset($rows[0])?unserialize($rows[0]->session_value,['allowed_classes'=>false]):[];
    if (!is_array($data)) throw new RuntimeException('Cart storage invalid');
    $snapshot=['cart'=>[],'applied_coupons'=>[],'removed_cart_contents'=>[]];
    if (isset($rows[0]) && (int)$rows[0]->session_expiry>time()) foreach(array_keys($snapshot) as $key) $snapshot[$key]=moto_merge_session_field($data[$key]??[]);
    $snapshot['cart']=moto_merge_cart_array($snapshot['cart']);
    foreach (['applied_coupons','removed_cart_contents'] as $key) if (!is_array($snapshot[$key])) throw new RuntimeException('Cart data invalid');
    $snapshot['has_cart']=isset($rows[0]) && (int)$rows[0]->session_expiry>time() && array_key_exists('cart',$data);
    return $snapshot;
}

function moto_merge_cart_plan(int $source,int $target) {
    try {
        if(class_exists('WC_Session_Handler')&&isset(WC()->session)&&!(WC()->session instanceof Moto_Merge_Session_Handler))throw new RuntimeException('Custom session handler needs operator review');
        $a=moto_merge_cart_snapshot($source);$b=moto_merge_cart_snapshot($target);
        $key='_woocommerce_persistent_cart_'.get_current_blog_id();
        $savedA=get_user_meta($source,$key,true);$savedB=get_user_meta($target,$key,true);
        if (is_array($savedA)&&isset($savedA['cart']))$savedA['cart']=moto_merge_cart_array($savedA['cart']);
        if (is_array($savedB)&&isset($savedB['cart']))$savedB['cart']=moto_merge_cart_array($savedB['cart']);
        if (!$a['has_cart']) $a['cart']=moto_merge_cart_array(is_array($savedA)?($savedA['cart']??[]):[]);
        if (!$b['has_cart']) $b['cart']=moto_merge_cart_array(is_array($savedB)?($savedB['cart']??[]):[]);
        if ($source===get_current_user_id() && isset(WC()->cart) && is_callable([WC()->cart,'get_cart'])) {
            $a['cart']=moto_merge_cart_array(WC()->cart->get_cart());
            if (is_callable([WC()->cart,'get_applied_coupons'])) $a['applied_coupons']=WC()->cart->get_applied_coupons();
        }
        $merged=$b['cart'];
        foreach($a['cart'] as $id=>$item){
            if(isset($merged[$id])){
                $left=moto_merge_cart_intent([$id=>$merged[$id]]);$right=moto_merge_cart_intent([$id=>$item]);unset($left[$id]['quantity'],$right[$id]['quantity']);
                if($left!=$right) return moto_phone_error('merge_cart_conflict','В корзинах есть разные настройки одной позиции. Обратитесь в поддержку — товары сохранены.',409);
                $merged[$id]['quantity']+=$item['quantity'];
            } else $merged[$id]=$item;
        }
        $merged=moto_merge_cart_array($merged);
        $coupons=array_values(array_unique(array_merge($b['applied_coupons'],$a['applied_coupons'])));
        foreach($coupons as $c)if(!is_string($c)||strlen($c)>200)throw new RuntimeException('Invalid coupon');
        return ['source'=>$a,'target'=>$b,'source_saved'=>$savedA,'target_saved'=>$savedB,'merged'=>$merged,'coupons'=>$coupons];
    } catch(Throwable $error){return moto_phone_error('merge_cart_unavailable','Не удалось безопасно прочитать корзины. Товары сохранены, повторите позже или обратитесь в поддержку.',409);}
}

function moto_merge_cart_cache(int $id): void {
    if (defined('WC_SESSION_CACHE_GROUP') && class_exists('WC_Cache_Helper')) wp_cache_delete(WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP).$id,WC_SESSION_CACHE_GROUP);
}

function moto_merge_cart_write(int $id,array $cart,array $coupons,array $removed,string $epoch): void {
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare("SELECT session_value,session_expiry FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=%s",(string)$id));
    if($wpdb->last_error)throw new RuntimeException('Session read failed');
    $data=isset($rows[0])?unserialize($rows[0]->session_value,['allowed_classes'=>false]):[];
    if(!is_array($data))throw new RuntimeException('Session invalid');
    $data['cart']=serialize($cart);$data['applied_coupons']=serialize($coupons);$data['removed_cart_contents']=serialize($removed);$data['_moto_merge_epoch']=$epoch;unset($data['cart_totals']);
    if($wpdb->replace($wpdb->prefix.'woocommerce_sessions',['session_key'=>(string)$id,'session_value'=>serialize($data),'session_expiry'=>max((int)($rows[0]->session_expiry??0),time()+2*DAY_IN_SECONDS)])===false)throw new RuntimeException('Session write failed');
    moto_merge_cart_cache($id);
    if(moto_merge_cart_snapshot($id)['cart']!==$cart)throw new RuntimeException('Cart write incomplete');
}

function moto_merge_cart_apply(array $plan,int $source,int $target): array {
    $key='_woocommerce_persistent_cart_'.get_current_blog_id();$epoch=bin2hex(random_bytes(16));
    $a=is_array($plan['source_saved'])?$plan['source_saved']:[];$b=is_array($plan['target_saved'])?$plan['target_saved']:[];$a['cart']=[];$b['cart']=$plan['merged'];
    update_user_meta($source,$key,$a);update_user_meta($target,$key,$b);
    update_user_meta($source,'_moto_cart_epoch',$epoch);update_user_meta($target,'_moto_cart_epoch',$epoch);
    if(get_user_meta($source,$key,true)!==$a||get_user_meta($target,$key,true)!==$b||moto_merge_cart_epoch($source)!==$epoch||moto_merge_cart_epoch($target)!==$epoch)throw new RuntimeException('Persistent cart transfer failed');
    moto_merge_cart_write($source,[],[],[],$epoch);
    moto_merge_cart_write($target,$plan['merged'],$plan['coupons'],array_replace($plan['source']['removed_cart_contents'],$plan['target']['removed_cart_contents']),$epoch);
    return ['before_source'=>$plan['source'],'before_target'=>$plan['target'],'after_source'=>moto_merge_cart_snapshot($source),'after_target'=>moto_merge_cart_snapshot($target)];
}

/** Avoid WooCommerce's different-user cookie reset emptying the transferred cart. */
function moto_merge_login(int $target): void {
    if(defined('COOKIEHASH'))unset($_COOKIE[apply_filters('woocommerce_cookie','wp_woocommerce_session_'.COOKIEHASH)]);
    wc_set_customer_auth_cookie($target);
    if(isset(WC()->session)&&is_callable([WC()->session,'set_customer_session_cookie'])){
        WC()->session->set_customer_session_cookie(true);
        if(class_exists('WC_Customer'))WC()->customer=new WC_Customer($target,true);
        if(isset(WC()->cart)&&class_exists('WC_Cart_Session'))(new WC_Cart_Session(WC()->cart))->get_cart_from_session();
    }
}
