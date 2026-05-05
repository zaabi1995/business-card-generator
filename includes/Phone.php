<?php
/**
 * Phone, lightweight E.164 normaliser.
 *
 * Strips spaces, dashes, parens; replaces leading `00` with `+`; if
 * the input is missing a country code, prepends the default (Oman
 * `+968`) when the remaining digit count looks like a local number.
 *
 * normalize('71616161')        -> '+96871616161'
 * normalize('+968 7161 6161')  -> '+96871616161'
 * normalize('968-7161-6161')   -> '+96871616161'
 * normalize('00968 71616161')  -> '+96871616161'
 *
 * Returns the normalised string or null when the input cannot be
 * coerced into a plausible E.164 number.
 */
class Phone {
    public const DEFAULT_COUNTRY_CODE = '968';

    public static function normalize(?string $input, ?string $defaultCountryCode = null): ?string {
        if ($input === null) return null;
        $cc = $defaultCountryCode ?: self::DEFAULT_COUNTRY_CODE;

        $s = trim($input);
        $s = preg_replace('/[\s().\-]/', '', $s);

        if ($s === '' || $s === null) return null;

        // Convert leading 00 to +
        if (strpos($s, '00') === 0) {
            $s = '+' . substr($s, 2);
        }

        // Bare digits: assume default country code when length looks local
        if ($s !== '' && $s[0] !== '+') {
            // Plain 8-digit Omani number -> add +968
            if (preg_match('/^[0-9]{8}$/', $s)) {
                $s = '+' . $cc . $s;
            } elseif (preg_match('/^' . preg_quote($cc, '/') . '[0-9]{6,}$/', $s)) {
                // Already prefixed with country code, just missing +
                $s = '+' . $s;
            } else {
                // Not a recognisable shape -> reject
                return null;
            }
        }

        // Final shape check: + then 7-15 digits per E.164
        if (!preg_match('/^\+[0-9]{7,15}$/', $s)) return null;

        return $s;
    }

    /**
     * Mask all but the last 4 digits, preserving the leading `+`.
     * Used when echoing a phone back in OTP UI without leaking it.
     */
    public static function mask(?string $e164): string {
        if ($e164 === null) return '';
        $len = strlen($e164);
        if ($len <= 5) return $e164;
        $tail = substr($e164, -4);
        return substr($e164, 0, 1) . str_repeat('*', $len - 5) . $tail;
    }
}
