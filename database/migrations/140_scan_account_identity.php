<?php

/**
 * Introduce immutable native-app accounts and explicit tenant memberships.
 *
 * Profile email and phone fields are deliberately not copied into login aliases.
 * Existing employees keep access through migrated bearer tokens or a successful
 * password proof. Cross-company memberships merge only when the exact stored
 * password hash was copied between different companies.
 */
function migration_140_scan_account_identity(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];

    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_accounts (
                id CHAR(36) NOT NULL PRIMARY KEY,
                user_id VARCHAR(36) NULL,
                password_hash VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scan_account_user (user_id),
                KEY idx_scan_account_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_account_memberships (
                account_id CHAR(36) NOT NULL,
                employee_id VARCHAR(36) NOT NULL,
                company_id VARCHAR(36) NOT NULL,
                membership_role VARCHAR(20) NOT NULL DEFAULT 'member',
                source VARCHAR(40) NOT NULL,
                proof_digest CHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (account_id, employee_id),
                UNIQUE KEY uniq_scan_membership_employee (employee_id),
                UNIQUE KEY uniq_scan_membership_company (account_id, company_id),
                KEY idx_scan_membership_company (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_account_identifiers (
                identifier_hash CHAR(64) NOT NULL PRIMARY KEY,
                account_id CHAR(36) NOT NULL,
                identifier_type VARCHAR(16) NOT NULL,
                identifier_value VARCHAR(190) NOT NULL,
                verified_at DATETIME NULL,
                source VARCHAR(40) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_scan_identifier_account (account_id, identifier_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_identity_migration_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                canonical_account_id CHAR(36) NOT NULL,
                merged_account_id CHAR(36) NOT NULL,
                employee_id VARCHAR(36) NOT NULL,
                evidence_type VARCHAR(40) NOT NULL,
                evidence_digest CHAR(64) NOT NULL,
                merged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scan_identity_merge (employee_id, evidence_type),
                KEY idx_scan_identity_canonical (canonical_account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS scan_identity_user_link_audit (
                account_id CHAR(36) NOT NULL PRIMARY KEY,
                user_id VARCHAR(36) NOT NULL,
                evidence_type VARCHAR(40) NOT NULL,
                evidence_digest CHAR(64) NOT NULL,
                linked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_scan_identity_link_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $column = $pdo->query(
            "SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'scan_api_tokens'
               AND column_name = 'account_id'
             LIMIT 1"
        )->fetchColumn();
        if (!$column) {
            $pdo->exec(
                "ALTER TABLE scan_api_tokens
                 ADD COLUMN account_id CHAR(36) NULL AFTER employee_id,
                 ADD KEY idx_scan_token_account (account_id)"
            );
        }

        $newId = static function (): string {
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $hex = bin2hex($bytes);
            return substr($hex, 0, 8) . '-'
                . substr($hex, 8, 4) . '-'
                . substr($hex, 12, 4) . '-'
                . substr($hex, 16, 4) . '-'
                . substr($hex, 20);
        };

        $pdo->beginTransaction();

        $employees = $pdo->query(
            "SELECT e.id, e.company_id, e.password_hash
             FROM employees e
             JOIN companies c ON c.id = e.company_id
             LEFT JOIN scan_account_memberships m ON m.employee_id = e.id
             WHERE e.status = 'active'
               AND c.status = 'active'
               AND e.deleted_at IS NULL
               AND m.employee_id IS NULL
             ORDER BY e.created_at ASC, e.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $insertAccount = $pdo->prepare(
            "INSERT INTO scan_accounts (id, password_hash, status)
             VALUES (:id, :password_hash, 'active')"
        );
        $insertMembership = $pdo->prepare(
            "INSERT INTO scan_account_memberships
                (account_id, employee_id, company_id, membership_role, source, proof_digest)
             VALUES
                (:account_id, :employee_id, :company_id, 'member', 'legacy_backfill', NULL)"
        );
        foreach ($employees as $employee) {
            $accountId = $newId();
            $insertAccount->execute([
                'id' => $accountId,
                'password_hash' => ($employee['password_hash'] ?? '') !== ''
                    ? (string) $employee['password_hash']
                    : null,
            ]);
            $insertMembership->execute([
                'account_id' => $accountId,
                'employee_id' => (string) $employee['id'],
                'company_id' => (string) $employee['company_id'],
            ]);
        }

        $credentialRows = $pdo->query(
            "SELECT m.account_id, m.employee_id, m.company_id,
                    e.password_hash, e.created_at
             FROM scan_account_memberships m
             JOIN employees e ON e.id = m.employee_id
             JOIN companies c ON c.id = m.company_id
             WHERE e.status = 'active'
               AND c.status = 'active'
               AND e.deleted_at IS NULL
               AND e.password_hash IS NOT NULL
               AND e.password_hash <> ''
             ORDER BY e.created_at ASC, e.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $credentialGroups = [];
        foreach ($credentialRows as $row) {
            $credentialGroups[(string) $row['password_hash']][] = $row;
        }

        $companyExists = $pdo->prepare(
            "SELECT 1 FROM scan_account_memberships
             WHERE account_id = :account_id AND company_id = :company_id
             LIMIT 1"
        );
        $moveMembership = $pdo->prepare(
            "UPDATE scan_account_memberships
             SET account_id = :canonical,
                 source = 'credential_hash_merge',
                 proof_digest = :proof
             WHERE employee_id = :employee_id
               AND account_id = :source_account"
        );
        $insertAudit = $pdo->prepare(
            "INSERT IGNORE INTO scan_identity_migration_audit
                (canonical_account_id, merged_account_id, employee_id,
                 evidence_type, evidence_digest)
             VALUES
                (:canonical, :merged, :employee_id,
                 'credential_hash_merge', :proof)"
        );
        $deleteOrphanAccount = $pdo->prepare(
            "DELETE a FROM scan_accounts a
             LEFT JOIN scan_account_memberships m ON m.account_id = a.id
             WHERE a.id = :account_id
               AND a.user_id IS NULL
               AND m.account_id IS NULL"
        );

        foreach ($credentialGroups as $passwordHash => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $canonical = $rows[0];
            $canonicalAccount = (string) $canonical['account_id'];
            $proof = hash('sha256', $passwordHash);

            foreach (array_slice($rows, 1) as $candidate) {
                $sourceAccount = (string) $candidate['account_id'];
                if ($sourceAccount === $canonicalAccount) {
                    continue;
                }
                if ((string) $candidate['company_id'] === (string) $canonical['company_id']) {
                    continue;
                }
                $companyExists->execute([
                    'account_id' => $canonicalAccount,
                    'company_id' => (string) $candidate['company_id'],
                ]);
                if ($companyExists->fetchColumn()) {
                    continue;
                }

                $moveMembership->execute([
                    'canonical' => $canonicalAccount,
                    'proof' => $proof,
                    'employee_id' => (string) $candidate['employee_id'],
                    'source_account' => $sourceAccount,
                ]);
                if ($moveMembership->rowCount() !== 1) {
                    continue;
                }
                $insertAudit->execute([
                    'canonical' => $canonicalAccount,
                    'merged' => $sourceAccount,
                    'employee_id' => (string) $candidate['employee_id'],
                    'proof' => $proof,
                ]);
                $deleteOrphanAccount->execute(['account_id' => $sourceAccount]);
            }
        }

        $userLinkRows = $pdo->query(
            "SELECT DISTINCT m.account_id, u.id AS user_id, e.password_hash
             FROM scan_account_memberships m
             JOIN employees e ON e.id = m.employee_id
             JOIN users u
               ON BINARY u.password_hash = BINARY e.password_hash
              AND u.status = 'active'
             WHERE e.password_hash IS NOT NULL
               AND e.password_hash <> ''"
        )->fetchAll(PDO::FETCH_ASSOC);
        $usersByAccount = [];
        $accountsByUser = [];
        $proofByPair = [];
        foreach ($userLinkRows as $linkRow) {
            $candidateAccount = (string) $linkRow['account_id'];
            $candidateUser = (string) $linkRow['user_id'];
            $usersByAccount[$candidateAccount][$candidateUser] = true;
            $accountsByUser[$candidateUser][$candidateAccount] = true;
            $proofByPair[$candidateAccount][$candidateUser] = hash(
                'sha256',
                (string) $linkRow['password_hash']
            );
        }
        $linkUser = $pdo->prepare(
            "UPDATE scan_accounts
             SET user_id = :user_id
             WHERE id = :account_id
               AND (user_id IS NULL OR user_id = :same_user_id)"
        );
        $auditUserLink = $pdo->prepare(
            "INSERT IGNORE INTO scan_identity_user_link_audit
                (account_id, user_id, evidence_type, evidence_digest)
             VALUES
                (:account_id, :user_id, 'identical_password_hash', :proof)"
        );
        foreach ($usersByAccount as $candidateAccount => $candidateUsers) {
            if (count($candidateUsers) !== 1) {
                continue;
            }
            $candidateUser = (string) array_key_first($candidateUsers);
            if (count($accountsByUser[$candidateUser] ?? []) !== 1) {
                continue;
            }
            $linkUser->execute([
                'user_id' => $candidateUser,
                'account_id' => $candidateAccount,
                'same_user_id' => $candidateUser,
            ]);
            if ($linkUser->rowCount() === 0) {
                continue;
            }
            $auditUserLink->execute([
                'account_id' => $candidateAccount,
                'user_id' => $candidateUser,
                'proof' => $proofByPair[$candidateAccount][$candidateUser],
            ]);
        }
        $pdo->exec(
            "UPDATE scan_account_memberships m
             JOIN scan_accounts a ON a.id = m.account_id
             JOIN users u
               ON u.id = a.user_id
              AND u.status = 'active'
             SET m.membership_role = 'owner'
             WHERE u.company_id = m.company_id
               AND u.role IN ('company_admin', 'admin', 'company')"
        );

        $pdo->exec(
            "UPDATE scan_api_tokens t
             JOIN scan_account_memberships m ON m.employee_id = t.employee_id
             SET t.account_id = m.account_id
             WHERE t.account_id IS NULL"
        );
        $pdo->exec(
            "UPDATE scan_api_tokens t
             LEFT JOIN scan_accounts a
               ON a.id = t.account_id AND a.status = 'active'
             LEFT JOIN scan_account_memberships m
               ON m.account_id = t.account_id AND m.employee_id = t.employee_id
             LEFT JOIN users u
               ON u.id = a.user_id
              AND u.role = 'super_admin'
              AND u.status = 'active'
             SET revoked = 1
             WHERE a.id IS NULL OR (m.employee_id IS NULL AND u.id IS NULL)"
        );

        $pdo->commit();
        $result['success'] = true;
        $result['messages'][] = 'Immutable scan accounts and memberships created';
        $result['messages'][] = 'Legacy tokens bound to accounts, orphan tokens revoked';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}
