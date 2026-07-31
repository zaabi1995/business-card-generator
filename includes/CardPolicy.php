<?php

/**
 * Server authority for profile text and visual-design permissions.
 */
class CardPolicy
{
    public static function forState(
        bool $managed,
        string $membershipRole,
        bool $isSuperAdmin = false
    ): array {
        $role = strtolower(trim($membershipRole));
        if ($managed || $role !== 'owner') {
            return [
                'mode' => $managed ? 'managed_company' : 'unmanaged_company',
                'can_edit_text' => true,
                'can_edit_design' => false,
                'can_choose_design' => false,
            ];
        }
        return [
            'mode' => 'personal',
            'can_edit_text' => true,
            'can_edit_design' => true,
            'can_choose_design' => true,
        ];
    }

    public static function forContext(array $ctx, array $company): array
    {
        $accountId = trim((string) ($ctx['account_id'] ?? ''));
        $employeeId = trim((string) ($ctx['employee_id'] ?? ''));
        $companyId = trim((string) (
            $company['id'] ?? ($ctx['company_id'] ?? '')
        ));
        if ($accountId === '' || $employeeId === '' || $companyId === '') {
            return self::forState(true, 'member', false);
        }
        $row = Database::getInstance()->fetchOne(
            "SELECT m.membership_role,
                    COALESCE(ct.managed, 0) AS managed
               FROM scan_account_memberships m
               LEFT JOIN company_themes ct
                 ON ct.company_id = m.company_id
              WHERE m.account_id = :account_id
                AND m.employee_id = :employee_id
                AND m.company_id = :company_id
              LIMIT 1",
            [
                'account_id' => $accountId,
                'employee_id' => $employeeId,
                'company_id' => $companyId,
            ]
        );
        if (!is_array($row)) {
            return self::forState(
                true,
                'member',
                !empty($ctx['is_super_admin'])
            );
        }
        return self::forState(
            (int) ($row['managed'] ?? 0) === 1,
            (string) ($row['membership_role'] ?? 'member'),
            !empty($ctx['is_super_admin'])
        );
    }
}
