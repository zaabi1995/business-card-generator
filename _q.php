<?php
ini_set("display_errors","1"); error_reporting(E_ALL);
require __DIR__."/config.php";
$db=Database::getInstance();
$c=$db->fetchOne("SELECT * FROM companies WHERE slug = :s",["s"=>"kln"]);
echo "COMPANY id={$c["id"]} name=".($c["name"]??"")."\n";
$e=$db->fetchAll("SELECT id,email,name_en,status FROM employees WHERE company_id = :c",["c"=>$c["id"]]);
foreach($e as $r){ echo "EMP {$r["id"]} | {$r["email"]} | {$r["name_en"]} | {$r["status"]}\n"; }
echo "count=".count($e)."\n";
