<?php
// Build "MHD Consumer Division (ITICS)" pair: Tel / Fax / Mob / Mob, division first.
// Cloned from MHD Itics (2-Tel) rows + Office Products division/entity fields. 4 Oct 2026, Ali Akbar Khan request.
require '/www/wwwroot/cardify.om/config.php';
require_once '/www/wwwroot/cardify.om/includes/Database.php';
$db = Database::getInstance();
$CID='a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';
function uuid(){ $d=random_bytes(16); $d[6]=chr(ord($d[6])&0x0f|0x40); $d[8]=chr(ord($d[8])&0x3f|0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4)); }
$apply = in_array('--apply',$argv);
$src = []; foreach ($db->fetchAll("SELECT * FROM templates WHERE pair_id='1870b54f-c5f6-4fb8-9ab0-aecad3375940' AND deleted_at IS NULL") as $r) $src[$r['side']]=$r;
$op  = []; foreach ($db->fetchAll("SELECT * FROM templates WHERE pair_id='0bc4394f-63d4-4652-b582-112e18876223' AND deleted_at IS NULL") as $r) $op[$r['side']]=json_decode($r['fields_json'],true);
$M2X_EN = (float)($argv[1] ?? 721.0);  $M2X_AR = (float)($argv[2] ?? 71.5);
// front
$f = json_decode($src['front']['fields_json'], true);
$fax = $f['phone_2']; $fax['detected_text']='24798662';
$mob2 = $f['fax']; $mob2['detected_text']=''; $mob2['x']=$M2X_EN; $mob2['width']=272; unset($mob2['baselineFactor']); $mob2['autoShrink']=false;
unset($f['phone_2'], $f['fax']);
$f['fax']=$fax; $f['mobile_2']=$mob2;
$f['phone']['detected_text']='24788933';
$f['division_en']=$op['front']['division_en']; $f['division_en']['detected_text']='Consumer Division'; $f['division_en']['label']='Decoration: Consumer Division';
$f['entity_en']=$op['front']['entity_en'];
// back
$b = json_decode($src['back']['fields_json'], true);
$faxa = $b['phone_2_ar'];
$mob2a = $b['fax_ar']; $mob2a['x']=$M2X_AR; $mob2a['width']=230; $mob2a['detected_text']=''; $mob2a['autoShrink']=false;
unset($b['phone_2_ar'], $b['fax_ar']);
$b['fax_ar']=$faxa; $b['mobile_2_ar']=$mob2a;
$b['division_ar']=$op['back']['division_ar']; $b['division_ar']['detected_text']='قسم المنتجات الاستهلاكية'; $b['division_ar']['label']='Decoration: قسم المنتجات الاستهلاكية';
$b['entity_ar']=$op['back']['entity_ar'];
$existing = $db->fetchOne("SELECT pair_id FROM templates WHERE company_id=:c AND name='MHD Consumer Division (ITICS)' AND deleted_at IS NULL LIMIT 1",['c'=>$CID]);
echo json_encode(['front'=>array_intersect_key($f,array_flip(['phone','fax','mobile_2','mobile','division_en','entity_en'])),'back'=>array_intersect_key($b,array_flip(['phone_ar','fax_ar','mobile_2_ar','mobile_ar','division_ar','entity_ar']))],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
if (!$apply) { echo "dry run; existing=".json_encode($existing)."\n"; exit; }
if ($existing) {
  $pair=$existing['pair_id'];
  foreach (['front'=>$f,'back'=>$b] as $side=>$fj) $db->query("UPDATE templates SET fields_json=? WHERE pair_id=? AND side=?",[json_encode($fj,JSON_UNESCAPED_UNICODE),$pair,$side]);
  echo "updated pair $pair\n"; exit;
}
$pair=uuid(); $idF=uuid(); $idB=uuid();
foreach (['front'=>[$idF,$idB,$f,1],'back'=>[$idB,$idF,$b,2]] as $side=>[$id,$other,$fj,$pg]) {
  $r=$src[$side];
  $row=['id'=>$id,'company_id'=>$CID,'pair_id'=>$pair,'theme_id'=>$r['theme_id'],'department_id'=>null,'name'=>'MHD Consumer Division (ITICS)','side'=>$side,
    'background_image_path'=>"/uploads/templates/imports/mhd-clean-v2/bg-telfaxmob2-v1-page-$pg.png",'original_pdf_path'=>$r['original_pdf_path'],
    'fields_json'=>json_encode($fj,JSON_UNESCAPED_UNICODE),'settings_json'=>$r['settings_json'],'is_active'=>$r['is_active'],'is_shared'=>0,
    'original_pdf_page'=>$r['original_pdf_page'],'paired_template_id'=>$other,'description'=>'MHD Consumer Division on the ITICS card: Tel / Fax / Mob / Mob (2nd row unbaked +968, stored with prefix). Built 4 Oct 2026 for Ali Mohammad Akbar Khan.',
    'tags'=>$r['tags'],'industry'=>$r['industry'],'has_vector_source'=>$r['has_vector_source'],'fonts_dir'=>$r['fonts_dir']];
  $db->insert('templates',$row);
}
$did=uuid();
$db->insert('departments',['id'=>$did,'company_id'=>$CID,'template_pair_id'=>$pair,'name'=>'Consumer Division (ITICS card)','slug'=>'consumer-itics',
  'description'=>'Oman Consumer Division on the ITICS design (Tel/Fax/Mob/Mob). Hidden; staff-only.','portal_enabled'=>0,
  'responsible_email'=>'eep@mhd.co.om','cc_emails'=>'aliakbar@mhd.co.om,manal.a@mhd.co.om','include_qr_default'=>0,'head_email'=>null,
  'erp_client_name'=>'Mohsin Haider Darwish L.L.C.( Consumer Division )','card_unit_price'=>'0.030','email_domain'=>'mhd.co.om',
  'office_tel1'=>'24788933','office_tel2'=>null,'office_fax'=>'24798662','invoice_without_po'=>0]);
echo "created pair $pair front $idF back $idB dept $did\n";
