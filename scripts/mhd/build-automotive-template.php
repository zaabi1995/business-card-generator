<?php
require_once '/www/wwwroot/cardify.om/config.php';
$db=Database::getInstance(); $CID='a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';
$IMPORT='/uploads/templates/imports/mhd-automotive-v1';
try {
  $ref=$db->fetchOne("SELECT * FROM templates WHERE id='723b11ee-5a40-4cf0-b99e-73b1ea35b6ea'");
  $settings=json_encode(['cardSize'=>'custom','customWidth'=>90.0,'customHeight'=>55.0,'customUnit'=>'mm','dpi'=>300,'width_pt'=>255.1,'height_pt'=>155.9,'qr_area'=>null,'fonts_used'=>['FrutigerLTStd','FrutigerLTArabic'],'imported_from'=>'pdf','import_token'=>'mhd-automotive-v1']);
  foreach(['created_at','updated_at','deleted_at'] as $c) unset($ref[$c]);
  $pair=generateUUID();
  foreach(['front'=>'/tmp/auto-front.json','back'=>'/tmp/auto-back.json'] as $side=>$fj){
    $row=$ref;
    $row['id']=generateUUID(); $row['company_id']=$CID; $row['pair_id']=$pair; $row['side']=$side;
    $row['name']='MHD Automotive'; $row['fields_json']=file_get_contents($fj);
    $row['settings_json']=$settings;
    $row['background_image_path']="$IMPORT/bg-page-".($side==='front'?1:2).".png";
    $row['fonts_dir']="$IMPORT/fonts"; $row['has_vector_source']=1; $row['current_version']=1;
    $db->insert('templates',$row);
    echo "created automotive $side {$row['id']}\n";
  }
  $db->update('departments',['template_pair_id'=>$pair],'company_id=:c AND slug=:s',['c'=>$CID,'s'=>'automotive']);
  echo "linked automotive -> pair $pair\n";
} catch (\Throwable $e){ echo "EXC: ".$e->getMessage()."\n"; }
