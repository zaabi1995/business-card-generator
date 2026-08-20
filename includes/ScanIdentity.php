<?php

/**
 * Immutable identity rules shared by Cardify's native-app authentication flows.
 *
 * Employee email and phone fields belong to the card profile. They are editable
 * content and must never grant tenant membership or elevated privileges.
 */
class ScanIdentity
{
    public static function identifierHash(string $identifier, string $type): string
    {
        $type = strtolower(trim($type));
        $value = trim($identifier);
        if ($type === 'email') {
            $value = strtolower($value);
        }
        return hash('sha256', $type . "\0" . $value);
    }

    public static function canMergeLegacyMembership(array $left, array $right): bool
    {
        $leftCompany = (string) ($left['company_id'] ?? '');
        $rightCompany = (string) ($right['company_id'] ?? '');
        $leftHash = (string) ($left['password_hash'] ?? '');
        $rightHash = (string) ($right['password_hash'] ?? '');

        return $leftCompany !== ''
            && $rightCompany !== ''
            && $leftCompany !== $rightCompany
            && $leftHash !== ''
            && $rightHash !== ''
            && hash_equals($leftHash, $rightHash);
    }

    public static function uniqueAccountId(array $accountIds): ?string
    {
        $unique = [];
        foreach ($accountIds as $accountId) {
            $accountId = trim((string) $accountId);
            if ($accountId !== '') {
                $unique[$accountId] = true;
            }
        }
        return count($unique) === 1 ? (string) array_key_first($unique) : null;
    }

    public static function membershipAuthorizes(
        string $accountId,
        ?array $membership,
        bool $isLinkedSuperAdmin
    ): bool {
        if ($isLinkedSuperAdmin) {
            return true;
        }
        return $membership !== null
            && hash_equals($accountId, (string) ($membership['account_id'] ?? ''));
    }

    public static function newId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }

    public static function findAccountByIdentifier(
        $db,
        string $identifier,
        string $type
    ): ?array {
        $sql = "SELECT a.id AS account_id, a.password_hash, a.user_id,
                       i.verified_at
                FROM scan_account_identifiers i
                JOIN scan_accounts a ON a.id = i.account_id
                WHERE i.identifier_hash = :identifier_hash
                  AND i.identifier_type = :identifier_type
                  AND i.verified_at IS NOT NULL
                  AND a.status = 'active'";
        $sql .= ' LIMIT 1';

        $row = $db->fetchOne($sql, [
            'identifier_hash' => self::identifierHash($identifier, $type),
            'identifier_type' => $type,
        ]);
        return is_array($row) ? $row : null;
    }

    public static function primaryEmployee($db, string $accountId): ?array
    {
        $row = $db->fetchOne(
            "SELECT m.employee_id, m.company_id
             FROM scan_account_memberships m
             JOIN employees e
               ON e.id = m.employee_id
              AND e.company_id = m.company_id
              AND e.status = 'active'
              AND e.deleted_at IS NULL
             JOIN companies c
               ON c.id = m.company_id
              AND c.status = 'active'
             JOIN scan_accounts a
               ON a.id = m.account_id
              AND a.status = 'active'
             WHERE m.account_id = :account_id
             ORDER BY m.created_at ASC, m.employee_id ASC
             LIMIT 1",
            ['account_id' => $accountId]
        );
        return is_array($row) ? $row : null;
    }

    public static function membershipForEmployee(
        $db,
        string $accountId,
        string $employeeId
    ): ?array {
        $row = $db->fetchOne(
            "SELECT m.account_id, m.employee_id, m.company_id
             FROM scan_account_memberships m
             JOIN employees e
               ON e.id = m.employee_id
              AND e.company_id = m.company_id
              AND e.status = 'active'
              AND e.deleted_at IS NULL
             JOIN companies c
               ON c.id = m.company_id
              AND c.status = 'active'
             JOIN scan_accounts a
               ON a.id = m.account_id
              AND a.status = 'active'
             WHERE m.account_id = :account_id
               AND m.employee_id = :employee_id
             LIMIT 1",
            [
                'account_id' => $accountId,
                'employee_id' => $employeeId,
            ]
        );
        return is_array($row) ? $row : null;
    }

    public static function isLinkedSuperAdmin($db, string $accountId): bool
    {
        $row = $db->fetchOne(
            "SELECT 1 AS allowed
             FROM scan_accounts a
             JOIN users u ON u.id = a.user_id
             WHERE a.id = :account_id
               AND a.status = 'active'
               AND u.role = 'super_admin'
               AND u.status = 'active'
             LIMIT 1",
            ['account_id' => $accountId]
        );
        return is_array($row);
    }

    public static function hasIdentifierType($db, string $accountId, string $type): bool
    {
        $row = $db->fetchOne(
            "SELECT 1 AS found
             FROM scan_account_identifiers
             WHERE account_id = :account_id
               AND identifier_type = :identifier_type
               AND verified_at IS NOT NULL
             LIMIT 1",
            [
                'account_id' => $accountId,
                'identifier_type' => $type,
            ]
        );
        return is_array($row);
    }

    public static function linkedIdentifiers($db, string $accountId): array
    {
        $rows = $db->fetchAll(
            "SELECT identifier_type, identifier_value
             FROM scan_account_identifiers
             WHERE account_id = :account_id
               AND verified_at IS NOT NULL
             ORDER BY created_at ASC",
            ['account_id' => $accountId]
        );
        $result = ['email' => null, 'phone' => null];
        foreach ($rows as $row) {
            $type = (string) ($row['identifier_type'] ?? '');
            if (array_key_exists($type, $result) && $result[$type] === null) {
                $result[$type] = (string) ($row['identifier_value'] ?? '');
            }
        }
        return $result;
    }

    public static function linkIdentifier(
        $db,
        string $accountId,
        string $identifier,
        string $type,
        bool $verified,
        string $source
    ): array {
        if (!$verified) {
            return ['success' => false, 'error' => 'verification_required'];
        }

        $type = strtolower(trim($type));
        if (!in_array($type, ['email', 'phone'], true)) {
            return ['success' => false, 'error' => 'invalid_identifier_type'];
        }

        $hash = self::identifierHash($identifier, $type);
        $storedValue = $type === 'email'
            ? strtolower(trim($identifier))
            : trim($identifier);
        $existing = $db->fetchOne(
            "SELECT account_id, verified_at
             FROM scan_account_identifiers
             WHERE identifier_hash = :identifier_hash
               AND identifier_type = :identifier_type
             LIMIT 1",
            [
                'identifier_hash' => $hash,
                'identifier_type' => $type,
            ]
        );
        if (is_array($existing)) {
            $existingAccountId = (string) ($existing['account_id'] ?? '');
            if (!empty($existing['verified_at'])) {
                if (!hash_equals($accountId, $existingAccountId)) {
                    return ['success' => false, 'error' => 'identifier_taken'];
                }
                return ['success' => true, 'already_linked' => true];
            }

            $updated = $db->update(
                'scan_account_identifiers',
                [
                    'account_id' => $accountId,
                    'identifier_type' => $type,
                    'identifier_value' => $storedValue,
                    'verified_at' => dbNow(),
                    'source' => $source,
                ],
                'identifier_hash = :where_hash AND verified_at IS NULL',
                ['where_hash' => $hash]
            );
            if ($updated > 0) {
                return [
                    'success' => true,
                    'already_linked' => false,
                    'replaced_unverified' => true,
                ];
            }

            $existing = $db->fetchOne(
                "SELECT account_id, verified_at
                 FROM scan_account_identifiers
                 WHERE identifier_hash = :identifier_hash
                   AND identifier_type = :identifier_type
                 LIMIT 1",
                [
                    'identifier_hash' => $hash,
                    'identifier_type' => $type,
                ]
            );
            if (is_array($existing)) {
                $existingAccountId = (string) ($existing['account_id'] ?? '');
                if (
                    !empty($existing['verified_at'])
                    && hash_equals($accountId, $existingAccountId)
                ) {
                    return ['success' => true, 'already_linked' => true];
                }
                return ['success' => false, 'error' => 'identifier_taken'];
            }
        }

        try {
            $db->insert('scan_account_identifiers', [
                'identifier_hash' => $hash,
                'account_id' => $accountId,
                'identifier_type' => $type,
                'identifier_value' => $storedValue,
                'verified_at' => dbNow(),
                'source' => $source,
            ]);
        } catch (Throwable $e) {
            $winner = $db->fetchOne(
                "SELECT account_id, verified_at
                 FROM scan_account_identifiers
                 WHERE identifier_hash = :identifier_hash
                   AND identifier_type = :identifier_type
                 LIMIT 1",
                [
                    'identifier_hash' => $hash,
                    'identifier_type' => $type,
                ]
            );
            if (
                is_array($winner)
                && !empty($winner['verified_at'])
                && hash_equals(
                    $accountId,
                    (string) ($winner['account_id'] ?? '')
                )
            ) {
                return ['success' => true, 'already_linked' => true];
            }
            return ['success' => false, 'error' => 'identifier_taken'];
        }
        return ['success' => true, 'already_linked' => false];
    }

    public static function linkVerifiedIdentifier(
        $db,
        string $accountId,
        string $identifier,
        string $type,
        string $source = 'otp_verified'
    ): array {
        return self::linkIdentifier(
            $db,
            $accountId,
            $identifier,
            $type,
            true,
            $source
        );
    }

    public static function createAccountForEmployee(
        $db,
        string $employeeId,
        ?string $passwordHash = null,
        ?string $identifier = null,
        ?string $identifierType = null,
        bool $identifierVerified = false,
        string $source = 'account_created',
        string $membershipRole = 'member'
    ): string {
        $existing = $db->fetchOne(
            "SELECT account_id
             FROM scan_account_memberships
             WHERE employee_id = :employee_id
             LIMIT 1",
            ['employee_id' => $employeeId]
        );
        if (is_array($existing)) {
            $accountId = (string) $existing['account_id'];
            if ($identifier !== null && $identifierType !== null) {
                $linked = self::linkIdentifier(
                    $db,
                    $accountId,
                    $identifier,
                    $identifierType,
                    $identifierVerified,
                    $source
                );
                if (empty($linked['success'])) {
                    throw new RuntimeException((string) $linked['error']);
                }
            }
            return $accountId;
        }

        $employee = $db->fetchOne(
            "SELECT e.id, e.company_id, e.password_hash
             FROM employees e
             JOIN companies c ON c.id = e.company_id
             WHERE e.id = :employee_id
               AND e.status = 'active'
               AND e.deleted_at IS NULL
               AND c.status = 'active'
             LIMIT 1",
            ['employee_id' => $employeeId]
        );
        if (!is_array($employee)) {
            throw new RuntimeException('employee_not_available');
        }

        $pdo = $db->getConnection();
        $started = !$pdo->inTransaction();
        if ($started) {
            $pdo->beginTransaction();
        }
        try {
            $accountId = self::newId();
            $credential = $passwordHash;
            if ($credential === null || $credential === '') {
                $credential = !empty($employee['password_hash'])
                    ? (string) $employee['password_hash']
                    : null;
            }
            $db->insert('scan_accounts', [
                'id' => $accountId,
                'user_id' => null,
                'password_hash' => $credential,
                'status' => 'active',
            ]);
            $db->insert('scan_account_memberships', [
                'account_id' => $accountId,
                'employee_id' => $employeeId,
                'company_id' => (string) $employee['company_id'],
                'membership_role' => in_array(
                    $membershipRole,
                    ['member', 'owner'],
                    true
                ) ? $membershipRole : 'member',
                'source' => $source,
                'proof_digest' => null,
            ]);
            if ($identifier !== null && $identifierType !== null) {
                $linked = self::linkIdentifier(
                    $db,
                    $accountId,
                    $identifier,
                    $identifierType,
                    $identifierVerified,
                    $source
                );
                if (empty($linked['success'])) {
                    throw new RuntimeException((string) $linked['error']);
                }
            }
            if ($started) {
                $pdo->commit();
            }
            return $accountId;
        } catch (Throwable $e) {
            if ($started && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function attachEmployee(
        $db,
        string $accountId,
        string $employeeId,
        string $source,
        string $membershipRole = 'member'
    ): array {
        $account = $db->fetchOne(
            "SELECT id FROM scan_accounts
             WHERE id = :account_id AND status = 'active'
             LIMIT 1",
            ['account_id' => $accountId]
        );
        if (!is_array($account)) {
            return ['success' => false, 'error' => 'account_not_available'];
        }
        $employee = $db->fetchOne(
            "SELECT e.id, e.company_id
             FROM employees e
             JOIN companies c ON c.id = e.company_id
             WHERE e.id = :employee_id
               AND e.status = 'active'
               AND e.deleted_at IS NULL
               AND c.status = 'active'
             LIMIT 1",
            ['employee_id' => $employeeId]
        );
        if (!is_array($employee)) {
            return ['success' => false, 'error' => 'employee_not_available'];
        }
        $existingEmployee = $db->fetchOne(
            "SELECT account_id FROM scan_account_memberships
             WHERE employee_id = :employee_id
             LIMIT 1",
            ['employee_id' => $employeeId]
        );
        if (is_array($existingEmployee)) {
            return hash_equals($accountId, (string) $existingEmployee['account_id'])
                ? ['success' => true, 'already_linked' => true]
                : ['success' => false, 'error' => 'identity_already_bound'];
        }
        $existingCompany = $db->fetchOne(
            "SELECT employee_id FROM scan_account_memberships
             WHERE account_id = :account_id
               AND company_id = :company_id
             LIMIT 1",
            [
                'account_id' => $accountId,
                'company_id' => (string) $employee['company_id'],
            ]
        );
        if (is_array($existingCompany)) {
            return ['success' => false, 'error' => 'company_already_bound'];
        }
        try {
            $db->insert('scan_account_memberships', [
                'account_id' => $accountId,
                'employee_id' => $employeeId,
                'company_id' => (string) $employee['company_id'],
                'membership_role' => in_array(
                    $membershipRole,
                    ['member', 'owner'],
                    true
                ) ? $membershipRole : 'member',
                'source' => $source,
                'proof_digest' => null,
            ]);
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'membership_conflict'];
        }
        return ['success' => true, 'already_linked' => false];
    }
}
