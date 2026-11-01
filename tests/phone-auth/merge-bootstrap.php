<?php
require __DIR__.'/merge-orderutil.php';
require __DIR__.'/bootstrap.php';
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS',86400);
$fixtureMergeSessions=[];$fixtureMergeFailOrder=0;$fixtureMergeMetaFail='';$fixtureMergeAuthFailure=false;
class WC_Data_Exception extends RuntimeException { public function __construct($code,$message,$status=400){parent::__construct($message,$status);} }
class MergeFixtureDB extends PhoneFixtureDB {
    public string $users='users',$posts='posts',$postmeta='postmeta',$prefix='fixture_';
    public function __construct(string $path) {
        parent::__construct($path);
        $cols=$this->db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN,1);
        if(!in_array('user_pass',$cols,true))$this->db->exec('ALTER TABLE users ADD COLUMN user_pass TEXT DEFAULT ""');
        $this->db->exec('CREATE TABLE IF NOT EXISTS fixture_orders (ID INTEGER PRIMARY KEY,customer_id INTEGER,total TEXT,status TEXT,currency TEXT)');
        $this->db->exec('CREATE VIEW IF NOT EXISTS fixture_wc_orders AS SELECT * FROM fixture_orders');
        $this->db->exec('CREATE TABLE IF NOT EXISTS fixture_woocommerce_downloadable_product_permissions (permission_id INTEGER PRIMARY KEY,user_id INTEGER,user_email TEXT,remaining INTEGER)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS fixture_woocommerce_api_keys (user_id INTEGER)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS fixture_wc_webhooks (user_id INTEGER)');
        $this->db->exec('CREATE TABLE IF NOT EXISTS fixture_woocommerce_sessions (session_key TEXT PRIMARY KEY,session_value TEXT,session_expiry INTEGER)');
    }
    public function query(string $sql) {return parent::query($sql==='START TRANSACTION'?'BEGIN IMMEDIATE':$sql);}
    public function get_results(string $sql): array {
        if(str_contains($sql,'information_schema.TABLES'))return array_map(fn($n)=>(object)['TABLE_NAME'=>$n,'ENGINE'=>'InnoDB'],['users','usermeta','options','posts','postmeta','fixture_wc_orders','fixture_woocommerce_downloadable_product_permissions','fixture_woocommerce_sessions']);
        return parent::get_results(str_replace(' FOR UPDATE','',$sql));
    }
    public function replace(string $table,array $data,array $formats=[]) {
        if($table==='usermeta'&&($GLOBALS['fixtureMergeMetaFail']??'')!==''&&$data['meta_key']===$GLOBALS['fixtureMergeMetaFail'])return false;
        return parent::replace($table,$data,$formats);
    }
}
$wpdb=new MergeFixtureDB(getenv('MOTO_PHONE_FIXTURE_DB')?:':memory:');
class WP_Session_Tokens {
    private function __construct(private int $id){}
    public static function get_instance($id){return new self((int)$id);}
    public function get($token){return $GLOBALS['fixtureMergeSessions'][$this->id][$token]??null;}
    public function destroy_all(){global $fixtureMergeSessions;unset($fixtureMergeSessions[$this->id]);update_user_meta($this->id,'session_tokens','');}
}
function is_email($value){return is_string($value)&&filter_var($value,FILTER_VALIDATE_EMAIL)!==false;}
function get_current_blog_id(){return 1;}
function wp_authenticate($login,$password){
    global $wpdb,$fixtureMergeAuthFailure;
    if($fixtureMergeAuthFailure)return new WP_Error('second_factor','Fixture additional factor required');
    $q=$wpdb->db->prepare('SELECT * FROM users WHERE user_login=? OR user_email=?');$q->execute([$login,$login]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $u=$row&&password_verify($password,$row['user_pass'])?new WP_User($row):new WP_Error('credentials','Fixture invalid credentials');
    return moto_merge_auth_guard($u);
}
function clean_user_cache($id){}
function wc_get_orders($args){
    global $wpdb;$q=$wpdb->db->prepare('SELECT ID FROM fixture_orders WHERE customer_id=? ORDER BY ID LIMIT ?');$q->bindValue(1,(int)$args['customer'],PDO::PARAM_INT);$q->bindValue(2,(int)$args['limit'],PDO::PARAM_INT);$q->execute();$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    if(!empty($args['paginate'])){return (object)['orders'=>$ids,'total'=>(int)$wpdb->db->query('SELECT COUNT(*) FROM fixture_orders WHERE customer_id='.(int)$args['customer'])->fetchColumn()];}
    return $ids;
}
class MergeFixtureOrder {
    private int $customer;
    public function __construct(private int $id){global $wpdb;$this->customer=(int)$wpdb->db->query('SELECT customer_id FROM fixture_orders WHERE ID='.$id)->fetchColumn();}
    public function get_customer_id(){return $this->customer;}
    public function set_customer_id($id){$this->customer=(int)$id;}
    public function save(){global $wpdb,$fixtureMergeFailOrder;moto_merge_order_write_guard($this);$wpdb->db->exec('UPDATE fixture_orders SET customer_id='.$this->customer.' WHERE ID='.$this->id);if($fixtureMergeFailOrder===$this->id)throw new RuntimeException('Fixture order save failed');return $this->id;}
}
function wc_get_order($id){global $wpdb;return $wpdb->db->query('SELECT ID FROM fixture_orders WHERE ID='.(int)$id)->fetchColumn()?new MergeFixtureOrder((int)$id):false;}
function wc_delete_shop_order_transients($id){}
class WC_Customer_Download {
    private int $user;private string $email;
    public function __construct(private int $id){global $wpdb;$r=$wpdb->db->query('SELECT * FROM fixture_woocommerce_downloadable_product_permissions WHERE permission_id='.$id)->fetch(PDO::FETCH_ASSOC);$this->user=(int)$r['user_id'];$this->email=$r['user_email'];}
    public function get_user_id(){return $this->user;}
    public function get_user_email(){return $this->email;}
    public function set_user_id($id){$this->user=(int)$id;}
    public function set_user_email($email){$this->email=$email;}
    public function save(){global $wpdb;$q=$wpdb->db->prepare('UPDATE fixture_woocommerce_downloadable_product_permissions SET user_id=?,user_email=? WHERE permission_id=?');$q->execute([$this->user,$this->email,$this->id]);return $this->id;}
}
function merge_fixture_user($login,$email='',$password='fixture-password',$role='customer'){
    global $wpdb;$id=wp_insert_user(['user_login'=>$login,'user_email'=>$email,'role'=>$role]);$q=$wpdb->db->prepare('UPDATE users SET user_pass=? WHERE ID=?');$q->execute([password_hash($password,PASSWORD_DEFAULT),$id]);return $id;
}
function merge_fixture_pair(){
    global $wpdb,$fixtureUser,$fixtureMergeSessions,$fixtureMergeFailOrder,$fixtureMergeMetaFail,$fixtureMergeAuthFailure,$fixtureCalls,$fixtureLoginCalls;
    moto_merge_order_unlock();unset($GLOBALS['fixtureWC']);
    foreach(['options','users','usermeta','provider_ops','provider_calls','fixture_orders','fixture_woocommerce_downloadable_product_permissions','fixture_woocommerce_api_keys','fixture_wc_webhooks','fixture_woocommerce_sessions'] as $table)$wpdb->db->exec('DELETE FROM '.$table);
    $fixtureMergeFailOrder=0;$fixtureMergeMetaFail='';$fixtureMergeAuthFailure=false;$fixtureCalls=[];$fixtureLoginCalls=[];
    $source=merge_fixture_user('moto_'.str_repeat('a',32));$target=merge_fixture_user('old-buyer','buyer@example.test');
    update_user_meta($source,'_moto_verified_phone','+79991234567');update_user_meta($source,'billing_phone','+79991234567');update_user_meta($target,'billing_phone','8 (999) 123-45-67');
    update_user_meta($source,'first_name','Анна');update_user_meta($target,'billing_city','Москва');
    $fixtureUser=$source;$fixtureMergeSessions=[$source=>[wp_get_session_token()=>['login'=>time()]]];
    $wpdb->db->exec('INSERT INTO fixture_orders VALUES(101,'.$source.',"1250.50","completed","RUB"),(102,'.$source.',"500.00","processing","RUB"),(201,'.$target.',"1000.00","completed","RUB")');
    $q=$wpdb->db->prepare('INSERT INTO fixture_woocommerce_downloadable_product_permissions VALUES(1,?,?,4)');$q->execute([$source,'phone@example.test']);
    return [$source,$target];
}
