<?php
/**
 * AdminApprovalToken, scoped magic-link tokens for one-tap admin
 * approvals (leave-company requests, edit requests, etc.) sent by
 * WhatsApp/email so an admin can approve without logging in.
 *
 * Token is 40 hex chars (20 random bytes), stored plain (unlike
 * EmployeeEditToken which hashes at rest) since these are short-lived
 * (7 days) and single-use once consumed via consumeApprove().
 *
 * Each token is scoped to exactly one company_id + request_id, so a
 * leaked link can never approve anything outside the request it was
 * minted for.
 */
class AdminApprovalToken
{
    public const TTL_DAYS = 7;
    public const BYTES = 20; // 40 hex chars

    /**
     * Mint a fresh token for a company/request/admin. Returns the
     * plain 40-char token to embed in the approval link.
     */
    public static function mint(string $companyId, string $requestId, string $adminEmail, string $purpose = 'card_request'): string
    {
        $db = Database::getInstance();
        $plain = bin2hex(random_bytes(self::BYTES));
        $id = function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16));

        $db->insert('admin_approval_tokens', [
            'id'           => $id,
            'company_id'   => $companyId,
            'request_id'   => $requestId,
            'admin_email'  => $adminEmail,
            'purpose'      => $purpose,
            'token'        => $plain,
            // gmdate, not date. config.php sets Asia/Muscat but the PDO
            // connection never issues SET time_zone, so MySQL stays on UTC and
            // the two reads below compare this column against NOW(). Stamping
            // it in Muscat wall-clock wrote it 4 hours ahead of the clock it is
            // judged by, so a 7-day token stayed valid for 7 days and 4 hours.
            // OtpService already does it this way.
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + (self::TTL_DAYS * 86400)),
        ]);

        return $plain;
    }

    /**
     * Look up a plain token. Returns the row (company_id, request_id,
     * admin_email, expires_at, used_at) on success, null if unknown, expired
     * or already used. A spent token is refused here rather than at the action,
     * because verifying one also opens an admin session; to tell a person that
     * their link was already used, call wasUsed() and say so without granting
     * anything.
     */
    public static function verify(string $token, ?string $expectedPurpose = null): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/i', $token)) return null;

        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT company_id, request_id, admin_email, purpose, expires_at, used_at
             FROM admin_approval_tokens
             WHERE token = :token AND expires_at > NOW() LIMIT 1",
            ['token' => $token]
        );

        if (!$row) { return null; }
        // A spent token is not a credential. verify() used to answer on
        // expires_at alone, so a link that had already approved its card went
        // on minting a company-wide admin session for the rest of its seven
        // days: anyone the mail was forwarded to held an admin session.
        if (!empty($row['used_at'])) { return null; }
        // A token minted to approve a card request must not be able to sign a
        // delivery note, and the reverse. Callers that care pass the purpose
        // they expect; callers that do not keep today's behaviour.
        if ($expectedPurpose !== null && ($row['purpose'] ?? 'card_request') !== $expectedPurpose) {
            return null;
        }
        return $row;
    }

    /** True when this token exists but has already been used. Grants nothing. */
    public static function wasUsed(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{40}$/i', $token)) { return false; }
        $row = Database::getInstance()->fetchOne(
            "SELECT used_at FROM admin_approval_tokens WHERE token = :t LIMIT 1", ['t' => $token]);
        return (bool)($row['used_at'] ?? null);
    }

    /**
     * Single-use consume for the one-tap approve action. Race-safe:
     * the UPDATE only matches rows still unused, so two simultaneous
     * clicks on the same link can never both succeed.
     */
    public static function consumeApprove(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{40}$/i', $token)) return false;

        $db = Database::getInstance();
        $affected = $db->update(
            'admin_approval_tokens',
            ['used_at' => dbNow()],
            'token = :tok AND used_at IS NULL AND expires_at > NOW()',
            ['tok' => $token]
        );

        return $affected === 1;
    }

    /**
     * Start an admin session scoped strictly to the token's company,
     * mirroring the session keys Auth.php sets for a company admin
     * login so the rest of the app (Auth::getCurrentUser(),
     * Auth::checkRole('admin'), etc.) recognises the session.
     */
    public static function startAdminSession(array $row): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = 'company_' . $row['company_id'];
        $_SESSION['company_id'] = $row['company_id'];
        $_SESSION['user_company_id'] = $row['company_id'];
        $_SESSION['user_role'] = 'admin';
        $_SESSION['user_email'] = $row['admin_email'] ?? null;

        if (empty($row['company_slug']) || empty($row['company_name'])) {
            $db = Database::getInstance();
            $company = $db->fetchOne(
                'SELECT slug, name FROM companies WHERE id = :cid LIMIT 1',
                ['cid' => $row['company_id']]
            );
            if ($company) {
                $row['company_slug'] = $row['company_slug'] ?? $company['slug'];
                $row['company_name'] = $row['company_name'] ?? $company['name'];
            }
        }

        $_SESSION['company_slug'] = $row['company_slug'] ?? null;
        $_SESSION['company_name'] = $row['company_name'] ?? null;

        // Mark it for what it is. This session came from a link in an email,
        // not from a login, and the only person holding it is one division's
        // approver. Pages that show more than one division read this and scope
        // themselves; without it the console handed every division's jobs and
        // documents to whoever clicked an approval link.
        $_SESSION['magic_link'] = true;
        $_SESSION['magic_link_email'] = $row['admin_email'] ?? null;
        $_SESSION['magic_link_request'] = $row['request_id'] ?? null;
    }
}
