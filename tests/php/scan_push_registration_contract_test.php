<?php
$root = dirname(__DIR__, 2);
$failures = 0;

function pushRegistrationCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

$registerPush = (string) file_get_contents($root . '/api/scan/register-push.php');
$migrationPath = $root . '/database/migrations/142_push_token_revocation.php';
$migration = is_file($migrationPath)
    ? (string) file_get_contents($migrationPath)
    : '';
$basePushMigration = (string) file_get_contents(
    $root . '/database/migrations/133_scan_push_tokens.php'
);

pushRegistrationCheck(
    'push registration remains POST-only with authenticated legacy paths',
    strpos($registerPush, "ScanAuth::requireEmployee()") !== false
        && strpos($registerPush, "\$_SERVER['REQUEST_METHOD'] !== 'POST'") !== false
        && strpos($registerPush, 'if (!$guestRevocation)') !== false
);
pushRegistrationCheck(
    'request body and push token use strict type and length validation',
    strpos($registerPush, 'json_last_error() !== JSON_ERROR_NONE') !== false
        && strpos($registerPush, '!is_array($body)') !== false
        && strpos($registerPush, "!is_string(\$body['token'] ?? null)") !== false
        && strpos($registerPush, "\$token === '' || strlen(\$token) > 255") !== false
        && strpos($registerPush, "'invalid_token'") !== false
);
pushRegistrationCheck(
    'optional legacy fields use strict types and bounded lengths',
    strpos($registerPush, "array_key_exists('unregister', \$body)") !== false
        && strpos($registerPush, "!is_bool(\$body['unregister'])") !== false
        && strpos($registerPush, "array_key_exists('platform', \$body)") !== false
        && strpos($registerPush, "!is_string(\$body['platform'])") !== false
        && strpos($registerPush, 'strlen($platform) > 20') !== false
);
pushRegistrationCheck(
    'revocation secret rejects malformed types, case, and lengths',
    strpos($registerPush, "array_key_exists('revocation_secret', \$body)") !== false
        && strpos($registerPush, "!is_string(\$body['revocation_secret'])") !== false
        && strpos($registerPush, 'strlen($revocationSecret) !== 64') !== false
        && strpos(
            $registerPush,
            "preg_match('/^[a-f0-9]{64}\$/D', \$revocationSecret)"
        ) !== false
        && strpos($registerPush, "'invalid_revocation_secret'") !== false
);
pushRegistrationCheck(
    'guest secret revocation is parsed before auth and rate limited by IP',
    strpos($registerPush, "require_once INCLUDES_DIR . '/RateLimiter.php'") !== false
        && strpos($registerPush, "require_once INCLUDES_DIR . '/UrlSafety.php'") !== false
        && strpos($registerPush, '$guestRevocation = $unregister && $hasRevocationSecret;')
            !== false
        && strpos(
            $registerPush,
            "RateLimiter::check('scan_push_revoke', \$ip, 30, 900)"
        ) !== false
        && strpos($registerPush, "http_response_code(429)") !== false
        && strpos($registerPush, "'error' => 'rate_limited'") !== false
);
pushRegistrationCheck(
    'authenticated unregister still deletes only its employee matching token',
    preg_match(
        '/DELETE FROM push_tokens\s+WHERE employee_id = :employee_id AND token = :token/',
        $registerPush
    ) === 1
        && strpos(
            $registerPush,
            "'employee_id' => \$ctx['employee_id']"
        ) !== false
);
pushRegistrationCheck(
    'registration stores only the SHA-256 hash of a supplied client secret',
    strpos($registerPush, "hash('sha256', \$revocationSecret)") !== false
        && strpos($registerPush, 'revocation_secret_hash') !== false
        && strpos($registerPush, 'random_bytes(') === false
        && strpos($registerPush, "\$response['revocation_secret']") === false
        && strpos($registerPush, "'revocation_token'") === false
);
pushRegistrationCheck(
    'registration with a supplied secret rotates the stored hash',
    strpos($registerPush, 'INSERT INTO push_tokens') !== false
        && strpos($registerPush, 'ON DUPLICATE KEY UPDATE') !== false
        && strpos(
            $registerPush,
            'WHEN VALUES(revocation_secret_hash) IS NOT NULL'
        ) !== false
        && strpos(
            $registerPush,
            'THEN VALUES(revocation_secret_hash)'
        ) !== false
);
pushRegistrationCheck(
    'legacy registration preserves a hash only for the same employee owner',
    strpos(
        $registerPush,
        'WHEN employee_id = VALUES(employee_id) THEN revocation_secret_hash'
    ) !== false
);
pushRegistrationCheck(
    'legacy registration clears a stale hash before cross-owner reassignment',
    strpos($registerPush, 'ELSE NULL') !== false
        && strpos(
            $registerPush,
            'revocation_secret_hash = CASE'
        ) < strpos(
            $registerPush,
            'employee_id = VALUES(employee_id)'
        )
);
pushRegistrationCheck(
    'guest revocation atomically deletes only the exact token and hash',
    preg_match(
        '/DELETE FROM push_tokens\s+WHERE token = :token\s+AND revocation_secret_hash = :revocation_secret_hash/',
        $registerPush
    ) === 1
        && strpos(
            $registerPush,
            "'revocation_secret_hash' => hash('sha256', \$revocationSecret)"
        ) !== false
);
pushRegistrationCheck(
    'guest revocation reports wrong or null hashes false and absent tokens true',
    strpos($registerPush, '$revoked = $delete->rowCount() === 1;') !== false
        && strpos($registerPush, 'SELECT 1 FROM push_tokens') !== false
        && strpos($registerPush, '$revoked = !$stillExists;') !== false
        && strpos($registerPush, "'revoked' => \$revoked") !== false
);
pushRegistrationCheck(
    'registration returns no revocation secret and remains response compatible',
    strpos(
        $registerPush,
        "echo json_encode(['success' => true]);"
    ) !== false
);
pushRegistrationCheck(
    'authenticated legacy unregister returns idempotent revoked true',
    strpos(
        $registerPush,
        "echo json_encode(['success' => true, 'revoked' => true]);"
    ) !== false
);
pushRegistrationCheck(
    'push secrets are never included in server logs',
    strpos(
        $registerPush,
        "error_log('[scan/register-push] Database operation failed')"
    ) !== false
        && strpos($registerPush, '$e->getMessage()') === false
        && strpos($registerPush, 'error_log($revocationSecret') === false
);
pushRegistrationCheck(
    'migration 142 adds one nullable ascii-bin hash column without an index',
    strpos(
        $migration,
        'function migration_142_push_token_revocation(PDO $pdo): array'
    ) !== false
        && strpos($migration, "column_name = 'revocation_secret_hash'") !== false
        && strpos(
            $migration,
            'ADD COLUMN revocation_secret_hash CHAR(64)'
        ) !== false
        && strpos($migration, 'CHARACTER SET ascii COLLATE ascii_bin NULL') !== false
        && stripos($migration, 'ADD KEY') === false
        && stripos($migration, 'ADD INDEX') === false
);
pushRegistrationCheck(
    'migration preserves utf8mb4 tokens with exact binary equality',
    strpos(
        $migration,
        'MODIFY COLUMN token VARCHAR(255)'
    ) !== false
        && strpos(
            $migration,
            'CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'
        ) !== false
);
pushRegistrationCheck(
    'token collation migration preserves the global unique token key',
    strpos($basePushMigration, 'UNIQUE KEY uniq_token (token)') !== false
        && stripos($migration, 'DROP INDEX') === false
        && stripos($migration, 'DROP KEY') === false
);
pushRegistrationCheck(
    'all push token lookups rely on the binary-collated column equality',
    substr_count($registerPush, 'token = :token') === 3
        && strpos($registerPush, 'BINARY token') === false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
