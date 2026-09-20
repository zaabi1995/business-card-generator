<?php
require_once dirname(__DIR__,2).'/includes/CompanyJoinVerification.php';
// Delivery is intercepted. No email or network transport is loaded.
class OtpService {
 public static $codes=[];
 public static $sends=[];
 public static function send($email,$channel,$purpose){ self::$sends[]=[$email,$channel,$purpose];self::$codes[$email.'|'.$purpose]='123456';return ['ok'=>true]; }
 public static function verify($email,$code,$purpose){$k=$email.'|'.$purpose;$ok=isset(self::$codes[$k])&&hash_equals(self::$codes[$k],$code);if($ok)unset(self::$codes[$k]);return ['ok'=>$ok];}
}
$fails=0;
function check($ok,$label){global $fails;if(!$ok)$fails++;echo ($ok?'PASS ':'FAIL ').$label."\n";}
$a='member@tenant-a.invalid';$b='member@tenant-b.invalid';
check(!CompanyJoinVerification::check($a,'tenant-a','')['ok'],'an unverified join only issues a challenge');
check(OtpService::$sends[0][1]==='email','proof is sent to the claimed mailbox');
check(!CompanyJoinVerification::check($a,'tenant-b','123456')['ok'],'tenant A code cannot authorize tenant B');
check(!CompanyJoinVerification::check($b,'tenant-a','123456')['ok'],'another mailbox cannot use the code');
check(!CompanyJoinVerification::check($a,'tenant-a','000000')['ok'],'wrong code cannot authorize a pending membership');
check(CompanyJoinVerification::check($a,'tenant-a','123456')['ok'],'verified mailbox can submit its intended join');
check(!CompanyJoinVerification::check($a,'tenant-a','123456')['ok'],'a consumed code cannot be replayed');
check(!CompanyJoinVerification::check('bad','tenant-a','')['ok'],'invalid mailbox is rejected');
$source=file_get_contents(dirname(__DIR__,2).'/company/register.php');
check(strpos($source,'CompanyJoinVerification::check(')<strpos($source,'$empResult = addEmployee('),'real registration calls proof gate before persistence');
check(str_contains($source,'$needsJoinVerification = true;')&&str_contains($source,"'status' => 'pending'"),'proof still requires administrator approval');
exit($fails?1:0);
