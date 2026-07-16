<?php
/**
 * Brand-edit gate. The company brand (colour/logo in company_themes) is SHARED
 * across a company, so a MANAGED company's brand must not be repainted by a
 * regular employee. Rule:
 *   - company UNMANAGED (company_themes.managed = 0 or no theme row) -> allow
 *     (a new / personal company: the person is effectively the owner)
 *   - is company admin  (email == companies.admin_email)            -> allow
 *   - is super-admin    (a users row role=super_admin for the email) -> allow (Ali)
 *   - else (a managed-tenant employee)                              -> deny
 *
 * Per-person designs (the card_designs "wallet") are always editable by their
 * owner and are NOT gated here. See
 * docs/superpowers/specs/2026-07-16-card-designs-brand-model-design.md.
 */
function scanCanEditBrand(Database $db, string $employeeId): bool
{
    $row = $db->fetchOne(
        "SELECT LOWER(TRIM(e.email)) AS email,
                LOWER(TRIM(c.admin_email)) AS admin_email,
                COALESCE(ct.managed, 0) AS managed
           FROM employees e
           JOIN companies c ON c.id = e.company_id
           LEFT JOIN company_themes ct ON ct.company_id = e.company_id
          WHERE e.id = :id",
        ['id' => $employeeId]
    );
    if (!$row) {
        return false;
    }
    // Unmanaged company (personal / new): the owner edits freely.
    if ((int) ($row['managed'] ?? 0) !== 1) {
        return true;
    }
    $email = (string) ($row['email'] ?? '');
    if ($email === '') {
        return false; // managed tenant + no email to authorize against
    }
    // Company admin.
    if ($email === (string) ($row['admin_email'] ?? '')) {
        return true;
    }
    // Super-admin (web users role) - lets Ali edit any managed brand.
    $sa = $db->fetchOne(
        "SELECT 1 FROM users
          WHERE LOWER(TRIM(email)) = :e AND role = 'super_admin' AND status = 'active'
          LIMIT 1",
        ['e' => $email]
    );
    return (bool) $sa;
}
