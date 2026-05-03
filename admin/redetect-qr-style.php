<?php
/**
 * Re-sample QR style from the original PDF for a single template.
 *
 * POST: csrf_token, template_id
 * Auth: company admin (must own the template).
 *
 * Calls the same code path as scripts/backfill-qr-styles.php but
 * scoped to one row. Updates fields_json.qr_code (style + rect),
 * bumps current_version so CF/browser caches refresh, and returns
 * the new qr_code field so the editor can re-render the preview
 * inline without a page reload.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Database.php';

header('Content-Type: application/json');

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF check failed']);
    exit;
}

$templateId = trim($_POST['template_id'] ?? '');
if ($templateId === '') {
    echo json_encode(['success' => false, 'error' => 'template_id required']);
    exit;
}

$companyId = getCurrentCompanyId();
if (!$companyId) {
    echo json_encode(['success' => false, 'error' => 'No company context']);
    exit;
}

$db = Database::getInstance();
$row = $db->fetchOne(
    "SELECT id, fields_json, settings_json, background_image_path, current_version
     FROM templates WHERE id = :id AND company_id = :cid",
    ['id' => $templateId, 'cid' => $companyId]
);
if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Template not found']);
    exit;
}

$fields = json_decode($row['fields_json'] ?: '{}', true) ?: [];
$settings = json_decode($row['settings_json'] ?: '{}', true) ?: [];
$qa = $settings['qr_area'] ?? null;
if (!$qa || !isset($qa['x_pt'], $qa['y_pt'], $qa['w_pt'], $qa['h_pt'])) {
    echo json_encode(['success' => false, 'error' => 'No QR area in template settings, cannot re-detect']);
    exit;
}

// Resolve bg path on disk; sample against the WITH-TEXT copy because
// PyMuPDF redaction can wipe vector QR modules from the redacted bg.
$bgRel = $row['background_image_path'];
if (!$bgRel) {
    echo json_encode(['success' => false, 'error' => 'Template has no background image']);
    exit;
}
$bgAbs = realpath(__DIR__ . '/..' . (strpos($bgRel, '/') === 0 ? '' : '/') . $bgRel);
if (!$bgAbs || !is_file($bgAbs)) {
    echo json_encode(['success' => false, 'error' => 'Background image file not found']);
    exit;
}
$withText = preg_replace('/(bg-page-\d+)\.png$/i', '$1-with-text.png', $bgAbs);
if ($withText && $withText !== $bgAbs && is_file($withText)) {
    $bgAbs = $withText;
}

$cli = escapeshellarg(__DIR__ . '/../scripts/sample_qr_style_cli.py');
$cmd = sprintf(
    '/usr/bin/env python3 %s --bg %s --x-pt %s --y-pt %s --w-pt %s --h-pt %s --bg-dpi 1200 --real-qr 2>&1',
    $cli,
    escapeshellarg($bgAbs),
    escapeshellarg((string)$qa['x_pt']),
    escapeshellarg((string)$qa['y_pt']),
    escapeshellarg((string)$qa['w_pt']),
    escapeshellarg((string)$qa['h_pt'])
);
$output = shell_exec($cmd);
$jsonStart = $output ? strpos($output, '{') : false;
if ($jsonStart === false) {
    echo json_encode(['success' => false, 'error' => 'Sampler failed', 'output' => $output]);
    exit;
}
$newStyle = json_decode(substr($output, $jsonStart), true);
if (!is_array($newStyle)) {
    echo json_encode(['success' => false, 'error' => 'Sampler output not parseable', 'output' => $output]);
    exit;
}

if (!isset($fields['qr_code']) || !is_array($fields['qr_code'])) {
    $fields['qr_code'] = ['enabled' => true, 'x' => 0, 'y' => 0, 'size' => 145];
}
$fields['qr_code']['qr_style'] = $newStyle;

// Apply the same canonical-rect logic as scripts/backfill-qr-styles.php:
// when the style has a panel, use the FULL QR area as the base (not the
// importer's 90% factor) so the dynamic panel covers the source design.
if (!empty($newStyle['panel_padding_px']) && $newStyle['qr_px_width'] > 0) {
    $padEditorPx = (int)round($newStyle['panel_padding_px'] * 300.0 / 1200.0);
    if ($padEditorPx > 0) {
        $editorScale = 300.0 / 72.0;
        $qWeditor = (float)$qa['w_pt'] * $editorScale;
        $qHeditor = (float)$qa['h_pt'] * $editorScale;
        $qXeditor = (float)$qa['x_pt'] * $editorScale;
        $qYeditor = (float)$qa['y_pt'] * $editorScale;
        $origSize = (int)round(min($qWeditor, $qHeditor));
        $origX = (int)round($qXeditor + ($qWeditor - $origSize) / 2);
        $origY = (int)round($qYeditor + ($qHeditor - $origSize) / 2);
        $fields['qr_code']['size'] = $origSize + 2 * $padEditorPx;
        $fields['qr_code']['x'] = max(0, $origX - $padEditorPx);
        $fields['qr_code']['y'] = max(0, $origY - $padEditorPx);
    }
}

$newVersion = (int)$row['current_version'] + 1;
$db->update(
    'templates',
    [
        'fields_json' => json_encode($fields, JSON_UNESCAPED_UNICODE),
        'current_version' => $newVersion,
    ],
    'id = :id',
    ['id' => $row['id']]
);

echo json_encode([
    'success' => true,
    'qr_code' => $fields['qr_code'],
    'current_version' => $newVersion,
]);
