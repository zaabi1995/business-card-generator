<?php
// ScanAuth: long-lived bearer tokens for the Cardify Scan mobile app.
// Tokens are random 32 bytes, stored as sha256 hashes in scan_api_tokens.
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ScanIdentity.php';

class ScanAuth {

    public static function generateToken(): string {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string {
        return hash('sha256', $token);
    }

    // $employeeId is a string: employees.id is VARCHAR(36) (UUID), not an
    // int as the brief assumed. See task-2-report.md for the schema-type
    // concern this uncovered in Task 1's scan_api_tokens.employee_id column.
    public static function issueToken(
        string $employeeId,
        string $label = 'mobile',
        ?string $accountId = null
    ): string {
        $db = Database::getInstance();
        if ($accountId === null) {
            $membership = $db->fetchOne(
                "SELECT account_id
                 FROM scan_account_memberships
                 WHERE employee_id = :employee_id
                 LIMIT 1",
                ['employee_id' => $employeeId]
            );
            $accountId = is_array($membership)
                ? (string) $membership['account_id']
                : null;
        }
        if ($accountId === null || $accountId === '') {
            throw new RuntimeException('identity_not_bound');
        }
        $membership = ScanIdentity::membershipForEmployee($db, $accountId, $employeeId);
        $isSuperAdmin = ScanIdentity::isLinkedSuperAdmin($db, $accountId);
        if (!ScanIdentity::membershipAuthorizes($accountId, $membership, $isSuperAdmin)) {
            throw new RuntimeException('identity_not_authorized');
        }

        $token = self::generateToken();
        $db->insert('scan_api_tokens', [
            'employee_id' => $employeeId,
            'account_id'  => $accountId,
            'token_hash'  => self::hashToken($token),
            'label'       => $label,
        ]);
        return $token;
    }

    // Authorization header with fallbacks: some FPM/Apache setups drop
    // HTTP_AUTHORIZATION or rename it REDIRECT_HTTP_AUTHORIZATION after a
    // rewrite. Same three-tier pattern as wsAuthToken() in
    // wc_wallet_service.php, which hit this trap first.
    private static function getAuthorizationHeader(): string {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '') {
            $hdrs = [];
            if (function_exists('getallheaders')) { $hdrs = getallheaders(); }
            elseif (function_exists('apache_request_headers')) { $hdrs = apache_request_headers(); }
            foreach ($hdrs as $k => $v) { if (strtolower($k) === 'authorization') { $h = $v; break; } }
        }
        return $h;
    }

    // Validates the Authorization header. On success returns
    // ['employee_id' => string, 'company_id' => string]; on failure sends
    // 401 JSON and exits.
    public static function requireEmployee(): array {
        $header = self::getAuthorizationHeader();
        if (stripos($header, 'Bearer ') !== 0) {
            self::deny();
        }
        $token = trim(substr($header, 7));
        if ($token === '') {
            self::deny();
        }
        $db = Database::getInstance();
        // Status filters mirror Auth::unifiedLogin's employee login query
        // (e.status = 'active' AND c.status = 'active'): a deactivated
        // employee or suspended company loses API access immediately,
        // even with a valid unrevoked token.
        $row = $db->fetchOne(
            "SELECT t.employee_id, t.account_id, e.company_id,
                    m.account_id AS membership_account_id,
                    CASE WHEN u.id IS NULL THEN 0 ELSE 1 END AS is_super_admin
             FROM scan_api_tokens t
             JOIN scan_accounts a
               ON a.id = t.account_id
              AND a.status = 'active'
             JOIN employees e
               ON e.id = t.employee_id
              AND e.status = 'active'
              AND e.deleted_at IS NULL
             JOIN companies c
               ON c.id = e.company_id
              AND c.status = 'active'
             LEFT JOIN scan_account_memberships m
               ON m.account_id = t.account_id
              AND m.employee_id = t.employee_id
              AND m.company_id = e.company_id
             LEFT JOIN users u ON u.id = a.user_id
              AND u.role = 'super_admin'
              AND u.status = 'active'
             WHERE t.token_hash = :h
               AND t.revoked = 0",
            ['h' => self::hashToken($token)]
        );
        if (!$row) {
            self::deny();
        }
        $accountId = (string) ($row['account_id'] ?? '');
        $membership = !empty($row['membership_account_id'])
            ? ['account_id' => (string) $row['membership_account_id']]
            : null;
        $isSuperAdmin = (int) ($row['is_super_admin'] ?? 0) === 1;
        if ($accountId === ''
            || !ScanIdentity::membershipAuthorizes($accountId, $membership, $isSuperAdmin)) {
            self::deny();
        }
        $db->getConnection()->prepare(
            "UPDATE scan_api_tokens SET last_used_at = NOW() WHERE token_hash = ?"
        )->execute([self::hashToken($token)]);
        return [
            'account_id' => $accountId,
            'employee_id' => (string) $row['employee_id'],
            'company_id' => (string) $row['company_id'],
            'is_super_admin' => $isSuperAdmin,
        ];
    }

    private static function deny() {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}
