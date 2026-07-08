<?php
/**
 * Create the MHD Logistics card template pair (Group B, own brand). Clones the
 * ITICS ref row structure, swaps bg + fonts + fields + dims to the Logistics
 * clean import dir, links the logistics department. 2-sided (EN front / AR back).
 *
 * Run: /www/server/php/83/bin/php scripts/mhd/build-logistics-template.php
 */
require_once __DIR__ . '/../../config.php';
$db  = Database::getInstance();
$CID = 'a9ba4c5e-7b8e-4ccc-a3bd-08ab9af7b1d5';
$IMPORT = '/uploads/templates/imports/mhd-logistics-v1';
$stage  = __DIR__ . '/tmp-logi';

$refFront = $db->fetchOne("SELECT * FROM templates WHERE id='723b11ee-5a40-4cf0-b99e-73b1ea35b6ea'");
$refBack  = $db->fetchOne("SELECT * FROM templates WHERE id='479dc3cb-8eef-4f71-8ea7-3dc98dbb32a5'");
if (!$refFront || !$refBack) { fwrite(STDERR, "ref templates missing\n"); exit(1); }

// Logistics card = 271 x 149 pt (clipped 1-up)
$settings = json_encode([
    'cardSize' => 'custom', 'customWidth' => 95.62, 'customHeight' => 52.57,
    'customUnit' => 'mm', 'dpi' => 300, 'width_pt' => 271, 'height_pt' => 149,
    'qr_area' => null, 'fonts_used' => ['FrutigerLTStd', 'FrutigerLTArabic'],
    'imported_from' => 'pdf', 'import_token' => 'mhd-logistics-v1',
]);

$drop = ['created_at','updated_at','deleted_at'];
$pairId = generateUUID();
foreach (['front' => $refFront, 'back' => $refBack] as $side => $ref) {
    $fj = file_get_contents("$stage/logi-$side.json");
    if ($fj === false) { fwrite(STDERR, "missing fields $side\n"); exit(1); }
    $row = $ref;
    foreach ($drop as $c) unset($row[$c]);
    $row['id']                    = generateUUID();
    $row['company_id']            = $CID;
    $row['pair_id']               = $pairId;
    $row['side']                  = $side;
    $row['name']                  = 'MHD Logistics';
    $row['fields_json']           = $fj;
    $row['settings_json']         = $settings;
    $row['background_image_path'] = "$IMPORT/bg-page-" . ($side === 'front' ? 1 : 2) . ".png";
    $row['fonts_dir']             = "$IMPORT/fonts";
    $row['has_vector_source']     = 1;
    $row['current_version']       = 1;
    $db->insert('templates', $row);
    echo "created logistics $side {$row['id']}\n";
}
$db->update('departments', ['template_pair_id' => $pairId],
    'company_id = :c AND slug = :s', ['c' => $CID, 's' => 'logistics']);
echo "linked logistics -> pair $pairId\n";
