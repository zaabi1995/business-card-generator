<?php
/**
 * Arabic identity lines were set tighter than every other card in the family.
 *
 * Measured glyph ink gaps on the back (raster, not font bbox, which is inflated
 * for Arabic): Group A ran 8px name-to-title and 11px title-to-subtitle, against
 * 26px/15px on the English front and 19px/31px on Automotive. Arabic glyphs are
 * taller than Latin at the same nominal size, so the same y-pitch leaves them
 * almost touching.
 *
 * Nudge the second and third lines down. 11px at 300dpi is 2.6pt, under a
 * millimetre, so the block barely moves but the lines breathe.
 */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$NUDGE = ['position_ar' => 11.0, 'position_ar_2' => 14.0];
$groupA = ['logistics'];
$rows = $db->fetchAll(
    "SELECT d.slug, t.id, t.fields_json FROM templates t
     JOIN departments d ON d.template_pair_id = t.pair_id
     JOIN companies c ON c.id = d.company_id AND c.slug='mhd'
     WHERE t.side='back' AND d.slug IN ('".implode("','", $groupA)."')");
foreach ($rows as $r) {
    $f = json_decode($r['fields_json'], true);
    $out = [];
    foreach ($NUDGE as $k => $dy) {
        if (!isset($f[$k]['y'])) continue;
        $old = (float)$f[$k]['y'];
        $f[$k]['y'] = round($old + $dy, 2);
        $out[] = "$k $old->{$f[$k]['y']}";
    }
    $db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id' => $r['id']]);
    printf("%-20s %s\n", $r['slug'], implode('  ', $out));
}
