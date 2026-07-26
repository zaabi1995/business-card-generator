<?php
/**
 * Automotive and Logistics DO have art level with the name, it is just painted
 * into the background PNG rather than declared as a field, so the field-based
 * check said "nothing level with the name" and handed them the full line.
 * Measured off the raster instead: first baked ink right of the identity block
 * is x=594.0 (Automotive) and x=568.6 (Logistics).
 */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$GUTTER = 24.0;
$BAKED = ['automotive' => 594.0, 'logistics' => 568.6];
foreach ($BAKED as $slug => $colX) {
    $r = $db->fetchOne(
        "SELECT t.id, t.fields_json FROM templates t
         JOIN departments d ON d.template_pair_id = t.pair_id
         JOIN companies c ON c.id = d.company_id AND c.slug='mhd'
         WHERE t.side='front' AND d.slug = :s", ['s' => $slug]);
    if (!$r) continue;
    $f = json_decode($r['fields_json'], true);
    $nx = (float)$f['name_en']['x'];
    $f['name_en']['width'] = round($colX - $GUTTER - $nx, 2);
    $db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id' => $r['id']]);
    printf("%-12s baked column x=%.1f  name width %.1f\n", $slug, $colX, $f['name_en']['width']);
}
