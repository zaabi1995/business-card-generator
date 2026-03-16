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

$redirect = getBasePath() . $companySlug . '/admin/?welcome=1';
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
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if ($file['size'] > $maxSize) return null;
    if (!in_array($file['type'], $allowedTypes)) {
        // Double check by extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) return null;
    }

    $uploadDir = COMPANIES_UPLOADS_DIR . '/' . $companyId;
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    // Delete old logos
    foreach (glob($uploadDir . '/logo.*') as $old) {
        @unlink($old);
    }

    $filename = 'logo.' . ($ext ?: 'png');
    $dest = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) return null;

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
                'created_at'      => date('Y-m-d H:i:s'),
            ];
            $db->insert('company_themes', $data);
        }
    } catch (Exception $e) {
        // Non-fatal
    }
}

function seedStarterTemplate($db, $companyId, $templateKey) {
    // Check if company already has templates
    $existing = $db->fetchOne(
        "SELECT COUNT(*) as cnt FROM templates WHERE company_id = :id",
        ['id' => $companyId]
    );
    if (($existing['cnt'] ?? 0) > 0) return; // Already has templates

    $templates = getBhdTemplateDefinitions();
    if (!isset($templates[$templateKey])) {
        $templateKey = 'bhd-classic';
    }

    $def = $templates[$templateKey];
    $pairId = generateUUID();
    $now = date('Y-m-d H:i:s');

    // Insert front template
    $frontId = generateUUID();
    $db->insert('templates', [
        'id'                   => $frontId,
        'company_id'           => $companyId,
        'pair_id'              => $pairId,
        'name'                 => $def['name'] . ' (Front)',
        'side'                 => 'front',
        'background_image_path' => '',
        'fields_json'          => json_encode($def['front_fields']),
        'settings_json'        => json_encode($def['settings']),
        'is_active'            => 1,
        'is_shared'            => 0,
        'created_at'           => $now,
        'updated_at'           => $now,
    ]);

    // Insert back template
    $backId = generateUUID();
    $db->insert('templates', [
        'id'                   => $backId,
        'company_id'           => $companyId,
        'pair_id'              => $pairId,
        'name'                 => $def['name'] . ' (Back)',
        'side'                 => 'back',
        'background_image_path' => '',
        'fields_json'          => json_encode($def['back_fields']),
        'settings_json'        => json_encode($def['settings']),
        'is_active'            => 1,
        'is_shared'            => 0,
        'created_at'           => $now,
        'updated_at'           => $now,
    ]);
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

function getBhdTemplateDefinitions() {
    $standardSettings = [
        'cardSize'        => 'standard',
        'cardOrientation' => 'landscape',
        'dpi'             => 300,
        'bleedEnabled'    => false,
        'bleedSize'       => 3,
        'bleedUnit'       => 'mm',
    ];

    $classicFront = [
        'name_en' => [
            'enabled' => true, 'x' => 60, 'y' => 70,
            'fontSize' => 26, 'fontFamily' => 'Inter', 'fontWeight' => 'bold',
            'fill' => '#1f2937', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
        ],
        'position_en' => [
            'enabled' => true, 'x' => 60, 'y' => 108,
            'fontSize' => 14, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
            'fill' => '#009bc1', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
        ],
        'company_en' => [
            'enabled' => true, 'x' => 60, 'y' => 135,
            'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
            'fill' => '#6b7280', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
        ],
        'phone' => [
            'enabled' => true, 'x' => 60, 'y' => 175,
            'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
            'fill' => '#374151', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
        ],
        'email' => [
            'enabled' => true, 'x' => 60, 'y' => 198,
            'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
            'fill' => '#374151', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
        ],
        'website' => [
            'enabled' => true, 'x' => 60, 'y' => 221,
            'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
            'fill' => '#009bc1', 'textAlign' => 'left', 'originX' => 'left', 'originY' => 'top'
        ],
    ];

    $classicBack = [
        'company_en' => [
            'enabled' => true, 'x' => 500, 'y' => 130,
            'fontSize' => 22, 'fontFamily' => 'Inter', 'fontWeight' => 'bold',
            'fill' => '#1f2937', 'textAlign' => 'center', 'originX' => 'center', 'originY' => 'top'
        ],
        'website' => [
            'enabled' => true, 'x' => 500, 'y' => 165,
            'fontSize' => 12, 'fontFamily' => 'Inter', 'fontWeight' => 'normal',
            'fill' => '#009bc1', 'textAlign' => 'center', 'originX' => 'center', 'originY' => 'top'
        ],
    ];

    $navyFront = array_merge($classicFront, [
        'name_en' => array_merge($classicFront['name_en'], ['fill' => '#ffffff']),
        'position_en' => array_merge($classicFront['position_en'], ['fill' => '#009bc1']),
        'company_en' => array_merge($classicFront['company_en'], ['fill' => '#94a3b8']),
        'phone' => array_merge($classicFront['phone'], ['fill' => '#cbd5e1']),
        'email' => array_merge($classicFront['email'], ['fill' => '#cbd5e1']),
        'website' => array_merge($classicFront['website'], ['fill' => '#38bdf8']),
    ]);

    $skyFront = array_merge($classicFront, [
        'name_en' => array_merge($classicFront['name_en'], ['fill' => '#ffffff']),
        'position_en' => array_merge($classicFront['position_en'], ['fill' => '#e0f7ff']),
        'company_en' => array_merge($classicFront['company_en'], ['fill' => '#cceeff']),
        'phone' => array_merge($classicFront['phone'], ['fill' => '#ffffff']),
        'email' => array_merge($classicFront['email'], ['fill' => '#ffffff']),
        'website' => array_merge($classicFront['website'], ['fill' => '#ffffff']),
    ]);

    return [
        'bhd-classic' => [
            'name'         => 'BHD Classic',
            'front_fields' => $classicFront,
            'back_fields'  => $classicBack,
            'settings'     => array_merge($standardSettings, ['backgroundColor' => '#ffffff']),
        ],
        'bhd-navy' => [
            'name'         => 'BHD Navy',
            'front_fields' => $navyFront,
            'back_fields'  => array_merge($classicBack, [
                'company_en' => array_merge($classicBack['company_en'], ['fill' => '#ffffff']),
                'website' => array_merge($classicBack['website'], ['fill' => '#38bdf8']),
            ]),
            'settings'     => array_merge($standardSettings, ['backgroundColor' => '#0f172a']),
        ],
        'bhd-sky' => [
            'name'         => 'BHD Sky',
            'front_fields' => $skyFront,
            'back_fields'  => array_merge($classicBack, [
                'company_en' => array_merge($classicBack['company_en'], ['fill' => '#ffffff']),
                'website' => array_merge($classicBack['website'], ['fill' => '#ffffff']),
            ]),
            'settings'     => array_merge($standardSettings, ['backgroundColor' => '#009bc1']),
        ],
    ];
}
