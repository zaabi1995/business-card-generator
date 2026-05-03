<?php
/**
 * Re-sample one text field from the original source PDF.
 *
 * POST: csrf_token, template_id, field_key
 * Auth: company admin (must own the template).
 *
 * Mirrors admin/redetect-qr-style.php exactly, with sample_text_field_cli.py
 * as the Python helper. Updates the named field's detected_text + font
 * properties in fields_json, bumps current_version so CF/browser caches
 * refresh, and returns the new field for inline preview.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Database.php';
require_once INCLUDES_DIR . '/CardRenderer.php';

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
$fieldKey   = trim($_POST['field_key'] ?? '');
if ($templateId === '' || $fieldKey === '') {
    echo json_encode(['success' => false, 'error' => 'template_id + field_key required']);
    exit;
}
if (!preg_match('/^[a-zA-Z0-9_]+$/', $fieldKey)) {
    echo json_encode(['success' => false, 'error' => 'invalid field_key']);
    exit;
}

$companyId = getCurrentCompanyId();
if (!$companyId) {
    echo json_encode(['success' => false, 'error' => 'No company context']);
    exit;
}

$db = Database::getInstance();
$row = $db->fetchOne(
    "SELECT id, fields_json, settings_json, original_pdf_path, original_pdf_page, current_version
     FROM templates WHERE id = :id AND company_id = :cid",
    ['id' => $templateId, 'cid' => $companyId]
);
if (!$row) {
    echo json_encode(['success' => false, 'error' => 'Template not found']);
    exit;
}

$pdfRel = $row['original_pdf_path'] ?? '';
if (!$pdfRel) {
    echo json_encode(['success' => false, 'error' => 'Template has no original PDF, cannot re-detect']);
    exit;
}
$pdfAbs = realpath(__DIR__ . '/..' . (strpos($pdfRel, '/') === 0 ? '' : '/') . $pdfRel);
if (!$pdfAbs || !is_file($pdfAbs)) {
    echo json_encode(['success' => false, 'error' => 'Source PDF not found on disk']);
    exit;
}
$page = (int)($row['original_pdf_page'] ?? 1);

$fields = json_decode($row['fields_json'] ?: '{}', true) ?: [];
if (!isset($fields[$fieldKey]) || !is_array($fields[$fieldKey])) {
    echo json_encode(['success' => false, 'error' => 'field_key not found in template']);
    exit;
}
$f = $fields[$fieldKey];

// Convert the field's stored editor-pixel bbox (300 DPI) to PDF points.
// Editor scale = 300 dpi / 72 pt = 4.1667 px/pt.
$EDITOR_SCALE = 300.0 / 72.0;
$x_pt = (float)($f['x'] ?? 0) / $EDITOR_SCALE;
$y_pt = (float)($f['y'] ?? 0) / $EDITOR_SCALE;
// Width/height aren't always present (some fields are anchor-only); fall
// back to fontSize * 1.4 for height and a wide guess for width so the slop
// in the Python sampler still finds the span.
$w_pt = isset($f['width']) && $f['width'] > 0 ? (float)$f['width'] / $EDITOR_SCALE : 200.0 / $EDITOR_SCALE;
$h_pt = isset($f['height']) && $f['height'] > 0 ? (float)$f['height'] / $EDITOR_SCALE : ((float)($f['fontSize'] ?? 16) * 1.4) / $EDITOR_SCALE;

$cli = escapeshellarg(__DIR__ . '/../scripts/sample_text_field_cli.py');
$cmd = sprintf(
    'timeout 20 /usr/bin/env python3 %s --pdf %s --page %d --x-pt %s --y-pt %s --w-pt %s --h-pt %s 2>&1',
    $cli,
    escapeshellarg($pdfAbs),
    $page,
    escapeshellarg(number_format($x_pt, 4, '.', '')),
    escapeshellarg(number_format($y_pt, 4, '.', '')),
    escapeshellarg(number_format($w_pt, 4, '.', '')),
    escapeshellarg(number_format($h_pt, 4, '.', ''))
);
$output = shell_exec($cmd);
$jsonStart = $output ? strpos($output, '{') : false;
if ($jsonStart === false) {
    echo json_encode(['success' => false, 'error' => 'Sampler failed', 'output' => $output]);
    exit;
}
$res = json_decode(substr($output, $jsonStart), true);
if (!is_array($res) || empty($res['ok'])) {
    echo json_encode([
        'success' => false,
        'error' => $res['error'] ?? 'Sampler returned no result',
        'output' => $output,
    ]);
    exit;
}

// Patch only the detected props. Position + size of the field stay locked,
// admin can still adjust them via the existing inputs. Auto-shrink etc.
// untouched.
$EDITOR_SCALE = 300.0 / 72.0;
$f['detected_text'] = (string)$res['detected_text'];
$f['fontFamily']    = (string)$res['font_family'];
$f['fontWeight']    = (int)$res['font_weight'];
$f['fontStyle']     = !empty($res['italic']) ? 'italic' : 'normal';
$f['italic']        = !empty($res['italic']);
$f['fontSize']      = (int)round((float)$res['font_size_pt'] * $EDITOR_SCALE);
$f['fill']          = (string)$res['color'];
$f['color']         = (string)$res['color']; // backward-compat key

$fields[$fieldKey] = $f;

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

// Force regeneration of every cached card PNG + vector PDF for this
// company so the re-detected text actually shows up next time.
try {
    CardRenderer::invalidateForCompany($companyId, 'redetect_text_field:' . $fieldKey);
} catch (Exception $e) {
    // Non-fatal: the version bump alone forces re-render via the
    // template_version freshness check.
}

echo json_encode([
    'success' => true,
    'field' => $f,
    'field_key' => $fieldKey,
    'current_version' => $newVersion,
    'span_count' => $res['span_count'] ?? 1,
]);
