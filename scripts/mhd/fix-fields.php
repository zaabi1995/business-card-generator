<?php
require_once __DIR__ . '/../../config.php';
$db = Database::getInstance();
$tpls = $db->fetchAll("SELECT id, side, fields_json FROM templates WHERE background_image_path LIKE '%mhd-clean-v1%' AND deleted_at IS NULL");
$n = 0;
foreach ($tpls as $t) {
    $f = json_decode($t['fields_json'], true);
    $changed = false;
    // position_ar clips longer titles: keep right edge (x+width), widen leftwards
    if ($t['side'] === 'back' && isset($f['position_ar'])) {
        $f['position_ar']['x'] = 713; $f['position_ar']['width'] = 350; $changed = true;
    }
    // widen position_en title on front for longer roles
    if ($t['side'] === 'front' && isset($f['position_en'])) {
        $f['position_en']['width'] = 300; $changed = true;
    }
    if ($changed) {
        $db->update('templates', ['fields_json' => json_encode($f, JSON_UNESCAPED_UNICODE), 'current_version' => (int)($t['current_version'] ?? 1) + 1], 'id = :id', ['id' => $t['id']]);
        echo "fixed {$t['side']} {$t['id']}\n"; $n++;
    }
}
echo "updated $n templates\n";
