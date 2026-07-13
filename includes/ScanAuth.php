<?php
// ScanAuth: long-lived bearer tokens for the Cardify Scan mobile app.
// Tokens are random 32 bytes, stored as sha256 hashes in scan_api_tokens.
require_once __DIR__ . '/Database.php';

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
    public static function issueToken(string $employeeId, string $label = 'mobile'): string {
        $token = self::generateToken();
        Database::getInstance()->insert('scan_api_tokens', [
            'employee_id' => $employeeId,
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
            "SELECT t.employee_id, e.company_id
             FROM scan_api_tokens t
             JOIN employees e ON e.id = t.employee_id AND e.status = 'active'
             JOIN companies c ON c.id = e.company_id AND c.status = 'active'
             WHERE t.token_hash = :h AND t.revoked = 0",
            ['h' => self::hashToken($token)]
        );
        if (!$row) {
            self::deny();
        }
        $db->getConnection()->prepare(
            "UPDATE scan_api_tokens SET last_used_at = NOW() WHERE token_hash = ?"
        )->execute([self::hashToken($token)]);
        return ['employee_id' => (string)$row['employee_id'], 'company_id' => (string)$row['company_id']];
    }

    private static function deny() {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
}
