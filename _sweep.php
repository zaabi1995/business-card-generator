<?php
require __DIR__."/config.php";
require_once __DIR__."/includes/CardifyConvention.php";
$db=Database::getInstance();
$cos=$db->fetchAll("SELECT id,slug,name,status FROM companies ORDER BY slug",[]);
$out=[];
foreach($cos as $c){
  $e=$db->fetchAll("SELECT id,email FROM employees WHERE company_id = :c AND status=\"active\" AND deleted_at IS NULL",["c"=>$c["id"]]);
  foreach($e as $r){
    $lp=strtolower((string)strstr((string)$r["email"],"@",true));
    $lp=preg_replace("/[^a-z0-9._-]/","",$lp);
    $tok = $lp!=="" ? $lp : $r["id"];
    $out[]=$c["slug"]."\t".$tok;
  }
}
file_put_contents("/root/cards.tsv",implode("\n",$out)."\n");
echo count($out)." card urls\n";
