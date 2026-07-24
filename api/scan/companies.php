<?php
/**
 * GET /api/scan/companies.php
 *
 * Lists companies attached to the authenticated immutable scan account.
 * Employee profile email and phone values are never consulted for membership.
 * An account linked to an active users.id with the super_admin role may also
 * review other active tenants.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'companies', 600);

$db = Database::getInstance();
$accountId = (string) $ctx['account_id'];
$activeCompanyId = (string) $ctx['company_id'];
$isSuperAdmin = !empty($ctx['is_super_admin']);
$companies = [];
$seen = [];

$memberships = $db->fetchAll(
    "SELECT m.employee_id, m.company_id, c.name, c.slug
     FROM scan_account_memberships m
     JOIN scan_accounts a
       ON a.id = m.account_id
      AND a.status = 'active'
     JOIN employees e
       ON e.id = m.employee_id
      AND e.company_id = m.company_id
      AND e.status = 'active'
      AND e.deleted_at IS NULL
     JOIN companies c
       ON c.id = m.company_id
      AND c.status = 'active'
     WHERE m.account_id = :account_id",
    ['account_id' => $accountId]
);

foreach ($memberships as $membership) {
    $companyId = (string) $membership['company_id'];
    if (isset($seen[$companyId])) {
        continue;
    }
    $seen[$companyId] = true;
    $companies[] = [
        'company_id' => $companyId,
        'employee_id' => (string) $membership['employee_id'],
        'name' => (string) $membership['name'],
        'slug' => (string) $membership['slug'],
        'is_active' => $companyId === $activeCompanyId,
        'is_member' => true,
    ];
}

if ($isSuperAdmin) {
    $allCompanies = $db->fetchAll(
        "SELECT c.id AS company_id, c.name, c.slug,
                (
                    SELECT e.id
                    FROM employees e
                    WHERE e.company_id = c.id
                      AND e.status = 'active'
                      AND e.deleted_at IS NULL
                    ORDER BY e.created_at ASC, e.id ASC
                    LIMIT 1
                ) AS active_employee_id
         FROM companies c
         WHERE c.status = 'active'",
        []
    );
    foreach ($allCompanies as $company) {
        $companyId = (string) $company['company_id'];
        if (isset($seen[$companyId]) || empty($company['active_employee_id'])) {
            continue;
        }
        $seen[$companyId] = true;
        $companies[] = [
            'company_id' => $companyId,
            'employee_id' => (string) $company['active_employee_id'],
            'name' => (string) $company['name'],
            'slug' => (string) $company['slug'],
            'is_active' => $companyId === $activeCompanyId,
            'is_member' => false,
        ];
    }
}

usort($companies, static function (array $left, array $right): int {
    if ($left['is_member'] !== $right['is_member']) {
        return $left['is_member'] ? -1 : 1;
    }
    return strcasecmp((string) $left['name'], (string) $right['name']);
});

echo json_encode([
    'success' => true,
    'active_company_id' => $activeCompanyId,
    'is_super_admin' => $isSuperAdmin,
    'companies' => $companies,
]);
