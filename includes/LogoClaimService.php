<?php
require_once __DIR__ . '/LogoLibrary.php';

/**
 * LogoClaimService — claim flow state machine.
 *
 * Encapsulates auto-verify logic + manual queue insertion + decide/unclaim.
 * Does NOT handle authentication or file uploads — those live in logo-claim.php.
 */
class LogoClaimService {

    /**
     * Submit a new claim. Returns ['ok'=>bool, 'claim_id'=>int?, 'auto_verified'=>bool?, 'error'=>string?]
     */
    public static function submitClaim(
        Database $db,
        int $companyId,
        string $userId,
        string $userEmail,
        string $proofType,
        ?string $proofUrl,
        ?string $roleAtCompany,
        ?string $note
    ): array {
        $pdo = $db->getConnection();

        $company = $db->fetchOne(
            "SELECT * FROM om_companies WHERE id = :id",
            [':id' => $companyId]
        );
        if (!$company) return ['ok' => false, 'error' => 'Company not found'];

        if (in_array(($company['logo_status'] ?? ''), ['verified', 'takedown'], true)) {
            return ['ok' => false, 'error' => 'Logo already verified or removed'];
        }

        $openCount = (int) ($db->fetchOne(
            "SELECT COUNT(*) c FROM logo_claims WHERE user_id = :u AND status = 'pending'",
            [':u' => $userId]
        )['c'] ?? 0);
        if ($openCount >= 5) {
            return ['ok' => false, 'error' => 'Max 5 pending claims — wait for decisions'];
        }

        $companyClaimCount = (int) ($db->fetchOne(
            "SELECT COUNT(*) c FROM logo_claims
             WHERE company_id = :cid AND created_at > DATE_SUB(NOW(), INTERVAL 90 DAY)",
            [':cid' => $companyId]
        )['c'] ?? 0);
        if ($companyClaimCount >= 10) {
            return ['ok' => false, 'error' => 'This company has reached the claim-attempt limit; contact support'];
        }

        $autoVerify = false;
        if ($proofType === 'domain_email') {
            $companyDomain = $company['website_domain_cache'] ?? null;
            if ($companyDomain && LogoLibrary::emailDomainMatchesCompany($userEmail, $companyDomain)) {
                $matchingCompanies = LogoLibrary::countCompaniesForDomain($db, $companyDomain);
                if ($matchingCompanies === 1) {
                    $autoVerify = true;
                }
            }
        }

        $pdo->prepare(
            "INSERT INTO logo_claims
                (company_id, user_id, proof_type, proof_url, proof_email, role_at_company, note, auto_verified, status, ip_hash)
             VALUES
                (:cid, :uid, :pt, :pu, :pe, :r, :n, :av, :st, :ip)"
        )->execute([
            ':cid' => $companyId,
            ':uid' => $userId,
            ':pt'  => $proofType,
            ':pu'  => $proofUrl,
            ':pe'  => $userEmail,
            ':r'   => $roleAtCompany,
            ':n'   => $note,
            ':av'  => $autoVerify ? 1 : 0,
            ':st'  => $autoVerify ? 'approved' : 'pending',
            ':ip'  => LogoLibrary::ipHash(),
        ]);
        $claimId = (int) $pdo->lastInsertId();

        if ($autoVerify) {
            $pdo->prepare("UPDATE om_companies SET
                logo_status             = 'verified',
                logo_claimed_by_user_id = :u,
                logo_claimed_at         = NOW(),
                logo_verified_at        = NOW(),
                logo_updated_at         = NOW()
              WHERE id = :id")->execute([':u' => $userId, ':id' => $companyId]);

            // Auto-reject any older pending sibling claims on the same company.
            // Otherwise a later decideClaim('approved') on a stale pending row
            // would silently overwrite logo_claimed_by_user_id.
            $pdo->prepare(
                "UPDATE logo_claims SET
                    status         = 'rejected',
                    decided_by     = :u,
                    decided_at     = NOW(),
                    decision_notes = CONCAT(COALESCE(decision_notes, ''),
                        IF(decision_notes IS NULL OR decision_notes = '', '', '\n'),
                        '[auto-rejected: claim #', :sibling_id, ' auto-verified via domain match]')
                 WHERE company_id = :cid
                   AND status     = 'pending'
                   AND id        != :claim_id"
            )->execute([
                ':u'          => $userId,
                ':sibling_id' => $claimId,
                ':cid'        => $companyId,
                ':claim_id'   => $claimId,
            ]);
        } else {
            // Don't downgrade verified/takedown; move 'none'/'indexed' to 'pending'
            $pdo->prepare("UPDATE om_companies SET
                logo_status     = IF(logo_status IN ('verified','takedown'), logo_status, 'pending'),
                logo_updated_at = NOW()
              WHERE id = :id")->execute([':id' => $companyId]);
        }

        return ['ok' => true, 'claim_id' => $claimId, 'auto_verified' => $autoVerify];
    }

    /**
     * Admin decides a pending claim.
     * $decision ∈ ['approved', 'rejected'].
     */
    public static function decideClaim(
        Database $db,
        int $claimId,
        string $deciderUserId,
        string $decision,
        ?string $notes
    ): array {
        $pdo = $db->getConnection();

        $claim = $db->fetchOne(
            "SELECT * FROM logo_claims WHERE id = :id",
            [':id' => $claimId]
        );
        if (!$claim) return ['ok' => false, 'error' => 'Claim not found'];
        if ($claim['status'] !== 'pending') return ['ok' => false, 'error' => 'Already decided'];
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return ['ok' => false, 'error' => 'Invalid decision'];
        }

        $pdo->prepare(
            "UPDATE logo_claims SET status = :s, decided_by = :d, decided_at = NOW(), decision_notes = :n
             WHERE id = :id"
        )->execute([':s' => $decision, ':d' => $deciderUserId, ':n' => $notes, ':id' => $claimId]);

        if ($decision === 'approved') {
            $pdo->prepare("UPDATE om_companies SET
                logo_status             = 'verified',
                logo_claimed_by_user_id = :u,
                logo_claimed_at         = NOW(),
                logo_verified_at        = NOW(),
                logo_updated_at         = NOW()
              WHERE id = :id")->execute([':u' => $claim['user_id'], ':id' => $claim['company_id']]);

            // Auto-reject sibling pending claims on the same company so a later
            // approval can't silently transfer ownership to a different user.
            $pdo->prepare(
                "UPDATE logo_claims SET
                    status         = 'rejected',
                    decided_by     = :d,
                    decided_at     = NOW(),
                    decision_notes = CONCAT(COALESCE(decision_notes, ''),
                        IF(decision_notes IS NULL OR decision_notes = '', '', '\n'),
                        '[auto-rejected: sibling claim #', :sibling_id, ' was approved]')
                 WHERE company_id = :cid
                   AND status     = 'pending'
                   AND id        != :claim_id"
            )->execute([
                ':d'          => $deciderUserId,
                ':sibling_id' => $claimId,
                ':cid'        => $claim['company_id'],
                ':claim_id'   => $claimId,
            ]);
        } else {
            // Only revert 'pending' if there are no other pending claims.
            // Revert direction depends on whether the row actually has a
            // public logo: rows with logo paths → 'indexed' (publicly
            // listable); rows with no logo → 'none' (excluded from the
            // public library). Otherwise a rejected claim on a no-logo
            // company would silently make it appear in the hub.
            $stillPending = (int) ($db->fetchOne(
                "SELECT COUNT(*) c FROM logo_claims
                 WHERE company_id = :cid AND status = 'pending'",
                [':cid' => $claim['company_id']]
            )['c'] ?? 0);
            if ($stillPending === 0) {
                $pdo->prepare("UPDATE om_companies SET
                    logo_status     = IF(logo_status = 'pending',
                        IF(logo_svg_path IS NOT NULL
                           OR logo_png_path IS NOT NULL
                           OR logo_webp_path IS NOT NULL, 'indexed', 'none'),
                        logo_status),
                    logo_updated_at = NOW()
                  WHERE id = :id")->execute([':id' => $claim['company_id']]);
            }
        }

        return ['ok' => true];
    }

    /**
     * Verified claimant unclaims their profile. Reverts to 'indexed'.
     */
    public static function unclaim(Database $db, int $companyId, string $userId): array {
        $company = $db->fetchOne(
            "SELECT logo_claimed_by_user_id, logo_status FROM om_companies WHERE id = :id",
            [':id' => $companyId]
        );
        if (!$company) return ['ok' => false, 'error' => 'Company not found'];
        if ($company['logo_claimed_by_user_id'] !== $userId) {
            return ['ok' => false, 'error' => 'Not your claim'];
        }
        $db->getConnection()->prepare("UPDATE om_companies SET
            logo_status             = 'indexed',
            logo_claimed_by_user_id = NULL,
            logo_claimed_at         = NULL,
            logo_verified_at        = NULL,
            logo_updated_at         = NOW()
          WHERE id = :id")->execute([':id' => $companyId]);
        return ['ok' => true];
    }
}
