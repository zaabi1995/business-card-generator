<?php
/**
 * MHD ITICS QR card (pair a0c7d387): contact block as fixed columns, same method
 * as itics-contact-columns.php, at this card's 88% type size. Rows: Mob, (2nd mob),
 * Tel, (2nd tel); labels on rows 1 and 3 only, as MHD's design. Second-row codes
 * come from the stored value ("+968  NNNNNNNN") through valuePart, no padding.
 * php itics-qr-contact-columns.php [--apply] [json overrides]
 */
require '/www/wwwroot/cardify.om/config.php';
require_once '/www/wwwroot/cardify.om/includes/Database.php';
$db=Database::getInstance(); $apply=in_array('--apply',$argv);
$o=[]; foreach($argv as $a) if($a!=='' && $a[0]==='{') $o=json_decode($a,true)?:[];
$PAIR='a0c7d387-26de-4978-b12c-078e210bbc16';
$FONT='/www/wwwroot/cardify.om/uploads/templates/imports/mhd-itics-qr-v1/fonts/FrutigerLTStd-Roman.ttf';
$F=array_merge(['y0'=>387.36,'P'=>31.04,'L'=>58.6,'C'=>127.05,'N'=>191.65,'EY'=>511.54],$o['front']??[]);
$B=array_merge(['y0'=>381.67,'P'=>31.04,'R'=>1001.5,'COL'=>937.0,'NUM'=>918.0,'L'=>746.0,'EY'=>509.83],$o['back']??[]);
$rows=[['mobile','Mob:','نقال',false],['mobile_2',null,null,true],['phone','Tel:','هاتف',false],['phone_2',null,null,true]];
$out=[];
foreach($db->fetchAll("SELECT * FROM templates WHERE pair_id=:p AND deleted_at IS NULL",['p'=>$PAIR]) as $t){
  $side=$t['side']; $f=json_decode($t['fields_json'],true);
  foreach($f as $k=>$v) if(preg_match('/^(lbl|colon|code)_\d+$/',$k) || (is_array($v)&&!empty($v['is_static'])&&($v['y']??0)>=375&&($v['y']??0)<=505)) unset($f[$k]);
  foreach($rows as $i=>[$base,$en,$ar,$bound]){
    $n=$i+1;
    if($side==='front'){
      $g=$F; $y=round($g['y0']+$i*$g['P'],2);
      $t0=['enabled'=>true,'render_in_bg'=>false,'label'=>null,'height'=>31.2,'fontSize'=>24.9,'fontFamily'=>'FrutigerLTStd','fontWeight'=>400,'italic'=>false,
           'fontStyle'=>'normal','originY'=>'top','autoShrink'=>false,'fontPath'=>$FONT,'textAlign'=>'left','originX'=>'left','fill'=>'#4072a6','color'=>'#4072a6'];
      if($en) $f["lbl_$n"]=$t0+['is_static'=>true,'anchorBox'=>true,'detected_text'=>$en,'x'=>$g['L'],'y'=>$y,'width'=>$g['C']-$g['L']-3];
      $f["code_$n"]=$bound ? $t0+['is_static'=>false,'bind'=>$base,'valuePart'=>'code','detected_text'=>'','x'=>$g['C'],'y'=>$y,'width'=>60]
                           : $t0+['is_static'=>true,'anchorBox'=>true,'detected_text'=>'+968','x'=>$g['C'],'y'=>$y,'width'=>60];
      $f[$base]=array_merge($f[$base],['x'=>$g['N'],'y'=>$y,'width'=>200,'textAlign'=>'left','originX'=>'left','autoShrink'=>false],$bound?['valuePart'=>'number']:[]);
    } else {
      $g=$B; $y=round($g['y0']+$i*$g['P'],2); $k=$base.'_ar';
      $a0=['enabled'=>true,'render_in_bg'=>false,'label'=>null,'height'=>40.1,'fontSize'=>25.08,'fontFamily'=>'FrutigerLTArabic','fontWeight'=>400,'italic'=>false,
           'fontStyle'=>'normal','originY'=>'top','autoShrink'=>false,'webDy'=>2.6,'fill'=>'#4072a6','color'=>'#4072a6'];
      if($ar){ $f["lbl_$n"]=$a0+['is_static'=>true,'anchorBox'=>true,'detected_text'=>$ar,'x'=>$g['R']-110+1,'y'=>$y,'width'=>110,'textAlign'=>'right','originX'=>'right'];
               $f["colon_$n"]=array_merge($a0,['is_static'=>true,'anchorBox'=>true,'detected_text'=>"\u{061C}:",'x'=>$g['COL']-18,'y'=>$y,'width'=>18,'textAlign'=>'right','originX'=>'right','webDy'=>7.6]); }
      $f["code_$n"]=$bound ? $a0+['is_static'=>false,'bind'=>$k,'valuePart'=>'code','detected_text'=>'','x'=>$g['L'],'y'=>$y,'width'=>80,'textAlign'=>'left','originX'=>'left']
                           : $a0+['is_static'=>true,'anchorBox'=>true,'bidi'=>'ltr','detected_text'=>"\u{202D}+٩٦٨\u{202C}",'x'=>$g['L'],'y'=>$y,'width'=>80,'textAlign'=>'left','originX'=>'left'];
      $f[$k]=array_merge($f[$k],$a0,['is_static'=>false,'x'=>$g['NUM']-125,'y'=>$y,'width'=>125,'textAlign'=>'right','originX'=>'right','detected_text'=>'',
                                    'fill'=>$f[$k]['fill']??'#3e71a5','color'=>$f[$k]['color']??'#3e71a5'],$bound?['valuePart'=>'number']:[]);
    }
  }
  if($side==='front') $f['email']['y']=$F['EY']; else $f['email']['y']=$B['EY'];
  $bg=str_replace('bg-v9-','bg-v10-',$t['background_image_path']);
  $out[$t['id']]=[$f,$bg,$t];
}
if(!$apply){ echo "dry run ".count($out)." sides\n"; exit; }
foreach($out as $id=>[$f,$bg,$t]){
  if(strpos($t['background_image_path'],'bg-v9-')!==false || strpos($t['background_image_path'],'bg-v10-')!==false)
    file_put_contents("/www/wwwroot/cardify.om/private/backups/itics-qr-cols-$id-".date('Ymd-His').'.json',json_encode(['fields_json'=>$t['fields_json'],'background_image_path'=>$t['background_image_path']],JSON_UNESCAPED_UNICODE));
  $db->query("UPDATE templates SET fields_json=?, background_image_path=?, current_version=current_version+1 WHERE id=?",[json_encode($f,JSON_UNESCAPED_UNICODE),$bg,$id]);
  echo "applied $id {$t['side']} ".basename($bg)."\n";
}
