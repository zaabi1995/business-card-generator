<?php
require_once __DIR__ . '/../../config.php';
$db = Database::getInstance();
// single-Tel divisions (front id, back id) that use the 1tel bg
$singleTel = ['e0f32aa6-4d08-46ff-b7b3-c3e26b55b219','9b2218e4-0335-484b-9273-99b7821629b4','bec4133b-6c85-426d-9df1-34189f2f3ea3',
              '520c295b-0707-4477-9288-d87198a7d841','ac9b191a-e09a-4521-aaa5-c6e966a344b3','fbee519e-3a7a-4f60-985b-78df5d7ace5c'];
$tpls = $db->fetchAll("SELECT id, side, fields_json, current_version FROM templates WHERE background_image_path LIKE '%mhd-clean-v1%' AND deleted_at IS NULL");
$n = 0;
foreach ($tpls as $t) {
    $f = json_decode($t['fields_json'], true);
    $changed = false;
    // 1) AR sub-title sits tight under the AR title: nudge position_ar_2 down
    if ($t['side'] === 'back' && isset($f['position_ar_2'])) {
        $f['position_ar_2']['y'] = (int)$f['position_ar_2']['y'] + 16; $changed = true;
    }
    // 2) single-Tel: the 2nd Tel line is removed from the bg, so shift the
    //    fax/mobile/email fields UP one line (~35px @300dpi) to sit under the Tel.
    if (in_array($t['id'], $singleTel, true)) {
        foreach (['fax','tel2','mobile','email','fax_ar','tel2_ar','mobile_ar'] as $k) {
            if (isset($f[$k])) { $f[$k]['y'] = (int)$f[$k]['y'] - 35; $changed = true; }
        }
    }
    if ($changed) {
        $db->update('templates', ['fields_json' => json_encode($f, JSON_UNESCAPED_UNICODE), 'current_version' => (int)$t['current_version'] + 1], 'id = :id', ['id' => $t['id']]);
        echo "cosmetic {$t['side']} {$t['id']}\n"; $n++;
    }
}
echo "updated $n\n";
