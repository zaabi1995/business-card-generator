<?php
require_once __DIR__ . '/Database.php';

final class ScanClaimTicket
{
    public const TTL_SECONDS = 900;

    public static function issue(Database $db, int $shadowProfileId, string $verifiedIdentifier): string
    {
        if ($shadowProfileId <= 0 || trim($verifiedIdentifier) === '') {
            throw new InvalidArgumentException('Verified claim identity is required');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ticketHash = self::hashToken($token);
        $identifierHash = hash('sha256', strtolower(trim($verifiedIdentifier)));
        $ttlSeconds = self::TTL_SECONDS;
        $pdo = $db->getConnection();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection is unavailable');
        }

        if ($pdo->inTransaction()) {
            throw new LogicException('Claim ticket issuance requires a new transaction');
        }
        $pdo->beginTransaction();

        try {
            $profileStmt = $pdo->prepare(
                "SELECT id, claimed_at, opted_out
                   FROM shadow_profiles
                  WHERE id = :shadow_profile_id
                  FOR UPDATE"
            );
            $profileStmt->execute(['shadow_profile_id' => $shadowProfileId]);
            $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
            if (!$profile || !empty($profile['claimed_at']) || !empty($profile['opted_out'])) {
                throw new RuntimeException('Claim profile is unavailable');
            }

            $revokeStmt = $pdo->prepare(
                "UPDATE scan_claim_tickets
                    SET revoked_at = NOW()
                  WHERE shadow_profile_id = :shadow_profile_id
                    AND consumed_at IS NULL
                    AND revoked_at IS NULL"
            );
            $revokeStmt->execute(['shadow_profile_id' => $shadowProfileId]);

            $insertStmt = $pdo->prepare(
                "INSERT INTO scan_claim_tickets
                    (ticket_hash, shadow_profile_id, verified_identifier_hash, expires_at)
                 VALUES
                    (:ticket_hash, :shadow_profile_id, :identifier_hash, DATE_ADD(NOW(), INTERVAL {$ttlSeconds} SECOND))"
            );
            $insertStmt->execute([
                'ticket_hash' => $ticketHash,
                'shadow_profile_id' => $shadowProfileId,
                'identifier_hash' => $identifierHash,
            ]);

            $pdo->commit();
            return $token;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function findValid(Database $db, string $token): ?array
    {
        if (!self::isValidToken($token)) {
            return null;
        }

        $row = $db->fetchOne(
            "SELECT tickets.id AS ticket_id,
                    tickets.shadow_profile_id,
                    profiles.best_parsed,
                    profiles.email_primary,
                    profiles.phone_primary
               FROM scan_claim_tickets AS tickets
               JOIN shadow_profiles AS profiles
                 ON profiles.id = tickets.shadow_profile_id
              WHERE tickets.ticket_hash = :ticket_hash
                AND tickets.consumed_at IS NULL
                AND tickets.revoked_at IS NULL
                AND tickets.expires_at > NOW()
                AND profiles.claimed_at IS NULL
                AND profiles.opted_out = 0
              LIMIT 1",
            ['ticket_hash' => self::hashToken($token)]
        );
        return $row ?: null;
    }

    public static function lockForRegistration(Database $db, string $token): ?array
    {
        if (!self::isValidToken($token)) {
            return null;
        }

        $pdo = $db->getConnection();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection is unavailable');
        }
        if ($pdo->inTransaction()) {
            throw new LogicException('Claim registration requires a new transaction');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT tickets.id AS ticket_id,
                        tickets.shadow_profile_id
                   FROM scan_claim_tickets AS tickets
                   JOIN shadow_profiles AS profiles
                     ON profiles.id = tickets.shadow_profile_id
                  WHERE tickets.ticket_hash = :ticket_hash
                    AND tickets.consumed_at IS NULL
                    AND tickets.revoked_at IS NULL
                    AND tickets.expires_at > NOW()
                    AND profiles.claimed_at IS NULL
                    AND profiles.opted_out = 0
                  LIMIT 1
                  FOR UPDATE"
            );
            $stmt->execute(['ticket_hash' => self::hashToken($token)]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();
                return null;
            }
            return $row;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function completeRegistration(
        Database $db,
        array $lockedTicket,
        string $companyId,
        ?string $employeeId = null
    ): void {
        $pdo = $db->getConnection();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new LogicException('Claim registration transaction is not active');
        }

        try {
            $ticketStmt = $pdo->prepare(
                "UPDATE scan_claim_tickets
                    SET consumed_at = NOW(),
                        claimed_company_id = :company_id,
                        claimed_employee_id = :employee_id
                  WHERE id = :ticket_id
                    AND shadow_profile_id = :shadow_profile_id
                    AND consumed_at IS NULL
                    AND revoked_at IS NULL
                    AND expires_at > NOW()"
            );
            $ticketStmt->execute([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'ticket_id' => (int) ($lockedTicket['ticket_id'] ?? 0),
                'shadow_profile_id' => (int) ($lockedTicket['shadow_profile_id'] ?? 0),
            ]);
            if ($ticketStmt->rowCount() !== 1) {
                throw new RuntimeException('Claim ticket is no longer valid');
            }

            $profileStmt = $pdo->prepare(
                "UPDATE shadow_profiles
                    SET claimed_at = NOW(),
                        claimed_company_id = :company_id
                  WHERE id = :shadow_profile_id
                    AND claimed_at IS NULL
                    AND opted_out = 0"
            );
            $profileStmt->execute([
                'company_id' => $companyId,
                'shadow_profile_id' => (int) ($lockedTicket['shadow_profile_id'] ?? 0),
            ]);
            if ($profileStmt->rowCount() !== 1) {
                throw new RuntimeException('Claim profile is no longer available');
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function rollBackClaimTransaction(Database $db): void
    {
        $pdo = $db->getConnection();
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    public static function isValidToken(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $token) === 1;
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
