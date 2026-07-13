<?php
/**
 * POST /api/scan/invite.php, send the one-time WhatsApp claim invite
 *
 * Body: {scan_id} -> {success} or {success:false, error}.
 *
 * Consent rules, verbatim from the product spec: ONE WhatsApp message per
 * shadow profile EVER, never if opted out, never if already claimed, only
 * user-initiated (this endpoint, called by the employee from the app, is
 * the only trigger). The UPDATE below claims the one-invite slot
 * atomically before any send, so two employees racing to invite the same
 * shadow profile cannot both get through: only the row whose UPDATE
 * actually flips invite_sent_at from NULL wins, the loser sees
 * already_invited. If the send itself fails, the slot is rolled back to
 * NULL so the employee can retry, but only the failed slot moves, never a
 * slot that a WhatsApp send already used.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/WhatsAppCloud.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}
$ctx = ScanAuth::requireEmployee();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$scanId = (int)($body['scan_id'] ?? 0);

try {
    $db = Database::getInstance();
    $row = $db->fetchOne(
        "SELECT s.id AS scan_id, sp.* FROM scans s
         JOIN shadow_profiles sp ON sp.id = s.shadow_profile_id
         WHERE s.id = :i AND s.employee_id = :e",
        ['i' => $scanId, 'e' => $ctx['employee_id']]
    );

    if (!$row) { echo json_encode(['success' => false, 'error' => 'no_shadow_profile']); exit; }
    if ((int)$row['opted_out'] === 1) { echo json_encode(['success' => false, 'error' => 'opted_out']); exit; }
    if (!empty($row['claimed_at'])) { echo json_encode(['success' => false, 'error' => 'claimed']); exit; }
    if (!empty($row['invite_sent_at'])) { echo json_encode(['success' => false, 'error' => 'already_invited']); exit; }
    if (empty($row['phone_primary'])) { echo json_encode(['success' => false, 'error' => 'no_phone']); exit; }
    if (!WhatsAppCloud::isConfigured()) { echo json_encode(['success' => false, 'error' => 'not_configured']); exit; }

    // employees has name_en/name_ar, not a single name column; prefer the
    // English name with an Arabic fallback so the inviter line is never empty.
    $inviter = $db->fetchOne(
        "SELECT name_en, name_ar FROM employees WHERE id = :i", ['i' => $ctx['employee_id']]
    );
    $inviterName = ($inviter['name_en'] ?? '') !== ''
        ? $inviter['name_en']
        : (($inviter['name_ar'] ?? '') !== '' ? $inviter['name_ar'] : 'A Cardify user');

    $baseRow = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'scan_claim_base_url'");
    $base = $baseRow ? $baseRow['setting_value'] : 'https://cardify.om/claim.php?t=';
    $claimUrl = $base . $row['claim_token'];

    // Atomic claim of the one-invite slot BEFORE sending, so a race between
    // two employees inviting the same shadow profile cannot double-send:
    // only one UPDATE finds invite_sent_at still NULL and flips it.
    $stmt = $db->getConnection()->prepare(
        "UPDATE shadow_profiles SET invite_sent_at = NOW(), invited_by_employee_id = ?
         WHERE id = ? AND invite_sent_at IS NULL AND opted_out = 0"
    );
    $stmt->execute([$ctx['employee_id'], (int)$row['id']]);
    if (!$stmt->rowCount()) { echo json_encode(['success' => false, 'error' => 'already_invited']); exit; }

    $result = WhatsAppCloud::sendTemplate($row['phone_primary'], 'scan_claim_invite',
        [$inviterName, $claimUrl], 'ar');

    if (!$result['success']) {
        // Roll the slot back so a transient send failure can be retried;
        // only this request's own slot is reset, never one a successful
        // send already claimed.
        $db->getConnection()->prepare(
            "UPDATE shadow_profiles SET invite_sent_at = NULL, invited_by_employee_id = NULL WHERE id = ?"
        )->execute([(int)$row['id']]);
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }
} catch (\Throwable $e) {
    error_log('[scan/invite] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode(['success' => true]);
