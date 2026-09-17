<?php
/**
 * DivisionLoginToken, how a division head signs in without an account.
 *
 * They have no password and no user row. What proves who they are is the
 * mailbox the approvals already go to, so a one-time link is sent there and
 * holding it is the sign-in. The link lasts 30 minutes, works once, and reaches
 * only that division's own settings.
 *
 * The token is 40 hex characters, stored hashed, exactly as the employee
 * self-edit link does.
 */
class DivisionLoginToken
{
    public const TTL_MINUTES = 30;
    private const BYTES = 20;

    /**
     * Which division does this address speak for?
     *
     * Matches the division's own approver or head address, so nobody can ask
     * for a link to a mailbox that is not on the division already.
     */
    public static function divisionFor(string $companyId, string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return Database::getInstance()->fetchOne(
            "SELECT * FROM departments
              WHERE company_id = :cid
                AND (LOWER(responsible_email) = :e1 OR LOWER(head_email) = :e2)
              ORDER BY portal_enabled DESC
              LIMIT 1",
            ['cid' => $companyId, 'e1' => $email, 'e2' => $email]) ?: null;
    }

    /** @return string the plain token, which only ever appears in the email */
    public static function mint(string $companyId, string $departmentId, string $email, ?string $ip = null): string
    {
        $db    = Database::getInstance();
        $plain = bin2hex(random_bytes(self::BYTES));

        // One live link per division at a time: asking for a new one retires
        // the old, so a link left in an old mail cannot be used later.
        $db->query("UPDATE division_login_tokens SET used_at = NOW()
                     WHERE department_id = :d AND used_at IS NULL",
                   ['d' => $departmentId]);

        $db->insert('division_login_tokens', [
            'id'            => function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16)),
            'company_id'    => $companyId,
            'department_id' => $departmentId,
            'email'         => strtolower(trim($email)),
            'token_hash'    => hash('sha256', $plain),
            // gmdate, not date: PHP runs on Asia/Muscat and the database on UTC,
            // and verify() compares against NOW(). date() gave 4.5 hours, not 30 min.
            'expires_at'    => gmdate('Y-m-d H:i:s', time() + self::TTL_MINUTES * 60),
            'ip'            => $ip,
        ]);
        return $plain;
    }

    /** The row for a live token, or null when it is unknown, spent or expired. */
    public static function verify(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/i', $token)) {
            return null;
        }
        $row = Database::getInstance()->fetchOne(
            "SELECT * FROM division_login_tokens
              WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()
              LIMIT 1",
            ['h' => hash('sha256', $token)]);
        return $row ?: null;
    }

    /** Spend it. True for exactly one caller. */
    public static function consume(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{40}$/i', $token)) {
            return false;
        }
        $stmt = Database::getInstance()->query(
            "UPDATE division_login_tokens SET used_at = NOW()
              WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW()",
            ['h' => hash('sha256', $token)]);
        return $stmt->rowCount() === 1;
    }

    /** Record one changed field, so a division's settings have a history. */
    public static function logChange(array $dept, string $actor, string $field,
                                     ?string $old, ?string $new, ?string $ip = null): void
    {
        try {
            Database::getInstance()->insert('division_setting_changes', [
                'id'            => function_exists('generateUUID') ? generateUUID() : bin2hex(random_bytes(16)),
                'company_id'    => $dept['company_id'],
                'department_id' => $dept['id'],
                'actor'         => $actor,
                'field'         => $field,
                'old_value'     => $old,
                'new_value'     => $new,
                'ip'            => $ip,
            ]);
        } catch (Exception $e) {
            error_log('DivisionLoginToken::logChange ' . $e->getMessage());
        }
    }
}
