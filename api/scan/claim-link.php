<?php
/**
 * GET/POST /api/scan/claim-link.php {scan_id} -> the peer-to-peer claim link for
 * a scanned contact's free Cardify card, so the SCANNER can send it from their
 * OWN WhatsApp. A personal message from a known contact converts far better in
 * the GCC than a business-template send, and sidesteps Meta opt-in rules. This
 * neither sends anything nor consumes invite.php's one-time system-send slot;
 * it just hands back the link. Bearer-auth; the scan must belong to the caller.
 * Response: {success, claimable:bool, claim_url?, reason?}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'claim_link', 600);

$body = json_decode(file_get_contents('php://input'), true);
$scanId = (int) ($_GET['scan_id'] ?? (is_array($body) ? ($body['scan_id'] ?? 0) : 0));
if ($scanId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'scan_id_required']);
    exit;
}

$db = Database::getInstance();
$row = $db->fetchOne(
    "SELECT sp.claim_token, sp.opted_out, sp.claimed_at
       FROM scans s
       JOIN shadow_profiles sp ON sp.id = s.shadow_profile_id
      WHERE s.id = :i AND s.employee_id = :e",
    ['i' => $scanId, 'e' => $ctx['employee_id']]
);

// No shadow profile = the scan had no phone/email to build one from; nothing to
// claim. Already claimed / opted out = do not offer the invite.
if (!$row) {
    echo json_encode(['success' => true, 'claimable' => false, 'reason' => 'no_shadow_profile']);
    exit;
}
if (!empty($row['claimed_at'])) {
    echo json_encode(['success' => true, 'claimable' => false, 'reason' => 'already_claimed']);
    exit;
}
if (!empty($row['opted_out'])) {
    echo json_encode(['success' => true, 'claimable' => false, 'reason' => 'opted_out']);
    exit;
}

$baseRow = $db->fetchOne(
    "SELECT setting_value FROM system_settings WHERE setting_key = 'scan_claim_base_url'"
);
$base = $baseRow ? $baseRow['setting_value'] : 'https://cardify.om/claim-card.php?t=';

echo json_encode([
    'success'   => true,
    'claimable' => true,
    'claim_url' => $base . rawurlencode((string) $row['claim_token']),
], JSON_UNESCAPED_UNICODE);
