<?php
require_once __DIR__ . '/../../config.php';
$db = Database::getInstance();
$tpls = $db->fetchAll("SELECT id, side, fields_json, current_version FROM templates WHERE background_image_path LIKE '%mhd-clean-v1%' AND deleted_at IS NULL");
$n = 0;
foreach ($tpls as $t) {
    $f = json_decode($t['fields_json'], true);
    $changed = false;
    if ($t['side'] === 'back' && isset($f['name_ar'])) {
        $f['name_ar']['fontWeight'] = 900;      // was 400 -> picks FrutigerLTArabic-75Black
        $changed = true;
    }
    if ($t['side'] === 'front' && isset($f['name_en'])) {
        $f['name_en']['fontFamily'] = 'FrutigerLTStd-Black'; // pin to Black exactly
        $f['name_en']['fontWeight'] = 900;
        $changed = true;
    }
    if ($changed) {
        $db->update('templates', ['fields_json' => json_encode($f, JSON_UNESCAPED_UNICODE), 'current_version' => (int)$t['current_version'] + 1], 'id = :id', ['id' => $t['id']]);
        echo "fixed {$t['side']} {$t['id']}\n"; $n++;
    }
}
echo "updated $n\n";
