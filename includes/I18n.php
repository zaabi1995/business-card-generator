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
    // Cookie key, bumped to v3 on 2026-04-25 to invalidate any sticky
    // cardify_lang_v2=ar cookies left over from earlier sessions. Default
    // is English; Arabic is opt-in via the language pill.
    private const COOKIE_KEY = 'cardify_lang_v3';
    // Legacy keys we proactively delete on every request so we never get
    // stuck honouring an old preference even if the user reaches us via
    // a cached HTML body (the Set-Cookie response header still fires).
    private const LEGACY_COOKIE_KEYS = ['cardify_lang', 'cardify_lang_v2'];

    public static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        // Clear legacy cookies so previously persisted Arabic preferences
        // don't override the English default.
        if (!headers_sent()) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            foreach (self::LEGACY_COOKIE_KEYS as $legacy) {
                if (isset($_COOKIE[$legacy])) {
                    setcookie($legacy, '', [
                        'expires'  => time() - 3600,
                        'path'     => '/',
                        'secure'   => $secure,
                        'httponly' => false,
                        'samesite' => 'Lax',
                    ]);
                    unset($_COOKIE[$legacy]);
                }
            }
        }

        // If the visitor has no v2 cookie and no explicit ?lang= override,
        // also clear any leftover $_SESSION['cardify_lang'] from a prior
        // switcher click. Otherwise the session keeps forcing Arabic for
        // the rest of its lifetime even though the user expected English.
        if (!isset($_GET['lang'])
            && !isset($_COOKIE[self::COOKIE_KEY])
            && function_exists('session_status')
            && session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION['cardify_lang'])) {
            unset($_SESSION['cardify_lang']);
        }

        // The URL PATH outranks ?lang=. Without this, nginx's
        // `rewrite ^/ar/pricing$ /pricing.php?lang=ar last;` appends the
        // client's own args after ours, so /ar/pricing?lang=en arrives as
        // lang=ar&lang=en and PHP keeps the last one: an English body served
        // at an Arabic URL. r6-47.
        self::reconcilePathAndQuery();

        $locale = self::detect();
        self::$locale = $locale;

        // Persist if it came from a query param and wasn't already in the cookie.
        if (isset($_GET['lang']) && in_array($_GET['lang'], self::$supported, true)) {
            self::persistCookie($_GET['lang']);
            if (!isset($_COOKIE[self::COOKIE_KEY]) || $_COOKIE[self::COOKIE_KEY] !== $_GET['lang']) {
                $_COOKIE[self::COOKIE_KEY] = $_GET['lang'];
            }
            if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['cardify_lang'] = $_GET['lang'];
            }
        }
    }

    /**
     * Make the request PATH authoritative over the ?lang= query param.
     *
     * Two shapes were live before this existed, both measured on cardify.om:
     *   /ar/pricing?lang=en  -> 200, lang="en" dir="ltr", Arabic share 0.002
     *   /pricing?lang=ar     -> 200, lang="ar" dir="rtl", Arabic share 0.921
     * The first is an English body at an Arabic URL, the second a second copy
     * of the Arabic page at the English canonical. Both are duplicates that a
     * crawler can reach, and both survive the switcher fix because they are a
     * SERVER behaviour, not a link the switcher emits.
     *
     * Rules, deliberately narrow:
     *   A. path under /ar/  -> locale is ar, full stop. An explicit ?lang=en
     *      is read as "give me the English page" and 301s to the EN twin.
     *   B. path not under /ar/ with ?lang=ar -> 301 to the Arabic twin, but
     *      ONLY when ArTwins knows one exists. Everything ArTwins does not
     *      map (/admin, /portal, /company, the app surfaces) keeps the old
     *      query toggle untouched, which is what those pages opt into.
     */
    private static function reconcilePathAndQuery(): void
    {
        if (PHP_SAPI === 'cli') return;
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') return;

        // REQUEST_URI is the ORIGINAL client URI: nginx rewrites do not
        // rewrite it, which is the whole reason the path is trustworthy here.
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($uri === '') return;
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') return;

        $wanted = (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'ar'], true))
            ? $_GET['lang'] : null;
        $isArPath = ($path === '/ar' || $path === '/ar/' || strpos($path, '/ar/') === 0);

        if (!class_exists('ArTwins')) {
            $twins = __DIR__ . '/ArTwins.php';
            if (is_file($twins)) require_once $twins;
        }

        if ($isArPath) {
            if ($wanted === 'en' && class_exists('ArTwins')) {
                self::redirect(ArTwins::normalise($path), $uri);
                return;
            }
            // The path already said Arabic. Overwrite whatever the duplicated
            // query param left behind so page code reading $_GET['lang']
            // directly agrees with the URL it was served at.
            $_GET['lang'] = 'ar';
            return;
        }

        if ($wanted === 'ar' && class_exists('ArTwins')) {
            $arPath = ArTwins::arPath($path);
            if ($arPath !== null) {
                self::redirect($arPath, $uri);
            }
        }
    }

    /** 301 to $targetPath, carrying every query param except lang. */
    private static function redirect(string $targetPath, string $originalUri): void
    {
        if (headers_sent()) return;
        $query = parse_url($originalUri, PHP_URL_QUERY) ?: '';
        parse_str($query, $params);
        unset($params['lang']);
        $suffix = $params ? ('?' . http_build_query($params)) : '';
        header('Location: ' . $targetPath . $suffix, true, 301);
        exit;
    }

    private static function detect(): string
    {
        // 1. query param
        if (isset($_GET['lang']) && in_array($_GET['lang'], self::$supported, true)) {
            return $_GET['lang'];
        }
        // 2. cookie (current version)
        if (isset($_COOKIE[self::COOKIE_KEY]) && in_array($_COOKIE[self::COOKIE_KEY], self::$supported, true)) {
            return $_COOKIE[self::COOKIE_KEY];
        }
        // 3. session, only honoured when paired with the current cookie
        // (boot() clears the session var when no current cookie is present so
        // we don't get stuck on Arabic from a long-dead switcher click).
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE
            && isset($_SESSION['cardify_lang'])
            && in_array($_SESSION['cardify_lang'], self::$supported, true)) {
            return $_SESSION['cardify_lang'];
        }
        // 4. Always default to English. Accept-Language auto-detection is
        // intentionally off: every first-time visitor sees English
        // regardless of browser/OS locale. Arabic is opt-in via the
        // ?lang=ar query param or the header language pill.
        return self::$default;
    }

    private static function persistCookie(string $locale): void
    {
        if (headers_sent()) return;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_KEY, $locale, [
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
     * Register an extra runtime locale (e.g. a tenant's opt-in third card
     * language). Additive only: existing en/ar behaviour is untouched, so
     * this is safe to call per-request without affecting other pages/tenants.
     * After allow(), setLocale($code) and t(..., $code) work; missing keys
     * still fall back to the default (en) locale.
     */
    public static function allow(string $code, bool $rtl = false): void
    {
        $code = trim($code);
        if ($code === '') return;
        if (!in_array($code, self::$supported, true)) self::$supported[] = $code;
        if ($rtl && !in_array($code, self::$rtlLocales, true)) self::$rtlLocales[] = $code;
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
            // Longest placeholder first: ':large' is a prefix of ':largePct', so a
            // naive pass would rewrite ':largePct' into '1,027Pct'.
            $keys = array_keys($params);
            usort($keys, static fn($a, $b) => strlen((string) $b) <=> strlen((string) $a));
            foreach ($keys as $k) {
                $value = str_replace(':' . $k, (string) $params[$k], $value);
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
