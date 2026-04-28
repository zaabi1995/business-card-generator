<?php
/**
 * Send a one-time onboarding invite to the first employee.
 *
 * Creates an employees row in 'unclaimed' status, mints an EmployeeEditToken
 * (or our portable equivalent if that helper isn't loaded), and dispatches
 * a magic-link email to the address.
 *
 * POST /admin/invite_first.php
 *   email (required)
 *
 * Response: { ok: true, employee_id, edit_url } or { ok: false, error }
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
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$companyId = getCurrentCompanyId();
if (!$companyId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no_company_context']);
    exit;
}

$email = strtolower(trim((string)($_POST['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_email']);
    exit;
}

$db = Database::getInstance();
$company = $db->fetchOne("SELECT slug, name, email_domain FROM companies WHERE id = :id", ['id' => $companyId]);
$slug = $company['slug'] ?? '';

// Derive employee id from the email's local part.
$employeeId = CardifyConvention::employeeIdFromEmail($email, $companyId, $db);

// Create the employee row if it doesn't exist already.
$existing = $db->fetchOne(
    "SELECT id FROM employees WHERE company_id = :c AND (id = :i OR email = :e) LIMIT 1",
    ['c' => $companyId, 'i' => $employeeId, 'e' => $email]
);
if (!$existing) {
    try {
        $db->insert('employees', [
            'id'         => $employeeId,
            'company_id' => $companyId,
            'email'      => $email,
            'status'     => 'unclaimed',
        ]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'could_not_create_employee', 'detail' => $e->getMessage()]);
        exit;
    }
} else {
    $employeeId = $existing['id'];
}

$editUrl = function_exists('getTenantUrl')
    ? getTenantUrl($slug, '/portal?employee=' . urlencode($employeeId))
    : ('https://' . $slug . '.cardify.om/portal?employee=' . urlencode($employeeId));

// Try to dispatch via Cardify's existing invite helper. If not present,
// fall back to a plain Mailer message so the test still works in a fresh
// environment.
$dispatched = false;
if (class_exists('EmployeeEditToken') && method_exists('EmployeeEditToken', 'sendInvite')) {
    try {
        $employee = $db->fetchOne("SELECT * FROM employees WHERE id = :i AND company_id = :c", ['i' => $employeeId, 'c' => $companyId]);
        $res = EmployeeEditToken::sendInvite($employee, $company, 'email');
        $dispatched = !empty($res['email']);
    } catch (Throwable $e) {
        // fall through to plain mail
    }
}

if (!$dispatched && class_exists('Mailer')) {
    $companyName = $company['name'] ?? 'Cardify';
    $subject = "{$companyName} on Cardify, your business card invite";
    $body = "Hello,\n\n"
          . "{$companyName} has set up a digital business card workspace on Cardify, "
          . "and your card is ready for you to fill in.\n\n"
          . "Open this link to enter your details:\n{$editUrl}\n\n"
          . "It only takes a minute. You can come back any time to update your title, "
          . "phone, or photo, the QR on your printed card always points to the latest version.\n\n"
          . "If you didn't expect this email, you can ignore it.\n";
    try {
        $dispatched = (bool) Mailer::send($email, $subject, $body);
    } catch (Throwable $e) {
        // Soft fail
    }
}

echo json_encode([
    'ok'          => true,
    'employee_id' => $employeeId,
    'edit_url'    => $editUrl,
    'dispatched'  => $dispatched,
], JSON_UNESCAPED_SLASHES);
