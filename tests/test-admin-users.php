<?php
define('ABSPATH',__DIR__.'/');
$admin=true;$ajax=false;$permission=true;$screen='users';
function is_admin(){return $GLOBALS['admin'];}function wp_doing_ajax(){return $GLOBALS['ajax'];}
function current_user_can($cap){return $GLOBALS['permission']&&$cap==='list_users';}
function get_current_screen(){return (object)['id'=>$GLOBALS['screen']];}
function add_action(...$args){}function add_filter(...$args){}
class SearchFixtureDB {
    public string $users='users',$usermeta='usermeta';public PDO $db;
    public function __construct(){
        $this->db=new PDO('sqlite::memory:');$this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE users (ID INTEGER PRIMARY KEY, user_login TEXT, user_email TEXT, display_name TEXT, role TEXT)');
        $this->db->exec('CREATE TABLE usermeta (user_id INTEGER, meta_key TEXT, meta_value TEXT)');
        foreach([[1,'alice','alice@example.test','Alice','customer'],[2,'bob','bob@example.test','Bob','customer'],[3,'carol','carol@example.test','Carol','administrator'],[4,'numeric9912345678','other@example.test','Other','customer']] as $row){$this->db->prepare('INSERT INTO users VALUES(?,?,?,?,?)')->execute($row);}
        foreach([[1,'_moto_verified_phone','+79912345678'],[1,'billing_phone','8 (991) 234-56-78'],[1,'shipping_phone','9912345678'],[2,'billing_phone',"+7\u{00a0}(992)\u{202f}345\u{2011}67\u{2011}89"],[3,'shipping_phone','+7 (991) 234-56-78'],[4,'not_a_phone','+79912345678']] as $row){$this->db->prepare('INSERT INTO usermeta VALUES(?,?,?)')->execute($row);}
    }
    public function esc_like($text){return addcslashes($text,'_%\\');}
    public function prepare($sql,...$args){foreach($args as $arg){$sql=preg_replace('/%s/',str_replace('$','\\$',$this->db->quote((string)$arg)),$sql,1);}return $sql;}
}
$wpdb=new SearchFixtureDB();
// Minimal native search builder, following WP_User_Query::get_search_sql.
class WP_User_Query {
    public array $query_vars=[];public string $query_where='';
    protected function get_search_sql($search,$columns,$wild=false){
        global $wpdb;$parts=[];$like=(in_array($wild,['leading','both'],true)?'%':'').$wpdb->esc_like($search).(in_array($wild,['trailing','both'],true)?'%':'');
        foreach($columns as $column)$parts[]=$wpdb->prepare($column.($column==='ID'?' = %s':' LIKE %s'),$column==='ID'?$search:$like);
        return ' AND ('.implode(' OR ',$parts).')';
    }
}
require __DIR__.'/../plugin/solartek-sms-auth/includes/admin-users.php';
$checks=0;
function check($ok,$message){global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
function search_query($search,$restriction='',array $columns=['user_login','user_email','display_name']){
    $query=new WP_User_Query();$query->query_vars=ssa_admin_users_query_args(['search'=>'*'.$search.'*']);
    ssa_admin_users_search_columns($columns,$search,$query);
    $native=(new SSA_Admin_User_Search_SQL())->native_clause($search,$columns,$query->query_vars['search']);
    $query->query_where="WHERE 1=1$restriction$native";
    ssa_admin_users_phone_search($query);return $query;
}
function ids($query,$suffix=''){
    global $wpdb;return $wpdb->db->query('SELECT users.ID FROM users '.$query->query_where.' ORDER BY users.ID'.$suffix)->fetchAll(PDO::FETCH_COLUMN);
}
foreach(['+7 (991) 234-56-78','8 991 234 56 78','79912345678','89912345678','9912345678'] as $search){
    check(ids(search_query($search))===($search==='9912345678'?[1,3,4]:[1,3]),'Full phone formats find owners');
    check(in_array(1,ids(search_query($search)),true),'Verified/billing/shipping matches deduplicated');
}
check(ids(search_query('1234'))===[1,3,4],'Partial digits preserve ordinary login match');
check(ids(search_query('8 (992) 345-67-89'))===[2],'Stored Unicode spacing and dash normalized');
check(ids(search_query('79912345678'," AND role = 'customer'"))===[1],'Phone OR does not bypass role');
check(ids(search_query('79912345678',' AND users.ID NOT IN (1)'))===[3],'Exclusion retained');
check(ids(search_query('79912345678',' AND users.ID IN (1)'))===[1],'Include retained');
$query=search_query('79912345678');
check(ids($query,' LIMIT 1 OFFSET 1')===[3],'SQL pagination retained');
check((int)$wpdb->db->query('SELECT COUNT(*) FROM users '.$query->query_where)->fetchColumn()===2,'Total has no duplicate phone rows');
foreach(['alice','bob@example.test','Carol','9912@example.test',"9912%' OR 1=1 --",'12','1234567890123456'] as $search){
    $q=search_query($search);check(!str_contains($q->query_where,'ssa_phone_meta'),'Non-phone query unchanged');
}
check(ids(search_query('alice'))===[1],'Ordinary login works');
check(ids(search_query('bob@example.test'))===[2],'Ordinary email works');
check(ids(search_query('Carol'))===[3],'Ordinary display name works');
check(ids(search_query('9912345678','',['user_login']))===[1,3,4],'Custom native columns preserved');
$q=search_query('79912345678');$once=$q->query_where;ssa_admin_users_phone_search($q);check($once===$q->query_where,'Same query cannot be expanded twice');
foreach(['admin','ajax','permission','screen'] as $guard){
    $saved=$GLOBALS[$guard];$GLOBALS[$guard]=$guard==='ajax'?true:($guard==='screen'?'dashboard':false);
    check(!str_contains(search_query('79912345678')->query_where,'ssa_phone_meta'),'Scope guard '.$guard);$GLOBALS[$guard]=$saved;
}
$q=new WP_User_Query();$q->query_vars=['search'=>'*79912345678*'];$q->query_where='WHERE 1=1';ssa_admin_users_search_columns(['user_login'],'79912345678',$q);ssa_admin_users_phone_search($q);
check($q->query_where==='WHERE 1=1','Unmarked user queries unchanged even on users screen');
$q=new WP_User_Query();$q->query_vars=ssa_admin_users_query_args(['search'=>'*79912345678*']);ssa_admin_users_search_columns(['user_login'],'79912345678',$q);$q->query_where='WHERE 1=1 AND other_plugin_filter=1';ssa_admin_users_phone_search($q);
check(!str_contains($q->query_where,'ssa_phone_meta'),'Replaced native clause fails safely');
define('REST_REQUEST',true);
check(!str_contains(search_query('79912345678')->query_where,'ssa_phone_meta'),'REST requests unchanged');
echo json_encode(['suite'=>'admin-users','checks'=>$checks,'ok'=>true]).PHP_EOL;
