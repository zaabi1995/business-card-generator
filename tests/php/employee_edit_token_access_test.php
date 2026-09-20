<?php
// Exercises the real verifier and SQL using only synthetic A/B tenants in SQLite.
require_once dirname(__DIR__, 2) . '/includes/EmployeeEditToken.php';
function dbNow($ts = null) { return gmdate('Y-m-d H:i:s', $ts ?? time()); }
function dbTs($value) { return strtotime($value . ' UTC'); }
class Database {
    private static $instance;
    public $pdo;
    public static function getInstance() { return self::$instance ?? (self::$instance = new self()); }
    public function __construct() { $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
    public function fetchOne($sql, $params) { $q=$this->pdo->prepare($sql); $q->execute($params); return $q->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function update($table,$values,$where,$params) { $sets=[]; foreach($values as $k=>$v){$sets[]="$k = :set_$k";$params['set_'.$k]=$v;} $q=$this->pdo->prepare("UPDATE $table SET ".implode(',',$sets)." WHERE $where"); $q->execute($params); }
}
$db=Database::getInstance();
$db->pdo->exec('CREATE TABLE companies(id TEXT PRIMARY KEY,status TEXT); CREATE TABLE employees(id TEXT PRIMARY KEY,company_id TEXT,status TEXT,deleted_at TEXT,name_en TEXT); CREATE TABLE employee_edit_tokens(id INTEGER PRIMARY KEY,employee_id TEXT,token_hash TEXT,expires_at TEXT,last_used_at TEXT,revoked_at TEXT)');
$db->pdo->exec("INSERT INTO companies VALUES ('tenant-a','active'),('tenant-b','active'); INSERT INTO employees VALUES ('employee-a','tenant-a','active',NULL,'Synthetic A'),('employee-b','tenant-b','active',NULL,'Synthetic B')");
$plain=bin2hex(random_bytes(20));
$q=$db->pdo->prepare('INSERT INTO employee_edit_tokens VALUES (1,?,?,?,?,NULL)');
$q->execute(['employee-a',hash('sha256',$plain),dbNow(time()+3600),null]);
$fails=0;
function check($ok,$label) { global $fails; if(!$ok)$fails++; echo ($ok?'PASS ':'FAIL ').$label."\n"; }
check((EmployeeEditToken::verify($plain)['id'] ?? null)==='employee-a','active link reads its own employee');
check((EmployeeEditToken::verify($plain)['id'] ?? null)!=='employee-b','link never changes to tenant B');
foreach(['inactive','suspended','deleted'] as $status){
 $db->pdo->exec("UPDATE employees SET status='$status' WHERE id='employee-a'");
 check(EmployeeEditToken::verify($plain)===null,'denies employee status '.$status);
}
$db->pdo->exec("UPDATE employees SET status='active',deleted_at='2026-01-01' WHERE id='employee-a'");
check(EmployeeEditToken::verify($plain)===null,'denies soft-deleted employee');
$db->pdo->exec("UPDATE employees SET deleted_at=NULL WHERE id='employee-a'; UPDATE companies SET status='suspended' WHERE id='tenant-a'");
check(EmployeeEditToken::verify($plain)===null,'denies suspended tenant');
$db->pdo->exec("UPDATE companies SET status='active' WHERE id='tenant-a'; UPDATE employees SET status='pending' WHERE id='employee-a'");
check((EmployeeEditToken::verify($plain)['id'] ?? null)==='employee-a','admin-invited pending employee can complete details');
$db->pdo->exec("UPDATE employee_edit_tokens SET revoked_at='2026-01-01' WHERE id=1");
check(EmployeeEditToken::verify($plain)===null,'explicit revocation is enforced');
$db->pdo->exec("UPDATE employee_edit_tokens SET revoked_at=NULL,expires_at='2020-01-01' WHERE id=1");
check(EmployeeEditToken::verify($plain)===null,'expired link is denied');
check(EmployeeEditToken::verify('invalid')===null,'malformed link is denied');
exit($fails?1:0);
