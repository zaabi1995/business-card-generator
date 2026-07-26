<?php
/**
 * The static Arabic division / entity lines were left-anchored too.
 *
 * On MHD's master the two share a right margin to 0.01pt (x1 247.19 / 247.18),
 * so they are right-aligned by design. Ours anchored each on a box whose x came
 * from the import's own text width, so a SHORT division name floated: Healthcare
 * (رعاية صحية) and Building Materials (مواد البناء) sat ~12mm inboard of the
 * entity line beneath them.
 */
require_once __DIR__ . '/config.php';
$db = Database::getInstance();
$PX = 4.16667;
$MARGIN_PX = (258.009 - 1.417) * $PX - (9.6 * $PX);   // same margin as the identity block
$LEFT_PX   = 400.0;

$groupA = ['itics','ipd','tech-comm','healthcare','office-products','infrastructure','building-materials'];
$rows = $db->fetchAll(
    "SELECT d.slug, t.id, t.fields_json FROM templates t
     JOIN departments d ON d.template_pair_id = t.pair_id
     JOIN companies c ON c.id = d.company_id AND c.slug='mhd'
     WHERE t.side='back' AND d.slug IN ('".implode("','", $groupA)."')");

foreach ($rows as $r) {
    $f = json_decode($r['fields_json'], true);
    $done = [];
    foreach (['division_ar', 'entity_ar'] as $k) {
        if (!isset($f[$k]) || trim((string)($f[$k]['detected_text'] ?? '')) === '') continue;
        $f[$k]['textAlign'] = 'right';
        $f[$k]['x']         = round($LEFT_PX, 2);
        $f[$k]['width']     = round($MARGIN_PX - $LEFT_PX, 2);
        $done[] = $k;
    }
    if ($done) $db->update('templates', ['fields_json' => json_encode($f)], 'id = :id', ['id' => $r['id']]);
    printf("%-20s %s\n", $r['slug'], implode(', ', $done) ?: 'none');
}
