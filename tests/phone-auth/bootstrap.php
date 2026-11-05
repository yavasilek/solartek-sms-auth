<?php
// Isolated WordPress boundary doubles + a real SQLite state/locking database.
// No site bootstrap, production DB, real credential, network or SMS is used.
define('ABSPATH', dirname(__DIR__, 2));
define('MOTO_PHONE_AUTH_ENABLED', true);
$fixtureKey = sys_get_temp_dir() . '/moto-sms-fixture-' . getmypid() . '.txt';
file_put_contents($fixtureKey, 'sms_live_' . str_repeat('x', 32));
define('MOTO_SMS_API_KEY_FILE', $fixtureKey);
register_shutdown_function(static function () use ($fixtureKey) { if (is_file($fixtureKey)) unlink($fixtureKey); });

class WP_Error {
    public function __construct(private string $code = '', private string $message = '', private $data = null) {}
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_User {
    public int $ID;
    public string $user_login;
    public string $display_name;
    public string $user_email;
    public string $user_pass;
    public int $user_status = 0;
    public array $roles = [];
    public array $caps = [];
    public function __construct(array $row = []) {
        $this->ID = (int) ($row['ID'] ?? 0);
        $this->user_login = $row['user_login'] ?? '';
        $this->display_name = $row['display_name'] ?? $this->user_login;
        $this->user_email = $row['user_email'] ?? '';
        $this->user_pass = $row['user_pass'] ?? '';
        $this->roles = json_decode($row['roles'] ?? '[]', true);
        $this->caps = json_decode($row['caps'] ?? '[]', true);
        $this->user_status = (int) ($row['user_status'] ?? 0);
    }
}
class PhoneFixtureDB {
    public string $options = 'options', $usermeta = 'usermeta', $last_error = '';
    public PDO $db;
    public function __construct(string $path) {
        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA busy_timeout = 5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS options (option_name TEXT PRIMARY KEY, option_value TEXT, autoload TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS users (ID INTEGER PRIMARY KEY AUTOINCREMENT, user_login TEXT UNIQUE, roles TEXT, caps TEXT DEFAULT "[]", user_status INTEGER DEFAULT 0, user_email TEXT DEFAULT "", display_name TEXT DEFAULT "")');
        $this->db->exec('CREATE TABLE IF NOT EXISTS usermeta (user_id INTEGER, meta_key TEXT, meta_value TEXT, UNIQUE(user_id,meta_key))');
        $this->db->exec('CREATE TABLE IF NOT EXISTS provider_ops (idempotency TEXT PRIMARY KEY, challenge_id TEXT, phone TEXT)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS provider_calls (id INTEGER PRIMARY KEY, path TEXT, body TEXT, idempotency TEXT)');
    }
    public function prepare(string $sql, ...$args): string {
        return preg_replace_callback('/%[sd]/', function ($m) use (&$args) { return $m[0] === '%d' ? (string) (int) array_shift($args) : $this->db->quote((string) array_shift($args)); }, $sql);
    }
    public function query(string $sql) {
        $this->last_error = '';
        try { return $this->db->exec(str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $sql)); }
        catch (Throwable $e) { $this->last_error = 'fixture SQL failed'; return false; }
    }
    public function get_var(string $sql) {
        $this->last_error = '';
        try { $result = $this->db->query($sql)->fetchColumn(); return $result === false ? null : $result; }
        catch (Throwable $e) { $this->last_error = 'fixture SQL failed'; return null; }
    }
    public function get_results(string $sql): array {
        $this->last_error = '';
        $sql = preg_replace_callback("/(LIKE '(?:[^']|'')*')/", fn($m) => $m[1] . ' ESCAPE ' . $this->db->quote('\\'), $sql);
        return $this->db->query($sql)->fetchAll(PDO::FETCH_OBJ);
    }
    public function replace(string $table, array $data, array $formats = []) {
        $columns = array_keys($data);
        $statement = $this->db->prepare('INSERT OR REPLACE INTO ' . $table . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')');
        return $statement->execute(array_values($data)) ? $statement->rowCount() : false;
    }
    public function esc_like(string $value): string { return addcslashes($value, '_%\\'); }
}
$fixtureDbPath = getenv('MOTO_PHONE_FIXTURE_DB') ?: ':memory:';
$wpdb = new PhoneFixtureDB($fixtureDbPath);
$fixtureUser = 0;
$fixtureSession = 'fixture-wordpress-session';
$fixtureProviderMode = 'ok';
$fixtureCalls = [];
$fixtureLoginCalls = [];
$fixtureActions = [];
function add_action($name, $callback, ...$args) { global $fixtureActions; $fixtureActions[$name][] = $callback; }
function add_filter($name, $callback, ...$args) { add_action($name, $callback, ...$args); }
function apply_filters($name, $value, ...$args) { global $fixtureActions; foreach ($fixtureActions[$name] ?? [] as $callback) $value = $callback($value, ...$args); return $value; }
if (!function_exists('is_email')) {
    function is_email($value) { return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : false; }
}
function WC() { return $GLOBALS['fixtureWC'] ?? (object) []; }
function wp_salt($scheme) { return 'fixture-salt-constant-not-a-credential'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($value) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function wp_generate_uuid4() { $x = bin2hex(random_bytes(16)); return substr($x,0,8).'-'.substr($x,8,4).'-4'.substr($x,13,3).'-a'.substr($x,17,3).'-'.substr($x,20); }
function wp_generate_password(...$args) { return bin2hex(random_bytes(32)); }
function get_current_user_id() { global $fixtureUser; return $fixtureUser; }
function wp_get_session_token() { global $fixtureSession; return get_current_user_id() ? $fixtureSession : ''; }
function wp_get_current_user() { return get_user_by('id', get_current_user_id()) ?: new WP_User; }
function get_user_by($field, $id) { global $wpdb; $row = $wpdb->db->query('SELECT * FROM users WHERE ID='.(int)$id)->fetch(PDO::FETCH_ASSOC); return $row ? new WP_User($row) : false; }
function get_users($args) {
    global $wpdb;
    $q=$wpdb->db->prepare('SELECT users.* FROM users JOIN usermeta ON usermeta.user_id=users.ID WHERE meta_key=? AND meta_value=? LIMIT ?');
    $q->bindValue(1,$args['meta_key']); $q->bindValue(2,$args['meta_value']); $q->bindValue(3,$args['number'],PDO::PARAM_INT); $q->execute();
    return array_map(fn($row) => new WP_User($row),$q->fetchAll(PDO::FETCH_ASSOC));
}
function user_can($user, $cap) { return in_array($cap, $user->caps, true) || in_array('administrator', $user->roles, true); }
function is_super_admin($id) { return in_array('administrator', get_user_by('id',$id)->roles ?? [], true); }
function get_role($name) { return $name === 'customer' ? (object)['name'=>'customer'] : null; }
function wp_insert_user($data) {
    global $wpdb;
    try { $q=$wpdb->db->prepare('INSERT INTO users (user_login,roles,user_email,display_name) VALUES (?,?,?,?)'); $q->execute([$data['user_login'],json_encode([$data['role']]),$data['user_email']??'',$data['display_name']??$data['user_login']]); return (int)$wpdb->db->lastInsertId(); }
    catch (Throwable $e) { return new WP_Error('exists','fixture duplicate'); }
}
function update_user_meta($id,$key,$value) { global $wpdb; $wpdb->replace('usermeta',['user_id'=>$id,'meta_key'=>$key,'meta_value'=>is_array($value)||is_object($value)?serialize($value):$value]); return true; }
function get_user_meta($id,$key,$single=true) { global $wpdb; $q=$wpdb->db->prepare('SELECT meta_value FROM usermeta WHERE user_id=? AND meta_key=?'); $q->execute([$id,$key]);$v=$q->fetchColumn()?:'';return is_string($v)&&preg_match('/^[aO]:[0-9]+:/',$v)?unserialize($v,['allowed_classes'=>false]):$v; }
function home_url($path='') { return (getenv('MOTO_PHONE_FIXTURE_ORIGIN') ?: 'https://мото.example.test').$path; }
function site_url($path='') { return home_url($path); }
function wp_parse_url($value) { return parse_url($value); }
function is_ssl() { return str_starts_with(home_url(), 'https:'); }
function wp_unslash($value) { return is_string($value) ? stripslashes($value) : $value; }
function wp_create_nonce($action) { return 'fixture-nonce-'.$action; }
function wc_lostpassword_url() { return home_url('/my-account/lost-password/'); }
function is_user_logged_in() { return get_current_user_id()>0; }
function wc_get_page_permalink($name) { return home_url('/my-account/'); }
function wc_get_checkout_url() { return home_url('/checkout/'); }
function is_checkout() { return parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH)==='/checkout/'; }
function wc_set_customer_auth_cookie($id) {
    global $fixtureLoginCalls,$fixtureUser;
    $fixtureLoginCalls[]=$id; $fixtureUser=$id;
    if (PHP_SAPI==='cli-server') setcookie('fixture_customer',(string)$id,['path'=>'/','httponly'=>true,'samesite'=>'Strict']);
}
function do_action($name,...$args) { if (!empty($GLOBALS['fixtureDispatchHooks'])) foreach ($GLOBALS['fixtureActions'][$name] ?? [] as $callback) $callback(...$args); }
function admin_url($path) { return home_url('/wp-admin/'.$path); }
function content_url($path) { return home_url('/'.$path); }
function wp_unique_id($prefix) { static $i=0; return $prefix.++$i; }
function esc_url($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function esc_attr($v) { return esc_url($v); }
function esc_html($v) { return esc_url($v); }
function get_privacy_policy_url() { return home_url('/privacy-policy/'); }
function wp_next_scheduled($name) { return true; }
function nocache_headers() { if (PHP_SAPI==='cli-server') header('Cache-Control: no-cache'); }
function wp_send_json_error($data,$status=400) { http_response_code($status); header('Content-Type: application/json'); echo json_encode(['success'=>false,'data'=>$data]); exit; }
function wp_send_json_success($data) {
    // Deterministic human answer only in this loopback fixture, never production.
    if (($_POST['operation'] ?? '') === 'captcha' && isset($data['id'])) {
        $session=moto_phone_session(); $state=moto_phone_state('antibot:'.$session);
        $state['answer_hash']=moto_phone_antibot_answer($session,$state,'A7K3RM');
        $state['image']=moto_phone_antibot_image('A7K3RM');
        moto_phone_store('antibot:'.$session,$state); $data=moto_phone_antibot_public($state);
    }
    header('Content-Type: application/json'); echo json_encode(['success'=>true,'data'=>$data]); exit;
}
function wp_remote_retrieve_response_code($response) { return $response['status']; }
function wp_remote_retrieve_body($response) { return json_encode($response['body']); }
function wp_remote_post($url,$args) {
    global $fixtureCalls,$fixtureProviderMode,$wpdb;
    $body=json_decode($args['body'],true);
    $path=str_replace('https://sms.solartek.ru','',$url);
    $idem=$args['headers']['Idempotency-Key']??'';
    $fixtureCalls[]=[$url,$args];
    $wpdb->replace('provider_calls',['id'=>null,'path'=>$path,'body'=>json_encode($body),'idempotency'=>$idem]);
    if (str_ends_with($path,'/verify/')) {
        $verified=$body['code']==='123456' || $body['code']==='1234';
        return ['status'=>200,'body'=>['verified'=>$verified,'simulated'=>$fixtureProviderMode==='simulated','reason'=>$verified?'':'invalid_code'] + ($fixtureProviderMode==='wrong_verify_phone'?['phone'=>'+79990000099']:[])];
    }
    $q=$wpdb->db->prepare('SELECT * FROM provider_ops WHERE idempotency=?'); $q->execute([$idem]); $op=$q->fetch(PDO::FETCH_ASSOC);
    if (!$op) { $op=['idempotency'=>$idem,'challenge_id'=>wp_generate_uuid4(),'phone'=>$body['phone']]; $wpdb->replace('provider_ops',$op); }
    if ($fixtureProviderMode==='timeout') return new WP_Error('timeout','fixture secret transport detail');
    if ($fixtureProviderMode==='slow') usleep(700000);
    if ($fixtureProviderMode==='http_error') return ['status'=>401,'body'=>['secret'=>'must not escape']];
    if ($fixtureProviderMode==='malformed') return ['status'=>200,'body'=>['verified'=>true]];
    return ['status'=>201,'body'=>['challenge_id'=>$op['challenge_id'],'message_id'=>wp_generate_uuid4(),'phone'=>$fixtureProviderMode==='wrong_phone'?'+79990000099':$body['phone'],
        'channel'=>'sms','status'=>$fixtureProviderMode==='failed'?'failed':'pending','error_code'=>'','expires_at'=>gmdate('c',time()+300),'code_length'=>$fixtureProviderMode==='four'?4:6,'simulated'=>$fixtureProviderMode==='simulated']];
}
require_once __DIR__.'/ssa-fixture.php';
require dirname(__DIR__,2).'/plugin/solartek-sms-auth/compat/motonaklejki-phone-auth.php';
require dirname(__DIR__,2).'/plugin/solartek-sms-auth/compat/motonaklejki-account-merge.php';

function fixture_antibot_proof(string $session, string $phone): array {
    static $work, $image;
    $seed=str_repeat('a',32);
    if (!isset($work)) { $n=0; while(!moto_phone_antibot_work($seed,(string)$n,18)) $n++; $work=(string)$n; }
    if (!isset($image)) $image=moto_phone_antibot_image('A7K3RM');
    $state=['id'=>bin2hex(random_bytes(16)),'phone'=>$phone,'binding'=>moto_phone_binding(), 'work'=>$seed,'bits'=>18,
        'attempts'=>0,'status'=>'pending','issued_at'=>microtime(true)-2,'expires'=>time()+180,'image'=>$image];
    $state['answer_hash']=moto_phone_antibot_answer($session,$state,'A7K3RM');
    moto_phone_store('antibot:'.$session,$state);
    return ['id'=>$state['id'],'answer'=>'A7K3RM','work'=>$work];
}
function fixture_send(string $session, string $phone, bool $resend=false) {
    return moto_phone_send($session,$phone,$resend,fixture_antibot_proof($session,$phone));
}
