<?php
/**
 * POST /api/scan/register-push.php
 *
 * Registration and legacy unregister require ScanAuth. New clients may supply
 * a client-generated revocation secret during registration, then use the same
 * secret to revoke that exact push token after logout without a bearer token.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/UrlSafety.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($body)) {
    echo json_encode(['success' => false, 'error' => 'invalid_request']);
    exit;
}

if (!is_string($body['token'] ?? null)) {
    echo json_encode(['success' => false, 'error' => 'invalid_token']);
    exit;
}
$token = trim($body['token']);
if ($token === '' || strlen($token) > 255) {
    echo json_encode(['success' => false, 'error' => 'invalid_token']);
    exit;
}

if (array_key_exists('unregister', $body) && !is_bool($body['unregister'])) {
    echo json_encode(['success' => false, 'error' => 'invalid_unregister']);
    exit;
}
$unregister = $body['unregister'] ?? false;

$platform = null;
if (array_key_exists('platform', $body)) {
    if ($body['platform'] !== null && !is_string($body['platform'])) {
        echo json_encode(['success' => false, 'error' => 'invalid_platform']);
        exit;
    }
    if (is_string($body['platform'])) {
        $platform = trim($body['platform']);
        if (strlen($platform) > 20) {
            echo json_encode(['success' => false, 'error' => 'invalid_platform']);
            exit;
        }
        if ($platform === '') {
            $platform = null;
        }
    }
}

$hasRevocationSecret = array_key_exists('revocation_secret', $body);
$revocationSecret = null;
if ($hasRevocationSecret) {
    if (!is_string($body['revocation_secret'])) {
        echo json_encode(['success' => false, 'error' => 'invalid_revocation_secret']);
        exit;
    }
    $revocationSecret = $body['revocation_secret'];
    if (
        strlen($revocationSecret) !== 64
        || preg_match('/^[a-f0-9]{64}$/D', $revocationSecret) !== 1
    ) {
        echo json_encode(['success' => false, 'error' => 'invalid_revocation_secret']);
        exit;
    }
}

$guestRevocation = $unregister && $hasRevocationSecret;
$ctx = null;
if ($guestRevocation) {
    $ip = function_exists('getClientIp')
        ? getClientIp()
        : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!RateLimiter::check('scan_push_revoke', $ip, 30, 900)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'rate_limited']);
        exit;
    }
}
if (!$guestRevocation) {
    $ctx = ScanAuth::requireEmployee();
}

try {
    $connection = Database::getInstance()->getConnection();

    if ($guestRevocation) {
        $delete = $connection->prepare(
            'DELETE FROM push_tokens
             WHERE token = :token
               AND revocation_secret_hash = :revocation_secret_hash'
        );
        $delete->execute([
            'token' => $token,
            'revocation_secret_hash' => hash('sha256', $revocationSecret),
        ]);
        $revoked = $delete->rowCount() === 1;

        if (!$revoked) {
            $exists = $connection->prepare(
                'SELECT 1 FROM push_tokens
                 WHERE token = :token
                 LIMIT 1'
            );
            $exists->execute(['token' => $token]);
            $stillExists = (bool) $exists->fetchColumn();
            $revoked = !$stillExists;
        }

        echo json_encode(['success' => true, 'revoked' => $revoked]);
        exit;
    }

    if ($unregister) {
        $legacyDelete = $connection->prepare(
            'DELETE FROM push_tokens
             WHERE employee_id = :employee_id AND token = :token'
        );
        $legacyDelete->execute([
            'employee_id' => $ctx['employee_id'],
            'token' => $token,
        ]);
        $legacyRevoked = $legacyDelete->rowCount() === 1;

        if (!$legacyRevoked) {
            $legacyExists = $connection->prepare(
                'SELECT 1 FROM push_tokens
                 WHERE token = :token
                 LIMIT 1'
            );
            $legacyExists->execute(['token' => $token]);
            $legacyStillExists = (bool) $legacyExists->fetchColumn();
            $legacyRevoked = !$legacyStillExists;
        }

        echo json_encode(['success' => true, 'revoked' => $legacyRevoked]);
        exit;
    } else {
        $revocationSecretHash = $hasRevocationSecret
            ? hash('sha256', $revocationSecret)
            : null;
        $connection->prepare(
            'INSERT INTO push_tokens
                (employee_id, token, platform, revocation_secret_hash)
             VALUES
                (:employee_id, :token, :platform, :revocation_secret_hash)
             ON DUPLICATE KEY UPDATE
                revocation_secret_hash = CASE
                    WHEN VALUES(revocation_secret_hash) IS NOT NULL
                        THEN VALUES(revocation_secret_hash)
                    WHEN employee_id = VALUES(employee_id) THEN revocation_secret_hash
                    ELSE NULL
                END,
                employee_id = VALUES(employee_id),
                platform = VALUES(platform),
                updated_at = CURRENT_TIMESTAMP'
        )->execute([
            'employee_id' => $ctx['employee_id'],
            'token' => $token,
            'platform' => $platform,
            'revocation_secret_hash' => $revocationSecretHash,
        ]);
    }
} catch (\Throwable $e) {
    error_log('[scan/register-push] Database operation failed');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode(['success' => true]);
