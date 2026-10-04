<?php
// MHD Consumer Division (ITICS) back: contact block as fixed columns, equal pitch.
// Labels right-aligned on one x, colons on one x, numbers right-aligned on one x,
// codes left-aligned on one x, email + www right-aligned on the block's right edge.
// Usage: php columns-back.php <json overrides> [--apply]
require '/www/wwwroot/cardify.om/config.php';
require_once '/www/wwwroot/cardify.om/includes/Database.php';
$db=Database::getInstance();
$apply=in_array('--apply',$argv);
$o=json_decode($argv[1]??'{}',true)?:[];
$P   = $o['P']   ?? 36.5;     // line pitch, = address pitch
$Y0  = $o['Y0']  ?? 410.31;   // row 1 box top (baseline 425.5)
$R   = $o['R']   ?? 365.5;    // block right edge (labels, address, email, www)
$COL = $o['COL'] ?? 293.5;    // colon column right edge
$NUM = $o['NUM'] ?? 268.0;    // number column right edge
$L   = $o['L']   ?? 61.5;     // code column left edge (= address left edge)
$EY  = $o['EY']  ?? 550.31;   // email box top
$WY  = $o['WY']  ?? 587.6;    // www box top
$DR  = $o['DR']  ?? 1001.0;   // division/entity right edge
$dx  = $o['dx']  ?? [];       // per-field x nudges for ink side bearings
$TID='2f0627d8-d801-4c56-88f9-dfeb5c003c32';
$t=$db->fetchOne("SELECT fields_json,background_image_path FROM templates WHERE id=:i",['i'=>$TID]);
@mkdir('/www/wwwroot/cardify.om/private/backups',0750,true);
$bk='/www/wwwroot/cardify.om/private/backups/consumer-itics-back-'.date('Ymd-His').'.json';
file_put_contents($bk,$t['fields_json']);
$f=json_decode($t['fields_json'],true);
foreach (['static_7','static_8','static_9','static_10','static_13'] as $k) unset($f[$k]);
$ar=['webDy'=>3.0,'enabled'=>true,'render_in_bg'=>false,'label'=>null,'height'=>44,'fontSize'=>29,'fontFamily'=>'FrutigerLTArabic',
     'fontWeight'=>400,'italic'=>false,'fontStyle'=>'normal','fill'=>'#3e71a5','color'=>'#3e71a5','originY'=>'top','autoShrink'=>false];
$cell=function($static,$text,$x,$w,$y,$align,$extra=[]) use($ar){
  return array_merge($ar,['is_static'=>$static,'detected_text'=>$text,'x'=>$x,'y'=>$y,'width'=>$w,
    'textAlign'=>$align,'originX'=>$align],$static?['anchorBox'=>true]:[],$extra); };
$labels=['هاتف','فاكس','نقال','نقال'];
$nums=['phone_ar','fax_ar','mobile_2_ar','mobile_ar'];
for($i=0;$i<4;$i++){
  $y=round($Y0+$i*$P,2);
  $n=$i+1;
  $f["lbl_$n"]   = $cell(true,$labels[$i], $R-130+($dx["lbl_$n"]??0),130,$y,'right');
  $f["colon_$n"] = $cell(true,"\u{061C}:", $COL-20+($dx["colon_$n"]??0),20,$y,'right',["webDy"=>8.75]);
  $k=$nums[$i];
  $f[$k] = $cell(false,'', $NUM-140+($dx[$k]??0),140,$y,'right', $k==='mobile_2_ar'?['valuePart'=>'number']:[]);
  if ($i===2) $f["code_$n"] = $cell(false,'', $L+($dx["code_$n"]??0),90,$y,'left',['bind'=>'mobile_2_ar','valuePart'=>'code']);
  else        $f["code_$n"] = $cell(true,"\u{202D}+٩٦٨\u{202C}", $L+($dx["code_$n"]??0),90,$y,'left',['bidi'=>'ltr']);
}
$lat=['enabled'=>true,'render_in_bg'=>false,'label'=>null,'fontFamily'=>'FrutigerLTStd','fontWeight'=>400,'italic'=>false,'fontStyle'=>'normal',
      'originY'=>'top','autoShrink'=>false,'fontPath'=>'/www/wwwroot/cardify.om/uploads/templates/imports/mhd-clean-v2/fonts/FrutigerLTStd-Roman.ttf'];
$f['email']=array_merge($lat,['is_static'=>false,'detected_text'=>'','x'=>$L,'y'=>$EY,'width'=>$R-$L+($dx['email']??0),'height'=>35,'fontSize'=>29,
   'fill'=>'#3e71a5','color'=>'#3e71a5','textAlign'=>'right','originX'=>'right']);
$f['www']=array_merge($lat,['is_static'=>true,'anchorBox'=>true,'detected_text'=>'www.mhditics.com','x'=>$L,'y'=>$WY,'width'=>$R-$L+($dx['www']??0),'height'=>34,'fontSize'=>28,
   'fill'=>'#4072a6','color'=>'#4072a6','textAlign'=>'right','originX'=>'right']);
foreach (['division_ar','entity_ar'] as $k){ $f[$k]['anchorBox']=true; $f[$k]['x']=$DR-440+($dx[$k]??0); $f[$k]['width']=440; $f[$k]['textAlign']='right'; $f[$k]['originX']='right'; }
if(!$apply){ echo json_encode(array_intersect_key($f,array_flip(['lbl_3','colon_3','mobile_2_ar','code_3','email','www','division_ar'])),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\nbackup $bk\n"; exit; }
$db->query("UPDATE templates SET fields_json=?, background_image_path=?, current_version=current_version+1 WHERE id=?",
  [json_encode($f,JSON_UNESCAPED_UNICODE),'/uploads/templates/imports/mhd-clean-v2/bg-telfaxmob2-v2-page-2.png',$TID]);
// stored value: one plain "+code number", no padding, no bidi marks (the field adds isolation)
$db->query("UPDATE employees SET mobile_2_ar=? WHERE id='aliakbar'",['+٩٧٣ ٣٨٤٥٦٤١٥']);
echo "applied, backup $bk\n";
