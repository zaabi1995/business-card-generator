<?php
/**
 * Minimal RS256 JWT signer for Google Wallet + service-account auth.
 * Clean-room implementation; no external dependency.
 *
 * Usage:
 *   $jwt = cardify_jwt_rs256_sign(['iss' => 'sa@proj.iam.gserviceaccount.com', ...], $privateKeyPem);
 */

if (!function_exists('cardify_base64url_encode')) {
    function cardify_base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('cardify_jwt_rs256_sign')) {
    /**
     * Sign a JWT with RS256.
     *
     * @param array  $payload     Claims
     * @param string $privateKey  PEM-encoded RSA private key
     * @param array  $extraHeader Extra JOSE header fields (e.g. kid)
     * @return string             Compact JWS
     * @throws RuntimeException on signing failure
     */
    function cardify_jwt_rs256_sign(array $payload, string $privateKey, array $extraHeader = []): string
    {
        $header  = array_merge(['alg' => 'RS256', 'typ' => 'JWT'], $extraHeader);
        $segment = cardify_base64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES))
                 . '.' . cardify_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $key = openssl_pkey_get_private($privateKey);
        if ($key === false) {
            throw new RuntimeException('JWT: invalid private key: ' . openssl_error_string());
        }

        $signature = '';
        if (!openssl_sign($segment, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('JWT: openssl_sign failed: ' . openssl_error_string());
        }

        return $segment . '.' . cardify_base64url_encode($signature);
    }
}
