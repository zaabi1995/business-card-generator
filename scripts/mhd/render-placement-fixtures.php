<?php
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/CardPDFRenderer.php';
$db = Database::getInstance();
$co = $db->fetchOne("SELECT id FROM companies WHERE slug='mhd'");
$depts = $db->fetchAll("SELECT id,slug FROM departments WHERE company_id=:c AND portal_enabled=1 ORDER BY slug",['c'=>$co['id']]);
$ar = function($s){ return strtr($s, ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']); };
$sets = [
 // typical: a real MHD person
 'typ' => ['name_en'=>'Madhu Pillai','name_ar'=>'مادهو بيلاي',
           'position_en'=>'Deputy Manager','position_ar'=>'نائب مدير',
           'position_en_2'=>'Mobile Device Sales','position_ar_2'=>'مبيعات الأجهزة المحمولة',
           'mobile'=>'71557240','email'=>'madhupillai@mhd.co.om'],
 // long: the worst real case seen on the portal
 'long'=> ['name_en'=>'Hafizur Rehman Siddiqui','name_ar'=>'حافظ الرحمن صديقي',
           'position_en'=>'Jr. Specialist Culture & Engagement','position_ar'=>'أخصائي مبتدى للثقافة والمشاركة المؤسسية',
           'position_en_2'=>'Infrastructure & Building Systems','position_ar_2'=>'البنية التحتية ونظم البناء',
           'mobile'=>'71557238','email'=>'hafizur.rehman.siddiqui@mhd.co.om'],
];
foreach ($sets as $tag=>$s) {
  foreach ($depts as $d) {
    $id = 'pl-'.$tag.'-'.$d['slug'];
    $row = $s + ['company_id'=>$co['id'],'department_id'=>$d['id'],'status'=>'active',
                 'mobile_ar'=>$ar($s['mobile'])];
    if ($db->fetchOne("SELECT id FROM employees WHERE id=:i",['i'=>$id])) $db->update('employees',$row,'id=:i',['i'=>$id]);
    else $db->insert('employees',['id'=>$id]+$row);
    foreach (glob(__DIR__.'/tmp/pdf-vector/*') as $f) @unlink($f);
    $r = CardPDFRenderer::render($id,'print',['include_qr'=>false]);
    if (!empty($r['success'])) copy($r['path'], __DIR__."/tmp/pl-$tag-{$d['slug']}.pdf");
    else echo "FAIL $id\n";
  }
}
echo "rendered\n";
