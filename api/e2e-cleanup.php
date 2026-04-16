<?php
/**
 * E2E test cleanup endpoint.
 *
 * The Playwright smoke-test suite hits /claim with a clearly-fake phone
 * (+96800000000). This endpoint lets the test remove that row after it
 * passes so the leads table doesn't accumulate junk across nightly runs.
 *
 * Security:
 *   - Must send BOTH X-E2E-Test: 1 AND X-E2E-Cleanup-Token matching
 *     the E2E_CLEANUP_TOKEN constant in config.php / env.
 *   - Only deletes rows where source = 'e2e_test' (double belt-and-braces).
 *   - No-op (204) when the token isn't configured — test never blocks.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Opt-in: only active when the token is configured on the server.
$expected = defined('E2E_CLEANUP_TOKEN') ? E2E_CLEANUP_TOKEN : (getenv('E2E_CLEANUP_TOKEN') ?: '');
if ($expected === '') {
    // Quietly disabled — don't leak that the feature exists.
    http_response_code(204);
    exit;
}

// Required headers.
$e2eFlag = $_SERVER['HTTP_X_E2E_TEST'] ?? '';
$token   = $_SERVER['HTTP_X_E2E_CLEANUP_TOKEN'] ?? '';
if ($e2eFlag !== '1' || !hash_equals($expected, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$phone = trim((string)($body['phone'] ?? ''));

// Safety: only ever delete the fixed fake phone, and only test-flagged rows.
$allowed = ['+96800000000'];
if (!in_array($phone, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'phone not in cleanup whitelist']);
    exit;
}

try {
    $db = Database::getInstance();
    $stmt = $db->getConnection()->prepare(
        "DELETE FROM cardify_signup_leads
          WHERE phone = :p
            AND source = 'e2e_test'"
    );
    $stmt->execute([':p' => $phone]);
    $deleted = $stmt->rowCount();

    echo json_encode(['success' => true, 'deleted' => $deleted]);
} catch (Throwable $e) {
    error_log('e2e-cleanup: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'cleanup_failed']);
}
