<?php
/**
 * MHD ITICS cards: contact block as fixed columns (Ali, 4 Oct 2026: "Fix it all").
 *
 * Root cause it removes: the labels and +968 codes were baked into the
 * background from MHD's own artwork, each row its own run at its own x, rows on
 * uneven pitch, and numbers anchored on the side that moves with the digits.
 * Content does not change, only positions:
 *   front (EN): label column left, code column left, number column left,
 *               email + www left on the label edge, one pitch from address to www
 *   back  (AR): label column right, colon column, number column right,
 *               code column left, email + www right on the block edge
 * Rows keep their order and fill the LAST slots of the 4-row grid, so a 1-Tel
 * card keeps MHD's blank line under the address.
 *
 * php itics-contact-columns.php <pair_id> [--apply] [--code-bind=mobile_2,phone_2]
 */
require '/www/wwwroot/cardify.om/config.php';
require_once '/www/wwwroot/cardify.om/includes/Database.php';
$db=Database::getInstance();
$pair=$argv[1]??''; $apply=in_array('--apply',$argv);
$bind=[]; foreach($argv as $a) if(strpos($a,'--code-bind=')===0) $bind=array_filter(explode(',',substr($a,12)));
$FONT_EN='/www/wwwroot/cardify.om/uploads/templates/imports/mhd-clean-v2/fonts/FrutigerLTStd-Roman.ttf';
// Grid, measured on the shared address lines (1200 dpi): 36.5 px pitch.
$G=['front'=>['slot0'=>405.81,'P'=>36.0,'L'=>643.65,'C'=>719.4,'N'=>790.9,'EY'=>549.75,'WY'=>585.75],
    'back' =>['slot0'=>404.31,'P'=>36.5,'R'=>365.5,'COL'=>290.5,'NUM'=>268.0,'L'=>61.5,'EY'=>549.61,'WY'=>586.85]];
$LBL=['phone'=>['Tel:','هاتف'],'phone_2'=>['Tel:','هاتف'],'fax'=>['Fax:','فاكس'],'mobile'=>['Mob:','نقال'],'mobile_2'=>['Mob:','نقال']];
$rows=[]; $out=[];
foreach($db->fetchAll("SELECT * FROM templates WHERE pair_id=:p AND deleted_at IS NULL",['p'=>$pair]) as $t){
  $side=$t['side']; $g=$G[$side]; $f=json_decode($t['fields_json'],true);
  $bgOld=$t['background_image_path'];
  $bgNew=strpos($bgOld,'bg-1tel-')!==false ? str_replace(basename($bgOld),"bg-1tel-cols-v1-page-".($side==='front'?1:2).".png",$bgOld)
                                            : str_replace(basename($bgOld),"bg-cols-v1-page-".($side==='front'?1:2).".png",$bgOld);
  // contact rows in their current order
  $sfx=$side==='back'?'_ar':'';
  $r=[]; foreach($f as $k=>$v){ if(!is_array($v)||!($v['enabled']??true)) continue;
    if(preg_match('/^(phone|phone_2|fax|mobile|mobile_2)'.$sfx.'$/',$k)) $r[]=[preg_replace('/_ar$/','',$k),$k,(float)$v['y']]; }
  usort($r,fn($a,$b)=>$a[2]<=>$b[2]);
  // drop baked contact decorations (they live in the old bg) and any earlier column fields
  foreach($f as $k=>$v){ if(!is_array($v)) continue;
    $inBlock=($v['y']??0)>=398 && ($v['y']??0)<=626 && ($side==='front' ? ($v['x']??0)>=600 : ($v['x']??0)<=372);
    if((!empty($v['is_static']) && $inBlock) || preg_match('/^(lbl|colon|code)_\d+$/',$k)) unset($f[$k]); }
  $first=4-count($r);
  foreach($r as $i=>[$base,$key,$y]){
    $n=$i+1; $y=round($g['slot0']+($first+$i)*$g['P'],2);
    $bound=in_array($base,$bind,true);
    // optional rows (2nd tel / 2nd mob): label, colon and code print only with a number
    $opt=in_array($base,['phone_2','mobile_2'],true) ? ['showIf'=>$key] : [];
    if($side==='front'){
      $txt=['enabled'=>true,'render_in_bg'=>false,'label'=>null,'height'=>34,'fontSize'=>28,'fontFamily'=>'FrutigerLTStd','fontWeight'=>400,
            'italic'=>false,'fontStyle'=>'normal','originY'=>'top','autoShrink'=>false,'fontPath'=>$FONT_EN,'textAlign'=>'left','originX'=>'left'];
      $f["lbl_$n"]=$txt+$opt+['is_static'=>true,'anchorBox'=>true,'detected_text'=>$LBL[$base][0],'x'=>$g['L'],'y'=>$y,'width'=>$g['C']-$g['L']-4,'fill'=>'#4072a6','color'=>'#4072a6'];
      $f["code_$n"]=$bound ? $txt+['is_static'=>false,'bind'=>$key,'valuePart'=>'code','detected_text'=>'','x'=>$g['C'],'y'=>$y,'width'=>66,'fill'=>'#4072a6','color'=>'#4072a6']
                           : $txt+$opt+['is_static'=>true,'anchorBox'=>true,'detected_text'=>'+968','x'=>$g['C'],'y'=>$y,'width'=>66,'fill'=>'#4072a6','color'=>'#4072a6'];
      $old=$f[$key]; unset($old['baselineFactor']);
      $f[$key]=array_merge($old,['x'=>$g['N'],'y'=>$y,'width'=>200,'textAlign'=>'left','originX'=>'left','autoShrink'=>false,'detected_text'=>'',
                                 'fill'=>'#3e71a5','color'=>'#3e71a5'], $bound?['valuePart'=>'number']:[]);
    } else {
      $ar=['enabled'=>true,'render_in_bg'=>false,'label'=>null,'height'=>44,'fontSize'=>29,'fontFamily'=>'FrutigerLTArabic','fontWeight'=>400,
           'italic'=>false,'fontStyle'=>'normal','originY'=>'top','autoShrink'=>false,'webDy'=>3.0];
      $f["lbl_$n"]=$ar+$opt+['is_static'=>true,'anchorBox'=>true,'detected_text'=>$LBL[$base][1],'x'=>$g['R']-130+1,'y'=>$y,'width'=>130,'textAlign'=>'right','originX'=>'right','fill'=>'#4072a6','color'=>'#4072a6'];
      $f["colon_$n"]=array_merge($ar,$opt,['is_static'=>true,'anchorBox'=>true,'detected_text'=>"\u{061C}:",'x'=>$g['COL']-20,'y'=>$y,'width'=>20,'textAlign'=>'right','originX'=>'right','fill'=>'#4072a6','color'=>'#4072a6','webDy'=>8.75]);
      $f["code_$n"]=$bound ? $ar+['is_static'=>false,'bind'=>$key,'valuePart'=>'code','detected_text'=>'','x'=>$g['L']-2.25,'y'=>$y,'width'=>90,'textAlign'=>'left','originX'=>'left','fill'=>'#4072a6','color'=>'#4072a6']
                           : $ar+$opt+['is_static'=>true,'anchorBox'=>true,'bidi'=>'ltr','detected_text'=>"\u{202D}+٩٦٨\u{202C}",'x'=>$g['L']-2.25,'y'=>$y,'width'=>90,'textAlign'=>'left','originX'=>'left','fill'=>'#4072a6','color'=>'#4072a6'];
      $old=$f[$key];
      $f[$key]=array_merge($old,$ar,['is_static'=>false,'x'=>$g['NUM']-140,'y'=>$y,'width'=>140,'textAlign'=>'right','originX'=>'right','detected_text'=>'',
                                    'fill'=>'#3e71a5','color'=>'#3e71a5'], $bound?['valuePart'=>'number']:[]);
    }
    $rows[$side][]="$key@slot".($first+$i+1);
  }
  // email + www on the column edge, one pitch below the last slot
  if($side==='front'){
    $f['email']=array_merge($f['email'],['x'=>$g['L'],'y'=>$g['EY'],'textAlign'=>'left','originX'=>'left','width'=>350]);
    $f['www']=['enabled'=>true,'render_in_bg'=>false,'label'=>null,'is_static'=>true,'anchorBox'=>true,'detected_text'=>'www.mhditics.com','x'=>$g['L'],'y'=>$g['WY'],
               'width'=>350,'height'=>34,'fontSize'=>28,'fontFamily'=>'FrutigerLTStd','fontWeight'=>400,'italic'=>false,'fontStyle'=>'normal','originY'=>'top',
               'textAlign'=>'left','originX'=>'left','autoShrink'=>false,'fontPath'=>$FONT_EN,'fill'=>'#4072a6','color'=>'#4072a6'];
  } else {
    $f['email']=array_merge($f['email'],['x'=>$g['L'],'y'=>$g['EY'],'width'=>$g['R']-$g['L']+2.25,'textAlign'=>'right','originX'=>'right']);
    $f['www']=['enabled'=>true,'render_in_bg'=>false,'label'=>null,'is_static'=>true,'anchorBox'=>true,'detected_text'=>'www.mhditics.com','x'=>$g['L'],'y'=>$g['WY'],
               'width'=>$g['R']-$g['L']+2.25,'height'=>34,'fontSize'=>28,'fontFamily'=>'FrutigerLTStd','fontWeight'=>400,'italic'=>false,'fontStyle'=>'normal','originY'=>'top',
               'textAlign'=>'right','originX'=>'right','autoShrink'=>false,'fontPath'=>$FONT_EN,'fill'=>'#4072a6','color'=>'#4072a6'];
  }
  $out[$t['id']]=[$f,$bgNew,$t];
}
echo "pair $pair rows ".json_encode($rows)."\n";
if(!$apply){ echo "dry run\n"; exit; }
@mkdir('/www/wwwroot/cardify.om/private/backups',0750,true);
foreach($out as $id=>[$f,$bg,$t]){
  file_put_contents("/www/wwwroot/cardify.om/private/backups/itics-cols-$id-".date('Ymd-His').'.json',json_encode(['fields_json'=>$t['fields_json'],'background_image_path'=>$t['background_image_path']],JSON_UNESCAPED_UNICODE));
  $db->query("UPDATE templates SET fields_json=?, background_image_path=?, current_version=current_version+1 WHERE id=?",[json_encode($f,JSON_UNESCAPED_UNICODE),$bg,$id]);
  echo "applied $id {$t['side']} bg ".basename($bg)."\n";
}
