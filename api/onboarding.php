<?php
/**
 * Onboarding API
 * Handles logo upload, template seeding, and onboarding completion
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

header('Content-Type: application/json');

// Auth check
if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$companyId = getCurrentCompanyId();
$companySlug = getCurrentCompanySlug();
if (!$companyId) {
    echo json_encode(['success' => false, 'error' => 'No company context']);
    exit;
}

// CSRF check
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'skip_onboarding') {
    markOnboardingComplete($companyId);
    echo json_encode(['success' => true]);
    exit;
}

if ($action !== 'complete_onboarding') {
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// --- Complete Onboarding ---
$phone    = trim($_POST['phone'] ?? '');
$website  = trim($_POST['website'] ?? '');
$tagline  = trim($_POST['tagline'] ?? '');
$template = preg_replace('/[^a-z0-9\-]/', '', $_POST['template'] ?? 'bhd-classic');
$source   = preg_replace('/[^a-z0-9_\-]/', '', $_POST['source'] ?? 'general');

$db = Database::getInstance();

// 1. Save company defaults (phone/website/tagline as employee defaults via settings)
saveCompanyDefaults($db, $companyId, $phone, $website, $tagline);

// 2. Handle logo upload
$logoPath = null;
if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $logoPath = uploadCompanyLogo($_FILES['logo'], $companyId);
}

// 3. Save logo to company_themes
if ($logoPath || $source === 'bhd') {
    saveCompanyTheme($db, $companyId, $logoPath, $template, $source);
}

// 4. Seed starter template
seedStarterTemplate($db, $companyId, $template);

// 5. Mark onboarding as complete
markOnboardingComplete($companyId);

$redirect = getTenantUrl($companySlug, '/admin/?welcome=1');
echo json_encode(['success' => true, 'redirect' => $redirect]);
exit;

// --- Functions ---

function saveCompanyDefaults($db, $companyId, $phone, $website, $tagline) {
    // Store as metadata in company_themes or a simple update
    // We use company_themes header_text for tagline, and extend with phone/website
    try {
        $existing = $db->fetchOne("SELECT id FROM company_themes WHERE company_id = :id", ['id' => $companyId]);
        if ($existing) {
            $updates = [];
            if ($tagline) $updates['header_text'] = $tagline;
            if (!empty($updates)) {
                $db->update('company_themes', $updates, 'company_id = :id', ['id' => $companyId]);
            }
        }
        // Phone/website stored in session for pre-filling new employee forms
        if ($phone) $_SESSION['default_company_phone'] = $phone;
        if ($website) $_SESSION['default_company_website'] = $website;
    } catch (Exception $e) {
        // Non-fatal
    }
}

function uploadCompanyLogo($file, $companyId) {
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) return null;

    // Detect real MIME from file content, never trust client-supplied type
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $realMime = $finfo->file($file['tmp_name']);
    $mimeToExt = [
        'image/jpeg'     => 'jpg',
        'image/png'      => 'png',
        'image/gif'      => 'gif',
        'image/webp'     => 'webp',
        'image/svg+xml'  => 'svg',
    ];
    if (!isset($mimeToExt[$realMime])) return null;
    $ext = $mimeToExt[$realMime];

    $uploadDir = COMPANIES_UPLOADS_DIR . '/' . $companyId;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    // Delete old logos
    foreach (glob($uploadDir . '/logo.*') as $old) {
        @unlink($old);
    }

    $dest = $uploadDir . '/logo.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;
    @chmod($dest, 0644);

    return getWebPath($dest);
}

function saveCompanyTheme($db, $companyId, $logoPath, $templateKey, $source) {
    $bhdPrimary = '#009bc1';
    $bhdSecondary = '#0f3460';

    $colors = [
        'bhd-classic' => ['primary' => $bhdPrimary, 'secondary' => $bhdSecondary],
        'bhd-navy'    => ['primary' => $bhdPrimary, 'secondary' => '#0f172a'],
        'bhd-sky'     => ['primary' => '#ffffff',   'secondary' => $bhdSecondary],
    ];

    $palette = $colors[$templateKey] ?? $colors['bhd-classic'];
    // Some palettes (e.g. bhd-sky) carry a white primary for the card art;
    // as a digital-card accent that hides every button behind white text.
    // Clamp to a readable shade before persisting (no-op for dark brands).
    require_once INCLUDES_DIR . '/ColorContrast.php';
    $palette['primary'] = ColorContrast::safeAccent((string) $palette['primary']);

    try {
        $existing = $db->fetchOne("SELECT id FROM company_themes WHERE company_id = :id", ['id' => $companyId]);
        if ($existing) {
            $updates = [
                'primary_color'   => $palette['primary'],
                'secondary_color' => $palette['secondary'],
            ];
            if ($logoPath) $updates['logo_path'] = $logoPath;
            $db->update('company_themes', $updates, 'company_id = :id', ['id' => $companyId]);
        } else {
            $data = [
                'id'              => generateUUID(),
                'company_id'      => $companyId,
                'primary_color'   => $palette['primary'],
                'secondary_color' => $palette['secondary'],
                'logo_path'       => $logoPath,
                'created_at'      => dbNow(),
            ];
            $db->insert('company_themes', $data);
        }
    } catch (Exception $e) {
        // Non-fatal
    }
}

function markOnboardingComplete($companyId) {
    try {
        $db = Database::getInstance();
        $db->update(
            'companies',
            ['onboarding_completed' => 1],
            'id = :id',
            ['id' => $companyId]
        );
    } catch (Exception $e) {
        // Column might not exist yet if migration hasn't run
    }
}

