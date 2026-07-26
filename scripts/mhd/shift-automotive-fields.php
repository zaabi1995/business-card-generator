<?php
/** Move the Automotive dynamic fields up by the same distance the artwork moved,
 *  otherwise the name/mobile/email drift off their baked labels. */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$shift = json_decode(file_get_contents('/root/auto-shift.json'), true); // pt, per page
$PX_PER_PT = 4.16667;
$pair = $db->fetchOne("SELECT d.template_pair_id pid FROM departments d
    JOIN companies c ON c.id=d.company_id AND c.slug='mhd' WHERE d.slug='automotive'");
foreach ($db->fetchAll("SELECT id, side, fields_json FROM templates WHERE pair_id=:p", ['p'=>$pair['pid']]) as $t) {
    $page = $t['side'] === 'front' ? '1' : '2';
    $dy   = round($shift[$page] * $PX_PER_PT, 2);
    $f = json_decode($t['fields_json'], true);
    $moved = 0;
    foreach ($f as $k => &$v) {
        if (!is_array($v) || !isset($v['y'])) continue;
        if (!empty($v['render_in_bg'])) continue;   // baked, moves with the image
        $v['y'] = round($v['y'] - $dy, 2);
        $moved++;
    }
    unset($v);
    $db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id'=>$t['id']]);
    echo $t['side'], ": moved $moved fields up {$dy}px\n";
}
