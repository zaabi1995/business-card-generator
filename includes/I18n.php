<?php
/**
 * Cardify I18n, lightweight bilingual (EN/AR) translation layer.
 *
 * Usage:
 *   t('common.save')                          // returns "Save" or "حفظ"
 *   t('auth.welcome', ['name' => $name])      // "Welcome, Ali" / "مرحبا، Ali"
 *   I18n::setLocale('ar')                     // switch + persist cookie
 *   I18n::getDir()                            // "ltr" | "rtl"
 *
 * Locale files: lang/{en,ar}/{namespace}.php, each returning a flat array.
 * Namespaces auto-load on first dotted-key lookup: t('admin.dashboard.title')
 * loads lang/{locale}/admin.php once.
 *
 * Lookup: param `?lang=` -> cookie `cardify_lang` -> session -> Accept-Language -> default.
 */
class I18n
{
    private static string $locale = 'en';
    private static string $default = 'en';
    private static array $supported = ['en', 'ar'];
    private static array $rtlLocales = ['ar'];
    private static array $loaded = []; // [locale][ns] => array
    private static bool $booted = false;

    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        $locale = self::detect();
        self::$locale = $locale;

        // Persist if it came from a query param and wasn't already in the cookie.
        if (isset($_GET['lang']) && in_array($_GET['lang'], self::$supported, true)) {
            self::persistCookie($_GET['lang']);
            if (!isset($_COOKIE['cardify_lang']) || $_COOKIE['cardify_lang'] !== $_GET['lang']) {
                $_COOKIE['cardify_lang'] = $_GET['lang'];
            }
            if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['cardify_lang'] = $_GET['lang'];
            }
        }
    }

    private static function detect(): string
    {
        // 1. query param
        if (isset($_GET['lang']) && in_array($_GET['lang'], self::$supported, true)) {
            return $_GET['lang'];
        }
        // 2. cookie
        if (isset($_COOKIE['cardify_lang']) && in_array($_COOKIE['cardify_lang'], self::$supported, true)) {
            return $_COOKIE['cardify_lang'];
        }
        // 3. session
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION['cardify_lang'])
            && in_array($_SESSION['cardify_lang'], self::$supported, true)) {
            return $_SESSION['cardify_lang'];
        }
        // 4. Accept-Language, only honour the PRIMARY language. A visitor
        // with Accept-Language: en-US,en;q=0.9,ar;q=0.5 wants English, not
        // Arabic. Previous heuristic (any ',ar' substring) flipped too many
        // bilingual visitors to AR by accident.
        $accept = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if ($accept !== '') {
            // Take the first language tag, strip any quality factor.
            $primary = strtolower(trim(explode(',', $accept)[0]));
            $primary = trim(explode(';', $primary)[0]);
            if ($primary === 'ar' || strpos($primary, 'ar-') === 0) {
                return 'ar';
            }
        }
        return self::$default;
    }

    private static function persistCookie(string $locale): void
    {
        if (headers_sent()) return;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('cardify_lang', $locale, [
            'expires'  => time() + 31536000, // 1 year
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => false, // readable by JS for instant UI switch
            'samesite' => 'Lax',
        ]);
    }

    public static function setLocale(string $locale): void
    {
        if (!in_array($locale, self::$supported, true)) return;
        self::$locale = $locale;
        self::persistCookie($locale);
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['cardify_lang'] = $locale;
        }
    }

    public static function getLocale(): string
    {
        return self::$locale;
    }

    public static function getDir(): string
    {
        return in_array(self::$locale, self::$rtlLocales, true) ? 'rtl' : 'ltr';
    }

    public static function isRtl(): bool
    {
        return self::getDir() === 'rtl';
    }

    public static function supported(): array
    {
        return self::$supported;
    }

    /**
     * t('admin.dashboard.title', ['name' => 'Ali'])
     * Splits on first dot, treats first segment as file/namespace.
     * Returns the key itself if translation is missing (so untranslated strings surface loudly in QA).
     */
    public static function t(string $key, array $params = [], ?string $locale = null): string
    {
        $locale = $locale ?: self::$locale;
        $parts = explode('.', $key, 2);
        if (count($parts) < 2) {
            // flat key in 'common' namespace
            $ns = 'common';
            $sub = $key;
        } else {
            $ns = $parts[0];
            $sub = $parts[1];
        }

        $translations = self::loadNamespace($locale, $ns);
        $value = self::dotGet($translations, $sub);

        if ($value === null && $locale !== self::$default) {
            // fallback to default locale
            $translations = self::loadNamespace(self::$default, $ns);
            $value = self::dotGet($translations, $sub);
        }
        if ($value === null) {
            $value = $key; // surface missing keys rather than silently returning blank
        }

        if (!empty($params) && is_string($value)) {
            foreach ($params as $k => $v) {
                $value = str_replace(':' . $k, (string) $v, $value);
            }
        }
        return is_string($value) ? $value : $key;
    }

    private static function loadNamespace(string $locale, string $ns): array
    {
        if (isset(self::$loaded[$locale][$ns])) {
            return self::$loaded[$locale][$ns];
        }
        $path = (defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__))
              . '/lang/' . $locale . '/' . $ns . '.php';
        $data = [];
        if (is_file($path)) {
            $data = require $path;
            if (!is_array($data)) $data = [];
        }
        self::$loaded[$locale][$ns] = $data;
        return $data;
    }

    private static function dotGet(array $arr, string $path)
    {
        if (isset($arr[$path])) return $arr[$path];
        $segments = explode('.', $path);
        $cur = $arr;
        foreach ($segments as $seg) {
            if (is_array($cur) && array_key_exists($seg, $cur)) {
                $cur = $cur[$seg];
            } else {
                return null;
            }
        }
        return $cur;
    }

    /** Arabic has 6 plural forms (CLDR). */
    public static function plural(int $n, array $forms, ?string $locale = null): string
    {
        $locale = $locale ?: self::$locale;
        if ($locale === 'ar') {
            if ($n === 0) return $forms['zero']  ?? $forms['other'] ?? '';
            if ($n === 1) return $forms['one']   ?? $forms['other'] ?? '';
            if ($n === 2) return $forms['two']   ?? $forms['other'] ?? '';
            if ($n % 100 >= 3  && $n % 100 <= 10)  return $forms['few']  ?? $forms['other'] ?? '';
            if ($n % 100 >= 11 && $n % 100 <= 99)  return $forms['many'] ?? $forms['other'] ?? '';
            return $forms['other'] ?? '';
        }
        if ($n === 1) return $forms['one'] ?? $forms['other'] ?? '';
        return $forms['other'] ?? '';
    }

    public static function formatCurrency(float $amount, ?string $locale = null): string
    {
        $locale = $locale ?: self::$locale;
        $formatted = number_format($amount, 3, '.', ',');
        if ($locale === 'ar') {
            $digits = ['0','1','2','3','4','5','6','7','8','9'];
            $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
            $formatted = str_replace($digits, $arabic, $formatted);
            $formatted = str_replace('.', '٫', $formatted);
            $formatted = str_replace(',', '٬', $formatted);
            return $formatted . ' ر.ع.';
        }
        return 'OMR ' . $formatted;
    }

    public static function formatDate(int $ts, ?string $locale = null): string
    {
        $locale = $locale ?: self::$locale;
        if ($locale === 'ar') {
            $months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
            $d = (int)date('j', $ts);
            $m = (int)date('n', $ts);
            $y = date('Y', $ts);
            $out = $d . ' ' . $months[$m-1] . ' ' . $y;
            $digits = ['0','1','2','3','4','5','6','7','8','9'];
            $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
            return str_replace($digits, $arabic, $out);
        }
        return date('j M Y', $ts);
    }

    public static function timeAgo(int $ts, ?string $locale = null): string
    {
        $locale = $locale ?: self::$locale;
        $diff = time() - $ts;
        if ($diff < 0) $diff = 0;
        $units = [
            ['sec', 60, ['en' => ['one' => 'just now', 'other' => ':n sec ago'],
                         'ar' => ['one' => 'الآن',    'other' => 'قبل :n ثانية']]],
            ['min', 3600, ['en' => ['one' => '1 min ago', 'other' => ':n min ago'],
                           'ar' => ['one' => 'قبل دقيقة', 'other' => 'قبل :n دقيقة']]],
            ['hr',  86400, ['en' => ['one' => '1 hr ago', 'other' => ':n hr ago'],
                            'ar' => ['one' => 'قبل ساعة',  'other' => 'قبل :n ساعة']]],
            ['day', 604800, ['en' => ['one' => 'yesterday', 'other' => ':n days ago'],
                             'ar' => ['one' => 'أمس',        'other' => 'قبل :n يوم']]],
            ['wk',  2628000, ['en' => ['one' => '1 wk ago', 'other' => ':n wk ago'],
                              'ar' => ['one' => 'قبل أسبوع',  'other' => 'قبل :n أسبوع']]],
            ['mo',  31536000, ['en' => ['one' => '1 mo ago', 'other' => ':n mo ago'],
                               'ar' => ['one' => 'قبل شهر',    'other' => 'قبل :n شهر']]],
            ['yr',  PHP_INT_MAX, ['en' => ['one' => '1 yr ago', 'other' => ':n yr ago'],
                                  'ar' => ['one' => 'قبل سنة',   'other' => 'قبل :n سنة']]],
        ];
        $unit = 1;
        foreach ($units as [$label, $threshold, $forms]) {
            if ($diff < $threshold) {
                $n = max(1, (int) floor($diff / $unit));
                $f = $forms[$locale] ?? $forms['en'];
                $tpl = ($n === 1) ? ($f['one'] ?? $f['other']) : $f['other'];
                $out = str_replace(':n', (string)$n, $tpl);
                if ($locale === 'ar') {
                    $digits = ['0','1','2','3','4','5','6','7','8','9'];
                    $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
                    $out = str_replace($digits, $arabic, $out);
                }
                return $out;
            }
            $unit = $threshold;
        }
        return self::formatDate($ts, $locale);
    }
}
