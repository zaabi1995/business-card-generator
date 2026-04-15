<?php
/**
 * GoogleWalletPass — Google Wallet Generic pass JWT builder.
 *
 * Clean-room: uses only ext-openssl via includes/jwt.php.
 *
 * Required config constants:
 *   GOOGLE_WALLET_ENABLED              bool
 *   GOOGLE_WALLET_SERVICE_ACCOUNT_JSON path to service-account key JSON
 *   GOOGLE_WALLET_ISSUER_ID            Google Wallet issuer ID (10-16 digits)
 *   GOOGLE_WALLET_CLASS_ID             class suffix (arbitrary, e.g. cardify_business_card_v1)
 */

require_once __DIR__ . '/jwt.php';

class GoogleWalletPass
{
    /**
     * Build the https://pay.google.com/gp/v/save/<jwt> URL for a single generic object.
     *
     * @param array $object Google Wallet GenericObject fields
     *                      (see https://developers.google.com/wallet/generic/rest/v1/genericobject)
     * @return string       Save URL
     * @throws RuntimeException
     */
    public static function buildSaveUrl(array $object): string
    {
        self::assertConfigured();
        $sa = self::loadServiceAccount();

        $payload = [
            'iss'     => $sa['client_email'],
            'aud'     => 'google',
            'typ'     => 'savetowallet',
            'iat'     => time(),
            'origins' => self::origins(),
            'payload' => [
                'genericObjects' => [$object],
            ],
        ];

        $jwt = cardify_jwt_rs256_sign($payload, $sa['private_key'], ['kid' => $sa['private_key_id'] ?? null]);
        return 'https://pay.google.com/gp/v/save/' . $jwt;
    }

    public static function classResourceId(): string
    {
        return GOOGLE_WALLET_ISSUER_ID . '.' . GOOGLE_WALLET_CLASS_ID;
    }

    public static function objectResourceId(string $employeeId): string
    {
        // Wallet object IDs must be `issuerID.uniqueSuffix` where suffix is alphanumeric/._-.
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $employeeId);
        return GOOGLE_WALLET_ISSUER_ID . '.' . GOOGLE_WALLET_CLASS_ID . '_' . $safe;
    }

    public static function isEnabled(): bool
    {
        if (!defined('GOOGLE_WALLET_ENABLED') || !GOOGLE_WALLET_ENABLED) return false;
        foreach ([
            'GOOGLE_WALLET_SERVICE_ACCOUNT_JSON',
            'GOOGLE_WALLET_ISSUER_ID',
            'GOOGLE_WALLET_CLASS_ID',
        ] as $c) {
            if (!defined($c) || !constant($c)) return false;
        }
        return is_readable(GOOGLE_WALLET_SERVICE_ACCOUNT_JSON);
    }

    private static function assertConfigured(): void
    {
        if (!self::isEnabled()) {
            throw new RuntimeException('Google Wallet not configured. See plans/2026-04-16-wallet-passes.md');
        }
    }

    private static function loadServiceAccount(): array
    {
        $raw = file_get_contents(GOOGLE_WALLET_SERVICE_ACCOUNT_JSON);
        if ($raw === false) {
            throw new RuntimeException('Cannot read Google service account JSON');
        }
        $sa = json_decode($raw, true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new RuntimeException('Invalid Google service account JSON');
        }
        return $sa;
    }

    private static function origins(): array
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'cardify.om';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return [$scheme . '://' . $host];
    }
}
