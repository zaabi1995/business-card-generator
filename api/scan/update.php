<?php
/**
 * POST /api/scan/update.php, edit a rolodex entry (any subset of fields)
 *
 * Body: {id, parsed?, tags?, met_where?, met_at?} -> {success}.
 * An edited parsed also re-upserts the shadow profile, owner corrections
 * should improve the claimable card, same growth-loop path upload.php uses.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/ScanParser.php';
require_once INCLUDES_DIR . '/ShadowProfileService.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}
$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'update', 600);
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$id = (int)($body['id'] ?? 0);

$db = Database::getInstance();
// Ownership check happens here, and again on the WHERE clause of the
// UPDATE below: belt-and-braces, a row that fails this lookup never
// reaches the mutation.
$scan = $db->fetchOne(
    "SELECT id FROM scans WHERE id = :i AND employee_id = :e AND status != 'deleted'",
    ['i' => $id, 'e' => $ctx['employee_id']]
);
if (!$scan) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

$sets = [];
$vals = [];

if (isset($body['parsed']) && is_array($body['parsed'])) {
    // Untrusted client edit: sanitize to the canonical parse shape (unknown
    // keys dropped, strings capped, phones/emails bounded) before it can be
    // stored on the row or reach the shadow-profile upsert, same guard
    // upload.php applies to the on-device draft.
    $parsed = ScanParser::sanitizeDraft($body['parsed']);
    $sets[] = 'parsed = ?';
    $vals[] = json_encode($parsed, JSON_UNESCAPED_UNICODE);
    $newShadow = ShadowProfileService::upsertFromParsed($parsed);
    if ($newShadow) {
        $sets[] = 'shadow_profile_id = ?';
        $vals[] = $newShadow;
    }
}
if (array_key_exists('tags', $body)) {
    $sets[] = 'tags = ?';
    $vals[] = substr(trim((string)$body['tags']), 0, 500) ?: null;
}
if (array_key_exists('met_where', $body)) {
    $sets[] = 'met_where = ?';
    $vals[] = substr(trim((string)$body['met_where']), 0, 255) ?: null;
}
// What a merge kept, replaced, and what the losing value was. The app writes it
// as JSON and renders it in the user's own language, so nothing here is prose.
// Without this the record of what a merge destroyed never left the device: the
// `merged` tag synced while the explanation did not, leaving a second device
// showing a contact flagged as merged with no way to see what it lost.
// Stored verbatim as an opaque string; the server never interprets it. Only a
// syntactically valid JSON object or array is accepted, so a malformed write
// cannot poison the column the app parses on every pull.
if (array_key_exists('merge_provenance', $body)) {
    $raw = trim((string)$body['merge_provenance']);
    if ($raw === '') {
        // An EXPLICIT empty value clears the column, matching met_at's behaviour.
        $sets[] = 'merge_provenance = ?';
        $vals[] = null;
    } elseif (($clean = ScanParser::mergeProvenanceOrNull($raw)) !== null) {
        $sets[] = 'merge_provenance = ?';
        $vals[] = $clean;
    }
    // Malformed or oversized: SKIP, never null. A bad write must not be able to
    // erase a good history, the same stance this file takes for an invalid met_at.
}
// Same regex + checkdate() combination as upload.php: a syntactically
// matching but calendar-invalid date (e.g. 2026-02-30) never reaches the
// DATE column. An invalid met_at is skipped, not nulled out, so a bad date
// doesn't clobber a previously good one when other fields are being edited
// in the same request.
// An EXPLICIT empty value clears the column. Without this branch the whole
// condition failed for '' and met_at was simply omitted from the UPDATE, so
// clearing "met on" never reached the server and the next pull put the old
// date straight back on the device. A non-empty but invalid date is still
// skipped rather than nulled, preserving the intent described above.
if (array_key_exists('met_at', $body)) {
    $raw = trim((string)$body['met_at']);
    if ($raw === '') {
        $sets[] = 'met_at = ?';
        $vals[] = null;
    } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)
        && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $sets[] = 'met_at = ?';
        $vals[] = $m[0];
    }
}
if (!$sets) {
    echo json_encode(['success' => false, 'error' => 'nothing_to_update']);
    exit;
}

try {
    $vals[] = $id;
    $vals[] = $ctx['employee_id'];
    $db->getConnection()->prepare(
        "UPDATE scans SET " . implode(', ', $sets) . " WHERE id = ? AND employee_id = ?"
    )->execute($vals);
} catch (\Throwable $e) {
    error_log('[scan/update] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode(['success' => true]);
