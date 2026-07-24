<?php
/**
 * Verifies StoreKit 2 transaction and renewal-info JWS values: ES256 signature
 * over the payload, an x5c certificate chain rooted in the pinned Apple Root
 * CA - G3, and the expected app, product, and environment claims.
 */
class AppleStoreKitVerify {
    const ROOT_PEM   = __DIR__ . '/certs/AppleRootCA-G3.pem';
    const BUNDLE_ID  = 'om.cardify.scan';

    private static function b64url(string $s): string {
        $s = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return base64_decode($s) ?: '';
    }

    // Raw ECDSA (R||S, 64 bytes) -> DER for openssl_verify.
    private static function rawToDer(string $raw): ?string {
        if (strlen($raw) !== 64) return null;
        $r = ltrim(substr($raw, 0, 32), "\x00");
        $s = ltrim(substr($raw, 32, 32), "\x00");
        if ($r === '') $r = "\x00";
        if ($s === '') $s = "\x00";
        if (ord($r[0]) & 0x80) $r = "\x00" . $r;
        if (ord($s[0]) & 0x80) $s = "\x00" . $s;
        $seq = "\x02" . chr(strlen($r)) . $r . "\x02" . chr(strlen($s)) . $s;
        return "\x30" . chr(strlen($seq)) . $seq;
    }

    private static function pem(string $b64der): string {
        return "-----BEGIN CERTIFICATE-----\n" . chunk_split($b64der, 64, "\n") . "-----END CERTIFICATE-----\n";
    }

    private static function verifySignedPayload(string $jws): ?array {
        if (strlen($jws) < 32 || strlen($jws) > 65536) return null;
        $parts = explode('.', $jws);
        if (count($parts) !== 3) return null;
        [$h64, $p64, $s64] = $parts;
        $header = json_decode(self::b64url($h64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'ES256'
            || empty($header['x5c']) || !is_array($header['x5c']) || count($header['x5c']) < 2) {
            return null;
        }
        $pems = array_map([self::class, 'pem'], $header['x5c']);
        if (in_array(false, array_map('openssl_x509_read', $pems), true)) return null;

        // 1) Chain: each cert signed by the next; the top signed by the pinned root.
        $root = @file_get_contents(self::ROOT_PEM);
        if (!$root) return null;
        for ($i = 0, $n = count($pems); $i < $n - 1; $i++) {
            if (openssl_x509_verify($pems[$i], $pems[$i + 1]) !== 1) return null;
        }
        if (openssl_x509_verify($pems[$n - 1], $root) !== 1) return null;

        // 1b) Leaf cert must be within its validity window (openssl_x509_verify
        //     checks signatures only, not dates).
        $leafInfo = openssl_x509_parse($pems[0]);
        if (!is_array($leafInfo)) return null;
        $now2 = time();
        if (isset($leafInfo['validFrom_time_t']) && $now2 < (int)$leafInfo['validFrom_time_t']) return null;
        if (isset($leafInfo['validTo_time_t']) && $now2 > (int)$leafInfo['validTo_time_t']) return null;

        // 2) Signature over "h64.p64" with the leaf public key.
        $leaf = openssl_pkey_get_public($pems[0]);
        if ($leaf === false) return null;
        $der = self::rawToDer(self::b64url($s64));
        if ($der === null) return null;
        if (openssl_verify($h64 . '.' . $p64, $der, $leaf, OPENSSL_ALGO_SHA256) !== 1) return null;

        $payload = json_decode(self::b64url($p64), true);
        if (!is_array($payload)) return null;
        return $payload;
    }

    private static function environmentAllowed(array $payload): bool {
        $allowSandbox = defined('CARDIFY_ALLOW_SANDBOX_RECEIPTS')
            && CARDIFY_ALLOW_SANDBOX_RECEIPTS;
        return ($payload['environment'] ?? '') === 'Production'
            || ($allowSandbox && ($payload['environment'] ?? '') === 'Sandbox');
    }

    public static function verify(string $jws, array $allowedProductIds): ?array {
        return self::verifyTransaction($jws, $allowedProductIds, true);
    }

    public static function verifyTransaction(
        string $jws,
        array $allowedProductIds,
        bool $requireUnexpired = true
    ): ?array {
        $payload = self::verifySignedPayload($jws);
        if ($payload === null) return null;
        if (($payload['bundleId'] ?? '') !== self::BUNDLE_ID) return null;
        if (!in_array($payload['productId'] ?? '', $allowedProductIds, true)) return null;
        if (!self::environmentAllowed($payload)) return null;
        if (trim((string) ($payload['originalTransactionId'] ?? '')) === '') return null;
        if (!empty($payload['revocationDate'])) return null;
        $expiresDate = (int) ($payload['expiresDate'] ?? 0);
        if ($expiresDate <= 0) return null;
        if ($requireUnexpired && ($expiresDate / 1000) <= time()) return null;
        return $payload;
    }

    public static function verifyRenewalInfo(
        string $jws,
        array $allowedProductIds
    ): ?array {
        $payload = self::verifySignedPayload($jws);
        if ($payload === null) return null;
        if (!self::environmentAllowed($payload)) return null;
        if (trim((string) ($payload['originalTransactionId'] ?? '')) === '') return null;
        if (!in_array($payload['productId'] ?? '', $allowedProductIds, true)) return null;
        return $payload;
    }
}
