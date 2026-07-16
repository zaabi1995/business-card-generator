<?php
/**
 * Brand-lock guard. Brand colour + logo live on company_themes (company-scoped),
 * so one employee editing them from the app would repaint EVERY colleague's card
 * (OTECH alone has 260 active employees on one theme row). Only the company
 * admin may change company-wide branding; a solo company (<=1 active member) is
 * always allowed since that is effectively a personal card.
 *
 * There is no role column on employees; the admin signal is
 * companies.admin_email matching the employee's own email (case/space
 * insensitive). Returns true if this employee may write company branding.
 */
function scanCanEditBrand(Database $db, string $employeeId): bool
{
    $row = $db->fetchOne(
        "SELECT LOWER(TRIM(e.email)) AS email, LOWER(TRIM(c.admin_email)) AS admin_email,
                (SELECT COUNT(*) FROM employees x
                   WHERE x.company_id = e.company_id AND x.status = 'active') AS members
           FROM employees e
           JOIN companies c ON c.id = e.company_id
          WHERE e.id = :id",
        ['id' => $employeeId]
    );
    if (!$row) {
        return false;
    }
    if ((int) ($row['members'] ?? 0) <= 1) {
        return true;
    }
    $email = (string) ($row['email'] ?? '');
    $admin = (string) ($row['admin_email'] ?? '');
    return $email !== '' && $admin !== '' && $email === $admin;
}
