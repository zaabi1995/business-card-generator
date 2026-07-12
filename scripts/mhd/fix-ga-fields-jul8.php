<?php
require_once '/www/wwwroot/cardify.om/config.php';
$db=Database::getInstance(); $CID='a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';
$GA=['MHD Itics','MHD Ipd','MHD Tech Comm','MHD Healthcare','MHD Office Products','MHD Infrastructure','MHD Building Materials'];
$BUMP=7; // px: dynamic/static digits ride high vs baked labels
foreach($GA as $name){
  foreach(['front','back'] as $side){
    $tpl=$db->fetchOne("SELECT id,fields_json FROM templates WHERE company_id=:c AND name=:n AND side=:s",['c'=>$CID,'n'=>$name,'s'=>$side]);
    if(!$tpl) { echo "MISS $name $side\n"; continue; }
    $f=json_decode($tpl['fields_json'],true);
    $chg=[];
    foreach(['tel1','tel2','fax','mobile','tel1_ar','tel2_ar','fax_ar','mobile_ar'] as $k){
      if(isset($f[$k]['y'])){ $f[$k]['y']+=$BUMP; $chg[]=$k; }
    }
    if($side==='front' && isset($f['qr_code'])){
      $f['qr_code']=['enabled'=>false,'x'=>82,'y'=>408,'size'=>125]; $chg[]='qr_slot';
    }
    if($name==='MHD Itics'){
      if($side==='front'){
        if(isset($f['tel1'])){ $f['tel1']['detected_text']='24732500'; $chg[]='tel1=24732500'; }
        if(isset($f['fax'])){ $f['fax']['detected_text']='24793256'; $chg[]='fax=24793256'; }
      } else {
        if(isset($f['tel1_ar'])){ $f['tel1_ar']['detected_text']='٢٤٧٣٢٥٠٠'; $chg[]='tel1_ar'; }
        if(isset($f['fax_ar'])){ $f['fax_ar']['detected_text']='٢٤٧٩٣٢٥٦'; $chg[]='fax_ar'; }
      }
    }
    if($side==='front' && $name==='MHD Tech Comm'){
      foreach($f as $k=>&$v){ if(!empty($v['is_static']) && isset($v['detected_text']) && stripos($v['detected_text'],'Technology & Communications')!==false){ $v['detected_text']='TECHNOLOGY & COMMUNICATIONS'; $chg[]="$k=CAPS"; } } unset($v);
    }
    if($side==='front' && $name==='MHD Healthcare'){
      foreach($f as $k=>&$v){ if(!empty($v['is_static']) && isset($v['detected_text']) && stripos($v['detected_text'],'Healthcare')!==false && stripos($v['detected_text'],'Darwish')===false){ $v['detected_text']='HEALTHCARE'; $chg[]="$k=HEALTHCARE"; } } unset($v);
    }
    $db->query("UPDATE templates SET fields_json=:fj, current_version=current_version+1 WHERE id=:i",
      ['fj'=>json_encode($f,JSON_UNESCAPED_UNICODE),'i'=>$tpl['id']]);
    echo "$name $side: ".implode(',',$chg)."\n";
  }
}
// automotive front: dynamic mobile digits ~2pt high vs baked "Mob: +968"
$tpl=$db->fetchOne("SELECT id,fields_json FROM templates WHERE company_id=:c AND name='MHD Automotive' AND side='front'",['c'=>$CID]);
$f=json_decode($tpl['fields_json'],true); $f['mobile']['y']+=8;
$db->query("UPDATE templates SET fields_json=:fj, current_version=current_version+1 WHERE id=:i",['fj'=>json_encode($f,JSON_UNESCAPED_UNICODE),'i'=>$tpl['id']]);
echo "MHD Automotive front: mobile+8\n";
// logistics bg version bump (bar fix)
$db->query("UPDATE templates SET current_version=current_version+1 WHERE company_id=:c AND name='MHD Logistics'",['c'=>$CID]);
echo "logistics bumped\n";
