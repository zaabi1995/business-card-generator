<?php
/**
 * Shared brand settings affect every card in a company.
 *
 * Unmanaged personal companies remain editable. Managed-company changes need
 * an owner membership on the immutable scan account, or an account linked by
 * users.id to an active super-admin.
 */
function scanCanEditBrand(
    Database $db,
    string $accountId,
    string $employeeId
): bool {
    $row = $db->fetchOne(
        "SELECT COALESCE(ct.managed, 0) AS managed,
                m.membership_role
         FROM employees e
         JOIN companies c ON c.id = e.company_id
         LEFT JOIN company_themes ct ON ct.company_id = e.company_id
         LEFT JOIN scan_account_memberships m
           ON m.account_id = :account_id
          AND m.employee_id = e.id
          AND m.company_id = e.company_id
         WHERE e.id = :employee_id
           AND e.status = 'active'
           AND e.deleted_at IS NULL
           AND c.status = 'active'
         LIMIT 1",
        [
            'account_id' => $accountId,
            'employee_id' => $employeeId,
        ]
    );
    if (!is_array($row)) {
        return false;
    }
    if ((int) ($row['managed'] ?? 0) !== 1) {
        return true;
    }
    if (in_array(
        (string) ($row['membership_role'] ?? ''),
        ['owner'],
        true
    )) {
        return true;
    }
    return ScanIdentity::isLinkedSuperAdmin($db, $accountId);
}
