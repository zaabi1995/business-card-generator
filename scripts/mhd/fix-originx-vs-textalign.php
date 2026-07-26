<?php
/**
 * textAlign said right, originX still said left.
 *
 * card-editor.js:713 lets a stored originX override the alignment it just
 * derived from textAlign ("for backward compatibility"), so the browser preview
 * left-anchored every Arabic field at its box x while the print renderer, which
 * never reads originX, right-aligned it. Preview and print disagreed on the
 * whole Arabic column. Automotive had it too, from its original build.
 */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$rows = $db->fetchAll(
    "SELECT d.slug, t.id, t.side, t.fields_json FROM templates t
     JOIN departments d ON d.template_pair_id = t.pair_id
     JOIN companies c ON c.id = d.company_id AND c.slug='mhd'");
$total = 0;
foreach ($rows as $r) {
    $f = json_decode($r['fields_json'], true);
    if (!is_array($f)) continue;
    $done = [];
    foreach ($f as $k => &$v) {
        if (!is_array($v) || empty($v['textAlign'])) continue;
        $want = $v['textAlign'] === 'right' ? 'right'
              : ($v['textAlign'] === 'center' ? 'center' : 'left');
        if (($v['originX'] ?? 'left') !== $want) { $v['originX'] = $want; $done[] = $k; }
    }
    unset($v);
    if ($done) {
        $db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id' => $r['id']]);
        $total += count($done);
        printf("%-20s %-5s %s\n", $r['slug'], $r['side'], implode(', ', $done));
    }
}
echo "fields corrected: $total\n";
