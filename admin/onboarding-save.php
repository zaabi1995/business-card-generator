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
require_once INCLUDES_DIR . '/LogoLibrary.php';

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

// Step 1 (logo): attempt dominant-color extraction and prefill step 2
// colors.primary if the user has not already picked one. Silent on
// failure, saving still proceeds with the original payload.
$extractedColor = null;
if ($step === 1 && !empty($payload['url']) && is_string($payload['url'])
    && strncmp($payload['url'], 'data:image/', 11) === 0
    && class_exists('LogoLibrary')) {
    $extractedColor = extract_logo_dominant_color($payload['url']);
    if ($extractedColor) {
        $payload['dominant_color'] = $extractedColor;
    }
}

try {
    Onboarding::saveStep($companyId, $step, $payload);

    // Merge dominant color into step 2 colors payload if not yet set.
    if ($extractedColor) {
        $state = Onboarding::get($companyId);
        $existingColors = $state['data']['colors'] ?? [];
        // Only prefill if user has not yet picked a primary (step 2 not saved
        // yet, or they left it at the default Cardify teal #009bc1).
        $currentPrimary = strtolower($existingColors['primary'] ?? '');
        if ($currentPrimary === '' || $currentPrimary === '#009bc1') {
            $existingColors['primary'] = $extractedColor;
            if (empty($existingColors['accent'])) {
                $existingColors['accent'] = '#824598';
            }
            Onboarding::saveStep($companyId, 2, $existingColors);
        }
    }

    echo json_encode(['ok' => true, 'extracted_color' => $extractedColor]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save failed']);
}

/**
 * Decode a data:image/... URL, write it to a temp file, and ask
 * LogoLibrary::dominantColor() to inspect it. Returns '#RRGGBB' or null.
 * Caller is responsible for any cleanup beyond the auto-unlink here.
 */
function extract_logo_dominant_color(string $dataUrl): ?string
{
    if (!preg_match('#^data:(image/(png|jpeg|jpg|webp));base64,(.+)$#i', $dataUrl, $m)) {
        return null;
    }
    $bytes = base64_decode($m[3], true);
    if ($bytes === false || strlen($bytes) === 0 || strlen($bytes) > 5 * 1024 * 1024) {
        return null;
    }
    $ext = strtolower($m[2]);
    if ($ext === 'jpg') $ext = 'jpeg';
    $tmp = tempnam(sys_get_temp_dir(), 'cardify_logo_') . '.' . $ext;
    if (@file_put_contents($tmp, $bytes) === false) return null;
    try {
        $color = LogoLibrary::dominantColor($tmp);
    } catch (Throwable $e) {
        $color = null;
    }
    @unlink($tmp);
    return $color;
}
