<?php
/**
 * POST-only endpoint that persists a single step of the onboarding
 * wizard. Expects JSON body { step, payload }. Also handles ?skip=1
 * to mark the wizard as skipped-for-24h.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Onboarding.php';

requireAdmin();
$companyId = getCurrentCompanyId();
if (!$companyId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not authenticated']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid csrf']);
    exit;
}

// Skip path
if (!empty($_GET['skip'])) {
    Onboarding::markSkipped($companyId);
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || !isset($body['step']) || !isset($body['payload'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid body']);
    exit;
}

$step = (int) $body['step'];
if ($step < 1 || $step > Onboarding::TOTAL_STEPS) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'step out of range']);
    exit;
}

$payload = is_array($body['payload']) ? $body['payload'] : [];

// Strip anything absurdly large (e.g., gigantic logo data URL). 2 MB cap per
// step payload keeps the JSON column well under max_allowed_packet.
$encoded = json_encode($payload);
if ($encoded === false || strlen($encoded) > 2 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'payload too large']);
    exit;
}

try {
    Onboarding::saveStep($companyId, $step, $payload);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save failed']);
}
