<?php
/**
 * GET /api/scan/companies.php -> the Cardify companies the signed-in person can
 * act as, for the in-app company switcher. Two tiers:
 *   - MEMBER companies: every active employee row (in an active company) whose
 *     email OR normalised phone matches the current employee's (same person,
 *     already proved at login).
 *   - If the person is a SUPER-ADMIN (any of their linked emails is an active
 *     users.super_admin row), ALSO every other active company, acting as that
 *     company's primary active employee. Lets Ali review/test all tenants.
 * Bearer-auth.
 *
 * Response: {success, active_company_id, is_super_admin, companies:[{company_id,
 *   employee_id, name, slug, is_active, is_member}]}  (members first, then A->Z)
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/Phone.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'companies', 600);

$db = Database::getInstance();
$self = $ctx['employee_id'];
$activeCompanyId = (string) $ctx['company_id'];

// Identity of the current person: email + a normalised phone.
$me = $db->fetchOne("SELECT email, mobile, phone FROM employees WHERE id = :id", ['id' => $self]);
$email = strtolower(trim($me['email'] ?? ''));
$phoneNorm = null;
foreach (['mobile', 'phone'] as $c) {
    $n = Phone::normalize($me[$c] ?? '');
    if ($n) { $phoneNorm = $n; break; }
}

// --- Tier 1: member companies (share email or phone) ---
$conds = [];
$params = [];
if ($email !== '') {
    $conds[] = "LOWER(TRIM(e.email)) = :em";
    $params['em'] = $email;
}
$phoneTail = $phoneNorm ? substr(preg_replace('/\D/', '', $phoneNorm), -8) : '';
if ($phoneTail !== '') {
    // Distinct placeholders: this PDO runs with emulation OFF, so a named
    // parameter may not be reused across the statement (HY093 otherwise).
    $conds[] = "(e.mobile LIKE :pt1 OR e.phone LIKE :pt2)";
    $params['pt1'] = '%' . $phoneTail . '%';
    $params['pt2'] = '%' . $phoneTail . '%';
}

$companies = [];
$seen = [];
$myEmails = $email !== '' ? [$email] : [];
if ($conds) {
    $rows = $db->fetchAll(
        "SELECT e.id AS employee_id, e.email, e.mobile, e.phone,
                c.id AS company_id, c.name, c.slug
         FROM employees e JOIN companies c ON c.id = e.company_id
         WHERE e.status = 'active' AND c.status = 'active' AND e.deleted_at IS NULL
           AND (" . implode(' OR ', $conds) . ")",
        $params
    );
    foreach ($rows as $r) {
        $rEmail = strtolower(trim($r['email'] ?? ''));
        $sameEmail = $email !== '' && $rEmail === $email;
        $samePhone = false;
        if ($phoneNorm) {
            foreach (['mobile', 'phone'] as $c) {
                if (Phone::normalize($r[$c] ?? '') === $phoneNorm) { $samePhone = true; break; }
            }
        }
        if (!$sameEmail && !$samePhone) { continue; }
        if ($rEmail !== '') { $myEmails[] = $rEmail; }
        $cid = (string) $r['company_id'];
        if (isset($seen[$cid])) { continue; }
        $seen[$cid] = true;
        $companies[] = [
            'company_id'  => $cid,
            'employee_id' => (string) $r['employee_id'],
            'name'        => (string) $r['name'],
            'slug'        => (string) $r['slug'],
            'is_active'   => $cid === $activeCompanyId,
            'is_member'   => true,
        ];
    }
}

// Safety net: always include the current company.
if (!isset($seen[$activeCompanyId])) {
    $cur = $db->fetchOne("SELECT id, name, slug FROM companies WHERE id = :id", ['id' => $activeCompanyId]);
    if ($cur) {
        $seen[$activeCompanyId] = true;
        $companies[] = [
            'company_id'  => (string) $cur['id'],
            'employee_id' => (string) $self,
            'name'        => (string) $cur['name'],
            'slug'        => (string) $cur['slug'],
            'is_active'   => true,
            'is_member'   => true,
        ];
    }
}

// --- Super-admin check: any linked email that is an active super_admin ---
$isSuper = false;
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
    $isSuper = ((int) ($cnt['n'] ?? 0)) > 0;
}

// --- Tier 2: super-admin sees every active company (act as its primary emp) ---
if ($isSuper) {
    $all = $db->fetchAll(
        "SELECT c.id AS company_id, c.name, c.slug,
                (SELECT e.id FROM employees e
                 WHERE e.company_id = c.id AND e.status = 'active' AND e.deleted_at IS NULL
                 ORDER BY (CASE WHEN e.email <> '' THEN 0 ELSE 1 END), e.created_at ASC
                 LIMIT 1) AS act_emp
         FROM companies c WHERE c.status = 'active'",
        []
    );
    foreach ($all as $a) {
        $cid = (string) $a['company_id'];
        if (isset($seen[$cid])) { continue; }
        if (empty($a['act_emp'])) { continue; } // no one to act as
        $seen[$cid] = true;
        $companies[] = [
            'company_id'  => $cid,
            'employee_id' => (string) $a['act_emp'],
            'name'        => (string) $a['name'],
            'slug'        => (string) $a['slug'],
            'is_active'   => false,
            'is_member'   => false,
        ];
    }
}

// Members first, then alphabetical.
usort($companies, function ($a, $b) {
    if ($a['is_member'] !== $b['is_member']) { return $a['is_member'] ? -1 : 1; }
    return strcasecmp($a['name'], $b['name']);
});

echo json_encode([
    'success'           => true,
    'active_company_id' => $activeCompanyId,
    'is_super_admin'    => $isSuper,
    'companies'         => $companies,
]);
