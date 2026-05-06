<?php
/**
 * Print Shop: Upload font files to make them available in the editor.
 *
 * Accepts .ttf / .otf / .woff / .woff2 files. Saves them under
 * /uploads/fonts/<family-slug>/ and appends the family name to the
 * installed-fonts list so future PDF imports recognise them.
 *
 * Route: POST /printshop/upload_fonts.php (multipart/form-data, fields: fonts[])
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

header('Content-Type: application/json');

Auth::requireLogin();
$user = Auth::getCurrentUser();
// Same role list as upload_font.php (singular wizard endpoint) so a
// company admin can drop a missing font from the template editor's
// banner, not just during the onboarding wizard.
$allowedRoles = ['print_shop', 'super_admin', 'admin', 'company_admin', 'company'];
if (!in_array($user['role'], $allowedRoles, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!validateCSRFToken($csrf)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_csrf']);
    exit;
}

if (empty($_FILES['fonts'])) {
    http_response_code(400);
    echo json_encode(['error' => 'no_files']);
    exit;
}

$baseDir = realpath(__DIR__ . '/..') . '/uploads/fonts';
@mkdir($baseDir, 0755, true);

$installedFile = $baseDir . '/installed.txt';
$installed = is_file($installedFile)
    ? array_values(array_filter(array_map('trim', file($installedFile))))
    : [];
$installedSet = array_flip(array_map('strtolower', $installed));

$uploaded = [];
$rejected = [];
$allowed = ['ttf', 'otf', 'woff', 'woff2'];

$count = is_array($_FILES['fonts']['name']) ? count($_FILES['fonts']['name']) : 0;
for ($i = 0; $i < $count; $i++) {
    $err  = $_FILES['fonts']['error'][$i];
    $name = $_FILES['fonts']['name'][$i];
    $tmp  = $_FILES['fonts']['tmp_name'][$i];
    if ($err !== UPLOAD_ERR_OK) {
        $rejected[] = ['name' => $name, 'reason' => 'upload_error_' . $err];
        continue;
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        $rejected[] = ['name' => $name, 'reason' => 'bad_extension'];
        continue;
    }

    // Family slug from filename: "Sora-Regular.ttf" → family "Sora"
    $base = pathinfo($name, PATHINFO_FILENAME);
    $family = preg_split('/[-_\s]/', $base)[0];
    $familySlug = preg_replace('/[^a-z0-9]/i', '', strtolower($family));
    if ($familySlug === '') $familySlug = 'font';

    $familyDir = $baseDir . '/' . $familySlug;
    @mkdir($familyDir, 0755, true);
    $dest = $familyDir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if (!move_uploaded_file($tmp, $dest)) {
        $rejected[] = ['name' => $name, 'reason' => 'move_failed'];
        continue;
    }
    $uploaded[] = [
        'family'    => $family,
        'file'      => basename($dest),
        'web_path'  => '/uploads/fonts/' . $familySlug . '/' . basename($dest),
    ];
    $key = strtolower($family);
    if (!isset($installedSet[$key])) {
        $installed[] = $family;
        $installedSet[$key] = true;
    }
}

file_put_contents($installedFile, implode("\n", $installed) . "\n");

echo json_encode([
    'uploaded' => $uploaded,
    'rejected' => $rejected,
]);
