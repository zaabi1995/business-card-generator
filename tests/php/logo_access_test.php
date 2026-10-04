<?php
// Integration tests against an isolated, disposable local MySQL database.
// No email is sent and no payment gateway is called.
define('CARDIFY_LOGO_ACCESS_TEST', true);
define('PAYMOB_HMAC_SECRET', 'logo-access-test-only-not-a-real-secret');
ob_start();
session_start();
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
$_GET = [];
function generateUUID(): string { $h = bin2hex(random_bytes(16)); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function getClientIp(): string { return $_SERVER['REMOTE_ADDR']; }
class Database
{
    public static $instance;
    public PDO $pdo;
    public static function getInstance(): self { return self::$instance; }
    public function getConnection(): PDO { return $this->pdo; }
    public function fetchOne($sql, $params = []) { $s = $this->pdo->prepare($sql); $s->execute($params); return $s->fetch(PDO::FETCH_ASSOC) ?: null; }
    public function insert($table, $values) { $columns = array_keys($values); $s = $this->pdo->prepare('INSERT INTO ' . $table . ' (`' . implode('`,`',$columns) . '`) VALUES (' . implode(',', array_fill(0,count($columns),'?')) . ')'); $s->execute(array_values($values)); }
}
$dsn = getenv('LOGO_TEST_MYSQL_DSN') ?: 'mysql:unix_socket=/tmp/mysql.sock;charset=utf8mb4';
$username = getenv('LOGO_TEST_MYSQL_USER') ?: 'root';
$password = getenv('LOGO_TEST_MYSQL_PASSWORD') ?: '';
$connect = static fn() => new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo = $connect();
$schema = 'cardify_logo_access_test_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$schema`");
Database::$instance = new Database(); Database::$instance->pdo = $pdo;
$root = dirname(__DIR__, 2);
$checks = 0;
$parentPid = getmypid();
function checkLogo(bool $value, string $message): void { global $checks; $checks++; if (!$value) throw new RuntimeException($message); echo "PASS $message\n"; }
function resetLogoMember(): void { foreach (['memberResolved' => false, 'cachedMember' => null] as $key => $value) { $r = new ReflectionProperty(LogoAccess::class, $key); $r->setValue(null, $value); } }
try {
    $pdo->exec("CREATE TABLE payments (id VARCHAR(36) PRIMARY KEY, company_id VARCHAR(36) NOT NULL, type ENUM('subscription','print_order','card_order') NOT NULL, reference_id VARCHAR(36), amount DECIMAL(10,3), currency VARCHAR(3), special_reference VARCHAR(255), paymob_order_id VARCHAR(255), paymob_transaction_id VARCHAR(255), payment_method VARCHAR(50), callback_data JSON, status VARCHAR(20))");
    require $root . '/database/migrations/174_logo_access.php';
    require $root . '/includes/LogoAccess.php';
    require $root . '/includes/Payment.php';
    checkLogo(LogoAccess::period() === (new DateTimeImmutable('now',new DateTimeZone('Asia/Muscat')))->format('Y-m-d'), 'quota uses the Oman calendar day');
    for ($i=1;$i<=5;$i++) checkLogo((bool)LogoAccess::issueTicket($i,'svg'), "guest entity $i allowed");
    checkLogo(LogoAccess::issueTicket(6,'svg') === null, 'sixth distinct guest entity blocked');
    $ticket = LogoAccess::issueTicket(1,'png_2048');
    checkLogo((bool)$ticket && LogoAccess::state()['used'] === 5, 'another format of the same entity is free');
    checkLogo(!LogoAccess::consumeTicket($ticket,1,'svg'), 'ticket format cannot be substituted');
    checkLogo(LogoAccess::consumeTicket($ticket,1,'png_2048'), 'matching ticket succeeds');
    checkLogo(!LogoAccess::consumeTicket($ticket,1,'png_2048'), 'ticket is single use');
    $ticket = LogoAccess::issueTicket(1,'svg');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.11';
    checkLogo(!LogoAccess::consumeTicket($ticket,1,'svg'), 'ticket cannot be moved to another guest');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    checkLogo(LogoAccess::consumeTicket($ticket,1,'svg'), 'ownership failure did not consume the real ticket');
    $member = LogoAccess::ensureMember('fixture@example.invalid');
    LogoAccess::signIn($member);
    checkLogo(LogoAccess::state()['limit'] === 10 && LogoAccess::state()['used'] === 5, 'registration carries five selected logos into the ten-logo allowance');
    for ($i=6;$i<=10;$i++) checkLogo((bool)LogoAccess::issueTicket($i,'svg'), "registered entity $i allowed");
    checkLogo(LogoAccess::issueTicket(11,'svg') === null, 'eleventh distinct free-account entity blocked');
    resetLogoMember();
    checkLogo(LogoAccess::state()['registered'], 'remembered account restores without a Cardify admin session');
    checkLogo(!Auth::isLoggedIn(), 'logo signup does not create an admin login');
    checkLogo(!LogoAccess::reward('forged') && LogoAccess::state()['rewardedUnit'] === '', 'unconfigured rewarded ads cannot grant downloads');

    $orderId = generateUUID(); $paymentId = generateUUID();
    Database::$instance->insert('logo_pass_orders',['id'=>$orderId,'member_id'=>$member['id'],'payment_id'=>$paymentId,'amount'=>2.000,'days'=>30]);
    Database::$instance->insert('payments',['id'=>$paymentId,'type'=>'logo_pass','reference_id'=>$orderId,'amount'=>2.000,'currency'=>'OMR','special_reference'=>'LOGO_TEST','paymob_order_id'=>'test-order-1','status'=>'pending']);
    $callback = ['id'=>'test-transaction-1','merchant_order_id'=>'LOGO_TEST','order'=>'test-order-1','amount_cents'=>2000,'currency'=>'OMR','success'=>true,'pending'=>false,'is_auth'=>false,'is_refunded'=>false,'is_voided'=>false];
    $run = static function($data, $signature = null) { return Payment::handleCallback($data, $signature ?? Payment::computeHmac($data,PAYMOB_HMAC_SECRET)); };
    checkLogo(!$run($callback,'invalid')['success'] && !LogoAccess::paid(), 'invalid HMAC cannot activate paid access');
    $bad = $callback; $bad['order']='other-order'; checkLogo(!$run($bad)['success'], 'signed callback from a different order is refused');
    $bad = $callback; $bad['amount_cents']=1; checkLogo(!$run($bad)['success'], 'wrong payment amount is refused');
    $bad = $callback; $bad['currency']='USD'; checkLogo(!$run($bad)['success'], 'wrong payment currency is refused');
    $bad = $callback; $bad['pending']=true; checkLogo($run($bad)['status']==='pending' && !LogoAccess::paid(), 'pending payment does not activate access');
    $bad = $callback; $bad['is_auth']=true; checkLogo($run($bad)['status']==='pending' && !LogoAccess::paid(), 'authorization without settlement does not activate access');
    $bad = $callback; $bad['success']=false; checkLogo($run($bad)['status']==='failed' && !LogoAccess::paid(), 'declined payment does not activate access');
    checkLogo($run($callback)['status']==='paid' && LogoAccess::paid(), 'settled signed payment activates the pass');
    $expiry = LogoAccess::member()['paid_until'];
    checkLogo($run($callback)['idempotent'] && LogoAccess::member()['paid_until']===$expiry, 'repeat callback cannot extend the pass twice');
    for ($i=11;$i<=40;$i++) checkLogo((bool)LogoAccess::issueTicket($i,'svg'), "paid entity $i allowed beyond the free limit");
    checkLogo(LogoAccess::state()['used']===10, 'paid downloads do not consume the free allowance');
    $refund=$callback; $refund['is_refunded']=true;
    checkLogo($run($refund)['status']==='refunded' && !LogoAccess::paid(), 'refund revokes the current pass');
    checkLogo($run($callback)['status']!=='paid' && !LogoAccess::paid(), 'replaying success after refund cannot reactivate access');
    checkLogo(LogoAccess::issueTicket(41,'svg')===null, 'expired or refunded account has its normal limit again');
    LogoAccess::signOut(); checkLogo(!LogoAccess::state()['registered'], 'signout revokes the remembered account token');

    // Independent connections race the same guest quota, not the PHP session lock.
    $_SERVER['REMOTE_ADDR']='192.0.2.99'; resetLogoMember();
    $results=[];
    if (function_exists('pcntl_fork')) {
        $files=[]; $children=[];
        for ($i=1;$i<=12;$i++) {
            $file=tempnam(sys_get_temp_dir(),'logo-quota-test-'); $files[]=$file;
            $pid=pcntl_fork();
            if ($pid===0) {
                ob_end_clean();
                try {
                    Database::$instance->pdo=$connect(); Database::$instance->pdo->exec("USE `$schema`");
                    $ok=LogoAccess::issueTicket($i,'svg'); file_put_contents($file,$ok?'1':'0'); exit(0);
                } catch (Throwable $e) { file_put_contents($file,'ERROR: '.$e->getMessage()); exit(1); }
            }
            $children[]=$pid;
        }
        foreach ($children as $pid) pcntl_waitpid($pid,$status);
        foreach ($files as $file) { $results[]=file_get_contents($file); unlink($file); }
        $pdo=$connect(); $pdo->exec("USE `$schema`"); Database::$instance->pdo=$pdo;
        checkLogo(!array_filter($results,static fn($v)=>str_starts_with($v,'ERROR:')), 'concurrent quota requests complete without lock errors');
        checkLogo(count(array_filter($results,static fn($v)=>$v==='1'))===5, 'twelve concurrent requests cannot exceed the five-logo guest cap');
    }
    echo "ALL PASS: $checks checks\n";
} finally {
    if (getmypid()===$parentPid) { $cleanup=$connect(); $cleanup->exec("DROP DATABASE IF EXISTS `$schema`"); }
}
