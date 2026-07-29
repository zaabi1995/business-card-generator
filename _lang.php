<?php
require __DIR__."/config.php";
$db=Database::getInstance();
$c=$db->fetchOne("SELECT * FROM companies WHERE slug = :s",["s"=>"kln"]);
foreach($c as $k=>$v){ if(stripos($k,"lang")!==false||stripos($k,"locale")!==false||stripos($k,"ar")===0) echo "$k = ".var_export($v,true)."\n"; }
$e=$db->fetchOne("SELECT * FROM employees WHERE company_id = :c",["c"=>$c["id"]]);
foreach($e as $k=>$v){ if($v!==null && $v!=="") echo "EMP $k = ".substr((string)$v,0,60)."\n"; }
