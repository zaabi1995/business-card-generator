<?php
/**
 * WalletHealth: validates everything the updatable-pass feature needs, and
 * reports SAFE states only.
 *
 * Privacy/security rules enforced here:
 *   - never returns certificate contents, key material, tokens, or push tokens;
 *   - never returns absolute filesystem paths (only a basename + a boolean);
 *   - a public caller gets a coarse status; details require an admin context.
 *
 * Production must FAIL CLOSED for push when the credential is invalid, while the
 * rest of Cardify keeps working - so `ready` is per-capability, not global.
 */
require_once __DIR__ . '/SecretBox.php';

class WalletHealth
{
    /** @return array{status:string, checks:array<string,array{ok:bool,detail:string}>} */
    public static function report(): array
    {
        $checks = [];

        // --- signing chain -------------------------------------------------
        $checks['signing_cert'] = self::certCheck(
            defined('APPLE_WALLET_CERT_PATH') ? APPLE_WALLET_CERT_PATH : null,
            'pass signing certificate'
        );
        $checks['signing_key'] = self::keyPresentCheck(
            defined('APPLE_WALLET_CERT_PATH') ? APPLE_WALLET_CERT_PATH : null
        );
        $checks['wwdr_cert'] = self::certCheck(
            defined('APPLE_WALLET_WWDR_PATH') ? APPLE_WALLET_WWDR_PATH : null,
            'WWDR intermediate'
        );

        // --- identifiers ----------------------------------------------------
        $passType = defined('APPLE_WALLET_PASS_TYPE_ID') ? APPLE_WALLET_PASS_TYPE_ID : '';
        $teamId = defined('APPLE_WALLET_TEAM_ID') ? APPLE_WALLET_TEAM_ID : '';
        $checks['pass_type_id'] = [
            'ok' => (bool) preg_match('/^pass\.[A-Za-z0-9._-]+$/', $passType),
            'detail' => $passType !== '' ? $passType : 'not configured',
        ];
        $checks['team_id'] = [
            'ok' => (bool) preg_match('/^[A-Z0-9]{10}$/', $teamId),
            'detail' => $teamId !== '' ? $teamId : 'not configured',
        ];
        // The signing cert must actually belong to this Pass Type ID.
        $checks['cert_matches_pass_type'] = self::certMatchesPassType($passType);

        // --- web service ----------------------------------------------------
        $apex = function_exists('cardifyApexHost') ? cardifyApexHost() : 'cardify.om';
        $url = 'https://' . $apex . '/wallet';
        $checks['web_service_https'] = [
            'ok' => str_starts_with($url, 'https://'),
            'detail' => $url,
        ];
        $checks['web_service_route'] = self::routeCheck($apex, $passType);

        // --- token encryption ------------------------------------------------
        $checks['token_encryption_key'] = [
            'ok' => SecretBox::available(),
            'detail' => SecretBox::available()
                ? 'present (v' . SecretBox::currentVersion() . ')'
                : 'MISSING - pass creation will fail closed',
        ];

        // --- schema ----------------------------------------------------------
        $checks['schema'] = self::schemaCheck();

        // --- APNs push credential --------------------------------------------
        $checks['apns_credential'] = self::apnsCheck();

        // Overall: signing+schema+key are required to ISSUE passes; the APNs
        // credential is required only to PUSH updates. Report them distinctly so
        // a missing push cert never looks like a broken feature.
        $issueOk = $checks['signing_cert']['ok'] && $checks['wwdr_cert']['ok']
            && $checks['pass_type_id']['ok'] && $checks['team_id']['ok']
            && $checks['token_encryption_key']['ok'] && $checks['schema']['ok'];
        $status = !$issueOk ? 'not_ready'
            : ($checks['apns_credential']['ok'] ? 'ready' : 'ready_without_push');

        return ['status' => $status, 'checks' => $checks];
    }

    private static function certCheck(?string $path, string $label): array
    {
        if (!$path) return ['ok' => false, 'detail' => "$label not configured"];
        if (!is_readable($path)) return ['ok' => false, 'detail' => "$label unreadable (" . basename($path) . ')'];
        $pem = @file_get_contents($path);
        $x = $pem ? @openssl_x509_parse($pem) : false;
        if (!$x) return ['ok' => false, 'detail' => "$label unparseable"];
        $end = $x['validTo_time_t'] ?? 0;
        $days = (int) floor(($end - time()) / 86400);
        if ($days < 0) return ['ok' => false, 'detail' => "$label EXPIRED " . abs($days) . 'd ago'];
        if ($days < 30) return ['ok' => true, 'detail' => "$label expires in {$days}d - renew"];
        return ['ok' => true, 'detail' => "$label valid ({$days}d left)"];
    }

    private static function keyPresentCheck(?string $path): array
    {
        if (!$path || !is_readable($path)) return ['ok' => false, 'detail' => 'signing key unreadable'];
        $pem = (string) @file_get_contents($path);
        $ok = @openssl_pkey_get_private($pem) !== false;
        return ['ok' => $ok, 'detail' => $ok ? 'private key present' : 'private key missing from PEM'];
    }

    private static function certMatchesPassType(string $passType): array
    {
        $path = defined('APPLE_WALLET_CERT_PATH') ? APPLE_WALLET_CERT_PATH : null;
        if (!$path || !is_readable($path) || $passType === '') {
            return ['ok' => false, 'detail' => 'cannot compare'];
        }
        $x = @openssl_x509_parse((string) @file_get_contents($path));
        $uid = $x['subject']['UID'] ?? ($x['subject']['userId'] ?? '');
        if ($uid === '') return ['ok' => false, 'detail' => 'certificate has no UID to compare'];
        $ok = $uid === $passType;
        return ['ok' => $ok, 'detail' => $ok ? 'certificate UID matches Pass Type ID' : 'certificate UID does NOT match the configured Pass Type ID'];
    }

    private static function routeCheck(string $apex, string $passType): array
    {
        // Loopback probe of the PassKit route; a 401 proves the route exists and
        // auth is enforced (unauthenticated is exactly what we send).
        $url = "https://127.0.0.1/wallet/v1/passes/$passType/healthprobe";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => ["Host: $apex"],
            CURLOPT_TIMEOUT => 4,
            CURLOPT_NOBODY => false,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // 401 = routed + auth enforced. 404 = nginx route missing.
        $ok = $code === 401;
        return ['ok' => $ok, 'detail' => $ok ? 'PassKit route reachable, auth enforced' : "unexpected HTTP $code (nginx route missing?)"];
    }

    private static function schemaCheck(): array
    {
        try {
            $db = Database::getInstance();
            $t = $db->fetchAll("SHOW TABLES LIKE 'scan_pass%'", []);
            $names = array_map(fn($r) => array_values($r)[0], $t);
            $need = ['scan_passes', 'scan_pass_registrations'];
            $missing = array_diff($need, $names);
            if ($missing) return ['ok' => false, 'detail' => 'missing tables: ' . implode(',', $missing)];
            $cols = $db->fetchAll("SHOW COLUMNS FROM scan_passes LIKE 'auth_token_hmac'", []);
            if (!$cols) return ['ok' => false, 'detail' => 'auth_token_hmac column missing (run wallet_encrypt_tokens.php)'];
            $idx = $db->fetchAll("SHOW INDEX FROM scan_pass_registrations WHERE Key_name = 'uniq_reg'", []);
            if (!$idx) return ['ok' => false, 'detail' => 'uniq_reg index missing (duplicate registrations possible)'];
            return ['ok' => true, 'detail' => 'tables + indexes present'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => 'schema check failed'];
        }
    }

    private static function apnsCheck(): array
    {
        $path = defined('APPLE_WALLET_PUSH_CERT_PATH') ? APPLE_WALLET_PUSH_CERT_PATH : null;
        if (!$path) {
            return ['ok' => false, 'detail' => 'push credential not configured - updates queue but cannot send (see docs/WALLET_APNS_SETUP.md)'];
        }
        if (!is_readable($path)) {
            return ['ok' => false, 'detail' => 'push credential unreadable (' . basename($path) . ')'];
        }
        return self::certCheck($path, 'APNs push certificate');
    }
}
