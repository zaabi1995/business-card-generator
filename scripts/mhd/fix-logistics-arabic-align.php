<?php
/** Same cap for the Logistics back: its baked address column is wider, ending
 *  at 549.6px on a 1129.4px-wide card. */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$PX = 4.16667;
$MARGIN = (271.049 - 1.417) * $PX - (9.6 * $PX);   // trim right, less the design gap
$LEFT   = 549.6 + 24.0;
$r = $db->fetchOne(
    "SELECT t.id, t.fields_json FROM templates t
     JOIN departments d ON d.template_pair_id = t.pair_id
     JOIN companies c ON c.id = d.company_id AND c.slug='mhd'
     WHERE t.side='back' AND d.slug='logistics'");
$f = json_decode($r['fields_json'], true);
$done = [];
foreach (['name_ar','position_ar','position_ar_2'] as $k) {
    if (!isset($f[$k])) continue;
    $f[$k]['textAlign'] = 'right';
    $f[$k]['x']         = round($LEFT, 2);
    $f[$k]['width']     = round($MARGIN - $LEFT, 2);
    $done[] = $k;
}
$db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id' => $r['id']]);
printf("logistics margin=%.1f left=%.1f width=%.1f  %s\n", $MARGIN, $LEFT, $MARGIN-$LEFT, implode(',', $done));
