<?php
/**
 * AppleWalletPushNotifier - sends Wallet update pushes via APNs (HTTP/2).
 *
 * Wallet update pushes carry an EMPTY JSON payload ("{}"). The device, on
 * receiving it, calls our web service (wc_wallet_service.php) to fetch the
 * list of changed serials and then the new .pkpass.
 *
 * Authentication uses the SAME pass-signing certificate (APPLE_WALLET_CERT_PATH)
 * as a TLS client certificate against APNs production (api.push.apple.com).
 * The APNs topic = the pass type id (APPLE_WALLET_PASS_TYPE_ID).
 *
 * Requires curl with HTTP/2 (CURL_HTTP_VERSION_2_0). Returns a per-token result.
 */

class AppleWalletPushNotifier
{
    private const APNS_HOST = 'https://api.push.apple.com';

    /**
     * Push to a list of device push tokens. Returns:
     *   ['sent'=>int, 'failed'=>int, 'bad_tokens'=>[token,...], 'errors'=>[...]]
     * bad_tokens are 410/BadDeviceToken/Unregistered and should be pruned.
     */
    public static function pushMany(array $pushTokens): array
    {
        $out = ['sent'=>0, 'failed'=>0, 'bad_tokens'=>[], 'errors'=>[]];
        if (!self::isConfigured()) {
            $out['errors'][] = 'apns not configured';
            return $out;
        }
        if (!defined('CURL_HTTP_VERSION_2_0')) {
            $out['errors'][] = 'curl lacks HTTP/2';
            return $out;
        }
        $topic = APPLE_WALLET_PASS_TYPE_ID;
        $certPath = APPLE_WALLET_CERT_PATH;
        $certPw   = defined('APPLE_WALLET_CERT_PASSWORD') ? APPLE_WALLET_CERT_PASSWORD : '';

        foreach (array_unique($pushTokens) as $token) {
            $token = trim((string)$token);
            if ($token === '') continue;
            $url = self::APNS_HOST . '/3/device/' . rawurlencode($token);
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '{}',          // Wallet push = empty payload
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
                CURLOPT_HTTPHEADER     => ['apns-topic: ' . $topic, 'apns-push-type: background', 'content-type: application/json'],
                CURLOPT_SSLCERT        => $certPath,     // combined cert+key PEM
                CURLOPT_SSLKEY         => $certPath,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HEADER         => false,
            ];
            if ($certPw !== '') { $opts[CURLOPT_SSLKEYPASSWD] = $certPw; }
            curl_setopt_array($ch, $opts);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($code === 200) {
                $out['sent']++;
            } else {
                $out['failed']++;
                if ($code === 410 || stripos((string)$resp, 'BadDeviceToken') !== false || stripos((string)$resp, 'Unregistered') !== false) {
                    $out['bad_tokens'][] = $token;
                }
                $out['errors'][] = "token " . substr($token, 0, 8) . "...: HTTP $code " . ($err ?: substr((string)$resp, 0, 120));
            }
        }
        return $out;
    }

    public static function isConfigured(): bool
    {
        return defined('APPLE_WALLET_CERT_PATH')
            && defined('APPLE_WALLET_PASS_TYPE_ID')
            && APPLE_WALLET_PASS_TYPE_ID
            && is_readable(APPLE_WALLET_CERT_PATH);
    }
}
