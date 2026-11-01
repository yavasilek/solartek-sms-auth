<?php
defined('ABSPATH') || exit;

/** Scope the extension to the existing Users list, never public user lookups. */
function ssa_admin_users_search_allowed(): bool {
    if (!is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
        || !current_user_can('list_users') || !function_exists('get_current_screen')) return false;
    $screen = get_current_screen();
    return $screen && $screen->id === 'users';
}

function ssa_admin_users_phone_digits($search): string {
    if (!is_string($search) || strlen($search) > 128) return '';
    $search = trim(trim($search), '*');
    if (!preg_match('/^\+?[0-9\p{Z}\s().\-\x{2010}-\x{2015}\x{2212}]+$/uD', $search)) return '';
    $digits = preg_replace('/[^0-9]/', '', $search);
    return strlen($digits) >= 4 && strlen($digits) <= 15 ? $digits : '';
}

function ssa_admin_users_query_args(array $args): array {
    if (ssa_admin_users_search_allowed() && ssa_admin_users_phone_digits($args['search'] ?? '') !== '') {
        $args['ssa_admin_phone_search'] = true;
    }
    return $args;
}

/** Use core's own search SQL, including columns supplied by other plugins. */
class SSA_Admin_User_Search_SQL extends WP_User_Query {
    public function native_clause(string $search, array $columns, string $raw): string {
        $leading = str_starts_with(trim($raw), '*');
        $trailing = str_ends_with(trim($raw), '*');
        $wild = $leading && $trailing ? 'both' : ($leading ? 'leading' : ($trailing ? 'trailing' : false));
        return $this->get_search_sql($search, $columns, $wild);
    }
}

function ssa_admin_users_search_clauses(): WeakMap {
    static $clauses;
    return $clauses ??= new WeakMap();
}

function ssa_admin_users_search_columns(array $columns, string $search, WP_User_Query $query): array {
    if (!empty($query->query_vars['ssa_admin_phone_search']) && ssa_admin_users_search_allowed()
        && ssa_admin_users_phone_digits($query->query_vars['search'] ?? '') !== '') {
        $sql = new SSA_Admin_User_Search_SQL();
        ssa_admin_users_search_clauses()[$query] = $sql->native_clause($search, $columns, $query->query_vars['search']);
    }
    return $columns;
}

function ssa_admin_users_phone_search(WP_User_Query $query): void {
    $clauses = ssa_admin_users_search_clauses();
    if (!isset($clauses[$query])) return;
    $native = $clauses[$query];
    unset($clauses[$query]);
    if (!ssa_admin_users_search_allowed()) return;
    $digits = ssa_admin_users_phone_digits($query->query_vars['search'] ?? '');
    // If another plugin replaced core's clause, leave that query untouched.
    if ($digits === '' || substr_count($query->query_where, $native) !== 1) return;
    global $wpdb;
    $value = 'ssa_phone_meta.meta_value';
    // MySQL 5.7-compatible; no migration or scan of every user in PHP is needed.
    foreach (['+', ' ', "\t", "\n", "\r", '(', ')', '.', '-', "\u{00a0}", "\u{202f}",
        "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"] as $separator) {
        $value = $wpdb->prepare("REPLACE($value, %s, '')", $separator);
    }
    $variants = [];
    if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
        $national = substr($digits, 1);
        $variants = ['7'.$national, '8'.$national, $national];
    } elseif (strlen($digits) === 10) {
        $variants = ['7'.$digits, '8'.$digits, $digits];
    }
    if ($variants) {
        $matches = $wpdb->prepare("$value IN (%s, %s, %s)", ...$variants);
    } else {
        $matches = $wpdb->prepare("$value LIKE %s", '%'.$wpdb->esc_like($digits).'%');
    }
    $phone = "EXISTS (SELECT 1 FROM {$wpdb->usermeta} AS ssa_phone_meta"
        . " WHERE ssa_phone_meta.user_id = {$wpdb->users}.ID"
        . " AND ssa_phone_meta.meta_key IN ('_moto_verified_phone', 'billing_phone', 'shipping_phone')"
        . " AND ($matches))";
    // Broaden only the search group, retaining roles, site, dates and include/exclude.
    $replacement = ' AND (' . substr($native, 6, -1) . ' OR ' . $phone . ')';
    $query->query_where = str_replace($native, $replacement, $query->query_where);
}

add_filter('users_list_table_query_args', 'ssa_admin_users_query_args');
add_filter('user_search_columns', 'ssa_admin_users_search_columns', PHP_INT_MAX, 3);
add_action('pre_user_query', 'ssa_admin_users_phone_search', 20);
