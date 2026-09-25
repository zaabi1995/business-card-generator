<?php
/**
 * Deterministic normalization for Oman company identity.
 * No network, no model, no invented values.
 */
final class CompanyNormalizer
{
    private const EN_SUFFIXES = [
        'llc', 'l l c', 'limited liability company', 'saoc', 'saog', 'fzc', 'fze',
        'spc', 'co', 'company', 'the', 'and',
    ];

    public static function englishName(?string $name): string
    {
        $s = strtolower(trim((string) $name));
        $s = str_replace(['&', '+'], ' and ', $s);
        $s = preg_replace('/\bl\s*\.?\s*l\s*\.?\s*c\b/', ' ', $s) ?? $s;
        $s = preg_replace('/[^a-z0-9\x{0600}-\x{06FF}]+/u', ' ', $s) ?? $s;
        $parts = preg_split('/\s+/', trim($s)) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if ($p === '' || in_array($p, self::EN_SUFFIXES, true)) {
                continue;
            }
            $out[] = $p;
        }
        return implode(' ', $out);
    }

    public static function arabicName(?string $name): string
    {
        $s = trim((string) $name);
        if (class_exists('ArabicText')) {
            try {
                $s = ArabicText::normalize($s);
            } catch (Throwable $e) {
                // Keep the raw string if intl is missing. Do not invent a form.
            }
        }
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $s) ?? $s;
        $s = strtr($s, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);
        $s = preg_replace('/(?:شركه|مؤسسه|ش[\s.]*م[\s.]*م|ذ[\s.]*م[\s.]*م)/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^\p{Arabic}\p{N}]+/u', ' ', $s) ?? $s;
        $s = preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s);
        return $s;
    }

    public static function domain(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        return $host;
    }

    /** Oman commercial registration numbers are digits. Empty if not a plausible CR. */
    public static function crNumber(?string $raw): string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($digits === '' || strlen($digits) < 4 || strlen($digits) > 12) {
            return '';
        }
        return $digits;
    }

    public static function phone(?string $raw): string
    {
        $d = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if (str_starts_with($d, '00968')) {
            $d = substr($d, 2);
        }
        if (str_starts_with($d, '968') && strlen($d) >= 11) {
            return '+' . $d;
        }
        if (strlen($d) === 8) {
            return '+968' . $d;
        }
        return '';
    }
}
