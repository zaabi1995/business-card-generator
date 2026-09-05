<?php
/**
 * Cardify EN <-> AR twin map, the single source of truth for the Arabic URL tree.
 *
 * Before this file existed the Arabic tree was decided in three places that
 * disagreed with each other:
 *   1. nginx rewrites        (which /ar/ URLs return 200)
 *   2. includes/ui-header.php (which hreflang tags a page emitted)
 *   3. sitemap.php           (which /ar/ URLs Google was told about)
 *
 * The result was measurable on the live site: /ar/pricing returned 200 but
 * declared hreflang="en" pointing at ITSELF (telling Google the Arabic page
 * was the English one), /ar/about 404'd while /ar/pricing worked, and not one
 * English page named its Arabic twin, so no pair ever had the reciprocal
 * return tag Google requires before it will honour an alternate.
 *
 * ONE list now feeds all three. A path belongs here only when the page BODY is
 * genuinely translated, not just the header/footer chrome. Chrome alone is
 * ~663 Arabic characters on any Cardify page; every entry below was measured
 * live at well above that floor. /industries, /security, /cookies and every
 * /solutions/{slug}, /tools/{slug} and /gcc/{country} child measured at
 * exactly the chrome floor and are deliberately absent: an /ar/ URL that
 * serves English body copy is a duplicate, not a translation.
 *
 * The right instrument is the Arabic share of the AR page's LETTERS, not a
 * raw Arabic character count. A count above the chrome floor passed both
 * /ar/blog (chrome Arabic over 7,302 Latin characters of English post titles)
 * and /ar/get-started (an entirely English landing body); the share caught
 * them at 0.13 and 0.24 against 0.73-0.96 for every real twin. /ar/blog still
 * 301s to its English canonical. /get-started was translated on 5 Sep 2026,
 * body and all, and rejoined the list once tools/verify-ar-body.php measured
 * the real share rather than the chrome floor.
 *
 * Adding an Arabic page is a two-step change, and tools/verify-ar-twins.php
 * fails if either step is missing:
 *   - add the EN path here
 *   - add the matching `rewrite ^/ar/<path>/?$ /<file>.php?lang=ar last;`
 *
 * That gate proves the URL EXISTS. tools/verify-ar-body.php proves it is in
 * Arabic: it fetches every path below and fails any whose Arabic letter share
 * falls under 0.55. Run both before adding an entry here. The paragraph above
 * used to be the only thing enforcing the share rule, and a rule that lives
 * only in a comment is not a gate.
 */
class ArTwins
{
    public const SITE = 'https://cardify.om';

    /**
     * EN canonical paths that have a live, body-translated /ar/ twin.
     * Keep sorted; keep in sync with the nginx rewrite block.
     */
    private const PATHS = [
        '/',
        '/about',
        '/app',
        '/business-card-scanner',
        '/careers',
        '/case-studies',
        '/changelog',
        '/companies',
        '/contact',
        '/faq',
        '/gcc-business-index',
        '/get-started',
        '/logos',
        '/logos/press',
        '/logos/terms',
        '/nfc-business-card',
        '/oman-business-index',
        '/press-kit',
        '/pricing',
        '/print-shops',
        '/privacy',
        '/solutions',
        '/status',
        '/terms',
        '/tools',
    ];

    /**
     * EN path PREFIXES whose children nginx serves in both languages through a
     * parameterised rewrite, e.g. /companies/{slug}, /logos/{sector},
     * /case-studies/{slug}. They cannot be enumerated in PATHS (the set is a
     * database, not a list), but a switcher that ignored them would suppress
     * itself on the 5,000 highest-volume URLs on the site. Keep 1:1 with the
     * `rewrite ^/ar/<prefix>/...` block in the nginx conf.
     */
    private const AR_SUBTREES = [
        '/companies',
        '/logos',
        '/case-studies',
    ];

    /**
     * Public, indexable EN routes that deliberately have no Arabic body.
     *
     * These routes still need a URL-authoritative locale. Without this list,
     * an Arabic preference cookie can render Arabic chrome, lang="ar" and RTL
     * at an English canonical URL even though no Arabic twin exists.
     */
    private const ENGLISH_ONLY_PATHS = [
        '/blog',
        '/compare',
        '/cookies',
        '/delete-account',
        '/digital-business-card',
        '/glossary',
        '/industries',
        '/intro',
        '/media-kit',
        '/press',
        '/security',
        '/virtual-business-card',
    ];

    /**
     * Public EN-only route families. A family hub that has a real Arabic twin,
     * such as /tools or /solutions, is excluded by the arPath() check first.
     */
    private const ENGLISH_ONLY_SUBTREES = [
        '/blog',
        '/careers',
        '/compare',
        '/gcc',
        '/glossary',
        '/industries',
        '/solutions',
        '/tools',
    ];

    /** All EN paths with an Arabic twin. */
    public static function paths(): array
    {
        return self::PATHS;
    }

    /** Normalise any URL or path to a bare, trailing-slash-free EN path. */
    public static function normalise(string $urlOrPath): string
    {
        $path = parse_url($urlOrPath, PHP_URL_PATH);
        if (!is_string($path) || $path === '') $path = '/';
        // Strip the /ar prefix so both twins normalise to the same EN key.
        if ($path === '/ar' || $path === '/ar/') return '/';
        if (strpos($path, '/ar/') === 0) $path = substr($path, 3);
        if ($path !== '/') $path = '/' . trim($path, '/');
        return $path === '' ? '/' : $path;
    }

    /** True when the given URL/path is the Arabic side of a pair. */
    public static function isArabic(string $urlOrPath): bool
    {
        $path = parse_url($urlOrPath, PHP_URL_PATH) ?: '/';
        return $path === '/ar' || $path === '/ar/' || strpos($path, '/ar/') === 0;
    }

    /** True only for the configured Cardify site host, never a tenant host. */
    public static function isCanonicalSiteHost(string $host): bool
    {
        $normaliseHost = static function (string $value): string {
            $value = strtolower(trim($value));
            if ($value === '') return '';
            if (strpos($value, '://') !== false) {
                $parsed = parse_url($value, PHP_URL_HOST);
                $value = is_string($parsed) ? $parsed : '';
            }
            $value = preg_replace('/:\d+$/', '', $value) ?? '';
            return rtrim($value, '.');
        };

        $expected = defined('APP_HOST') ? (string) APP_HOST : (string) parse_url(self::SITE, PHP_URL_HOST);
        $expected = $normaliseHost($expected);
        $actual = $normaliseHost($host);
        if ($expected === '' || $actual === '') return false;

        if (strpos($expected, 'www.') === 0) $expected = substr($expected, 4);
        if (strpos($actual, 'www.') === 0) $actual = substr($actual, 4);

        return $actual === $expected;
    }

    /** True when this path (either side) has a real Arabic twin. */
    public static function has(string $urlOrPath): bool
    {
        return in_array(self::normalise($urlOrPath), self::PATHS, true);
    }

    /** True when a public canonical route must always render in English. */
    public static function isEnglishOnly(string $urlOrPath): bool
    {
        $p = self::normalise($urlOrPath);

        // A real Arabic twin always wins, including parameterised subtrees.
        if (self::arPath($p) !== null) return false;
        if (in_array($p, self::ENGLISH_ONLY_PATHS, true)) return true;

        foreach (self::ENGLISH_ONLY_SUBTREES as $prefix) {
            if (strpos($p, $prefix . '/') === 0) return true;
        }
        return false;
    }

    /** Absolute EN URL for a path (accepts either side of the pair). */
    public static function en(string $urlOrPath): string
    {
        $p = self::normalise($urlOrPath);
        return self::SITE . ($p === '/' ? '/' : $p);
    }

    /** Absolute AR URL for a path, or null when no Arabic twin exists. */
    public static function ar(string $urlOrPath): ?string
    {
        $p = self::normalise($urlOrPath);
        if (!in_array($p, self::PATHS, true)) return null;
        return self::SITE . '/ar' . ($p === '/' ? '/' : $p);
    }

    /**
     * The hreflang set for the current URL, as [hreflang, href] pairs.
     *
     * Both twins get the IDENTICAL set, which is exactly what makes the
     * return tag reciprocal. A page with no Arabic twin gets en + x-default
     * pointing at itself: the honest "English only" signal, not a fabricated
     * pair aimed at a URL that 404s.
     */
    public static function tags(string $currentUrlOrPath): array
    {
        return self::alternates($currentUrlOrPath);
    }

    /**
     * THE alternate set for a path. One oracle, both populations.
     *
     * tags() used to answer from PATHS alone while arPath() answered from
     * PATHS *and* AR_SUBTREES, so the same URL got two different answers
     * depending on who asked. Measured on the live site (r80): every
     * /logos/{sector} page emitted hreflang="ar" while sitemap.php, asking
     * ar(), emitted no alternate for it and never listed the Arabic twin at
     * all. Four emitters of this one fact existed, and they disagreed:
     * tags() here, pairTags() below, a hand-written triple in
     * views/logos_sector.php, and sitemap.php's own smUrl()/smUrlBilingual()
     * pair. Everything now resolves through this method.
     *
     * arPath() is the right source because it is the one that covers the
     * parameterised children nginx really serves; it returns null rather
     * than inventing an /ar/ URL, so a page with no Arabic twin still gets
     * the honest en + x-default self-pair.
     */
    public static function alternates(string $urlOrPath): array
    {
        $en = self::en($urlOrPath);
        $ar = self::arPath($urlOrPath);
        if ($ar === null) {
            return [['en', $en], ['x-default', $en]];
        }
        return [['en', $en], ['ar', self::SITE . $ar], ['x-default', $en]];
    }

    /**
     * Force the bilingual set for a PARAMETERISED path whose Arabic twin is
     * known live but which cannot be enumerated in PATHS, e.g.
     * /companies/{slug}, /companies/sector/{slug}. Callers building their own
     * $extraHead use this instead of hand-writing the three link tags, which
     * is how three of companies.php's four branches shipped without an
     * x-default while the fourth had one.
     */
    public static function pairTags(string $enPath): array
    {
        $p  = '/' . ltrim($enPath, '/');
        $en = self::SITE . $p;
        $ar = self::SITE . '/ar' . $p;
        return [['en', $en], ['ar', $ar], ['x-default', $en]];
    }

    /** pairTags() rendered as <link> markup, for $extraHead strings. */
    public static function pairLinks(string $enPath): string
    {
        $out = '';
        foreach (self::pairTags($enPath) as [$hrefLang, $hrefUrl]) {
            $out .= '<link rel="alternate" hreflang="' . $hrefLang . '" href="' . htmlspecialchars($hrefUrl, ENT_QUOTES) . '">';
        }
        return $out;
    }

    /**
     * Relative AR URL for a path, or null when no Arabic URL exists.
     *
     * Covers both populations: the enumerated twins in PATHS and the
     * parameterised children under AR_SUBTREES. Returning null is the point,
     * callers use it to render nothing rather than to invent a URL.
     */
    public static function arPath(string $urlOrPath): ?string
    {
        $p = self::normalise($urlOrPath);
        if (in_array($p, self::PATHS, true)) {
            return $p === '/' ? '/ar/' : '/ar' . $p;
        }
        foreach (self::AR_SUBTREES as $prefix) {
            if (strpos($p, $prefix . '/') === 0) return '/ar' . $p;
        }
        return null;
    }

    /** True when the request being served is the Arabic side. */
    public static function servingArabic(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return self::isArabic($path) || (($_GET['lang'] ?? '') === 'ar');
    }

    /**
     * An internal NAV link for a bare EN slug, in the reader's language.
     *
     * llm78-1: the site had two footers (includes/ui-footer.php and index.php's
     * own) that each built links as getBasePath() . '<slug>'. getBasePath()
     * derives the app root from SCRIPT_NAME and is locale-blind, so every
     * Arabic page linked back to the English tree: 17 of 31 footer links on
     * /ar/careers, 9 of 49 on /ar/ after the shared footer was fixed and the
     * homepage's private copy was not. The rule lives here so a third copy of
     * the markup cannot carry a fourth copy of the rule.
     *
     * The /ar prefix is never applied blindly: arPath() returns null for
     * /blog, /get-started, /security, /cookies and every /solutions/{slug},
     * /tools/{slug}, /industries/{slug} and /gcc/{country} child, which have no
     * Arabic URL and would otherwise become manufactured redirects and
     * soft-404s. Null means "render the English link", which is the honest one.
     */
    public static function navLink(string $slug, string $base = '/', ?bool $isAr = null): string
    {
        $isAr = $isAr ?? self::servingArabic();
        if (!$isAr) return $base . $slug;
        // A bare anchor belongs to the HOME page, so it follows the home twin.
        if ($slug !== '' && $slug[0] === '#') {
            $home = self::arPath('/');
            return $home === null ? $base . $slug : $base . ltrim($home, '/') . $slug;
        }
        $ar = self::arPath('/' . ltrim($slug, '/'));
        return $ar === null ? $base . $slug : $base . ltrim($ar, '/');
    }
}
