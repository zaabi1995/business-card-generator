<?php
/**
 * Persist onboarding progress on each step transition.
 *
 * POST /admin/record_step.php
 *   step  (int 1..3)
 *   data  (JSON, optional)
 *
 * Returns: { ok: true, step: <new>, completed: bool }
 *
 * Used for "resume where you left off" so closing the tab mid-onboarding
 * doesn't drop the user back at step 1 the next time they sign in.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Onboarding.php';

header('Content-Type: application/json');
Auth::requireLogin();

$user = Auth::getCurrentUser();
$role = $user['role'] ?? '';
if (!in_array($role, ['admin', 'company_admin', 'company', 'super_admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$companyId = getCurrentCompanyId();
if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'no_company_context']);
    exit;
}

$step = (int)($_POST['step'] ?? 0);
if ($step < 1 || $step > Onboarding::TOTAL_STEPS) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_step', 'min' => 1, 'max' => Onboarding::TOTAL_STEPS]);
    exit;
}

$data = [];
if (!empty($_POST['data'])) {
    $decoded = json_decode((string)$_POST['data'], true);
    if (is_array($decoded)) $data = $decoded;
}

try {
    Onboarding::saveStep($companyId, $step, $data);
} catch (Throwable $e) {
    error_log('[admin/record_step] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error' => 'save_failed']);
    exit;
}

$state = Onboarding::get($companyId);
echo json_encode([
    'ok'        => true,
    'step'      => (int)($state['step'] ?? $step),
    'completed' => !empty($state['completed_at']),
], JSON_UNESCAPED_SLASHES);
