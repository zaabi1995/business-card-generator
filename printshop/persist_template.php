<?php
/**
 * Print Shop: Persist a reviewed PDF import as templates rows.
 *
 * Companion to /printshop/import_pdf.php. The wizard collects the user's
 * confirmed bindings (each detected text block bound to a Cardify field,
 * kept as static decoration, or skipped) and POSTs them here. We re-read
 * the parser output saved by import_pdf, apply the bindings, and write
 * the templates rows for both card sides.
 *
 * Body shape:
 *   {
 *     "import_token": "<hex>",
 *     "bindings": {
 *        "1": { "block_0": "name_en", "block_1": "static", "block_2": "skip", ... },
 *        "2": { "block_0": "static", ... }
 *     }
 *   }
 *
 * Returns:
 *   { "ok": true, "pair_id": "<uuid>", "template_ids": { "front":..., "back":... } }
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardifyTemplateImporter.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::getCurrentUser();
$allowedRoles = ['admin', 'company_admin', 'company', 'super_admin'];
if (!in_array($user['role'], $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$companyId = function_exists('getCurrentCompanyId') ? getCurrentCompanyId() : null;
if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'no_company_context']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

$token = preg_replace('/[^a-f0-9]/i', '', (string)($body['import_token'] ?? ''));
if (!$token) {
    http_response_code(400);
    echo json_encode(['error' => 'missing_import_token']);
    exit;
}

$bindings = $body['bindings'] ?? [];
if (!is_array($bindings)) $bindings = [];

$outRel = '/uploads/templates/imports/' . $token;
$outAbs = realpath(__DIR__ . '/..') . $outRel;
$envelopePath = $outAbs . '/parse.json';
if (!is_file($envelopePath)) {
    http_response_code(404);
    echo json_encode(['error' => 'import_not_found']);
    exit;
}

$envelope = json_decode(file_get_contents($envelopePath), true);
if (!is_array($envelope) || empty($envelope['pages'])) {
    http_response_code(500);
    echo json_encode(['error' => 'import_corrupt']);
    exit;
}

// Authorisation: only the company that uploaded this PDF can persist it.
if (!empty($envelope['company_id']) && $envelope['company_id'] !== $companyId) {
    http_response_code(403);
    echo json_encode(['error' => 'wrong_company']);
    exit;
}

// Whitelist allowed bindings so a malicious caller can't smuggle a key
// like "is_active" or "company_id" into fields_json.
$allowedBindings = [
    'static', 'skip',
    'name_en', 'name_ar', 'position_en', 'position_ar',
    'company_en', 'company_ar',
    'mobile', 'mobile_ar', 'phone', 'phone_ar', 'fax', 'fax_ar',
    'email', 'website', 'website_ar',
    'address', 'address_en', 'address_2_en', 'address_ar', 'address_2_ar',
    'social',
];
$bindingsByPage = [];
foreach ($bindings as $pageKey => $pageBindings) {
    $pn = (int)$pageKey;
    if (!is_array($pageBindings)) continue;
    $clean = [];
    foreach ($pageBindings as $blockId => $b) {
        $blockId = preg_replace('/[^a-z0-9_]/i', '', (string)$blockId);
        if (!$blockId) continue;
        $b = (string)$b;
        if (!in_array($b, $allowedBindings, true)) {
            $b = 'static';
        }
        $clean[$blockId] = $b;
    }
    $bindingsByPage[$pn] = $clean;
}

try {
    $db = Database::getInstance();
    $fontsDirRel = $envelope['fonts_dir_rel'] ?? null;
    $result = CardifyTemplateImporter::persist(
        $db,
        $companyId,
        $token,
        $outRel,
        (string)($envelope['original_filename'] ?? 'design.pdf'),
        $envelope['pages'],
        $bindingsByPage,
        $fontsDirRel
    );

    echo json_encode([
        'ok'           => true,
        'pair_id'      => $result['pair_id'],
        'template_ids' => $result['template_ids'],
        'import_token' => $token,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[persist_template] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'persist_failed', 'message' => $e->getMessage()]);
}
