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
require_once INCLUDES_DIR . '/OnboardingImport.php';
require_once INCLUDES_DIR . '/EmployeeEditToken.php';
require_once INCLUDES_DIR . '/AuditLog.php';

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

// Validate the step payload. Enforce strictness for steps that must
// have data to move the wizard forward (2=colors, 4=first employee,
// 7=order qty if non-zero). Intermediate saves get lenient mode.
$strict = in_array($step, [2, 4, 7], true);
$validationErrors = Onboarding::validatePayload($step, $payload, $strict);
if ($validationErrors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'validation_failed', 'errors' => $validationErrors]);
    exit;
}

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

// Step 6 (invite team): parse CSV server-side if a full content blob
// was uploaded, stash the parsed rows on the payload so the step-7
// finish hook can bulk-commit + dispatch invites.
$csvParsed = null;
if ($step === 6 && !empty($payload['csv']['content']) && is_string($payload['csv']['content'])) {
    $csvParsed = OnboardingImport::parseCsv($payload['csv']['content']);
    $payload['csv']['parsed'] = [
        'total'   => $csvParsed['total'],
        'rows'    => $csvParsed['rows'],
        'errors'  => $csvParsed['errors'],
        'preview' => $csvParsed['preview'],
    ];
    // Drop the raw content once parsed; rows are what we need.
    unset($payload['csv']['content']);
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

    // Final wizard step. Five things happen atomically with finish:
    //   1. Wipe the 5 demo placeholder employees (Sarah/Ahmed/Fatma/...).
    //   2. Persist the uploaded logo (data:image base64) to a real file
    //      and upsert company_themes so the digital card shows the brand.
    //   3. Mark the most recent imported PDF template pair as active
    //      and set companies.default_{front,back}_template_id so cards
    //      render with that design instead of the seeded BHD-Classic.
    //   4. Insert the admin as their own first employee (matches the
    //      hosn.cardify.om/<localpart> URL the wizard preview promises).
    //   5. Commit parsed CSV rows saved at step 6 (legacy 7-step flow).
    $importResult = null;
    $firstEmpResult = null;
    if ($step === Onboarding::TOTAL_STEPS) {
        $state = Onboarding::get($companyId);

        // 1. Clear demos
        try {
            require_once INCLUDES_DIR . '/DemoData.php';
            DemoData::clear($companyId);
        } catch (Throwable $e) {
            error_log('[onboarding] demo clear failed: ' . $e->getMessage());
        }

        // 2. Persist logo + upsert company_themes
        try {
            $logoBlob = $state['data']['logo'] ?? [];
            $primaryColor = trim((string) ($logoBlob['dominant_color'] ?? ''))
                ?: trim((string) ($state['data']['colors']['primary'] ?? ''))
                ?: '#009bc1';
            // The dominant colour is sampled client-side and on a logo with a
            // white background it comes back near-white (e.g. #f1f6f8), which
            // makes every accent-tinted button/header on the shared card
            // invisible. Clamp to a readable shade before persisting.
            require_once INCLUDES_DIR . '/ColorContrast.php';
            $primaryColor = ColorContrast::safeAccent($primaryColor);
            $logoUrl = (string) ($logoBlob['url'] ?? '');
            $logoPath = null;
            if ($logoUrl !== '' && strncmp($logoUrl, 'data:image/', 11) === 0) {
                $logoPath = onboarding_save_logo_file($companyId, $logoUrl);
            }
            // Optional light/reverse logo (white, for dark headers / Apple
            // Wallet). Saved as the "-dark" sibling of the main logo so
            // wallet_apple.php + the reverse-logo convention use it on dark
            // backgrounds. Additive: absent => unchanged behaviour.
            $lightUrl = (string) (($logoBlob['light']['url'] ?? '') ?: '');
            if ($logoPath && $lightUrl !== '' && strncmp($lightUrl, 'data:image/', 11) === 0) {
                onboarding_save_dark_logo_sibling($logoPath, $lightUrl);
            }
            $db = Database::getInstance();
            $existingTheme = $db->fetchOne(
                "SELECT id FROM company_themes WHERE company_id = :cid LIMIT 1",
                ['cid' => $companyId]
            );
            $themeData = ['primary_color' => $primaryColor];
            if ($logoPath !== null) $themeData['logo_path'] = $logoPath;
            if ($existingTheme) {
                $db->update('company_themes', $themeData, 'company_id = :cid', ['cid' => $companyId]);
            } else {
                $themeData['id']         = generateUUID();
                $themeData['company_id'] = $companyId;
                $db->insert('company_themes', $themeData);
            }
        } catch (Throwable $e) {
            error_log('[onboarding] theme persist failed: ' . $e->getMessage());
        }

        // 3. Mark latest imported template pair active for this company
        try {
            $db = Database::getInstance();
            $pair = $db->fetchOne(
                "SELECT pair_id, MAX(created_at) AS ts FROM templates
                 WHERE company_id = :cid AND pair_id IS NOT NULL
                   AND deleted_at IS NULL AND has_vector_source = 1
                 GROUP BY pair_id ORDER BY ts DESC LIMIT 1",
                ['cid' => $companyId]
            );
            if ($pair && !empty($pair['pair_id'])) {
                $front = $db->fetchOne(
                    "SELECT id FROM templates WHERE company_id = :cid
                       AND pair_id = :p AND side = 'front' AND deleted_at IS NULL LIMIT 1",
                    ['cid' => $companyId, 'p' => $pair['pair_id']]
                );
                $back = $db->fetchOne(
                    "SELECT id FROM templates WHERE company_id = :cid
                       AND pair_id = :p AND side = 'back' AND deleted_at IS NULL LIMIT 1",
                    ['cid' => $companyId, 'p' => $pair['pair_id']]
                );
                if ($front || $back) {
                    // Deactivate everything else first
                    $db->query(
                        "UPDATE templates SET is_active = 0
                         WHERE company_id = :cid AND pair_id != :p",
                        ['cid' => $companyId, 'p' => $pair['pair_id']]
                    );
                    $db->query(
                        "UPDATE templates SET is_active = 1
                         WHERE company_id = :cid AND pair_id = :p",
                        ['cid' => $companyId, 'p' => $pair['pair_id']]
                    );
                    $companyUpdate = [];
                    if ($front) $companyUpdate['default_front_template_id'] = $front['id'];
                    if ($back)  $companyUpdate['default_back_template_id']  = $back['id'];
                    if ($companyUpdate) {
                        $db->update('companies', $companyUpdate, 'id = :id', ['id' => $companyId]);
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[onboarding] template activation failed: ' . $e->getMessage());
        }

        // 4. Insert first employee from saveMeta-seeded state
        $firstEmp = $state['data']['first_employee'] ?? [];
        $feEmail  = trim((string) ($firstEmp['email'] ?? ''));
        $feName   = trim((string) ($firstEmp['name']  ?? ''));
        if ($feEmail !== '' && $feName !== '' && filter_var($feEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                $db = Database::getInstance();
                $existing = $db->fetchOne(
                    "SELECT id FROM employees WHERE company_id = :cid AND LOWER(email) = LOWER(:em) LIMIT 1",
                    ['cid' => $companyId, 'em' => $feEmail]
                );
                if (!$existing) {
                    require_once INCLUDES_DIR . '/CardifyConvention.php';
                    $eid = CardifyConvention::employeeIdFromEmail($feEmail, $companyId, $db);
                    $db->insert('employees', [
                        'id'          => $eid,
                        'company_id'  => $companyId,
                        'email'       => $feEmail,
                        'name_en'     => $feName,
                        'position_en' => trim((string) ($firstEmp['title'] ?? '')) ?: null,
                        'phone'       => trim((string) ($firstEmp['phone'] ?? '')) ?: null,
                        'mobile'      => trim((string) ($firstEmp['phone'] ?? '')) ?: null,
                        'status'      => 'active',
                    ]);
                    $firstEmpResult = ['inserted' => true, 'id' => $eid, 'email' => $feEmail];
                } else {
                    $firstEmpResult = ['inserted' => false, 'reason' => 'already_exists'];
                }
            } catch (Throwable $e) {
                error_log('[onboarding] first_employee insert failed: ' . $e->getMessage());
            }
        }

        // 5. CSV commit (legacy)
        $rows = $state['data']['invite_team']['csv']['parsed']['rows'] ?? [];
        if (!empty($rows) && is_array($rows)) {
            try {
                $importResult = OnboardingImport::commit($companyId, $rows);
            } catch (Throwable $e) {
                error_log('[onboarding] csv commit failed: ' . $e->getMessage());
            }
        }
    }

    $resp = ['ok' => true, 'extracted_color' => $extractedColor];
    if ($csvParsed !== null) {
        $resp['csv'] = [
            'total'   => $csvParsed['total'],
            'errors'  => $csvParsed['errors'],
            'preview' => $csvParsed['preview'],
        ];
    }
    if ($importResult !== null) {
        $resp['import'] = $importResult;
    }
    if ($firstEmpResult !== null) {
        // Wizard JS uses this to redirect through admin/auto_generate.php
        // so the browser renders the new employee's card front+back PNGs
        // before landing on the dashboard. Otherwise their /<localpart>
        // URL would only show the basic contact-info page (no flip card)
        // until an admin manually clicks "Auto-generate cards".
        $resp['first_employee'] = $firstEmpResult;
    }
    echo json_encode($resp);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'save failed']);
}

/**
 * Persist a data:image base64 logo to /uploads/logos/<companyId>.<ext>
 * and return the web-relative path (e.g. /uploads/logos/abc.png) for
 * storage in company_themes.logo_path. Returns null on failure.
 */
function onboarding_save_logo_file(string $companyId, string $dataUrl): ?string
{
    if (!preg_match('#^data:(image/(png|jpeg|jpg|webp|svg\+xml));base64,(.+)$#i', $dataUrl, $m)) {
        return null;
    }
    $mimeMap = ['image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg','image/webp'=>'webp','image/svg+xml'=>'svg'];
    $mime = strtolower($m[1]);
    $ext = $mimeMap[$mime] ?? 'png';
    $bin = base64_decode(strtr($m[3], "\n\r\t ", ''), true);
    if ($bin === false || strlen($bin) === 0) return null;
    $logosDir = realpath(__DIR__ . '/..') . '/uploads/logos';
    if (!is_dir($logosDir)) @mkdir($logosDir, 0755, true);
    if (!is_dir($logosDir)) return null;
    $filename = preg_replace('/[^a-z0-9-]/i', '', $companyId) . '.' . $ext;
    $absPath  = $logosDir . '/' . $filename;
    if (file_put_contents($absPath, $bin) === false) return null;
    @chmod($absPath, 0644);
    return '/uploads/logos/' . $filename;
}

/**
 * Save an optional light/reverse logo as the "-dark" sibling of the main logo
 * (e.g. /uploads/logos/<id>.png -> <id>-dark.png). wallet_apple.php and the
 * reverse-logo convention pick it up automatically for dark headers. Keeps the
 * uploaded image's true extension; the wallet -dark lookup globs by extension.
 */
function onboarding_save_dark_logo_sibling(string $mainLogoWebPath, string $dataUrl): ?string
{
    if (!preg_match('#^data:(image/(png|jpeg|jpg|webp|svg\+xml));base64,(.+)$#i', $dataUrl, $m)) {
        return null;
    }
    $mimeMap = ['image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg','image/webp'=>'webp','image/svg+xml'=>'svg'];
    $ext = $mimeMap[strtolower($m[1])] ?? 'png';
    $bin = base64_decode(strtr($m[3], "\n\r\t ", ''), true);
    if ($bin === false || strlen($bin) === 0) return null;
    $root = realpath(__DIR__ . '/..');
    if (!$root) return null;
    $rel = ltrim($mainLogoWebPath, '/');                 // uploads/logos/<id>.png
    $base = (strrpos($rel, '.') !== false) ? substr($rel, 0, strrpos($rel, '.')) : $rel;
    $darkRel = $base . '-dark.' . $ext;
    $absPath = $root . '/' . $darkRel;
    $dir = dirname($absPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (file_put_contents($absPath, $bin) === false) return null;
    @chmod($absPath, 0644);
    return '/' . $darkRel;
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
