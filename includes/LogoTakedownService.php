<?php
require_once __DIR__ . '/LogoLibrary.php';

/**
 * LogoTakedownService — takedown state machine.
 *
 * Public submit (rate-limited by IP). Admin decides to hide or reject.
 * Hiding flips om_companies.logo_status to 'takedown' (logo disappears
 * from public UI; files remain in storage pending resolution).
 */
class LogoTakedownService {

    public static function submit(Database $db, int $companyId, array $fields): array {
        $pdo = $db->getConnection();

        $company = $db->fetchOne(
            "SELECT id, name_en, logo_status FROM om_companies WHERE id = :id",
            [':id' => $companyId]
        );
        if (!$company) return ['ok' => false, 'error' => 'Company not found'];

        $ipHash = LogoLibrary::ipHash();
        $recent = (int) ($db->fetchOne(
            "SELECT COUNT(*) c FROM logo_takedowns
             WHERE ip_hash = :i AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [':i' => $ipHash]
        )['c'] ?? 0);
        if ($recent >= 3) {
            return ['ok' => false, 'error' => 'Too many requests; try again later'];
        }

        $pdo->prepare(
            "INSERT INTO logo_takedowns
                (company_id, requester_name, requester_email, requester_role,
                 claim_basis, proof_url, related_urls, status, ip_hash)
             VALUES
                (:cid, :rn, :re, :rr, :cb, :pu, :ru, 'received', :ip)"
        )->execute([
            ':cid' => $companyId,
            ':rn'  => $fields['name'],
            ':re'  => $fields['email'],
            ':rr'  => $fields['role'] ?? null,
            ':cb'  => $fields['claim_basis'],
            ':pu'  => $fields['proof_url'] ?? null,
            ':ru'  => $fields['related_urls'] ?? null,
            ':ip'  => $ipHash,
        ]);

        return ['ok' => true, 'takedown_id' => (int) $pdo->lastInsertId()];
    }

    public static function hideLogo(Database $db, int $takedownId, string $deciderUserId, ?string $notes): array {
        $t = $db->fetchOne("SELECT company_id FROM logo_takedowns WHERE id = :id", [':id' => $takedownId]);
        if (!$t) return ['ok' => false, 'error' => 'Not found'];
        $pdo = $db->getConnection();
        $pdo->prepare("UPDATE logo_takedowns SET status = 'logo_hidden', decided_by = :d, decided_at = NOW(), resolution_notes = :n WHERE id = :id")
            ->execute([':d' => $deciderUserId, ':n' => $notes, ':id' => $takedownId]);
        $pdo->prepare("UPDATE om_companies SET logo_status = 'takedown', logo_updated_at = NOW() WHERE id = :id")
            ->execute([':id' => $t['company_id']]);
        return ['ok' => true];
    }

    public static function reject(Database $db, int $takedownId, string $deciderUserId, ?string $notes): array {
        $db->getConnection()->prepare(
            "UPDATE logo_takedowns SET status = 'rejected', decided_by = :d, decided_at = NOW(), resolution_notes = :n WHERE id = :id"
        )->execute([':d' => $deciderUserId, ':n' => $notes, ':id' => $takedownId]);
        return ['ok' => true];
    }
}
