<?php
/**
 * POST /api/scan/switch-company.php  {employee_id}
 * -> Switch the session to another company. Allowed when EITHER the target is
 * the same person (shared email / normalised phone, proved at login) OR the
 * caller is a super-admin (any linked email is an active users.super_admin row),
 * who may act as any active employee in any active company to review/test it.
 * Issues a fresh token bound to the target employee. Bearer-auth.
 *
 * Response: {success, token, employee_id, company_id}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/Phone.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'switch_company', 120);

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) { $body = []; }
$target = trim((string) ($body['employee_id'] ?? ''));
if ($target === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_employee_id']);
    exit;
}

$db = Database::getInstance();
$self = $ctx['employee_id'];

$me = $db->fetchOne("SELECT email, mobile, phone FROM employees WHERE id = :id", ['id' => $self]);
if (!$me) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'employee_not_found']);
    exit;
}

$t = $db->fetchOne(
    "SELECT e.id, e.email, e.mobile, e.phone, e.company_id, e.status AS estat, c.status AS cstat
     FROM employees e JOIN companies c ON c.id = e.company_id
     WHERE e.id = :id",
    ['id' => $target]
);
if (!$t || $t['estat'] !== 'active' || $t['cstat'] !== 'active') {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'target_not_available']);
    exit;
}

// Same person? (shared email or normalised phone)
$email = strtolower(trim($me['email'] ?? ''));
$sameEmail = $email !== '' && strtolower(trim($t['email'] ?? '')) === $email;
$myPhone = null;
foreach (['mobile', 'phone'] as $c) {
    $n = Phone::normalize($me[$c] ?? '');
    if ($n) { $myPhone = $n; break; }
}
$samePhone = false;
if ($myPhone) {
    foreach (['mobile', 'phone'] as $c) {
        if (Phone::normalize($t[$c] ?? '') === $myPhone) { $samePhone = true; break; }
    }
}

$allowed = $sameEmail || $samePhone;

// Otherwise: is the caller a super-admin? Gather the person's linked emails
// (own + every employee row sharing the phone) and check the users table.
if (!$allowed) {
    $myEmails = $email !== '' ? [$email] : [];
    if ($myPhone) {
        $tail = substr(preg_replace('/\D/', '', $myPhone), -8);
        if ($tail !== '') {
            $sib = $db->fetchAll(
                "SELECT e.email, e.mobile, e.phone FROM employees e
                 WHERE e.status = 'active' AND (e.mobile LIKE :a OR e.phone LIKE :b)",
                ['a' => '%' . $tail . '%', 'b' => '%' . $tail . '%']
            );
            foreach ($sib as $s) {
                if (Phone::normalize($s['mobile'] ?? '') === $myPhone
                    || Phone::normalize($s['phone'] ?? '') === $myPhone) {
                    $se = strtolower(trim($s['email'] ?? ''));
                    if ($se !== '') { $myEmails[] = $se; }
                }
            }
        }
    }
    $myEmails = array_values(array_unique(array_filter($myEmails)));
    if ($myEmails) {
        $ph = [];
        $sp = [];
        foreach ($myEmails as $i => $em) { $ph[] = ":e$i"; $sp["e$i"] = $em; }
        $cnt = $db->fetchOne(
            "SELECT COUNT(*) AS n FROM users
             WHERE role = 'super_admin' AND status = 'active'
               AND LOWER(TRIM(email)) IN (" . implode(',', $ph) . ")",
            $sp
        );
        $allowed = ((int) ($cnt['n'] ?? 0)) > 0;
    }
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'not_your_company']);
    exit;
}

$token = ScanAuth::issueToken((string) $t['id']);
echo json_encode([
    'success'     => true,
    'token'       => $token,
    'employee_id' => (string) $t['id'],
    'company_id'  => (string) $t['company_id'],
]);
