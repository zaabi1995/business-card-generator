<?php
require_once __DIR__ . '/LogoLibrary.php';

/**
 * LogoTakedownService, takedown state machine.
 *
 * Public submit (rate-limited by IP). Admin decides to hide or reject.
 * Hiding flips om_companies.logo_status to 'takedown' (logo disappears
 * from public UI; files remain in storage pending resolution).
 */
class LogoTakedownService {

    public static function submit(Database $db, int $companyId, array $fields): array {
        $pdo = $db->getConnection();

        // Company ID of 0 + company_hint is the "unresolved" sentinel: admin triages
        // by hint text. Otherwise the company row must exist.
        if ($companyId > 0) {
            $company = $db->fetchOne(
                "SELECT id, name_en, logo_status FROM om_companies WHERE id = :id",
                [':id' => $companyId]
            );
            if (!$company) return ['ok' => false, 'error' => 'Company not found'];
        } elseif (empty($fields['company_hint'])) {
            return ['ok' => false, 'error' => 'Company not specified'];
        }

        $ipHash = LogoLibrary::ipHash();
        $recent = (int) ($db->fetchOne(
            "SELECT COUNT(*) c FROM logo_takedowns
             WHERE ip_hash = :i AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [':i' => $ipHash]
        )['c'] ?? 0);
        if ($recent >= 3) {
            return ['ok' => false, 'error' => 'Too many requests; try again later'];
        }

        // Prepend the company hint to claim_basis so admin triage sees it without
        // needing an extra column.
        $claimBasis = $fields['claim_basis'];
        if (empty($companyId) && !empty($fields['company_hint'])) {
            $claimBasis = '[UNMATCHED COMPANY, hint: ' . $fields['company_hint'] . "]\n\n" . $claimBasis;
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
            ':cb'  => $claimBasis,
            ':pu'  => $fields['proof_url'] ?? null,
            ':ru'  => $fields['related_urls'] ?? null,
            ':ip'  => $ipHash,
        ]);

        return ['ok' => true, 'takedown_id' => (int) $pdo->lastInsertId()];
    }

    public static function hideLogo(Database $db, int $takedownId, string $deciderUserId, ?string $notes): array {
        $t = $db->fetchOne("SELECT company_id FROM logo_takedowns WHERE id = :id", [':id' => $takedownId]);
        if (!$t) return ['ok' => false, 'error' => 'Not found'];

        // Unmatched (company_id=0) takedowns can't be "hidden" directly, the target
        // company needs to be resolved first. Refuse here so admins don't silently
        // close unresolved requests thinking they've hidden something.
        if ((int) $t['company_id'] <= 0) {
            return [
                'ok'    => false,
                'error' => 'Resolve the company for this takedown before hiding. '
                         . 'Edit the company_id on the row (or reject) first.',
            ];
        }

        $pdo = $db->getConnection();

        // Fetch every path we need to purge from disk BEFORE we null them out.
        $paths = $db->fetchOne(
            "SELECT logo_svg_path, logo_png_path, logo_png_512_path,
                    logo_png_2048_path, logo_webp_path, logo_url
               FROM om_companies WHERE id = :id",
            [':id' => $t['company_id']]
        ) ?: [];

        $pdo->prepare("UPDATE logo_takedowns SET status = 'logo_hidden', decided_by = :d, decided_at = NOW(), resolution_notes = :n WHERE id = :id")
            ->execute([':d' => $deciderUserId, ':n' => $notes, ':id' => $takedownId]);

        // Flip status AND clear paths + deprecated logo_url so nothing public can
        // still reach the mark. Physical files are deleted below.
        $pdo->prepare(
            "UPDATE om_companies SET
                logo_status        = 'takedown',
                logo_svg_path      = NULL,
                logo_png_path      = NULL,
                logo_png_512_path  = NULL,
                logo_png_2048_path = NULL,
                logo_webp_path     = NULL,
                logo_url           = NULL,
                logo_updated_at    = NOW()
             WHERE id = :id"
        )->execute([':id' => $t['company_id']]);

        // Delete files on disk. Only touch paths under /storage/logos/ (defensive)
        // so a stray value can't trick us into unlinking outside the library.
        $root = dirname(__DIR__);
        foreach ($paths as $relPath) {
            if (!is_string($relPath) || $relPath === '') continue;
            if (strpos($relPath, '/storage/logos/') !== 0) continue;
            $abs = $root . $relPath;
            if (is_file($abs)) @unlink($abs);
        }

        return ['ok' => true];
    }

    public static function reject(Database $db, int $takedownId, string $deciderUserId, ?string $notes): array {
        $db->getConnection()->prepare(
            "UPDATE logo_takedowns SET status = 'rejected', decided_by = :d, decided_at = NOW(), resolution_notes = :n WHERE id = :id"
        )->execute([':d' => $deciderUserId, ':n' => $notes, ':id' => $takedownId]);
        return ['ok' => true];
    }
}
