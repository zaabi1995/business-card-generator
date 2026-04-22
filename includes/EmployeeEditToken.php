<?php
/**
 * EmployeeEditToken, magic-link token issuer + verifier for
 * employee self-service edits (Cardify v2.0 Category E).
 *
 * Token is 40 hex chars (20 random bytes). Hashed SHA-256 at rest;
 * plain token only appears in the WhatsApp/email invite.
 *
 * TTL: 30 days from mint. Each successful verify extends last_used_at;
 * if last_used_at falls more than 30 days behind now the token is
 * considered expired regardless of the original expires_at.
 *
 * Minting a new token for a given employee revokes any prior active
 * tokens, so only one magic link per employee is ever valid at once.
 */
class EmployeeEditToken
{
    public const TTL_DAYS = 30;
    public const BYTES = 20; // 40 hex chars

    public static function mint(string $employeeId, ?string $createdBy = null, ?string $ip = null): string
    {
        $db = Database::getInstance();
        // Revoke previous
        $db->update(
            'employee_edit_tokens',
            ['revoked_at' => date('Y-m-d H:i:s')],
            'employee_id = :eid AND revoked_at IS NULL',
            ['eid' => $employeeId]
        );

        $plain = bin2hex(random_bytes(self::BYTES));
        $hash = hash('sha256', $plain);

        $db->insert('employee_edit_tokens', [
            'employee_id' => $employeeId,
            'token_hash'  => $hash,
            'expires_at'  => date('Y-m-d H:i:s', time() + (self::TTL_DAYS * 86400)),
            'created_by'  => $createdBy,
            'ip'          => $ip ? substr($ip, 0, 45) : null,
        ]);

        return $plain;
    }

    /**
     * Look up a plain token. Returns the employee row on success, null
     * on expired/revoked/unknown. Updates last_used_at on hit.
     */
    public static function verify(string $plain): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}$/i', $plain)) return null;
        $hash = hash('sha256', $plain);

        $db = Database::getInstance();
        $row = $db->fetchOne(
            "SELECT t.id, t.employee_id, t.expires_at, t.last_used_at, t.revoked_at, e.*
             FROM employee_edit_tokens t
             JOIN employees e ON e.id = t.employee_id
             WHERE t.token_hash = :h LIMIT 1",
            ['h' => $hash]
        );
        if (!$row) return null;
        if (!empty($row['revoked_at'])) return null;
        if (strtotime($row['expires_at']) < time()) return null;
        // Idle-timeout: more than TTL_DAYS since last use also expires.
        if (!empty($row['last_used_at']) && strtotime($row['last_used_at']) < (time() - self::TTL_DAYS * 86400)) {
            return null;
        }

        $db->update(
            'employee_edit_tokens',
            ['last_used_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $row['id']]
        );

        unset($row['id'], $row['expires_at'], $row['last_used_at'], $row['revoked_at']);
        return $row;
    }

    public static function buildUrl(string $plain): string
    {
        $host = defined('APP_HOST') ? APP_HOST : ($_SERVER['HTTP_HOST'] ?? 'cardify.om');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host . '/portal/employee-edit?token=' . $plain;
    }
}
