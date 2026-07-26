<?php
/**
 * The English identity fields kept the narrow boxes the importer derived from
 * its sample name, so the Latin auto-shrink fired far too early: "Ali Adnan
 * Haider Darwish Al Zaabi" rendered at 16.9px against a declared 37px, 46%,
 * while the Arabic name beside it held its full 38px. The two sides of the same
 * card disagreed on type size.
 *
 * Give the English column the same treatment the Arabic column got: run it to
 * the contact column and stop 24px clear, so the shrink only fires on genuinely
 * long names and at the same threshold as Arabic.
 */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$GUTTER  = 24.0;
$TARGETS = ['name_en', 'position_en', 'position_en_2'];

$rows = $db->fetchAll(
    "SELECT d.slug, t.id, t.fields_json FROM templates t
     JOIN departments d ON d.template_pair_id = t.pair_id
     JOIN companies c ON c.id = d.company_id AND c.slug='mhd'
     WHERE t.side='front'");

foreach ($rows as $r) {
    $f = json_decode($r['fields_json'], true);
    if (!is_array($f) || !isset($f['name_en']['x'])) { printf("%-20s no name_en\n", $r['slug']); continue; }
    $identX = (float)$f['name_en']['x'];
    // The contact column is everything sitting well to the right of the identity
    // block, statics included: they are baked but still carry their x.
    // Band = the vertical extent of the identity block. Without it the top
    // banner (x=472 on Group A, y=49) reads as the contact column and the name
    // gets a 349px box instead of the 581px it actually has.
    $bandTop = (float)$f['name_en']['y'];
    $bandBot = $bandTop + 40;
    foreach (['position_en', 'position_en_2'] as $k) {
        if (isset($f[$k]['y'])) $bandBot = max($bandBot, (float)$f[$k]['y'] + (float)($f[$k]['height'] ?? 30));
    }
    $colX = null;
    foreach ($f as $k => $v) {
        if (!is_array($v) || !isset($v['x'], $v['y']) || $k === 'qr_code') continue;
        $x = (float)$v['x'];
        $y = (float)$v['y'];
        $h = (float)($v['height'] ?? 30);
        if ($x <= $identX + 150) continue;
        if ($y + $h < $bandTop || $y > $bandBot) continue;   // not level with the name
        $colX = ($colX === null) ? $x : min($colX, $x);
    }
    if ($colX === null) { printf("%-20s no contact column found\n", $r['slug']); continue; }
    $width = round($colX - $GUTTER - $identX, 2);
    $done = [];
    foreach ($TARGETS as $k) {
        if (!isset($f[$k]['x'])) continue;
        $f[$k]['x']     = round($identX, 2);
        $f[$k]['width'] = $width;
        $done[] = $k;
    }
    if ($done) $db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id' => $r['id']]);
    printf("%-20s ident x=%.0f  column x=%.0f  width %.0f  %s\n", $r['slug'], $identX, $colX, $width, implode(',', $done));
}
