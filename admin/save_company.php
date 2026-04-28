<?php
/**
 * Save company name + admin_email during onboarding.
 *
 * If the slug isn't set yet (or doesn't match the email domain), we
 * auto-derive it via CardifyConvention::companySlugFromEmail and update
 * the row. Used by step 1 of /onboarding.php.
 *
 * POST /admin/save_company.php
 *   name (string, required)
 *   admin_email (email, required)
 *
 * Response: { ok: true, slug: "<slug>", tenant_url: "https://<slug>.cardify.om/" }
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';

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

$name  = trim((string)($_POST['name'] ?? ''));
$email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_input']);
    exit;
}

$db = Database::getInstance();
$current = $db->fetchOne("SELECT slug, name, admin_email FROM companies WHERE id = :id", ['id' => $companyId]);

// Derive slug only if blank or super_admin requested a refresh.
$slug = $current['slug'] ?? '';
if ($slug === '') {
    $slug = CardifyConvention::companySlugFromEmail($email, $db, $companyId);
}

$emailDomain = strpos($email, '@') !== false ? substr($email, strpos($email, '@') + 1) : null;

$update = [
    'name'         => $name,
    'admin_email'  => $email,
    'email_domain' => $emailDomain,
    'slug'         => $slug,
    'updated_at'   => date('Y-m-d H:i:s'),
];
$db->update('companies', $update, 'id = :id', ['id' => $companyId]);

// Refresh session slug so post-onboarding redirects use the right host.
$_SESSION['company_slug'] = $slug;

echo json_encode([
    'ok'         => true,
    'slug'       => $slug,
    'tenant_url' => function_exists('getTenantUrl') ? getTenantUrl($slug) : ('https://' . $slug . '.cardify.om/'),
], JSON_UNESCAPED_SLASHES);
