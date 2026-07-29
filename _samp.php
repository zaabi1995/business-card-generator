<?php
require __DIR__."/config.php";
$db=Database::getInstance();
$c=$db->fetchOne("SELECT id FROM companies WHERE slug = :s",["s"=>"otech"]);
$e=$db->fetchAll("SELECT email FROM employees WHERE company_id = :c AND status=\"active\" AND deleted_at IS NULL LIMIT 400",["c"=>$c["id"]]);
$d=[];$s=[];
foreach($e as $r){$lp=strtolower(strstr($r["email"],"@",true)); if(!$lp)continue; if(strpos($lp,".")!==false)$d[]=$lp; else $s[]=$lp;}
echo "DOTTED: ".implode(",",array_slice($d,0,3))."\nSINGLE: ".implode(",",array_slice($s,0,3))."\n";
