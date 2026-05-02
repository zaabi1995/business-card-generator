<?php
/**
 * Deterministic binding validator. Script-first rules decide what each
 * detected text block is; AI is only consulted when script can't, and
 * even then its answer gets re-checked against the same script rules.
 *
 * Order of precedence (highest -> lowest):
 *   1. Format pattern wins (email regex, phone regex, URL regex).
 *   2. Field-label text wins ("PHONE", "EMAIL", "بريد", "هاتف" -> static).
 *   3. Per-company shared values default to static (website, address,
 *      company name) so the portal never re-asks an employee for them.
 *   4. Script of the actual text wins for _en vs _ar bindings (Latin
 *      goes to _en, Arabic goes to _ar) regardless of position.
 *   5. AI suggestion (if any) for blocks the script couldn't classify.
 */
class BindingValidator
{
    /** Bindings the rest of the system understands. Mirrors AIBindingClassifier::VALID. */
    private const VALID = [
        'name_en','name_ar','position_en','position_ar',
        'company_en','company_ar','mobile','mobile_ar',
        'phone','phone_ar','fax','email','website','website_ar',
        'address','address_en','address_2_en','address_ar','address_2_ar',
        'social','static','skip',
    ];

    /** Per-company shared keys that default to static, never per-employee inputs. */
    private const PER_COMPANY_KEYS = [
        'website','website_ar','address','address_en','address_2_en',
        'address_ar','address_2_ar','company_en','company_ar','fax','social',
    ];

    /** Common label tokens that mark a block as a static field caption. */
    private const LABEL_TOKENS = [
        // English labels (also handles spaced-out P H O N E, E M A I L)
        '/^\s*p\s*h\s*o\s*n\s*e\s*[:\-]?\s*$/i',
        '/^\s*m\s*o\s*b\s*i\s*l\s*e\s*[:\-]?\s*$/i',
        '/^\s*e\s*m\s*a\s*i\s*l\s*[:\-]?\s*$/i',
        '/^\s*f\s*a\s*x\s*[:\-]?\s*$/i',
        '/^\s*w\s*e\s*b\s*[:\-]?\s*$/i',
        '/^\s*w\s*e\s*b\s*s\s*i\s*t\s*e\s*[:\-]?\s*$/i',
        '/^\s*a\s*d\s*d\s*r\s*e\s*s\s*s\s*[:\-]?\s*$/i',
        '/^\s*t\s*e\s*l\s*[:\-]?\s*$/i',
        // Arabic labels
        '/^\s*هاتف\s*[:\-]?\s*$/u',
        '/^\s*جوال\s*[:\-]?\s*$/u',
        '/^\s*بريد(?:\s*إلكتروني)?\s*[:\-]?\s*$/u',
        '/^\s*فاكس\s*[:\-]?\s*$/u',
        '/^\s*موقع(?:\s*إلكتروني)?\s*[:\-]?\s*$/u',
        '/^\s*العنوان\s*[:\-]?\s*$/u',
    ];

    /**
     * Deterministic classification. Returns:
     *   ['binding' => '<key>'|'static'|null,
     *    'confident' => bool,    // true = do NOT call AI for this block
     *    'reason'    => string]  // for logging / debugging
     */
    public static function scriptClassify(string $text, array $hints = []): array
    {
        $t = trim($text);
        if ($t === '') {
            return ['binding' => 'skip', 'confident' => true, 'reason' => 'empty'];
        }

        // 1. Field labels are always static decoration.
        foreach (self::LABEL_TOKENS as $rx) {
            if (preg_match($rx, $t)) {
                return ['binding' => 'static', 'confident' => true, 'reason' => 'label_token'];
            }
        }

        // 2. Email pattern wins absolutely.
        if (self::isEmail($t)) {
            return ['binding' => 'email', 'confident' => true, 'reason' => 'email_pattern'];
        }

        // 3. Phone pattern (>= 6 digits, may have +, spaces, dashes, parens).
        //    Distinguishes from short numeric labels (room numbers etc).
        if (self::isPhone($t)) {
            $arabic = self::hasArabic($t);
            return [
                'binding'   => $arabic ? 'mobile_ar' : 'mobile',
                'confident' => true,
                'reason'    => 'phone_pattern',
            ];
        }

        // 4. URL pattern. Per-company => static.
        if (self::isUrl($t)) {
            return ['binding' => 'static', 'confident' => true, 'reason' => 'url_per_company'];
        }

        // 5. Looks like a postal address line (contains street/PO Box/PC tokens).
        if (self::isAddressLine($t)) {
            return ['binding' => 'static', 'confident' => true, 'reason' => 'address_per_company'];
        }

        // No deterministic match. Fall through to AI guidance.
        return ['binding' => null, 'confident' => false, 'reason' => 'ambiguous'];
    }

    /**
     * Re-validate a binding (whether from AI or human) against script rules.
     * Returns the corrected binding. Never produces an unknown label.
     */
    public static function sanitize(string $text, ?string $proposed): string
    {
        $proposed = strtolower(trim((string)$proposed));
        if (!in_array($proposed, self::VALID, true)) {
            $proposed = 'static';
        }

        $script = self::scriptClassify($text);
        // Confident script rule overrides anything AI/human said.
        if ($script['confident'] && $script['binding'] !== null) {
            return $script['binding'];
        }

        // Per-company keys: collapse to static. We never ask an employee
        // to re-type the company website / address / company name.
        if (in_array($proposed, self::PER_COMPANY_KEYS, true)) {
            return 'static';
        }

        // Script direction enforcement on the typed name/position/etc.
        $arabic = self::hasArabic($text);
        $latin  = self::hasLatin($text);
        if ($arabic && !$latin && self::endsWithEn($proposed)) {
            return self::flipToAr($proposed);
        }
        if ($latin && !$arabic && self::endsWithAr($proposed)) {
            return self::flipToEn($proposed);
        }

        return $proposed;
    }

    public static function isEmail(string $s): bool
    {
        return (bool)preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $s);
    }
    public static function isPhone(string $s): bool
    {
        // Must contain >= 6 digits, may include + ( ) - and spaces, no letters.
        if (preg_match('/[A-Za-z]/', $s)) return false;
        $digits = preg_replace('/\D/', '', $s);
        return strlen($digits) >= 6;
    }
    public static function isUrl(string $s): bool
    {
        return (bool)preg_match('#^(https?://|www\.)#i', $s)
            || (bool)preg_match('#^[a-z0-9-]+(\.[a-z]{2,})+(?:/.*)?$#i', $s);
    }
    public static function isAddressLine(string $s): bool
    {
        return (bool)preg_match('/(P\.?\s?O\.?\s?Box|PC\s*\d|Postal Code|street|sultanate|muscat|bousher|ruwi|al\s*khuwair)/i', $s)
            || (bool)preg_match('/(ص\.ب|الرمز البريدي|سلطنة|مسقط|بوشر|روي|الخوير)/u', $s);
    }
    public static function hasArabic(string $s): bool { return (bool)preg_match('/\p{Arabic}/u', $s); }
    public static function hasLatin(string $s): bool  { return (bool)preg_match('/[A-Za-z]/', $s); }

    private static function endsWithEn(string $k): bool { return (bool)preg_match('/_en$/', $k); }
    private static function endsWithAr(string $k): bool { return (bool)preg_match('/_ar$/', $k); }
    private static function flipToAr(string $k): string { return preg_replace('/_en$/', '_ar', $k); }
    private static function flipToEn(string $k): string { return preg_replace('/_ar$/', '_en', $k); }
}
