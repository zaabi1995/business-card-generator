<?php
/**
 * Arabic identity fields on the back were left-anchored.
 *
 * MHD's own master (ITICS Business Card.ai p2, 92x57mm with 1mm bleed) right-
 * aligns the Arabic name and title 9.6pt in from the trim, and the division /
 * entity lines 10.8pt in. Our Group A backs carried textAlign 'left', so the
 * name rendered 15.8mm left of the design margin and a long title grew
 * rightward until it ran off the card entirely.
 *
 * Right-align the three dynamic identity fields on the design margin and give
 * them room to grow leftward instead of off the edge.
 */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$PX = 4.16667;

// Per template-family: [page width pt, bleed per side pt, left limit px]
$FAMILY = [
    'groupA'     => ['w' => 258.009, 'bleed' => 1.417, 'left' => 400.0],
    'logistics'  => ['w' => 271.049, 'bleed' => 1.417, 'left' => 430.0],
];
$IDENT_GAP_PT = 9.6;          // master: name + title, in from the trim
$TARGETS = ['name_ar', 'position_ar', 'position_ar_2'];

$groupA = ['itics','ipd','tech-comm','healthcare','office-products','infrastructure','building-materials'];
$rows = $db->fetchAll(
    "SELECT d.slug, t.id, t.fields_json FROM templates t
     JOIN departments d ON d.template_pair_id = t.pair_id
     JOIN companies c ON c.id = d.company_id AND c.slug='mhd'
     WHERE t.side='back' AND d.slug IN ('".implode("','", array_merge($groupA,['logistics']))."')");

foreach ($rows as $r) {
    $fam = in_array($r['slug'], $groupA, true) ? 'groupA' : 'logistics';
    $cfg = $FAMILY[$fam];
    $trimRightPx = ($cfg['w'] - $cfg['bleed']) * $PX;
    $marginPx    = $trimRightPx - ($IDENT_GAP_PT * $PX);
    $f = json_decode($r['fields_json'], true);
    $done = [];
    foreach ($TARGETS as $k) {
        if (!isset($f[$k])) continue;
        $f[$k]['textAlign'] = 'right';
        $f[$k]['x']         = round($cfg['left'], 2);
        $f[$k]['width']     = round($marginPx - $cfg['left'], 2);
        $done[] = $k;
    }
    if ($done) {
        $db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id' => $r['id']]);
    }
    printf("%-20s margin=%.1fpx  %s\n", $r['slug'], $marginPx, implode(', ', $done) ?: 'none');
}
