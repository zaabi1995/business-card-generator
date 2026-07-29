<?php
/**
 * ERP -> Cardify: push a hire's contact details onto their digital card.
 *
 * Called by BHD-ERP the moment an employment contract is signed, so the number
 * the signer typed under his signature and the work address he has just been
 * given appear on bhdoman.cardify.om/<slug> without anyone retyping them.
 *
 * The REVERSE direction of the existing link. Cardify already posts to the ERP
 * with `X-BHD-ERP-Ingest-Secret` (includes/WhatsApp.php::erpRecordAuthEvent);
 * this reuses that same shared secret rather than inventing a second one.
 *
 * PARTIAL MERGE, never a blank. Only keys that are present AND non-empty are
 * written, so a caller that knows the mobile but not the position cannot wipe a
 * position somebody set by hand. That is the same rule api/scan/my-card.php
 * follows for the app.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

function out($code, $body) {
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(405, ['success' => false, 'error' => 'Method not allowed']);
}

// Constant-time compare, and refuse outright when no secret is configured
// rather than falling through to an open endpoint.
$given = $_SERVER['HTTP_X_BHD_ERP_INGEST_SECRET'] ?? '';
if (!defined('ERP_INGEST_SECRET') || !ERP_INGEST_SECRET
    || !is_string($given) || !hash_equals(ERP_INGEST_SECRET, $given)) {
    out(401, ['success' => false, 'error' => 'unauthorized']);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    out(400, ['success' => false, 'error' => 'bad json']);
}

$companySlug = trim((string) ($in['company'] ?? ''));
$employeeId  = trim((string) ($in['employee'] ?? ''));
if ($companySlug === '' || $employeeId === '') {
    out(400, ['success' => false, 'error' => 'company and employee are required']);
}

$db = Database::getInstance();

// The employee is resolved THROUGH the company, so a caller holding the secret
// still cannot reach across tenants by guessing an employee id.
$row = $db->fetchOne(
    "SELECT e.id, e.company_id
       FROM employees e
       JOIN companies c ON c.id = e.company_id
      WHERE c.slug = :slug AND e.id = :eid AND e.deleted_at IS NULL
      LIMIT 1",
    ['slug' => $companySlug, 'eid' => $employeeId]
);
if (!$row) {
    out(404, ['success' => false, 'error' => 'employee not found']);
}
$companyId = $row['company_id'];

// The only columns this endpoint may touch. An allowlist rather than a loop
// over the payload: a sync route must never be able to set password_hash,
// status or company_id.
$ALLOWED = ['mobile', 'phone', 'email', 'position_en'];
$set = [];
$params = ['eid' => $employeeId];
foreach ($ALLOWED as $col) {
    if (!array_key_exists($col, $in)) continue;
    $val = trim((string) $in[$col]);
    if ($val === '') continue;              // absent and empty both mean "leave it"
    $set[] = "$col = :$col";
    $params[$col] = mb_substr($val, 0, 190);
}
if (!$set) {
    out(200, ['success' => true, 'updated' => [], 'note' => 'nothing to change']);
}

$db->query(
    'UPDATE employees SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :eid',
    $params
);

// Re-baking the card image, under the SAME guard api/scan/my-card.php uses.
//
// invalidateForEmployee NULLs generated_cards.*_path and does not re-render.
// digital_card.php gates the card block on the front image, so invalidating an
// employee whose company has no vector source replaces a slightly-stale card
// with NO card, which is worse than what it fixes. Only companies that can
// actually re-bake get invalidated.
$invalidated = false;
try {
    require_once INCLUDES_DIR . '/CardRenderer.php';
    $canRebake = $db->fetchOne(
        "SELECT 1 AS ok
           FROM templates
          WHERE company_id = :cid AND has_vector_source = 1
            AND is_active = 1 AND deleted_at IS NULL
          LIMIT 1",
        ['cid' => $companyId]
    );
    if (!empty($canRebake)) {
        CardRenderer::invalidateForEmployee((string) $employeeId, 'erp-employee-sync');
        $invalidated = true;
    }
} catch (\Throwable $e) {
    // Logged, never fatal: the details are already saved and the contract that
    // triggered this is already signed.
    error_log('[erp-employee-sync] invalidate: ' . $e->getMessage());
}

out(200, [
    'success' => true,
    'employee' => $employeeId,
    'updated' => array_values(array_diff(array_keys($params), ['eid'])),
    'card_invalidated' => $invalidated,
]);
