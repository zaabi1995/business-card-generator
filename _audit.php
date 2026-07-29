<?php
ini_set("display_errors","1"); error_reporting(E_ALL);
require __DIR__."/config.php";
$db=Database::getInstance();
$cos=$db->fetchAll("SELECT id,slug,name,status FROM companies ORDER BY slug",[]);
$totB=0;$totOK=0;
foreach($cos as $c){
  $e=$db->fetchAll("SELECT email FROM employees WHERE company_id = :c AND status='active' AND deleted_at IS NULL",["c"=>$c["id"]]);
  $b=0;$ok=0;
  foreach($e as $r){
    $lp=strtolower(substr((string)$r["email"],0,strpos((string)$r["email"],"@")?:strlen((string)$r["email"])));
    $lp=preg_replace("/[^a-z0-9._-]/","",$lp);
    if($lp==="") continue;
    if(strpos($lp,".")!==false) $ok++; else $b++;
  }
  $totB+=$b;$totOK+=$ok;
  printf("%-22s %-30s emp=%3d dotted_ok=%3d single_BROKEN=%3d\n",$c["slug"],substr($c["name"],0,28),count($e),$ok,$b);
}
echo "\nTOTAL dotted_ok=$totOK single_BROKEN=$totB\n";
