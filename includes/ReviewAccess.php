<?php

/**
 * Google Play review access for the passwordless mobile sign-in flow.
 *
 * Production enables this for one dedicated reviewer identity using a
 * SHA-256 code hash in the server environment. Normal users continue through
 * OtpService, and the reusable reviewer code never appears in source control.
 */
class ReviewAccess
{
    private const IDENTIFIER_KEY = 'CARDIFY_PLAY_REVIEW_IDENTIFIER';
    private const CODE_HASH_KEY = 'CARDIFY_PLAY_REVIEW_CODE_HASH';

    public static function usesStaticCode(string $identifier): bool
    {
        $config = self::configuration();
        return $config !== null
            && hash_equals($config['identifier'], trim($identifier));
    }

    /**
     * Returns null for normal OTP accounts, true for an accepted reviewer
     * code, and false for a rejected code on the configured reviewer account.
     */
    public static function verificationDecision(
        string $identifier,
        string $code
    ): ?bool {
        $config = self::configuration();
        if (
            $config === null
            || !hash_equals($config['identifier'], trim($identifier))
        ) {
            return null;
        }

        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        return hash_equals($config['code_hash'], hash('sha256', $code));
    }

    private static function configuration(): ?array
    {
        $identifier = trim(self::configuredValue(self::IDENTIFIER_KEY));
        $codeHash = strtolower(trim(self::configuredValue(self::CODE_HASH_KEY)));
        if (
            $identifier === ''
            || preg_match('/^[a-f0-9]{64}$/', $codeHash) !== 1
        ) {
            return null;
        }

        return [
            'identifier' => $identifier,
            'code_hash' => $codeHash,
        ];
    }

    private static function configuredValue(string $key): string
    {
        if (defined($key)) {
            return (string) constant($key);
        }

        $value = getenv($key);
        return $value === false ? '' : (string) $value;
    }
}
