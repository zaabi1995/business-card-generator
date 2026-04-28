<?php
/**
 * Apply Theme from Logo Upload
 *
 * Single-step onboarding endpoint. Accepts a logo file, runs LogoPalette
 * to derive primary / secondary / accent, saves the logo + palette into
 * company_themes for the current company. Returns the resulting theme as
 * JSON so the page can preview it before redirecting.
 *
 * POST /admin/apply_theme.php
 *   logo (multipart file, required)
 *
 * Response:
 *   {
 *     "ok": true,
 *     "logo_url": "/uploads/companies/<id>/logo.png",
 *     "primary": "#2d13ea",
 *     "secondary": "#ff7800",
 *     "accent": "#d0ea13",
 *     "tenant_url": "https://otech.cardify.om/"
 *   }
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/LogoPalette.php';

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

if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'no_logo_uploaded']);
    exit;
}

$tmp = $_FILES['logo']['tmp_name'];
$origName = $_FILES['logo']['name'];
$mime = mime_content_type($tmp) ?: '';
$allowed = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'unsupported_format', 'mime' => $mime]);
    exit;
}

$maxBytes = 5 * 1024 * 1024;
if ((int) $_FILES['logo']['size'] > $maxBytes) {
    http_response_code(413);
    echo json_encode(['error' => 'logo_too_large', 'max_mb' => $maxBytes / (1024 * 1024)]);
    exit;
}

// Save the logo under uploads/companies/<id>/logo.<ext>
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION) ?: 'png');
$ext = preg_replace('/[^a-z0-9]/', '', $ext);
if ($ext === '' || strlen($ext) > 5) $ext = 'png';

$companyDir = realpath(__DIR__ . '/..') . '/uploads/companies/' . $companyId;
@mkdir($companyDir, 0755, true);

$logoPath = $companyDir . '/logo.' . $ext;
if (!move_uploaded_file($tmp, $logoPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'cannot_save_logo']);
    exit;
}
@chmod($logoPath, 0644);

// Extract palette
$palette = LogoPalette::extract($logoPath);

// Persist into company_themes (upsert)
$db = Database::getInstance();
$existing = $db->fetchOne("SELECT id FROM company_themes WHERE company_id = :id LIMIT 1", ['id' => $companyId]);

$themeData = [
    'primary_color'   => $palette['primary'],
    'secondary_color' => $palette['secondary'],
    'logo_path'       => '/uploads/companies/' . $companyId . '/logo.' . $ext,
    'updated_at'      => date('Y-m-d H:i:s'),
];

if ($existing) {
    $db->update('company_themes', $themeData, 'id = :id', ['id' => $existing['id']]);
} else {
    $themeData['id'] = (function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16)));
    $themeData['company_id'] = $companyId;
    $themeData['created_at'] = date('Y-m-d H:i:s');
    $db->insert('company_themes', $themeData);
}

// Pull the company slug for the success URL
$company = $db->fetchOne("SELECT slug FROM companies WHERE id = :id LIMIT 1", ['id' => $companyId]);
$slug = $company['slug'] ?? '';
$tenantUrl = $slug !== '' && function_exists('getTenantUrl') ? getTenantUrl($slug) : null;

echo json_encode([
    'ok'         => true,
    'logo_url'   => $themeData['logo_path'],
    'primary'    => $palette['primary'],
    'secondary'  => $palette['secondary'],
    'accent'     => $palette['accent'],
    'tenant_url' => $tenantUrl,
], JSON_UNESCAPED_SLASHES);
