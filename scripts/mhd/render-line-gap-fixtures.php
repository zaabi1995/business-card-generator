<?php
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/CardPDFRenderer.php';
$db = Database::getInstance();
$co = $db->fetchOne("SELECT id FROM companies WHERE slug='mhd'");
$depts = $db->fetchAll("SELECT id,slug FROM departments WHERE company_id=:c AND portal_enabled=1 ORDER BY slug",['c'=>$co['id']]);
$base=['name_en'=>'Ali Adnan Haider Darwish','name_ar'=>'علي عدنان حيدر درويش',
 'position_en'=>'Jr. Specialist Culture & Engagement','position_ar'=>'أخصائي مبتدئ للثقافة والمشاركة',
 'position_en_2'=>'Engagement','position_ar_2'=>'مسؤول المشاركة',
 'mobile'=>'91117795','mobile_ar'=>'٩١١١٧٧٩٥','email'=>'ali@mhd.co.om','status'=>'active'];
foreach ($depts as $d) {
  $id='gap-'.$d['slug']; $row=$base+['company_id'=>$co['id'],'department_id'=>$d['id']];
  $row['company_id']=$co['id']; $row['department_id']=$d['id'];
  if ($db->fetchOne("SELECT id FROM employees WHERE id=:i",['i'=>$id])) $db->update('employees',$row,'id=:i',['i'=>$id]);
  else $db->insert('employees',['id'=>$id]+$row);
  foreach (glob(__DIR__.'/tmp/pdf-vector/*') as $f) @unlink($f);
  $r=CardPDFRenderer::render($id,'print',['include_qr'=>false]);
  if(!empty($r['success'])) copy($r['path'], __DIR__.'/tmp/gap-'.$d['slug'].'.pdf');
}
echo "done\n";
