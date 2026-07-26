<?php
/**
 * Cap the Arabic identity column at the free space, so long titles shrink
 * instead of running over the address block.
 *
 * The renderer already auto-shrinks Arabic to the field width (0.5pt steps,
 * 70% floor). It never fired because the box I gave it was wider than the gap:
 * the baked address column on the Arabic back ends at 453.2px, and a 629px box
 * let a long title reach 402px, 51px into it.
 */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$MARGIN = 1030.9;                 // measured right edge of the Arabic column
$BAKED_RIGHT = 453.2;             // rightmost baked ink in the contact column
$GUTTER = 24.0;
$LEFT = $BAKED_RIGHT + $GUTTER;   // 477.2

$groupA = ['itics','ipd','tech-comm','healthcare','office-products','infrastructure','building-materials'];
$rows = $db->fetchAll(
    "SELECT d.slug, t.id, t.fields_json FROM templates t
     JOIN departments d ON d.template_pair_id = t.pair_id
     JOIN companies c ON c.id = d.company_id AND c.slug='mhd'
     WHERE t.side='back' AND d.slug IN ('".implode("','", $groupA)."')");
foreach ($rows as $r) {
    $f = json_decode($r['fields_json'], true);
    $done = [];
    foreach (['name_ar','position_ar','position_ar_2','division_ar','entity_ar'] as $k) {
        if (!isset($f[$k]) || !isset($f[$k]['x'])) continue;
        $f[$k]['x']     = round($LEFT, 2);
        $f[$k]['width'] = round($MARGIN - $LEFT, 2);
        $done[] = $k;
    }
    $db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id' => $r['id']]);
    printf("%-20s left=%.1f width=%.1f  %s\n", $r['slug'], $LEFT, $MARGIN-$LEFT, implode(',', $done));
}
