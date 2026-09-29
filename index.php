<?php
// llm27-29: the homepage answered on two addresses, / and /index.php, both 200
// and both self-canonical, so the property's most-linked page had two identities.
// Done before anything else loads, and keyed on the REQUEST path rather than on
// SCRIPT_NAME, which is /index.php for the bare / route too and would loop.
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if (substr($reqPath, -10) === '/index.php') {
    $target = substr($reqPath, 0, -9);
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . $target . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit;
}
require_once __DIR__ . '/includes/PlatformStats.php';
// llm78-1: the homepage owns its footer (ui-footer.php skips it), so it carried
// a SECOND copy of the locale-blind getBasePath() . '<slug>' link list. After
// the shared footer was fixed, /ar/ was still linking 9 of its 49 internal
// footer links back to the English twin, and only the live probe saw it. Both
// footers now call the one rule in ArTwins::navLink().
require_once __DIR__ . '/includes/ArTwins.php';
require_once __DIR__ . '/includes/AppEntity.php';
$_homeIsAr = ArTwins::servingArabic();
// llm75-1: the homepage blog cards are bilingual DB records, and the class that
// refuses an untranslated one is required HERE, beside the call site's include,
// because this file has no autoloader: a bare `BilingualRecord::rows(...)`
// below would be a fatal on every locale, not a missing translation.
require_once __DIR__ . '/includes/BilingualRecord.php';
// llm47-4: the solutions CTA renders its count from the shelf, not from a digit
// typed into two translation files.
require_once __DIR__ . '/includes/SolutionShelf.php';
/**
 * Cardify - Business Cards Made Simple
 * SaaS Landing Page
 */

// Helper function to get base path (before config.php loads)
function getBasePathForRedirect() {
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
    $scriptPath = str_replace('\\', '/', $scriptPath);
    $scriptDir = dirname($scriptPath);
    
    if ($scriptDir === '/' || $scriptDir === '.' || $scriptDir === '') {
        return '/';
    }
    
    $basePath = rtrim($scriptDir, '/') . '/';
    if ($basePath[0] !== '/') {
        $basePath = '/' . $basePath;
    }
    return $basePath;
}

// Check if installation is needed
$configFile = __DIR__ . '/config.php';
$installDir = __DIR__ . '/install';

// If config.php doesn't exist, redirect to installer
if (!file_exists($configFile)) {
    if (is_dir($installDir)) {
        header('Location: ' . getBasePathForRedirect() . 'install/');
        exit;
    } else {
        die('Configuration file not found. Please run the installation wizard.');
    }
}

require_once $configFile;

// Hand-built tenant pages (includes/tenant-pages/<slug>/<page>.php), e.g. the
// Mehdi Store bag QR at mehdistore.cardify.om/main. Checked before the tenant
// DB lookup so a printed URL survives a suspended company row. A subdomain with
// no page directory returns straight away, which is every other tenant.
if (file_exists(__DIR__ . '/includes/TenantPages.php')) {
    require_once __DIR__ . '/includes/TenantPages.php';
    TenantPages::dispatch();
}

// Tenant subdomain check (e.g. ohb.cardify.om).
// Convention across all tenants:
//   <slug>.cardify.om/        -> portal.php  (employee Self-Service request form)
//   <slug>.cardify.om/login   -> tenant_login.php (admin OTP sign-in)
//   <slug>.cardify.om/admin/  -> admin dashboard (post-login)
//   <slug>.cardify.om/<email-localpart>  -> digital_card.php (printed card target)
//   <slug>.cardify.om/card/<id>          -> digital_card.php (legacy URL pattern)
if (file_exists(__DIR__ . '/includes/TenantHost.php')) {
    require_once __DIR__ . '/includes/TenantHost.php';
    if (TenantHost::isTenantHost()) {
        $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if ($reqPath === '/login' || $reqPath === '/login/') {
            require __DIR__ . '/tenant_login.php';
        } elseif ($reqPath === '/my-card' || $reqPath === '/my-card/') {
            // Employee self-service: OTP door onto their own card edit page.
            if (isset($_GET['restart'])) {
                if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
                unset(
                    $_SESSION['mycard_identifier'], $_SESSION['mycard_identifier_raw'],
                    $_SESSION['mycard_channel'], $_SESSION['mycard_employee_id'],
                    $_SESSION['mycard_pending_verify'], $_SESSION['mycard_notice']
                );
            }
            require __DIR__ . '/my_card.php';
        } else {
            // Default: employee request portal. A bare single-token path that
            // is NOT the canonical "/" or "/portal" reaches here only as a
            // soft-404 fallback (mistyped/old card link, scanner probing
            // /wp-admin, etc.). The portal still renders so a real visitor can
            // request a card, but tell search engines NOT to index these
            // arbitrary URLs, else Google indexes infinite junk paths per
            // tenant as soft-404s. (BHD loop audit iter 13, 3 Jun 2026.)
            $canonicalPortal = ($reqPath === '/' || $reqPath === ''
                || $reqPath === '/portal' || $reqPath === '/portal/'
                || preg_match('~^/portal/[a-z0-9-]+/?$~i', $reqPath));
            // A bare single-token path (/akamariz) is an employee card URL:
            // the email localpart. nginx routes dotted localparts (/first.last)
            // straight to digital_card.php but sends single tokens here, so
            // resolve them the same way before falling back to the portal.
            // Without this every employee whose localpart has no dot lands on
            // the request form instead of their own card.
            if (!$canonicalPortal && preg_match('~^/([a-z0-9][a-z0-9_-]*)/?$~i', $reqPath, $__cardTok)) {
                $__convFile = __DIR__ . '/includes/CardifyConvention.php';
                if (file_exists($__convFile)) {
                    require_once $__convFile;
                    $__tenantCo = findCompanyBySlug((string) TenantHost::slug());
                    if ($__tenantCo && CardifyConvention::resolveEmployeeToken($__cardTok[1], $__tenantCo['id'])) {
                        $_GET['employee_id'] = $__cardTok[1];
                        require __DIR__ . '/digital_card.php';
                        exit;
                    }
                }
            }
            if (!$canonicalPortal && !headers_sent()) {
                header('X-Robots-Tag: noindex, nofollow', true);
            }
            require __DIR__ . '/portal.php';
        }
        exit;
    }
}

// Custom Domain check, if the Host header maps to a verified custom domain,
// this serves the linked employee card and exits. Otherwise returns and the
// normal landing-page flow continues unchanged.
if (file_exists(__DIR__ . '/custom_domain_router.php')) {
    require __DIR__ . '/custom_domain_router.php';
}

// Check if database is configured and installation is complete
$needsInstallation = false;

if (!defined('DB_HOST') || empty(DB_HOST) || !defined('DB_NAME') || empty(DB_NAME)) {
    $needsInstallation = true;
} else {
    try {
        if (class_exists('Database')) {
            $db = Database::getInstance();
            if (!$db->isConnected()) {
                if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
                    $connected = $db->connect(DB_HOST, DB_NAME, DB_USER, DB_PASS ?? '', DB_PORT ?? '3306', DB_TYPE ?? 'mysql');
                    if (!$connected) {
                        $needsInstallation = true;
                    }
                } else {
                    $needsInstallation = true;
                }
            }
            
            if ($db->isConnected()) {
                try {
                    $tables = $db->fetchAll("SHOW TABLES LIKE 'system_settings'");
                    if (empty($tables)) {
                        $needsInstallation = true;
                    } else {
                        try {
                            $setting = $db->fetchOne("SELECT setting_value FROM system_settings WHERE setting_key = 'installation_complete'");
                            
                            if ($setting !== false && !empty($setting) && isset($setting['setting_value'])) {
                                $value = trim((string)$setting['setting_value']);
                                if ($value === '1' || $value === 'true' || $value === 'yes' || $value === 'TRUE') {
                                    $needsInstallation = false;
                                } else {
                                    $needsInstallation = true;
                                }
                            } else {
                                try {
                                    $companiesCount = $db->fetchOne("SELECT COUNT(*) as count FROM companies");
                                    if ($companiesCount && ($companiesCount['count'] ?? 0) > 0) {
                                        $uuid = generateUUID();
                                        try {
                                            $db->insert('system_settings', [
                                                'id' => $uuid,
                                                'setting_key' => 'installation_complete',
                                                'setting_value' => '1',
                                                'description' => 'Whether installation has been completed'
                                            ]);
                                            $needsInstallation = false;
                                        } catch (Exception $insertError) {
                                            try {
                                                $db->update('system_settings',
                                                    ['setting_value' => '1'],
                                                    'setting_key = :key',
                                                    ['key' => 'installation_complete']
                                                );
                                                $needsInstallation = false;
                                            } catch (Exception $updateError) {
                                                $needsInstallation = true;
                                            }
                                        }
                                    } else {
                                        $needsInstallation = true;
                                    }
                                } catch (Exception $e) {
                                    $needsInstallation = true;
                                }
                            }
                        } catch (Exception $e) {
                            $needsInstallation = true;
                        }
                    }
                } catch (Exception $e) {
                    $needsInstallation = true;
                }
            }
        } else {
            $needsInstallation = true;
        }
    } catch (Exception $e) {
        $needsInstallation = true;
    }
}

// Redirect to installer if needed
if ($needsInstallation && is_dir($installDir)) {
    if (!headers_sent()) {
        $basePath = function_exists('getBasePath') ? getBasePath() : getBasePathForRedirect();
        header('Location: ' . $basePath . 'install/');
        exit;
    }
}

// Suppress permission warnings during initialization
error_reporting(E_ALL & ~E_WARNING);
@initializeDataFiles();
error_reporting(E_ALL);

// Check if this is a company-specific route
if (isset($_GET['company_slug'])) {
    require __DIR__ . '/router.php';
    exit;
}

// r16-103 guard. index.php is reachable through the single-token catch-all
// rewrite and through ANY specific rewrite that fails to match (a deploy window,
// a truncated rewrite file, an edge divergence). Until now it emitted
// canonical=https://cardify.om/ unconditionally, so a fall-through published the
// homepage body under the homepage canonical: a silent soft-duplicate on
// /pricing, /about, /status, /changelog and /case-studies, plus on every unknown
// single-token path. Never publish a homepage canonical for a path that is not a
// homepage path. Self-canonicalise and noindex instead, so the failure is
// visible to the gate and to Search Console rather than merged away.
$__r16103Path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$__r16103Norm = rtrim($__r16103Path, '/');
if ($__r16103Norm === '') { $__r16103Norm = '/'; }
$__r16103IsHome = in_array($__r16103Norm, ['/', '/index.php', '/ar', '/ar/index.php'], true);

// Brand name
$brandName = 'Cardify';
$tagline = 'Business Cards Made Simple';
$pageTitle = t('landing.meta_title');
$pageDescription = t('landing.meta_desc');
// Self-canonicalize per locale (the AR home previously canonicalized to the EN
// home, so Google never indexed it) + emit a full bilingual hreflang set
// (ui-header's default only advertises en + x-default, never ar).
$canonicalUrl = (function_exists('currentLocale') && currentLocale() === 'ar')
    ? 'https://cardify.om/ar/'
    : 'https://cardify.om/';
if (!$__r16103IsHome) {
    // Fall-through: the body is the homepage but the URL is not.
    $canonicalUrl = 'https://cardify.om' . $__r16103Path;
    $metaRobots   = 'noindex, follow';
    if (!headers_sent()) { header('X-Robots-Tag: noindex, follow', true); }
}
$suppressDefaultHreflang = true;
$homeHreflang = '<link rel="alternate" hreflang="en" href="https://cardify.om/">'
              . '<link rel="alternate" hreflang="ar" href="https://cardify.om/ar/">'
              . '<link rel="alternate" hreflang="x-default" href="https://cardify.om/">';
$bodyClass = 'bg-white';

// Homepage pricing: compute display strings in the visitor's currency once,
// so the currency pill in the header switches ALL shown prices. Source of
// truth is OMR (rates live in Currency.php fx table); formatNumber respects
// per-currency decimals and separators.
require_once INCLUDES_DIR . '/Currency.php';
require_once INCLUDES_DIR . '/JsonLd.php';
require_once INCLUDES_DIR . '/CardCatalogPricing.php';
$homeCur     = Currency::getUserCurrency();
$homeCurName = $homeCur;
// Convert, then marketing-round (keeps OMR exact, rounds AED/USD/etc to
// clean psychological numbers like 50 / 150 / 1,500 instead of 47.72).
// BHD and KWD are rounded to the nearest whole number AND displayed with
// no decimals (4 instead of 4.000) since Ali asked for the "closest total".
$fmt = function ($omr) use ($homeCur) {
    $converted = Currency::convert((float)$omr, $homeCur);
    $rounded   = Currency::marketingRound($converted, $homeCur);
    if (in_array($homeCur, ['BHD', 'KWD'], true) && floor($rounded) == $rounded) {
        return number_format($rounded, 0);
    }
    return Currency::formatNumber($rounded, $homeCur);
};
// Tier-based subscription pricing was removed Apr 2026. Platform is free forever,
// revenue comes from per-order print products (see lang/en/pricing.php and /pricing).

// Cheapest print product, Standard at OMR 5.000 per 100, converted to the
// visitor's currency. The hero's "Prints from :amount :currency" line lost its
// value in that Apr 2026 removal and had been rendering "Prints from  OMR" with
// an empty amount ever since, logging an undefined-variable warning on every
// homepage hit. Keep in step with /pricing and the Product JSON-LD below.
$priceStarterFrom = $fmt(CardCatalogPricing::amount('standard'));

// Latest blog posts for homepage SEO (internal links + freshness signal).
//
// llm75-1: this section used to select `title` and `excerpt` only, so the
// Arabic homepage printed three English headings and three English blurbs
// inside <html lang="ar">. The posts are bilingual RECORDS (migration 087 added
// title_ar/excerpt_ar/slug_ar); a post with no Arabic twin has no business on
// an Arabic page, and /ar/blog is retired (301 -> /blog), so an Arabic card
// would also link a reader out of their own language. BilingualRecord refuses
// the untranslated rows; if none survive, the section does not render at all.
// Translate a post (fill title_ar + excerpt_ar) and it reappears by itself.
$latestPosts = [];
try {
    if (isset($db) && $db->isConnected() && $db->tableExists('blog_posts')) {
        $latestPosts = $db->fetchAll(
            "SELECT slug, slug_ar, title, title_ar, excerpt, excerpt_ar, featured_image, published_at
             FROM blog_posts
             WHERE status='published'
             ORDER BY published_at DESC
             LIMIT 3"
        );
        $latestPosts = BilingualRecord::rows($latestPosts, ['title', 'excerpt'], 'blog_posts');
    }
} catch (Exception $e) {
    $latestPosts = [];
}

// WebSite JSON-LD for homepage (Organization + SoftwareApp already in body of index.php)
// Adds SearchAction so Google can surface a sitelinks search box in SERP.
$siteLd = [
    '@context' => 'https://schema.org',
    '@type' => 'WebSite',
    // r6-95: the per-page WebPage nodes point isPartOf at this @id, so it has
    // to exist or every dateModified hangs off an unresolved reference.
    '@id' => 'https://cardify.om/#website',
    'name' => 'Cardify',
    'alternateName' => 'Cardify GCC',
    'url' => 'https://cardify.om/',
    'inLanguage' => ['en', 'ar'],
    // r20-11: this was a 4-key Organization node under the SAME @id the page
    // defines in full further down, so the document declared the entity twice
    // and the shorter copy (no parent, no address, no logo) is the one a
    // consumer meets first. A publisher slot takes a REFERENCE; the definition
    // lives in exactly one place.
    'publisher' => ['@id' => 'https://cardify.om/#organization'],
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => [
            '@type' => 'EntryPoint',
            'urlTemplate' => 'https://cardify.om/companies?q={search_term_string}',
        ],
        'query-input' => 'required name=search_term_string',
    ],
];
$homeJsonLd = '<script type="application/ld+json">' . json_encode($siteLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES) . '</script>';
// r81 / r6-99 + llm20-21: this literal WAS one of the two competing
// definitions of the app. It is now the ONE record in includes/AppEntity.php,
// read by this page, /app and /business-card-scanner, so a fourth spelling
// cannot be typed into a fourth file.
$scannerLd = ['@context' => 'https://schema.org'] + AppEntity::node();
$scannerJsonLd = '<script type="application/ld+json">' . json_encode($scannerLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES) . '</script>';
// r116 / bhd-r6-99: these five meta tags used to hand-type the App Store id
// twice, the app's name once and its page once, four lines under a comment
// promising a fourth spelling could not be typed into a fourth file. They
// read AppEntity now, so the promise is structural instead of stated.
$appDiscoveryHead = '<meta name="apple-itunes-app" content="app-id=' . AppEntity::APPSTORE_ID . ', app-argument=cardifyscan://">'
    . '<meta property="al:ios:app_store_id" content="' . AppEntity::APPSTORE_ID . '">'
    . '<meta property="al:ios:app_name" content="' . htmlspecialchars(AppEntity::NAME, ENT_QUOTES) . '">'
    . '<meta property="al:ios:url" content="cardifyscan://">'
    . '<meta property="al:web:url" content="' . AppEntity::PAGE . '">';

// r20-47: the hub carried no question-shaped heading and no FAQPage, so the
// one page every model lands on first answered none of the questions it is
// asked. These six reuse the SAME lang keys /faq renders, so the hub and the
// FAQ page can never drift apart, and the answers below are rendered visibly,
// which is what faq_gate.py asserts. One key per category, entity question
// last because it is the least useful to a buyer and the most useful to a
// model trying to resolve who publishes Cardify.
$homeFaqKeys = ['gs1', 'dc1', 'pr1', 'tm1', 'bl1', 'co1'];
$homeFaqPairs = [];
if (function_exists('t')) {
    foreach ($homeFaqKeys as $__k) {
        $__q = t('faq.' . $__k . '_q');
        $__a = t('faq.' . $__k . '_a');
        // A missing key echoes its own name back; never publish that.
        if ($__q && $__a && strpos($__q, 'faq.') !== 0 && strpos($__a, 'faq.') !== 0) {
            $homeFaqPairs[] = [$__q, $__a];
        }
    }
}
$homeFaqJsonLd = '';
if ($homeFaqPairs) {
    $__entries = [];
    foreach ($homeFaqPairs as [$__q, $__a]) {
        $__entries[] = [
            '@type' => 'Question',
            'name' => $__q,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $__a],
        ];
    }
    $homeFaqJsonLd = '<script type="application/ld+json">' . json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        '@id' => 'https://cardify.om/' . ((function_exists('currentLocale') && currentLocale() === 'ar') ? 'ar/' : '') . '#faq',
        'inLanguage' => (function_exists('currentLocale') && currentLocale() === 'ar') ? 'ar' : 'en',
        'mainEntity' => $__entries,
    ], JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
}

// r6-80: the measured desktop shift was the mock-card column being re-centred
// when the Sora swap grew the text column 28px. The .hero-grid / .hero-reserve
// / .hero-h1 / .hero-sub rules below anchor the columns to the top and commit
// the text column height up front. Written here because lg:items-start is
// absent from the prebuilt tailwind.min.css this page loads, so the utility
// class was inert. This note is PHP-side on purpose: it addresses whoever
// edits this file, not whoever reads the page, so it must not ship as bytes.
$extraHead = $homeHreflang . $homeJsonLd . $scannerJsonLd . $homeFaqJsonLd . $appDiscoveryHead . '<style>
    .hero-gradient { background: linear-gradient(135deg, #eff6ff 0%, #ffffff 50%, #fffbeb 100%); }
    @media (min-width: 1024px) {
      .hero-grid { align-items: start; }
      .hero-reserve { min-height: 780px; }
      /* measured post-swap heights at 1440px, so a reflow has room */
      .hero-h1    { min-height: 240px; }
      .hero-sub   { min-height: 168px; }
      .hero-trust { min-height: 76px; }
    }
    .card-shadow { box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15); }
    .float-animation { animation: float 6s ease-in-out infinite; }
    @keyframes float {
        0%, 100% { transform: translateY(0px) rotate(-2deg); }
        50% { transform: translateY(-20px) rotate(2deg); }
    }
    .float-delayed { animation: float 6s ease-in-out infinite; animation-delay: -3s; }
    .float-delay-2 { animation-delay: -1.5s; }
    .bg-blur { backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
</style>';

// Enable dynamic navigation with auth awareness
$showNavigation = true;
// r79: the homepage owns a SECOND copy of the nav list, exactly as it owned a
// second copy of the footer in r78. Both copies now ask ArTwins::navLink()
// instead of gluing getBasePath() to a slug, which is locale-blind.
// Bare '#features' resolves to /ar/#features on the Arabic home, not to the
// English home's anchor.
$navLinks = [
    ['href' => ArTwins::navLink('#features',            getBasePath(), $_homeIsAr), 'label' => function_exists('t') ? t('footer.link_features')   : 'Features'],
    ['href' => ArTwins::navLink('#pricing',             getBasePath(), $_homeIsAr), 'label' => function_exists('t') ? t('footer.link_pricing')    : 'Pricing'],
    ['href' => ArTwins::navLink('tools',                getBasePath(), $_homeIsAr), 'label' => function_exists('t') ? t('footer.link_all_tools')  : 'Free Tools'],
    ['href' => ArTwins::navLink('oman-business-index',  getBasePath(), $_homeIsAr), 'label' => function_exists('t') ? t('footer.link_oman_index') : 'Oman Business Index'],
    ['href' => ArTwins::navLink('blog',                 getBasePath(), $_homeIsAr), 'label' => function_exists('t') ? t('footer.link_blog')       : 'Blog'],
];

// Include Auth for navigation state
require_once INCLUDES_DIR . '/Auth.php';


require_once INCLUDES_DIR . '/ui-header.php';
?>

    <!-- ========== HERO SECTION (Flowbite Style) ========== -->
    <section id="landing-hero" class="hero-gradient pt-28 lg:pt-36 pb-16 lg:pb-24 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center hero-grid">
                <!-- Left Content -->
                <div class="lg:col-span-6 text-center lg:text-left hero-reserve">
                    <!-- Badge -->
                    <div class="hero-badge inline-flex items-center gap-2 py-1 pl-1 pr-4 mb-6 text-sm bg-white border border-gray-200 rounded-full shadow-sm">
                        <svg aria-hidden="true" focusable="false" width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden"><symbol id="cf-oman-flag" viewBox="0 0 640 480"><defs><clipPath id="cf-om-clip"><path fill-opacity=".7" d="M0 0h640v480H0z"/></clipPath></defs><g clip-path="url(#cf-om-clip)"><path fill="#ef2d29" fill-rule="evenodd" d="M-3.3-21.6H699v553H-3.3z"/><path fill="#009025" fill-rule="evenodd" d="M174.6 317.3h535.7V525H174.6z"/><path fill="#fff" fill-rule="evenodd" d="M174.6-35.4h564.9v190h-565z"/><g stroke="#ef2d28"><g fill="#fff" fill-rule="evenodd" transform="matrix(.19848 0 0 .17744 111.3 -13.4)"><rect width="138.2" height="85" x="17.7" y="467.7" stroke-width="1.4" rx="11.3" ry="11.8"/><rect width="131.1" height="78" x="21.3" y="471.3" stroke-width="1.3" rx="10.7" ry="10.9"/><path stroke-width="1.3" d="m65 396 9.7.5.4 5.8 8 5.3 6.2-6.7 7.5 5.3-7 5.8 1.7 8 8.8-.5V430l-7-.4-3.6 6.6 8 7.5-6.2 6.2-6.7-6.6-9.7 2.6.5 9.7-10.6 1-1.4-9.4-8.8-4.8-4.9 6.6-7.5-4.9 4.4-7.5-5.3-4.8H34l-.4-13.7 7.5.9 5.3-8-6.2-6.2 8-7 5.7 5.7 9.7-1.8L65 396z" transform="matrix(.68108 0 0 .5852 38 260.7)"/><ellipse cx="68.9" cy="426.8" stroke-width="1.3" rx="11.1" ry="9.9" transform="matrix(.65819 0 0 .70224 38.8 209.6)"/><path stroke-width="1.3" d="m39 474.8-10.7 10.6m17.8-10.6-10.7 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6L78 485.4m17.7-10.6L85 485.4m17.8-10.6L92 485.4m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m-17.7-10.6L78 485.4m46-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m0-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.7 10.6M85 474.8l10.6 10.6m-17.8-10.6 10.7 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6M85 474.8l10.6 10.6m-46-10.6 10.5 10.6m-17.7-10.6 10.7 10.6m-17.8-10.6L46 485.4m-17.8-10.6L39 485.4m0 49.6-10.6 10.7M46 535l-10.7 10.7M53.2 535l-10.7 10.7M60.2 535l-10.6 10.7M67.3 535l-10.6 10.7M74.4 535l-10.6 10.7M81.5 535 71 545.7M88.6 535 78 545.7M95.7 535 85 545.7m17.7-10.7L92 545.7m18-10.7-10.7 10.7M117 535l-10.6 10.7M88.6 535 78 545.7m46-10.7-10.6 10.7m17.7-10.7-10.6 10.7m17.7-10.7-10.6 10.7m17.7-10.7-10.6 10.7m0-10.7 10.6 10.7M127.6 535l10.6 10.7M120.5 535l10.6 10.7M113.4 535l10.6 10.7M106.3 535l10.6 10.7M99.2 535l10.7 10.7M92 535l10.7 10.7M85 535l10.6 10.7M78 535l10.6 10.7M70.9 535l10.6 10.7M63.8 535l10.6 10.7M56.7 535l10.6 10.7M85.1 535l10.6 10.7m-46-10.7 10.5 10.7M42.5 535l10.7 10.7M35.4 535l10.7 10.7M28.4 535 39 545.7"/></g><g fill="#fff" fill-rule="evenodd" transform="matrix(.19848 0 0 .17744 19.1 -14)"><rect width="138.2" height="85" x="17.7" y="467.7" stroke-width="1.4" rx="11.3" ry="11.8"/><rect width="131.1" height="78" x="21.3" y="471.3" stroke-width="1.3" rx="10.7" ry="10.9"/><path stroke-width="1.3" d="m65 396 9.7.5.4 5.8 8 5.3 6.2-6.7 7.5 5.3-7 5.8 1.7 8 8.8-.5V430l-7-.4-3.6 6.6 8 7.5-6.2 6.2-6.7-6.6-9.7 2.6.5 9.7-10.6 1-1.4-9.4-8.8-4.8-4.9 6.6-7.5-4.9 4.4-7.5-5.3-4.8H34l-.4-13.7 7.5.9 5.3-8-6.2-6.2 8-7 5.7 5.7 9.7-1.8L65 396z" transform="matrix(.68108 0 0 .5852 38 260.7)"/><ellipse cx="68.9" cy="426.8" stroke-width="1.3" rx="11.1" ry="9.9" transform="matrix(.65819 0 0 .70224 38.8 209.6)"/><path stroke-width="1.3" d="m39 474.8-10.7 10.6m17.8-10.6-10.7 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6L78 485.4m17.7-10.6L85 485.4m17.8-10.6L92 485.4m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m-17.7-10.6L78 485.4m46-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m17.7-10.6-10.6 10.6m0-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.7 10.6M85 474.8l10.6 10.6m-17.8-10.6 10.7 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6m-17.7-10.6 10.6 10.6M85 474.8l10.6 10.6m-46-10.6 10.5 10.6m-17.7-10.6 10.7 10.6m-17.8-10.6L46 485.4m-17.8-10.6L39 485.4m0 49.6-10.6 10.7M46 535l-10.7 10.7M53.2 535l-10.7 10.7M60.2 535l-10.6 10.7M67.3 535l-10.6 10.7M74.4 535l-10.6 10.7M81.5 535 71 545.7M88.6 535 78 545.7M95.7 535 85 545.7m17.7-10.7L92 545.7m18-10.7-10.7 10.7M117 535l-10.6 10.7M88.6 535 78 545.7m46-10.7-10.6 10.7m17.7-10.7-10.6 10.7m17.7-10.7-10.6 10.7m17.7-10.7-10.6 10.7m0-10.7 10.6 10.7M127.6 535l10.6 10.7M120.5 535l10.6 10.7M113.4 535l10.6 10.7M106.3 535l10.6 10.7M99.2 535l10.7 10.7M92 535l10.7 10.7M85 535l10.6 10.7M78 535l10.6 10.7M70.9 535l10.6 10.7M63.8 535l10.6 10.7M56.7 535l10.6 10.7M85.1 535l10.6 10.7m-46-10.7 10.5 10.7M42.5 535l10.7 10.7M35.4 535l10.7 10.7M28.4 535 39 545.7"/></g><path fill="#fff" fill-rule="evenodd" stroke-width="1.3" d="M538.6 531.5c1.7 166.6 24.8 202 3.5 202s-31.9-92.1-31.9-205.5 14.2-205.5 35.5-205.5-9 31.9-7.1 209z" transform="matrix(-.32136 -.12684 -.20158 .20221 345.9 61.4)"/><path fill="#fff" fill-rule="evenodd" stroke-width="1.2" d="m545.7 779.5-60.3 17.7c56.7 60.3 120.5 85 138.2 74.4 17.7-10.6-31.9-35.4-78-92z" transform="matrix(-.19848 0 0 .17744 145.3 -13.4)"/><path fill="#fff" fill-rule="evenodd" stroke-width="1.3" d="m547.3 786.9-51 14.7c56.7 60.3 112.8 77.4 127.3 70 14.6-7.3-30.3-28-76.3-84.7z" transform="matrix(-.19334 0 0 .17062 142.8 -8.1)"/><path fill="none" stroke-width="1.8" d="M353.1 634.2c.2 1.3.8 7.1 1.3 9.4 0 3.2.3 5.6.6 8.1.8 2.2.7 4.2 3.1 5a9.9 9.9 0 0 0 5 4.4 27.7 27.7 0 0 0 6.3 3.8 11.6 11.6 0 0 0 7.5.6c2.2-1.5 3.8-3.1 5.6-4.4.4-2 .8-4.9 1.3-6.9a32.6 32.6 0 0 0-1.3 8.2c.2 3 1.3 4.4 2.5 6.8" transform="matrix(-.13978 0 0 .12414 123 20.4)"/><path fill="none" stroke-width="1.8" d="m389.4 681.7.6-.6c-1.5 1.5-.9.8 2.5-1.3 2.4-1.2 5-1.8 8.1-2.4h8.8c3.4 0 5.7.5 8.1 1.2 1.8 1.8 4.4 2.8 6.3 4.4a11 11 0 0 1 3.7 5c1.7 1.7 2.8 4.3 4.4 5.6.7 2.9 2.1 2.8 3.1 5-3 .2-5.3.6-6.9 2.5-2.6 1.3 2.2-1.3 3.2-2.5 2-.6 2.5-1.2 5.6-1.2 2.8-1 4.6.7 7.5 1.2 1.7 1 2.2 1.3 4.4 1.3" transform="matrix(-.13978 0 0 .12414 124.9 19.7)"/><path fill="none" stroke-width="1.8" d="M438.1 724.9c1.3 0 7.1 1.1 9.4 0 2.6-.7 4-2 5.6-4.4.8-1.7 0 3 0 5 .3 3.7 1.4 3.7 3.2 6.2 1.8 1.2 3.7 2.8 5.6 3.8a18 18 0 0 0 5.6 3.1c2 1 4.1 1.8 5.6 3.1 2.1 1.5 2 3.3 3.2 5.7-.3 3-.8 4.8-2.5 6.2-.8 2.1-2.4 4.2-3.8 5.6-1.5 3-3.3 4.3.6 5 2.2 1 3.4.2 5.7 0" transform="matrix(-.13978 0 0 .12414 127 18.2)"/><path fill="none" stroke-width="1.8" d="M480.6 771.7c1.6-.4 7-2.2 9.4-3.1h8.8c3.3.3 4 1.3 6.8 2.5 1.9 1.9 3.1 3.2 5.7 4.4 1.3 1.7 4 4.7 5 6.9a25.5 25.5 0 0 1 1.2 8c0 3.7-1 4.4-1.2 7.6a19.3 19.3 0 0 1-3.8 7.5c-.3.8-.5 1-1.2 1.2" transform="matrix(-.13978 0 0 .12414 127.4 17.8)"/><path fill="none" stroke-width="2.3" d="M538.1 818c.4 0 1.2 2 2.5 3.7 2.9 3 3.2 3.2 7.5 3.2 4-.2 3.5-1.6 6.3-2.5 1.3-2 2.9-3.6 4.4-6.3 1-1.6 1.9-4.1 3-5.6 1.2-1.9 2.6-3.3 3.8-5 1.2-.5 1.6-1 3.2-1.3-3.6.6-4 1.9-6.3 3.8a58.9 58.9 0 0 0-3.1 5.6c-.4 2.7-1.2 4.8-1.3 8.1 0 3.5 0 5.9 1.3 8.8 1.5 1.5 2.5 3.1 4.4 4.4a36.7 36.7 0 0 1 4.3 5l5.7 3.7c1.8 1.1 3.9 2.2 6.8 2.5 3.7-.3 4.8-1.3 7.5-2.5 2.4-1.7 4.2-2.8 6.3-4.4a21 21 0 0 0 4.4-5c3.3-.8 5.5-.5 7.5 1.3a16.5 16.5 0 0 1 4.3 4.4c1 .5 2.8 2 3.8 2.5" transform="matrix(-.09924 0 0 .09799 109.5 38.8)"/><path fill="none" stroke-width="1.9" d="M503.8 836.1c-.8.3-3.8 2.4-5 3.1-.8 2.7-2.1 4.1-2.5 7-.7 2.6-.7 5.6-.7 8.7.7 3.2 2 5.5 3.2 8 2 1.4 3 2.4 5.6 3.8 2.5.4 5 .7 8.1.7 2 .6 5.6.9 7.5 0 2.8-.4 4.9-1.5 6.9-2.5 2.3-1.3 3.7-2.6 6.2-3.8 1.3-1.8 3.2-3.5 5-5.6 1.6-2.1 2.7-3.3 3.8-5.6-1 2.6-2.3 5-3.1 7.5-1.5 2.6-2.3 3.6-2.5 6.8-1 2.6-.7 5.8-.7 8.8.3 2 .4 5.8 1.3 7.5v1.9" transform="matrix(-.12338 0 0 .12229 111.4 19.3)"/><path fill="none" stroke-width="1.2" d="M541.3 799.2v.7c0-1.7 0-.9-.7 2.5-1.1 1.4-4 1.8-6.2 1.2-2-1.7-2-3-5.6-3.7-3.6.2-5 1.2-7 2.5a9.5 9.5 0 0 0-5 4.3c-.7 2.1-1.1 3.6 1.3 4.4a15.2 15.2 0 0 0 7 2.5c2.8 0 4.9-.3 6.2 1.3 2 2 1.8 3.2 1.8 6.8.7 1.1.8 4.4 1.3 5.7a10.6 10.6 0 0 0 5.6 1.2c.7-2 1.4-5.4 1.9-7.5.5-2.2.6-5.4 1.9-6.9a16.6 16.6 0 0 1 4.3-4.4 8 8 0 0 1 3.8-2.4c-2.5 1.2-2.8 2.6-3.1 5.6 2.1 1 2.7 1.8 6.2 1.9 3.5-.3 4.3-1.2 5.6-3.2.3 3.2 1.3 4.2 2 7 1.2 1.8 1.7 3.7 3 6.2-.5 3-1.7 3.1-1.8 6.8-.8 2.5-.8 4.8-2.5 6.3-.8 1-1.4 1.1-3.2 1.2 3.6 0 5.8-.4 8.2-1.8 1.8-1.2 3.2-2.6 5-3.8a23.9 23.9 0 0 1 7.5-3.8c2.9 0 5-.3 6.2 1.3 1.8 1.3 3 3.5 3.8 6.3.5 3.2.8 5.4-.7 7.5-1 2.4-1.3 3.3 0 6.2 1.5 2 3.6 2.3 7 3.1 2.3-.1 4.6-.6 6.2-1.2" transform="matrix(-.19848 0 0 .17744 145.3 -13.4)"/><g fill-rule="evenodd" stroke-width="1.3" transform="matrix(-.19848 0 0 .17744 145.3 -13.4)"><path fill="#fff" d="M531.5 359.6c0-165.2 8-299.4 17.7-299.4 9.8 0 17.7 134.2 17.7 299.4h-35.4z" transform="matrix(1.4216 -.73423 .46161 .89375 -716.8 541)"/><path fill="#fff" d="M531.5 359.6c0-165.2 8-299.4 17.7-299.4 9.8 0 17.7 134.2 17.7 299.4" transform="matrix(1.1373 -.58739 .44532 .86221 -554.8 471.8)"/><path fill="#fff" d="M563.4 301.2c.2 18.9 0 40.2 0 60.2H535c0-20-.2-41.3 0-60.2h28.4z" transform="matrix(1.4216 -.73423 .45889 .88849 -716.7 541.4)"/><path fill="#fff" d="M559.8 304.7c.2 19 0 33.1 0 53.2h-21.2c0-20-.2-34.3 0-53.2h21.2z" transform="matrix(1.4216 -.73423 .45889 .88849 -716.7 541.4)"/><path fill="#fff" d="M542.1 311.8h14.2v39h-14.2zm0 0 14.2 39m-14.2 0 14.2-39m-14.2-198.4h14.2" transform="matrix(1.4216 -.73423 .45889 .88849 -716.7 541.4)"/><circle cx="545.7" cy="92.1" r="3.5" fill="#ef0000" transform="matrix(1.6046 .45375 -.36215 1.5787 -734.9 -170.8)"/></g><path fill="#fff" fill-rule="evenodd" stroke-width="1.3" d="M538.6 531.5c1.7 166.6 24.8 202 3.5 202s-31.9-92.1-31.9-205.5 14.2-205.5 35.5-205.5-9 31.9-7.1 209z" transform="matrix(.32136 -.12684 .20158 .20221 -181.5 60.8)"/><path fill="#fff" fill-rule="evenodd" stroke-width="1.2" d="m545.7 779.5-60.3 17.7c56.7 60.3 120.5 85 138.2 74.4 17.7-10.6-31.9-35.4-78-92z" transform="matrix(.19848 0 0 .17744 19.1 -14)"/><path fill="#fff" fill-rule="evenodd" stroke-width="1.3" d="m547.3 786.9-51 14.7c56.7 60.3 112.8 77.4 127.3 70 14.6-7.3-30.3-28-76.3-84.7z" transform="matrix(.19334 0 0 .17062 21.6 -8.8)"/><path fill="none" stroke-width="1.8" d="M353.1 634.2c.2 1.3.8 7.1 1.3 9.4 0 3.2.3 5.6.6 8.1.8 2.2.7 4.2 3.1 5a9.9 9.9 0 0 0 5 4.4 27.7 27.7 0 0 0 6.3 3.8 11.6 11.6 0 0 0 7.5.6c2.2-1.5 3.8-3.1 5.6-4.4.4-2 .8-4.9 1.3-6.9a32.6 32.6 0 0 0-1.3 8.2c.2 3 1.3 4.4 2.5 6.8" transform="matrix(.13978 0 0 .12414 41.4 19.7)"/><path fill="none" stroke-width="1.8" d="m389.4 681.7.6-.6c-1.5 1.5-.9.8 2.5-1.3 2.4-1.2 5-1.8 8.1-2.4h8.8c3.4 0 5.7.5 8.1 1.2 1.8 1.8 4.4 2.8 6.3 4.4a11 11 0 0 1 3.7 5c1.7 1.7 2.8 4.3 4.4 5.6.7 2.9 2.1 2.8 3.1 5-3 .2-5.3.6-6.9 2.5-2.6 1.3 2.2-1.3 3.2-2.5 2-.6 2.5-1.2 5.6-1.2 2.8-1 4.6.7 7.5 1.2 1.7 1 2.2 1.3 4.4 1.3" transform="matrix(.13978 0 0 .12414 39.5 19)"/><path fill="none" stroke-width="1.8" d="M438.1 724.9c1.3 0 7.1 1.1 9.4 0 2.6-.7 4-2 5.6-4.4.8-1.7 0 3 0 5 .3 3.7 1.4 3.7 3.2 6.2 1.8 1.2 3.7 2.8 5.6 3.8a18 18 0 0 0 5.6 3.1c2 1 4.1 1.8 5.6 3.1 2.1 1.5 2 3.3 3.2 5.7-.3 3-.8 4.8-2.5 6.2-.8 2.1-2.4 4.2-3.8 5.6-1.5 3-3.3 4.3.6 5 2.2 1 3.4.2 5.7 0" transform="matrix(.13978 0 0 .12414 37.4 17.6)"/><path fill="none" stroke-width="1.8" d="M480.6 771.7c1.6-.4 7-2.2 9.4-3.1h8.8c3.3.3 4 1.3 6.8 2.5 1.9 1.9 3.1 3.2 5.7 4.4 1.3 1.7 4 4.7 5 6.9a25.5 25.5 0 0 1 1.2 8c0 3.7-1 4.4-1.2 7.6a19.3 19.3 0 0 1-3.8 7.5c-.3.8-.5 1-1.2 1.2" transform="matrix(.13978 0 0 .12414 37 17.2)"/><path fill="none" stroke-width="2.3" d="M538.1 818c.4 0 1.2 2 2.5 3.7 2.9 3 3.2 3.2 7.5 3.2 4-.2 3.5-1.6 6.3-2.5 1.3-2 2.9-3.6 4.4-6.3 1-1.6 1.9-4.1 3-5.6 1.2-1.9 2.6-3.3 3.8-5 1.2-.5 1.6-1 3.2-1.3-3.6.6-4 1.9-6.3 3.8a58.9 58.9 0 0 0-3.1 5.6c-.4 2.7-1.2 4.8-1.3 8.1 0 3.5 0 5.9 1.3 8.8 1.5 1.5 2.5 3.1 4.4 4.4a36.7 36.7 0 0 1 4.3 5l5.7 3.7c1.8 1.1 3.9 2.2 6.8 2.5 3.7-.3 4.8-1.3 7.5-2.5 2.4-1.7 4.2-2.8 6.3-4.4a21 21 0 0 0 4.4-5c3.3-.8 5.5-.5 7.5 1.3a16.5 16.5 0 0 1 4.3 4.4c1 .5 2.8 2 3.8 2.5" transform="matrix(.09924 0 0 .09799 55 38.2)"/><path fill="none" stroke-width="1.9" d="M503.8 836.1c-.8.3-3.8 2.4-5 3.1-.8 2.7-2.1 4.1-2.5 7-.7 2.6-.7 5.6-.7 8.7.7 3.2 2 5.5 3.2 8 2 1.4 3 2.4 5.6 3.8 2.5.4 5 .7 8.1.7 2 .6 5.6.9 7.5 0 2.8-.4 4.9-1.5 6.9-2.5 2.3-1.3 3.7-2.6 6.2-3.8 1.3-1.8 3.2-3.5 5-5.6 1.6-2.1 2.7-3.3 3.8-5.6-1 2.6-2.3 5-3.1 7.5-1.5 2.6-2.3 3.6-2.5 6.8-1 2.6-.7 5.8-.7 8.8.3 2 .4 5.8 1.3 7.5v1.9" transform="matrix(.12338 0 0 .12229 53 18.6)"/><path fill="none" stroke-width="1.2" d="M541.3 799.2v.7c0-1.7 0-.9-.7 2.5-1.1 1.4-4 1.8-6.2 1.2-2-1.7-2-3-5.6-3.7-3.6.2-5 1.2-7 2.5a9.5 9.5 0 0 0-5 4.3c-.7 2.1-1.1 3.6 1.3 4.4a15.2 15.2 0 0 0 7 2.5c2.8 0 4.9-.3 6.2 1.3 2 2 1.8 3.2 1.8 6.8.7 1.1.8 4.4 1.3 5.7a10.6 10.6 0 0 0 5.6 1.2c.7-2 1.4-5.4 1.9-7.5.5-2.2.6-5.4 1.9-6.9a16.6 16.6 0 0 1 4.3-4.4 8 8 0 0 1 3.8-2.4c-2.5 1.2-2.8 2.6-3.1 5.6 2.1 1 2.7 1.8 6.2 1.9 3.5-.3 4.3-1.2 5.6-3.2.3 3.2 1.3 4.2 2 7 1.2 1.8 1.7 3.7 3 6.2-.5 3-1.7 3.1-1.8 6.8-.8 2.5-.8 4.8-2.5 6.3-.8 1-1.4 1.1-3.2 1.2 3.6 0 5.8-.4 8.2-1.8 1.8-1.2 3.2-2.6 5-3.8a23.9 23.9 0 0 1 7.5-3.8c2.9 0 5-.3 6.2 1.3 1.8 1.3 3 3.5 3.8 6.3.5 3.2.8 5.4-.7 7.5-1 2.4-1.3 3.3 0 6.2 1.5 2 3.6 2.3 7 3.1 2.3-.1 4.6-.6 6.2-1.2" transform="matrix(.19848 0 0 .17744 19.1 -14)"/><g fill-rule="evenodd" stroke-width="1.3" transform="matrix(.19848 0 0 .17744 19.1 -14)"><path fill="#fff" d="M531.5 359.6c0-165.2 8-299.4 17.7-299.4 9.8 0 17.7 134.2 17.7 299.4h-35.4z" transform="matrix(1.4216 -.73423 .46161 .89375 -716.8 541)"/><path fill="#fff" d="M531.5 359.6c0-165.2 8-299.4 17.7-299.4 9.8 0 17.7 134.2 17.7 299.4" transform="matrix(1.1373 -.58739 .44532 .86221 -554.8 471.8)"/><path fill="#fff" d="M563.4 301.2c.2 18.9 0 40.2 0 60.2H535c0-20-.2-41.3 0-60.2h28.4z" transform="matrix(1.4216 -.73423 .45889 .88849 -716.7 541.4)"/><path fill="#fff" d="M559.8 304.7c.2 19 0 33.1 0 53.2h-21.2c0-20-.2-34.3 0-53.2h21.2z" transform="matrix(1.4216 -.73423 .45889 .88849 -716.7 541.4)"/><path fill="#fff" d="M542.1 311.8h14.2v39h-14.2zm0 0 14.2 39m-14.2 0 14.2-39m-14.2-198.4h14.2" transform="matrix(1.4216 -.73423 .45889 .88849 -716.7 541.4)"/><circle cx="545.7" cy="92.1" r="3.5" fill="#ef0000" transform="matrix(1.6046 .45375 -.36215 1.5787 -734.9 -170.8)"/></g><g fill="#fff" fill-rule="evenodd" transform="matrix(.19848 0 0 .17744 19.1 -14)"><path stroke-width="1.3" d="M305.6 396.9c0 124 .5 170.7-5.6 177.1-5.8 6.9-167.1 0-167.1 35.4s132.8 71 172.7 71c53.2 0 79.7-35.5 79.7-106.4V397h-79.7z" transform="matrix(1.3333 0 0 1 -141.7 0)"/><path stroke-width="1.3" d="M265.8 396.9v17.7a321.1 321.1 0 0 0 106.2 0v-17.7a321.1 321.1 0 0 1-106.2 0z" transform="matrix(1 0 0 .99999 0 35.4)"/><path stroke-width="1.3" d="M265.8 396.9v17.7a321.1 321.1 0 0 0 106.2 0v-17.7a321.1 321.1 0 0 1-106.2 0z" transform="scale(1 .99999)"/><path stroke-width="1.3" d="M265.8 396.9v17.7a321.1 321.1 0 0 0 106.2 0v-17.7a321.1 321.1 0 0 1-106.2 0z" transform="matrix(1 0 0 .99999 0 17.7)"/><path stroke-width="1.3" d="M265.8 396.9v17.7a321.1 321.1 0 0 0 106.2 0v-17.7a321.1 321.1 0 0 1-106.2 0z" transform="matrix(1 0 0 .99999 0 53.1)"/><ellipse cx="256.9" cy="210.8" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 394.3)"/><ellipse cx="292.3" cy="246.3" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 391.1)"/><ellipse cx="327.8" cy="264" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 390.5)"/><ellipse cx="363.2" cy="264" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 390.5)"/><ellipse cx="398.6" cy="246.3" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 391.1)"/><ellipse cx="434.1" cy="210.8" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 394.3)"/><path stroke-width="1.3" d="M265.8 485.4 372 581.1m-95.6-95.7 95.7 85M290.5 489l81.6 70.9m-71-71 71 60.3M311.8 489l60.2 49.6M322.4 489l49.6 39m-39-39 39 28.3M343.8 489l28.4 21.2M354.3 489l17.8 14.2M365 489l7 7m-106.2 0L372 591.8m0-106.3L255 591.7m106.3-106.3-102.7 92.2m88.6-88.6-81.5 70.8m70.8-70.8-70.8 60.2M326 489l-60.2 49.6m49.6-49.6-49.6 39m39-39-39 28.3M294 489l-28.3 21.2m17.7-21.2-17.7 14.2m7-14.2-7 7m106.3 0L255 602.5m117-95.7L255 613m117-95.7L255 623.6m117-95.7L255 634.4m117-95.7L255 644.9m117-95.7L255 655.5m117-95.7L255 666.1m117-95.6L255 676.8m117-95.7-109.9 99.2M372 591.7l-99.2 88.6m95.6-74.4-78 70.9M365 620l-63.8 56.7m56.7-39-32 28.3m-60.1-159.4 102.7 92.1m-102.7-81.5 102.7 92.2M265.8 528l99.2 88.5m-99.2-78 99.2 88.7m-99.2-78 95.6 85m-99.2-77.9 95.7 85M262.2 567l88.6 78m-92.1-71 88.6 78M255 581l85 74.4m-85-63.8 81.5 70.9M255 602.3l71 63.7"/><path stroke-width="1.3" d="M265.8 396.9v17.7a321.1 321.1 0 0 0 106.2 0v-17.7a321.1 321.1 0 0 1-106.2 0z" transform="matrix(1 0 0 .99999 0 70.9)"/><path stroke-width="1.3" d="m255.1 613 63.8 56.7m-63.8-46 56.7 49.5m-56.7-39 49.6 42.6m-49.6-32 35.5 32M255 655.5l28.4 24.8M255 666.1l17.7 14.2"/><ellipse cx="256.9" cy="210.8" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 447.5)"/><ellipse cx="292.3" cy="246.3" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 444.3)"/><ellipse cx="327.8" cy="264" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 443.6)"/><ellipse cx="363.2" cy="264" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 443.6)"/><ellipse cx="398.6" cy="246.3" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 444.3)"/><ellipse cx="434.1" cy="210.8" stroke-width="4.4" rx="8.9" ry="26.6" transform="matrix(.54545 0 0 .14383 130.5 447.5)"/><path stroke-width="1.3" d="m113.4 652 127.5-74.4m-134.6 70.8 120.5-70.8m-124 63.7 109.8-63.7M99.2 634.3l95.7-56.7M92 627.2l88.6-49.6m-85 39 60.2-35.5M92 609.4l46-28.3m-46 17.7 32-17.7m0 74.4 120.4-70.9M134.7 659 248 591.7m-102.7 70.9 106.3-63.8m-95.7 67.3 88.6-53.1M170 666.1l78-46m-67.4 49.6 67.3-39m-56.7 42.5 53.2-31.9m-39 32 42.5-24.9m-28.3 28.4L248 659m-14 17.8 17.7-10.6"/><path stroke-width="1.3" d="M265.8 396.9v17.7a321.1 321.1 0 0 0 106.2 0v-17.7a321.1 321.1 0 0 1-106.2 0z" transform="matrix(0 1 -.99999 0 655.5 308.3)"/><path stroke-width="1.3" d="m49.6 623.6 42.5-35.4m-42.5 10.6 42.5 42.5"/><path stroke-width="1.3" d="m260.1 388 5.6 26.6c35.5 5.9 65.3 5.9 100.8 0l11.1-26.6c-35.4 5.9-82 5.9-117.5 0z" transform="matrix(0 .63333 -.8 0 423.8 416.3)"/><path stroke-width="1.3" d="M258.7 350.8v-17.9c-10.7.2-17.8-10.5-17.8-21.2l-35.4.1c0 10.6-7 21.3-17.7 21.3v17.7h70.9z" transform="matrix(1.4983 0 0 1 -15.6 53.1)"/><path stroke-width="1.4" d="M296 343.7h45.6V365H296zm3.9-21.3h37.9v21.3h-38zm0-21.2h37.9v21.2h-38zm3.8-28.4H334v28.4h-30.3zm-1.3-24.8h33v24.8h-33z"/><ellipse cx="237.4" cy="161.2" stroke-width="1.3" rx="42.5" ry="33.7" transform="matrix(1.0333 0 0 1 75.3 63.8)"/><path stroke-width="1.3" d="M258.7 159.4c0 9.3 10.6 24.8 10.6 24.8-7.7 6.2-20.2 10.7-31.9 10.7s-26.3-2.6-31.9-10.7c0 0 10.6-15.4 10.6-24.7s-10.6-21.3-10.6-21.3a54.6 54.6 0 0 1 32-10.6c11.6 0 24 4.5 31.8 10.6 0 0-10.6 12-10.6 21.3z" transform="matrix(1.0333 0 0 1 75.3 63.8)"/><path stroke-width="1.3" d="M251.6 159.4c0 9.3 10.6 28.4 10.6 28.4-7.7 6-13 7-24.8 7s-19.2 1.1-24.8-7c0 0 10.6-19 10.6-28.4s-10.6-24.8-10.6-24.8c7.7-6 13-7 24.8-7 11.7 0 17.1 1 24.8 7 0 0-10.6 15.5-10.6 24.8z" transform="matrix(1.0333 0 0 1 75.3 63.8)"/><circle cx="194.9" cy="166.5" r="10.6" stroke-width="1.3" transform="matrix(1.0333 0 0 1 75.3 60.2)"/><circle cx="194.9" cy="166.5" r="10.6" stroke-width="1.3" transform="matrix(1.0333 0 0 1 163.2 60.2)"/><circle cx="194.9" cy="166.5" r="10.6" stroke-width="1.3" transform="matrix(1.0333 0 0 1 119.3 60.2)"/><circle cx="194.9" cy="166.5" r="10.6" stroke-width="1.3" transform="matrix(1.0333 0 0 1 119.3 24.8)"/><circle cx="194.9" cy="166.5" r="10.6" stroke-width="1.3" transform="matrix(1.0702 0 0 1 80 226.8)"/><circle cx="194.9" cy="166.5" r="10.6" stroke-width="1.3" transform="matrix(1.0702 0 0 1 140.6 226.8)"/><path stroke-width="1.3" d="M212.6 311.8h49.6l-24.8 31.9-24.8-31.9z" transform="matrix(1.0702 0 0 1 64.8 53.1)"/><circle cx="194.9" cy="166.5" r="10.6" stroke-width="1.3" transform="matrix(1.427 0 0 1.3333 40.7 167.7)"/></g><g fill="#fff" fill-rule="evenodd" transform="matrix(.19848 0 0 .17744 18.8 -19.1)"><rect width="81.5" height="21.3" x="262.2" y="524.4" stroke-width="1.2" rx="4.3" ry="3.7"/><path stroke-width="1.2" d="M368.5 506.7c-9.8 0-17.7 8.3-17.7 18.5v16.1a18 18 0 0 0 17.7 18.5 18 18 0 0 0 17.7-18.5v-16a18 18 0 0 0-17.7-18.6zm0 7c-5.9 0-10.6 6.7-10.6 14.9v9.3c0 8.2 4.7 14.8 10.6 14.8 5.9 0 10.6-6.6 10.6-14.8v-9.3c0-8.2-4.7-14.8-10.6-14.8zm-92.1-3.5c-6 0-10.6 6.6-10.6 14.9v16.4c0 8.2 4.7 14.8 10.6 14.8 5.9 0 10.6-6.6 10.6-14.8V525c0-8.3-4.7-14.9-10.6-14.9zm0-7a18 18 0 0 0-17.7 18.5v23.2a18 18 0 0 0 17.7 18.5c9.8 0 17.7-8.3 17.7-18.5v-23.2a18 18 0 0 0-17.7-18.6z"/><path stroke-width="1.2" d="M248 517.3c-5.9 0-10.6 6.6-10.6 14.8v3.9c0 8.2 4.7 14.8 10.6 14.8 6 0 10.7-6.6 10.7-14.8v-3.9c0-8.2-4.8-14.8-10.7-14.8zm0-7a18 18 0 0 0-17.7 18.5v10.6a18 18 0 0 0 17.7 18.5c9.8 0 17.8-8.3 17.8-18.5v-10.6a18 18 0 0 0-17.8-18.6z"/><path stroke-width=".9" d="M478.4 237.4c-6 0-10.7 6.6-10.7 14.8v16.5c0 8.1 4.8 14.8 10.7 14.8 5.8 0 10.6-6.6 10.6-14.9v-16.4c0-8.2-4.8-14.8-10.6-14.8zm0-7a18 18 0 0 0-17.8 18.4V272a18 18 0 0 0 17.7 18.6c9.9 0 17.8-8.3 17.8-18.6v-23.2a18 18 0 0 0-17.7-18.5z" transform="matrix(1.8 0 0 1.1176 -655.5 242.2)"/><path stroke-width=".9" d="M478.4 237.4c-6 0-10.7 6.6-10.7 14.8v16.5c0 8.1 4.8 14.8 10.7 14.8 5.8 0 10.6-6.6 10.6-14.9v-16.4c0-8.2-4.8-14.8-10.6-14.8zm0-7a18 18 0 0 0-17.8 18.4V272a18 18 0 0 0 17.7 18.6c9.9 0 17.8-8.3 17.8-18.6v-23.2a18 18 0 0 0-17.7-18.5z" transform="matrix(1.8 0 0 1.1176 -425.2 245.7)"/><rect width="42.5" height="21.3" x="375.6" y="524.4" stroke-width="1.2" rx="2.3" ry="3.7"/><rect width="24.8" height="28.4" x="336.6" y="520.9" stroke-width="1.2" rx="1.3" ry="4.9"/><rect width="24.8" height="28.4" x="219.7" y="520.9" stroke-width="1.2" rx="1.3" ry="4.9"/><rect width="49.6" height="35.4" x="141.7" y="517.3" stroke-width="1.2" rx="2.6" ry="6.1"/><rect width="46.1" height="35.4" x="450" y="520.9" stroke-width="1.2" rx="2.5" ry="6.1"/></g></g></g></symbol></svg>
                        <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 font-semibold text-xs px-3 py-1 rounded-full"><svg class="oman-flag inline-block shrink-0 rounded-sm" width="18" height="13.5" viewBox="0 0 640 480" aria-hidden="true" focusable="false"><use href="#cf-oman-flag"/></svg> <?= htmlspecialchars(t('landing.hero_badge_loc')) ?></span>
                        <span class="font-medium text-gray-700"><?= htmlspecialchars(t('landing.hero_badge_copy')) ?></span>
                    </div>

                    <!-- Headline -->
                    <h1 class="hero-h1 text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight text-gray-900 mb-6">
                        <?= htmlspecialchars(t('landing.hero_h1_line1')) ?>
                        <span class="text-blue-600 block"><?= htmlspecialchars(t('landing.hero_h1_line2')) ?></span>
                        <span class="hero-h1-line3 text-gray-500 text-3xl sm:text-4xl lg:text-5xl"><?= htmlspecialchars(t('landing.hero_h1_line3')) ?></span>
                    </h1>

                    <!-- Subheadline -->
                    <p class="hero-sub text-lg lg:text-xl text-gray-600 mb-8 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        <?= htmlspecialchars(t('landing.hero_subhead')) ?>
                        <strong class="text-gray-900"><?= htmlspecialchars(t('landing.hero_price_tag')) ?></strong>
                    </p>

                    <!-- CTA Buttons -->
                    <div class="hero-ctas flex flex-col sm:flex-row gap-4 justify-center lg:justify-start mb-10">
                        <a href="<?php echo getBasePath(); ?>company/register-otp.php" class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:-translate-y-0.5 text-lg">
                            <?= htmlspecialchars(t('landing.cta_start_free')) ?>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                        <?php
                            $cardifyDemoMsg = (currentLocale() === 'ar')
                                ? 'مرحباً، أرغب بعرض توضيحي لكارديفاي لشركتي'
                                : 'Hi, I would like a demo of Cardify for my company';
                            $cardifyDemoUrl = 'https://api.whatsapp.com/send?phone=96898899100&text=' . rawurlencode($cardifyDemoMsg);
                        ?>
                        <a href="<?= htmlspecialchars($cardifyDemoUrl) ?>" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-green-500 hover:bg-green-600 text-white font-semibold rounded-xl transition-all text-lg">
                            <i class="fa-brands fa-whatsapp"></i>
                            <?= htmlspecialchars(t('landing.cta_request_demo')) ?>
                        </a>
                    </div>

                    <!-- Trust Badges -->
                    <div class="hero-trust flex flex-wrap items-center justify-center lg:justify-start gap-3 text-sm">
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-green-50 text-green-700 rounded-full">
                            <i class="fa-solid fa-circle-check"></i>
                            <span><?= htmlspecialchars(t('landing.trust_free_design')) ?></span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-full">
                            <i class="fa-solid fa-print"></i>
                            <span><?= htmlspecialchars(t('landing.trust_printed_by')) ?></span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full">
                            <i class="fa-solid fa-users"></i>
                            <span><?= htmlspecialchars(t('landing.trust_bulk_csv')) ?></span>
                        </div>
                        <div class="flex items-center gap-2 px-3 py-1.5 bg-amber-50 text-amber-700 rounded-full">
                            <i class="fa-solid fa-language"></i>
                            <span><?= htmlspecialchars(t('landing.trust_bilingual')) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Right Content - Card Mockups -->
                <?php
                /*
                 * Hero product object. Replaces three floating cards that showed
                 * John Doe / TechCorp, Sarah Miller / creativeco.com and Alex Kim /
                 * StartupXYZ: invented people at invented companies, on an
                 * Arabic-first Omani product whose hero contained no Arabic at all.
                 * DESIGN.md already called for exactly this ("one hero object",
                 * "placeholder names -> real Omani names", "busy floating 3-card
                 * cluster -> one wallet pass").
                 *
                 * This is a SAMPLE card, labelled as one. It is not a customer and
                 * does not claim to be. Its job is to demonstrate the single thing
                 * that differentiates the product: Arabic and English on one card,
                 * each reading in its own direction.
                 *
                 * The markup below IS the product. Three.js, when it runs, lifts
                 * this same content into a rotatable card and hides the flat copy
                 * from sight only, never from assistive tech or crawlers. With no
                 * WebGL, reduced motion, or a failed asset, this is what stays, and
                 * it is complete on its own. It is no longer hidden on mobile.
                 */
                ?>
                <div class="hero-product lg:col-span-6 relative mt-12 lg:mt-0">
                    <div class="relative mx-auto w-full max-w-sm lg:max-w-md lg:h-[500px] flex flex-col items-center justify-center gap-5">

                        <div id="cardify-hero-card" class="cf-card w-full" role="img"
                             aria-label="<?= htmlspecialchars(t('herocard.alt')) ?>">
                            <div class="cf-card__inner">

                                <div class="cf-card__face cf-card__front" style="background:linear-gradient(150deg,#067a98,#053b49)">
                                    <div class="flex items-start justify-between px-5 pt-4 pb-2">
                                        <p class="text-[11px] font-bold tracking-[0.16em] uppercase" style="color:#ffffff">Cardify</p>
                                        <span class="text-[10px] font-bold tracking-widest text-white rounded-full px-2 py-0.5" style="background:rgba(255,255,255,.22)">
                                            <?= htmlspecialchars(t('herocard.sample')) ?>
                                        </span>
                                    </div>
                                    <div class="px-5 pb-3 grid grid-cols-2 gap-3 items-start">
                                        <div dir="ltr" class="text-left">
                                            <p class="font-display font-bold text-white text-base sm:text-lg leading-tight">Aisha Al Balushi</p>
                                            <p class="text-xs mt-1" style="color:rgba(255,255,255,.92)">Operations Manager</p>
                                        </div>
                                        <div dir="rtl" class="text-right">
                                            <p class="font-display font-bold text-white text-base sm:text-lg leading-tight">عائشة البلوشي</p>
                                            <p class="text-xs mt-1" style="color:rgba(255,255,255,.92)">مديرة العمليات</p>
                                        </div>
                                    </div>
                                    <div class="px-5 pb-3 flex items-end justify-between gap-3">
                                        <div class="space-y-1 text-xs" dir="ltr" style="color:rgba(255,255,255,.92)">
                                            <p>aisha@example.om</p>
                                            <p>+968 2200 0000</p>
                                        </div>
                                        <div class="shrink-0 w-12 h-12 rounded-lg flex items-center justify-center" aria-hidden="true" style="background:rgba(255,255,255,.96)">
                                            <i class="fa-solid fa-qrcode text-2xl" style="color:#053b49"></i>
                                        </div>
                                    </div>
                                    <div class="px-5 py-2 flex items-center gap-2" style="background:rgba(0,0,0,.18)">
                                        <i class="fa-brands fa-apple" aria-hidden="true" style="color:rgba(255,255,255,.95)"></i>
                                        <i class="fa-brands fa-google" aria-hidden="true" style="color:rgba(255,255,255,.95)"></i>
                                        <p class="text-xs" style="color:rgba(255,255,255,.92)"><?= htmlspecialchars(t('herocard.wallet')) ?></p>
                                    </div>
                                </div>

                                <div class="cf-card__face cf-card__back bg-white border border-gray-200">
                                    <div class="h-full flex flex-col items-center justify-center gap-4 p-8 text-center">
                                        <div class="w-24 h-24 rounded-xl bg-gray-900 flex items-center justify-center" aria-hidden="true">
                                            <i class="fa-solid fa-qrcode text-5xl text-white"></i>
                                        </div>
                                        <p class="text-gray-900 font-display font-bold"><?= htmlspecialchars(t('herocard.scan')) ?></p>
                                        <p class="text-gray-500 text-xs max-w-[15rem]"><?= htmlspecialchars(t('herocard.scan_hint')) ?></p>
                                    </div>
                                </div>

                            </div>
                        </div>

                        <button type="button" id="cardify-hero-flip"
                                class="inline-flex items-center gap-2 rounded-full border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                                style="outline-color:#009bc1" aria-pressed="false">
                            <i class="fa-solid fa-rotate" aria-hidden="true"></i>
                            <span><?= htmlspecialchars(t('herocard.flip')) ?></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== TRUST SIGNALS ========== -->
        <?php // Hero card: component-scoped, homepage only. Not site-wide, it exists on one screen. ?>
    <link rel="stylesheet" href="<?= htmlspecialchars(getBasePath()) ?>assets/css/cardify-hero-card.css">
    <script defer src="<?= htmlspecialchars(getBasePath()) ?>assets/js/cardify-hero-card.js"></script>

<?php @include __DIR__ . '/views/partials/customer_row.php'; ?>

    <?php @include __DIR__ . '/views/partials/proof_stats.php'; ?>

    <?php @include __DIR__ . '/views/partials/trust_logo_strip.php'; ?>

    <!-- ========== HOW IT WORKS (Techwind Style) ========== -->
    <section id="how-it-works" class="py-16 lg:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="max-w-2xl mx-auto text-center mb-16">
                <p class="text-sm font-semibold uppercase tracking-wider text-green-600 mb-3"><?= htmlspecialchars(t('landing.how_kicker')) ?></p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">
                    <?= htmlspecialchars(t('landing.how_headline')) ?>
                </h2>
                <p class="text-lg text-gray-600">
                    <?= htmlspecialchars(t('landing.how_subhead')) ?>
                </p>
            </div>

            <!-- Steps -->
            <div class="grid md:grid-cols-3 gap-8 lg:gap-12">
                <!-- Step 1 -->
                <div class="relative text-center group">
                    <div class="w-20 h-20 rounded-full bg-blue-600 text-white text-3xl font-bold flex items-center justify-center mx-auto mb-6 shadow-xl shadow-blue-600/30 group-hover:scale-110 transition-transform">
                        1
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.how_step1_title')) ?></h3>
                    <p class="text-gray-600"><?= htmlspecialchars(t('landing.how_step1_body')) ?></p>

                    <!-- Arrow (hidden on mobile) -->
                    <div class="hidden md:block absolute top-10 left-full w-full h-0.5 bg-gray-200 -translate-x-1/2">
                        <i class="fa-solid fa-chevron-right absolute right-0 -top-2 text-gray-300"></i>
                    </div>
                </div>

                <!-- Step 2 -->
                <div class="relative text-center group">
                    <div class="w-20 h-20 rounded-full bg-amber-500 text-white text-3xl font-bold flex items-center justify-center mx-auto mb-6 shadow-xl shadow-amber-500/30 group-hover:scale-110 transition-transform">
                        2
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.how_step2_title')) ?></h3>
                    <p class="text-gray-600"><?= htmlspecialchars(t('landing.how_step2_body')) ?></p>

                    <!-- Arrow (hidden on mobile) -->
                    <div class="hidden md:block absolute top-10 left-full w-full h-0.5 bg-gray-200 -translate-x-1/2">
                        <i class="fa-solid fa-chevron-right absolute right-0 -top-2 text-gray-300"></i>
                    </div>
                </div>

                <!-- Step 3 -->
                <div class="text-center group">
                    <div class="w-20 h-20 rounded-full bg-green-500 text-white text-3xl font-bold flex items-center justify-center mx-auto mb-6 shadow-xl shadow-green-500/30 group-hover:scale-110 transition-transform">
                        3
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.how_step3_title')) ?></h3>
                    <p class="text-gray-600"><?= htmlspecialchars(t('landing.how_step3_body')) ?></p>
                </div>
            </div>

            <!-- CTA -->
            <div class="text-center mt-16">
                <a href="<?php echo getBasePath(); ?>company/register-otp.php" class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all text-lg">
                    <?= htmlspecialchars(t('landing.how_cta')) ?>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- ========== FEATURES SECTION (Flowbite Style) ========== -->
    <section id="features" class="py-16 lg:py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="mx-auto max-w-2xl text-center mb-16">
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('landing.feat_kicker')) ?></p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">
                    <?= htmlspecialchars(t('landing.feat_headline')) ?>
                </h2>
                <p class="text-lg leading-relaxed text-gray-600">
                    <?= htmlspecialchars(t('landing.feat_subhead')) ?>
                </p>
            </div>

            <!-- Features Grid -->
            <div class="landing-features-grid grid md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature 1 - Design Once -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-blue-100 flex items-center justify-center mb-6 group-hover:bg-blue-600 transition-colors">
                        <i class="fa-solid fa-wand-magic-sparkles text-2xl text-blue-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_design_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_design_body')) ?>
                    </p>
                </div>

                <!-- Feature 2 - Print Integration -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="absolute -top-3 -right-3 px-2 py-1 bg-green-500 text-white text-xs font-bold rounded-full"><?= htmlspecialchars(t('landing.feat_badge_new')) ?></div>
                    <div class="w-14 h-14 rounded-xl bg-green-100 flex items-center justify-center mb-6 group-hover:bg-green-600 transition-colors">
                        <i class="fa-solid fa-print text-2xl text-green-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_print_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_print_body')) ?>
                    </p>
                </div>

                <!-- Feature 3 - Bilingual -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-amber-100 flex items-center justify-center mb-6 group-hover:bg-amber-500 transition-colors">
                        <i class="fa-solid fa-language text-2xl text-amber-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_lang_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_lang_body')) ?>
                    </p>
                </div>

                <!-- Feature 4 - Team Management -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-purple-100 flex items-center justify-center mb-6 group-hover:bg-purple-600 transition-colors">
                        <i class="fa-solid fa-users text-2xl text-purple-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_team_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_team_body')) ?>
                    </p>
                </div>

                <!-- Feature 5 - QR Tracking -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-pink-100 flex items-center justify-center mb-6 group-hover:bg-pink-600 transition-colors">
                        <i class="fa-solid fa-qrcode text-2xl text-pink-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_qr_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_qr_body')) ?>
                    </p>
                </div>

                <!-- Feature 6 - Self Service Portal -->
                <div class="relative bg-white rounded-2xl p-8 shadow-lg shadow-gray-200/60 border border-gray-100 hover:shadow-xl transition-shadow group">
                    <div class="w-14 h-14 rounded-xl bg-red-100 flex items-center justify-center mb-6 group-hover:bg-red-600 transition-colors">
                        <i class="fa-solid fa-door-open text-2xl text-red-600 group-hover:text-white transition-colors"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('landing.feat_portal_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('landing.feat_portal_body')) ?>
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- ========== PRICING SECTION ========== -->
    <section id="pricing" class="py-16 lg:py-24 bg-gradient-to-b from-gray-50 to-white px-4">
        <div class="max-w-6xl mx-auto">
            <!-- Section Header -->
            <div class="text-center mb-12">
                <span class="inline-flex items-center gap-2 py-1 px-3 mb-4 text-xs font-semibold text-blue-700 bg-blue-100 rounded-full uppercase tracking-wide">
                    <i class="fa-solid fa-tag"></i>
                    <?= htmlspecialchars(t('pricing.home_kicker')) ?>
                </span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4"><?= htmlspecialchars(t('pricing.home_headline')) ?></h2>
                <p class="text-lg text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.home_subhead')) ?></p>
            </div>

            <!-- Platform (free forever) card -->
            <article class="relative bg-white rounded-3xl px-8 pt-12 pb-8 lg:px-10 lg:pt-14 lg:pb-10 ring-1 ring-gray-200/70 shadow-xl mb-10">
                <span class="absolute -top-3 left-8 px-4 py-1 bg-green-600 text-white text-xs font-bold rounded-full uppercase tracking-wider whitespace-nowrap shadow-md z-10">
                    <?= htmlspecialchars(t('pricing.platform_badge')) ?>
                </span>
                <div class="grid lg:grid-cols-2 gap-8 items-center">
                    <div>
                        <h3 class="text-2xl font-bold text-gray-700 mb-2"><?= htmlspecialchars(t('pricing.platform_name')) ?></h3>
                        <div class="flex items-baseline gap-2 mb-3">
                            <span class="text-5xl lg:text-6xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.platform_price')) ?></span>
                        </div>
                        <p class="text-gray-500 mb-6"><?= htmlspecialchars(t('pricing.platform_sub')) ?></p>
                        <a href="<?= getBasePath() ?>company/register-otp.php" class="inline-flex items-center justify-center gap-2 px-7 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/30 transition hover:-translate-y-0.5">
                            <?= htmlspecialchars(t('pricing.platform_cta')) ?>
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                    <ul class="grid sm:grid-cols-2 gap-x-4 gap-y-1">
                        <?php for ($i = 1; $i <= 8; $i++): ?>
                            <li class="flex items-start gap-2 py-1.5 text-gray-700">
                                <i class="fa-solid fa-check text-green-600 mt-1 flex-shrink-0"></i>
                                <span><?= htmlspecialchars(t('pricing.platform_f' . $i)) ?></span>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </div>
            </article>

            <!-- Printed product catalogue -->
            <div class="text-center mb-8">
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-2"><?= htmlspecialchars(t('pricing.products_eyebrow')) ?></p>
                <h3 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('pricing.products_h')) ?></h3>
                <p class="text-base text-gray-600 max-w-2xl mx-auto"><?= htmlspecialchars(t('pricing.products_b')) ?></p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php
                $homeProducts = [
                    'standard' => ['accent' => 'blue',    'icon' => 'fa-id-card'],
                    'premium'  => ['accent' => 'purple',  'icon' => 'fa-gem'],
                    'luxury'   => ['accent' => 'amber',   'icon' => 'fa-award'],
                    'nfc'      => ['accent' => 'emerald', 'icon' => 'fa-wifi'],
                ];
                foreach ($homeProducts as $key => $meta):
                ?>
                    <article class="flex flex-col bg-white rounded-2xl p-6 ring-1 ring-gray-200/70 hover:-translate-y-1 hover:shadow-xl transition-all">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4 bg-<?= $meta['accent'] ?>-100">
                            <i class="fa-solid <?= $meta['icon'] ?> text-xl text-<?= $meta['accent'] ?>-600"></i>
                        </div>
                        <h4 class="text-lg font-bold text-gray-900 mb-1"><?= htmlspecialchars(t('pricing.product_' . $key . '_name')) ?></h4>
                        <p class="text-sm text-gray-500 mb-4 leading-relaxed flex-1"><?= htmlspecialchars(t('pricing.product_' . $key . '_spec')) ?></p>
                        <div class="mb-4">
                            <span class="text-2xl font-extrabold text-gray-900"><?= htmlspecialchars(t('pricing.product_' . $key . '_price')) ?></span>
                            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars(t('pricing.product_' . $key . '_unit')) ?></p>
                        </div>
                        <a href="<?= getBasePath() ?>company/register-otp.php" class="text-blue-600 hover:text-blue-700 font-semibold text-sm inline-flex items-center gap-1">
                            <?= htmlspecialchars(t('pricing.product_cta')) ?>
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>

            <p class="text-center text-sm text-gray-500 mt-10">
                <i class="fa-solid fa-shield-halved text-green-600 mr-1"></i>
                <?= htmlspecialchars(t('pricing.home_footnote')) ?>
            </p>
        </div>
    </section>

    <?php /* ========== WHY CARDIFY (checkable product facts) ==========
             r28 item 20-23: this section used to be four fabricated customer testimonials.
             r21 removed the invented names but left the attribution shell in place, so
             production kept shipping quote marks, quote-element semantics, the deleted
             people's initials and the raw i18n keys for author and role as visible text.
             All attribution markup is gone for good: these are product facts, not quotes.
             Do NOT reintroduce a quote or an attributed card without a named, contactable,
             consenting customer on file. */ ?>
    <section id="why-cardify" class="py-16 lg:py-24 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <!-- Section Header -->
            <div class="max-w-2xl mx-auto text-center mb-16">
                <p class="text-sm font-semibold uppercase tracking-wider text-blue-600 mb-3"><?= htmlspecialchars(t('testimonials.kicker')) ?></p>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-gray-900 mb-4">
                    <?= htmlspecialchars(t('testimonials.headline')) ?>
                </h2>
                <p class="text-lg text-gray-600">
                    <?= htmlspecialchars(t('testimonials.subhead')) ?>
                </p>
                <p class="text-sm text-gray-500 mt-3">
                    <?= htmlspecialchars(t('landing.about_entity')) ?>
                </p>
            </div>

            <!-- Fact grid -->
            <div class="grid lg:grid-cols-2 gap-8">
                <?php foreach ([
                    ['n' => 1, 'icon' => 'fa-language'],
                    ['n' => 2, 'icon' => 'fa-wallet'],
                    ['n' => 3, 'icon' => 'fa-print'],
                    ['n' => 4, 'icon' => 'fa-users'],
                ] as $fact): $k = 't' . $fact['n']; ?>
                <div class="bg-white rounded-2xl p-8 shadow-sm ring-1 ring-gray-200/70 hover:ring-blue-200 hover:shadow-lg transition-all">
                    <div class="mb-5 text-blue-600 text-2xl leading-none"><i class="fa-solid <?= htmlspecialchars($fact['icon']) ?>" aria-hidden="true"></i></div>
                    <h3 class="text-lg font-semibold text-gray-900 mb-4"><?= htmlspecialchars(t('testimonials.' . $k . '_title')) ?></h3>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('testimonials.' . $k . '_quote')) ?>
                    </p>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="text-center mt-10">
                <a href="<?= htmlspecialchars(ArTwins::navLink('companies', getBasePath(), $_homeIsAr)) ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-semibold">
                    <?= htmlspecialchars(t('testimonials.directory_cta')) ?>
                    <i class="fa-solid fa-arrow-right rtl:fa-rotate-180" aria-hidden="true"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- ========== FROM THE BLOG (SEO internal linking) ========== -->
    <?php if (!empty($latestPosts)): ?>
    <section class="py-16 lg:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-end justify-between mb-10 flex-wrap gap-4">
                <div>
                    <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-2"><?= htmlspecialchars(t('landing.blog_heading')) ?></h2>
                    <p class="text-lg text-gray-600"><?= htmlspecialchars(t('landing.blog_sub')) ?></p>
                </div>
                <a href="<?= getBasePath() ?>blog" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-semibold">
                    <?= htmlspecialchars(t('landing.blog_view_all')) ?>
                    <i class="fa-solid fa-arrow-right text-sm"></i>
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <?php foreach ($latestPosts as $post): ?>
                <?php
                    $postUrl = getBasePath() . 'blog/' . $post['slug'];
                    // llm75-1: date('M j, Y') prints 'Apr 19, 2026' in every locale, so
                    // a card that survives BilingualRecord would still carry an English
                    // month on an Arabic page. I18n::formatDate is the estate's one
                    // locale-aware formatter and already renders Arabic month + digits.
                    $postDate = I18n::formatDate(strtotime($post['published_at']));
                    $excerpt = $post['excerpt'] ?? '';
                    if (strlen($excerpt) > 140) $excerpt = substr($excerpt, 0, 140) . '…';
                    $img = !empty($post['featured_image']) ? htmlspecialchars($post['featured_image']) : 'assets/images/cardify-og.png';
                ?>
                <article class="group bg-white border border-gray-200 rounded-2xl overflow-hidden hover:shadow-xl transition-shadow">
                    <a href="<?= htmlspecialchars($postUrl) ?>" class="block aspect-video bg-gray-100 overflow-hidden">
                        <img src="<?= getBasePath() . $img ?>" alt="<?= htmlspecialchars($post['title']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition-transform" width="1200" height="675" loading="lazy" decoding="async">
                    </a>
                    <div class="p-6">
                        <time class="text-sm text-gray-500"><?= $postDate ?></time>
                        <h3 class="mt-2 text-xl font-bold text-gray-900 leading-snug">
                            <a href="<?= htmlspecialchars($postUrl) ?>" class="hover:text-blue-700 transition-colors"><?= htmlspecialchars($post['title']) ?></a>
                        </h3>
                        <?php if ($excerpt): ?>
                        <p class="mt-3 text-gray-600 text-sm leading-relaxed"><?= htmlspecialchars($excerpt) ?></p>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($postUrl) ?>" class="mt-4 inline-flex items-center gap-1 text-blue-600 hover:text-blue-700 text-sm font-semibold">
                            <?= htmlspecialchars(t('landing.blog_read_more')) ?>
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ========== FREE TOOLS & DIRECTORIES (SEO HUB) ========== -->
    <?php
        // r6-51: published counts are derived at render time, never hardcoded.
        // The directory count and the logo count are DIFFERENT populations:
        // om_companies rows vs rows whose logo_status is indexed/verified.
        // If either query fails we drop the number from the sentence rather than ship a stale one.
        $resCompaniesCount = null;
        $resLogosCount = null;
        try {
            if (isset($db) && $db->isConnected()) {
                $r = $db->fetchOne("SELECT COUNT(*) c FROM om_companies");
                if ($r && isset($r['c'])) $resCompaniesCount = (int) $r['c'];
                $r = $db->fetchOne("SELECT COUNT(*) c FROM om_companies WHERE logo_status IN ('indexed','verified')");
                if ($r && isset($r['c'])) $resLogosCount = (int) $r['c'];
            }
        } catch (Throwable $e) { /* leave both null: render the count-free copy */ }
        $resSubhead = $resCompaniesCount !== null
            ? t('landing.res_subhead', ['companies' => number_format($resCompaniesCount)])
            : t('landing.res_subhead_nc');
        $resLogosSub = $resLogosCount !== null
            ? t('landing.res_logos_sub', ['logos' => number_format($resLogosCount)])
            : t('landing.res_logos_sub_nc');
        // r20-26: this sentence carried a hardcoded 2,414 while the hero on the
        // same page said 2,502. One page, two sizes of one directory. Same rule
        // as the two counts above: derive it, or drop the number from the copy.
        $resObiSub = $resCompaniesCount !== null
            ? t('landing.res_obi_sub', ['companies' => number_format($resCompaniesCount)])
            : t('landing.res_obi_sub_nc');
    ?>
    <section id="resources" class="py-16 lg:py-24 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <span class="inline-block text-sm font-semibold text-blue-600 uppercase tracking-wider mb-3"><?= htmlspecialchars(t('landing.res_kicker')) ?></span>
                <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 mb-4">
                    <?= htmlspecialchars(t('landing.res_headline')) ?>
                </h2>
                <p class="text-lg text-gray-600 max-w-3xl mx-auto">
                    <?= htmlspecialchars($resSubhead) ?>
                </p>
            </div>

            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8 mb-12">
                <!-- Free Tools -->
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl p-8 border border-blue-100">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-12 h-12 rounded-xl bg-blue-600 text-white flex items-center justify-center">
                            <i class="fa-solid fa-toolbox text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('landing.res_tools_title')) ?></h3>
                    </div>
                    <p class="text-gray-600 mb-6"><?= htmlspecialchars(t('landing.res_tools_sub')) ?></p>
                    <ul class="space-y-3 mb-6">
                        <?php foreach ([
                            ['href' => '/tools/vcard-qr-generator', 'icon' => 'fa-solid fa-qrcode', 'k' => 'tool_vcard'],
                            ['href' => '/tools/email-signature-generator', 'icon' => 'fa-solid fa-envelope', 'k' => 'tool_sig'],
                            ['href' => '/tools/whatsapp-qr-generator', 'icon' => 'fa-brands fa-whatsapp', 'k' => 'tool_wa'],
                            ['href' => '/tools/nfc-business-card-guide', 'icon' => 'fa-solid fa-wifi', 'k' => 'tool_nfc'],
                        ] as $tl): ?>
                        <li>
                            <a href="<?= htmlspecialchars($tl['href']) ?>" class="flex items-start gap-3 p-3 rounded-lg hover:bg-white transition group">
                                <i class="<?= htmlspecialchars($tl['icon']) ?> text-blue-600 mt-1"></i>
                                <div>
                                    <div class="font-semibold text-gray-900 group-hover:text-blue-700"><?= htmlspecialchars(t('landing.res_' . $tl['k'] . '_title')) ?></div>
                                    <div class="text-sm text-gray-500"><?= htmlspecialchars(t('landing.res_' . $tl['k'] . '_sub')) ?></div>
                                </div>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?= htmlspecialchars(ArTwins::navLink('tools', getBasePath(), $_homeIsAr)) ?>" class="inline-flex items-center gap-2 text-blue-700 font-semibold hover:text-blue-800">
                        <?= htmlspecialchars(t('landing.res_tools_cta')) ?>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>

                <!-- Oman Business Directory -->
                <div class="bg-gradient-to-br from-emerald-50 to-teal-50 rounded-2xl p-8 border border-emerald-100">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-12 h-12 rounded-xl bg-emerald-600 text-white flex items-center justify-center">
                            <i class="fa-solid fa-building-columns text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('landing.res_obi_title')) ?></h3>
                    </div>
                    <p class="text-gray-600 mb-6"><?= htmlspecialchars($resObiSub) ?></p>
                    <div class="grid grid-cols-2 gap-2 mb-6">
                        <?php foreach ([
                            'oil_gas'      => '/companies/sector/oil-gas',
                            'construction' => '/companies/sector/construction',
                            'finance'      => '/companies/sector/finance',
                            'trading'      => '/companies/sector/trading',
                            'manufacturing'=> '/companies/sector/manufacturing',
                            'hospitality'  => '/companies/sector/hospitality-tourism',
                            'muscat'       => '/companies/wilayat/muscat',
                            'dhofar'       => '/companies/wilayat/dhofar',
                        ] as $k => $href): ?>
                            <a href="<?= htmlspecialchars($href) ?>" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-emerald-100 hover:text-emerald-700 transition font-medium border border-gray-100"><?= htmlspecialchars(t('landing.res_obi_' . $k)) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="<?= htmlspecialchars(ArTwins::navLink('oman-business-index', getBasePath(), $_homeIsAr)) ?>" class="inline-flex items-center gap-2 text-emerald-700 font-semibold hover:text-emerald-800">
                        <?= htmlspecialchars(t('landing.res_obi_cta')) ?>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>

                <!-- Omani Logo Library -->
                <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-2xl p-8 border border-amber-100">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-12 h-12 rounded-xl bg-amber-500 text-white flex items-center justify-center">
                            <i class="fa-solid fa-bezier-curve text-xl"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('landing.res_logos_title')) ?></h3>
                    </div>
                    <p class="text-gray-600 mb-6"><?= htmlspecialchars($resLogosSub) ?></p>
                    <div class="grid grid-cols-2 gap-2 mb-6">
                        <?php foreach ([
                            ['k' => 'pin1', 'href' => '/companies/bhd-group'],
                            ['k' => 'pin2', 'href' => '/companies/bank-muscat'],
                            ['k' => 'pin3', 'href' => '/companies/oq'],
                            ['k' => 'pin4', 'href' => '/companies/asyad-group'],
                            ['k' => 'pin5', 'href' => '/companies/oman-telecommunication'],
                            ['k' => 'pin6', 'href' => '/companies/ooredoo-01-0d1b'],
                            ['k' => 'pin7', 'href' => '/companies/bank-dhofar'],
                            ['k' => 'pin8', 'href' => '/companies/sohar-international-bank'],
                        ] as $pin): ?>
                            <a href="<?= htmlspecialchars($pin['href']) ?>" class="text-sm px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-amber-100 hover:text-amber-700 transition font-medium border border-gray-100"><?= htmlspecialchars(t('landing.res_logos_' . $pin['k'])) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <a href="<?= htmlspecialchars(ArTwins::navLink('logos', getBasePath(), $_homeIsAr)) ?>" class="inline-flex items-center gap-2 text-amber-700 font-semibold hover:text-amber-800">
                        <?= htmlspecialchars(t('landing.res_logos_cta')) ?>
                        <i class="fa-solid fa-arrow-right text-xs"></i>
                    </a>
                </div>
            </div>

            <!-- Solutions Row -->
            <div class="bg-gray-50 rounded-2xl p-8">
                <div class="flex items-center justify-between flex-wrap gap-4 mb-6">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-lg bg-purple-600 text-white flex items-center justify-center">
                            <i class="fa-solid fa-lightbulb"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900"><?= htmlspecialchars(t('landing.res_sol_heading')) ?></h3>
                    </div>
                    <a href="<?= htmlspecialchars(ArTwins::navLink('solutions', getBasePath(), $_homeIsAr)) ?>" class="text-sm font-semibold text-purple-700 hover:text-purple-800"><?= htmlspecialchars(t('landing.res_sol_cta', ['count' => solutionCount()])) ?></a>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <a href="/solutions/business-cards-oman-construction-companies" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Construction &amp; Contracting</a>
                    <a href="/solutions/digital-business-cards-oil-gas-oman" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Oil &amp; Gas</a>
                    <a href="/solutions/business-cards-oman-bank-employees" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Bank Employees</a>
                    <a href="/solutions/business-cards-muscat-doctors-clinics" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Doctors &amp; Clinics</a>
                    <a href="/solutions/business-cards-omani-law-firms" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Law Firms</a>
                    <a href="/solutions/digital-cards-oman-real-estate-agents" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Real Estate</a>
                    <a href="/solutions/qr-code-menu-muscat-restaurants" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Restaurants QR Menu</a>
                    <a href="/solutions/bilingual-arabic-english-business-cards" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Bilingual AR/EN Cards</a>
                    <a href="/solutions/nfc-business-cards-oman-executives" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">NFC for Executives</a>
                    <a href="/solutions/business-cards-oman-government-employees" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Government Employees</a>
                    <a href="/solutions/business-cards-oman-startups" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Startups</a>
                    <a href="/solutions/salalah-tourism-business-cards" class="p-3 bg-white rounded-lg text-sm font-medium text-gray-700 hover:text-purple-700 hover:shadow transition border border-gray-100">Salalah Tourism</a>
                </div>
            </div>
        </div>
    </section>

    <?php /* ========== FAQ (r20-47) ========== */ ?>
    <?php if (!empty($homeFaqPairs)): ?>
    <?php $__isAr = function_exists('currentLocale') && currentLocale() === 'ar'; ?>
    <section id="faq" class="py-16 lg:py-24 bg-white">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <h2 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-4 text-center">
                <?= $__isAr ? 'الأسئلة الشائعة' : 'Frequently asked questions' ?>
            </h2>
            <p class="text-gray-600 text-center mb-10">
                <?= $__isAr
                    ? 'إجابات مختصرة عن كارديفاي، منصة بطاقات العمل الرقمية والمطبوعة من مجموعة BHD في مسقط، سلطنة عمان.'
                    : 'Short answers about Cardify, the digital and printed business card platform built by BHD Group in Muscat, Oman.' ?>
            </p>
            <div class="space-y-3">
                <?php foreach ($homeFaqPairs as [$__q, $__a]): ?>
                    <details class="group bg-gray-50 rounded-xl border border-gray-100 overflow-hidden">
                        <summary class="flex items-center justify-between cursor-pointer px-6 py-5 text-left hover:bg-gray-100 transition-colors list-none [&amp;::-webkit-details-marker]:hidden">
                            <h3 class="text-base sm:text-lg font-semibold text-gray-900 <?= $__isAr ? 'pl-4' : 'pr-4' ?>"><?= htmlspecialchars($__q) ?></h3>
                            <span class="flex-shrink-0 w-6 h-6 flex items-center justify-center text-blue-700 transition-transform group-open:rotate-45">
                                <i class="fa-solid fa-plus"></i>
                            </span>
                        </summary>
                        <div class="px-6 pb-5 pt-4 text-gray-700 leading-relaxed border-t border-gray-100">
                            <?= htmlspecialchars($__a) ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
            <div class="mt-8 text-center">
                <a href="<?= $__isAr ? '/ar' : '' ?>/faq" class="inline-flex items-center gap-2 font-semibold text-blue-700 hover:text-blue-800">
                    <?= $__isAr ? 'كل الأسئلة الشائعة' : 'All frequently asked questions' ?>
                    <i class="fa-solid fa-arrow-<?= $__isAr ? 'left' : 'right' ?>"></i>
                </a>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ========== CTA SECTION (Flowbite Style) ========== -->
    <section id="landing-final-cta" class="py-16 lg:py-24 bg-gradient-to-br from-blue-600 to-indigo-700">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-white/10 backdrop-blur-sm rounded-full text-white text-sm font-medium mb-6">
                <svg class="oman-flag inline-block shrink-0 rounded-sm" width="18" height="13.5" viewBox="0 0 640 480" aria-hidden="true" focusable="false"><use href="#cf-oman-flag"/></svg>
                <span><?= htmlspecialchars(t('landing.cta_supporting')) ?></span>
            </div>

            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white mb-6">
                <?= htmlspecialchars(t('landing.cta_title')) ?>
            </h2>
            <p class="text-xl text-blue-100 mb-10 max-w-2xl mx-auto">
                <?= htmlspecialchars(t('landing.cta_sub', ['companies' => number_format(PlatformStats::all()['issuing'])])) ?>
            </p>

            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register-otp.php" class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white hover:bg-gray-100 text-blue-600 font-bold rounded-xl shadow-xl transition-all hover:-translate-y-0.5 text-lg">
                    <i class="fa-solid fa-rocket"></i>
                    <?= htmlspecialchars(t('landing.cta_start_trial')) ?>
                </a>
                <a href="<?php echo getBasePath(); ?>intro" class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-blue-500/20 hover:bg-blue-500/30 text-white font-semibold rounded-xl border-2 border-white/30 transition-all text-lg">
                    <i class="fa-solid fa-play-circle"></i>
                    <?= htmlspecialchars(t('landing.cta_see_how')) ?>
                </a>
            </div>

            <div class="mt-10 flex flex-wrap justify-center gap-6 text-white/70 text-sm">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span><?= htmlspecialchars(t('landing.cta_free_starter')) ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span><?= htmlspecialchars(t('landing.cta_free_trial')) ?></span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-check-circle"></i>
                    <span><?= htmlspecialchars(t('pricing.home_plans_from', ['amount' => $priceStarterFrom, 'currency' => $homeCurName])) ?></span>
                </div>
            </div>
            </p>
        </div>
    </section>

    <?php /* Mobile landing: product before prose, the Start free button
             inside the first 812px, and a Start free bar that follows the
             reader once the hero is gone. Plain CSS because the site ships a
             prebuilt Tailwind file with no build step, so new utility classes
             would not exist. */ ?>
    <style>
    @media (max-width: 1023px) {
        /* !important because the prebuilt Tailwind file marks every utility
           important, so plain overrides of gap-8, mb-6, text-4xl lose. */
        #landing-hero { padding-top: 5.25rem !important; padding-bottom: 2rem !important; }
        #landing-hero .hero-grid { display: flex; flex-direction: column; align-items: stretch; gap: 0 !important; }
        #landing-hero .hero-reserve { display: contents; }
        #landing-hero .hero-badge { order: 1; align-self: center; margin-bottom: 0.75rem !important; }
        #landing-hero .hero-h1 { order: 2; font-size: 1.75rem !important; line-height: 1.15 !important; margin-bottom: 0.875rem !important; }
        #landing-hero .hero-h1-line3 { display: none; }
        #landing-hero .hero-product { order: 3; width: 100%; margin-top: 0 !important; margin-bottom: 0.875rem !important; }
        #landing-hero .hero-product > div { gap: 0.5rem !important; }
        #landing-hero #cardify-hero-card { max-width: 290px; }
        #landing-hero #cardify-hero-flip { padding-top: 0.375rem !important; padding-bottom: 0.375rem !important; }
        #landing-hero .hero-sub { order: 4; font-size: 0.975rem !important; line-height: 1.5 !important; margin-bottom: 1rem !important; }
        #landing-hero .hero-ctas { order: 5; gap: 0.75rem !important; margin-bottom: 1.5rem !important; }
        #landing-hero .hero-ctas a { padding-top: 0.875rem !important; padding-bottom: 0.875rem !important; font-size: 1.0625rem !important; }
        #landing-hero .hero-trust { order: 6; }
        .landing-features-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; gap: 0.75rem !important; }
        .landing-features-grid > div { padding: 1rem !important; border-radius: 1rem !important; }
        .landing-features-grid > div > div.rounded-xl { width: 2.5rem !important; height: 2.5rem !important; margin-bottom: 0.75rem !important; }
        .landing-features-grid > div > div.rounded-xl i { font-size: 1.125rem !important; }
        .landing-features-grid h3 { font-size: 0.9375rem !important; line-height: 1.3 !important; margin-bottom: 0.375rem !important; }
        .landing-features-grid p { font-size: 0.8125rem !important; line-height: 1.45 !important; }
    }
    .landing-sticky-cta { display: none; }
    @media (max-width: 767px) {
        .landing-sticky-cta {
            display: block; position: fixed; left: 0; right: 0; bottom: 0; z-index: 40;
            padding: 0.625rem 1rem calc(0.625rem + env(safe-area-inset-bottom));
            background: rgba(255,255,255,0.96); border-top: 1px solid #e5e7eb;
            box-shadow: 0 -6px 20px rgba(15,23,42,0.08);
            transform: translateY(110%); transition: transform 0.25s cubic-bezier(0.23,1,0.32,1);
        }
        .landing-sticky-cta.is-visible { transform: translateY(0); }
        .landing-sticky-cta a {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            min-height: 48px; border-radius: 0.75rem;
            font-weight: 600; font-size: 1rem; text-decoration: none;
        }
    }
    @media (prefers-reduced-motion: reduce) { .landing-sticky-cta { transition: none; } }
    </style>
    <div class="landing-sticky-cta" id="landing-sticky-cta" aria-hidden="true">
        <a href="<?php echo getBasePath(); ?>company/register-otp.php" tabindex="-1" class="bg-blue-600 hover:bg-blue-700 text-white"><?= htmlspecialchars(t('landing.cta_start_free')) ?> <i class="fa-solid fa-arrow-right rtl:fa-rotate-180" aria-hidden="true"></i></a>
    </div>
    <script<?= function_exists('cspNonceAttr') ? cspNonceAttr() : '' ?>>
    (function () {
        // Show the bar once the hero is off screen, hide it again over the
        // final call to action (which carries the same button).
        var bar = document.getElementById('landing-sticky-cta');
        var hero = document.getElementById('landing-hero');
        var fin = document.getElementById('landing-final-cta');
        if (!bar || !hero || !('IntersectionObserver' in window)) return;
        var heroOut = false, finIn = false;
        function sync() {
            var on = heroOut && !finIn;
            bar.classList.toggle('is-visible', on);
            bar.setAttribute('aria-hidden', on ? 'false' : 'true');
            var a = bar.querySelector('a'); if (a) a.tabIndex = on ? 0 : -1;
        }
        new IntersectionObserver(function (e) { heroOut = !e[0].isIntersecting; sync(); }).observe(hero);
        if (fin) new IntersectionObserver(function (e) { finIn = e[0].isIntersecting; sync(); }).observe(fin);
    })();
    </script>

    <!-- ========== SCRIPTS ========== -->
    <?php
    // A heredoc does not run PHP tags. Written as a short-echo tag the nonce
    // printed literally, the element stopped being a script, and the mobile
    // menu toggle below it never ran.
    $cardifyNonce = function_exists('cspNonceAttr') ? cspNonceAttr() : '';
    $extraScripts = <<<HTML
    <script{$cardifyNonce}>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn');
        const mobileMenu = document.getElementById('mobile-menu');
        
        mobileMenuBtn?.addEventListener('click', () => {
            mobileMenu.classList.toggle('hidden');
        });

        // Navbar scroll effect
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            const currentScroll = window.pageYOffset;
            if (currentScroll > 50) {
                navbar.classList.add('shadow-md');
            } else {
                navbar.classList.remove('shadow-md');
            }
        });
    </script>
HTML;
?>

<?php
// bhd-group-seo-llm27-15: kept as a PHP comment, not a JSON-LD key. An
// internal review note must not ship inside structured data.
// r6-99:
// LocalBusiness is a SECOND @type on the one #organization node rather than a second node. Cardify
// is published from the BHD Group floor at HM Tower and a separate LocalBusiness node would have
// been a fifth BHD address on the estate, which is the defect r20-16 recorded. Address, phone and
// hours are the canonical block bhd.om/_nap.py owns, verbatim.
?>
<!-- JSON-LD Structured Data -->
<?php
// llm20-11 (r48): this node used to be a 24-key JSON literal typed here,
// beside a SECOND, shorter hand-written body for the same @id in
// includes/Seo.php that 20 /solutions/* pages published. Both claimed to be
// https://cardify.om/#organization and they disagreed on @type and
// contactPoint. The literal now lives in Seo::organizationNode() and is
// rendered from there, so the estate has one body for one identifier.
//
// r154: measured on the origin, this page served the owner TWICE, two
// byte-identical 24-key bodies. ui-header.php (required at line 456) now
// emits it, and this block sits ~950 lines further down, so the guard could
// not have been set yet: an ordering fact, not a design choice. Routing this
// call through the same once-emitter makes whichever runs first win and the
// second a no-op, which is the only shape that survives a page moving its
// header include.
require_once __DIR__ . '/includes/Seo.php';
echo Seo::organizationScriptOnce();
?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "SoftwareApplication",
  "@id": "https://cardify.om/#webapp",
  "name": "Cardify",
  "alternateName": "Cardify Web App",
  "applicationSuite": "Cardify",
  <?php /* r139 / bhd-r6-99: this literal named cardify.om/#organization while
     the iOS body it links to names AppEntity::PUBLISHER_ID, so one page
     answered "who publishes Cardify" twice. Apple's seller of record decides
     it for the store-backed node (entity_gate APP-PUBLISHER-REGISTRY) and no
     registry can rule on a web app, so the web app follows it. The brand
     relationship is already carried one hop up by cardify.om/#organization ->
     parentOrganization -> bhd.om/#organization. */ ?>
  "publisher": { "@id": "<?= AppEntity::PUBLISHER_ID ?>" },
  "isRelatedTo": { "@id": "<?= AppEntity::ID ?>" },
  "applicationCategory": "BusinessApplication",
  "operatingSystem": "Web browser on iOS, Android, macOS, Windows and Linux",
  "url": "https://cardify.om",
  "description": "Digital and printed business card SaaS for teams across the GCC. Bilingual EN+AR, QR vCard auto-save, Apple Wallet + Google Wallet, bulk team onboarding, local print fulfilment. Available in Oman, Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait.",
  "inLanguage": ["en", "ar"],
  "offers": [
    {
      "@type": "Offer",
      "name": "Platform Access",
      "price": "0",
      "priceCurrency": "OMR",
      "description": "Free forever. Unlimited employees, unlimited templates, digital cards with QR vCard, bilingual EN+AR, analytics, WhatsApp and email share, no credit card required.",
      "availability": "https://schema.org/InStock",
      <?php /* r328: this named /company/register.php, which robots.txt
         disallows under Disallow: /company/ (login and logout live there
         too). The free-tier Offer therefore pointed at a URL Googlebot was
         told not to fetch. robots.txt now carries an explicit
         Allow: /company/register.php so the CTA itself is crawlable, and the
         Offer moves to /get-started, which is a real indexable page outside
         the disallowed prefix. Both halves, because either alone leaves the
         Offer's landing page depending on a longest-match tie-break. */ ?>
      "url": "https://cardify.om/get-started"
    },
    {
      "@type": "Offer",
      "name": "Standard Printed Cards",
      "price": "<?= CardCatalogPricing::decimal('standard') ?>",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "<?= CardCatalogPricing::decimal('standard') ?>",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "100", "unitText": "cards" }
      },
      "description": "300gsm matt, full colour both sides. From OMR <?= CardCatalogPricing::decimal('standard') ?> per 100 cards, printed by verified Omani shops.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    },
    {
      "@type": "Offer",
      "name": "Premium Printed Cards",
      "price": "<?= CardCatalogPricing::decimal('premium') ?>",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "<?= CardCatalogPricing::decimal('premium') ?>",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "100", "unitText": "cards" }
      },
      "description": "350gsm soft-touch, full colour both sides. From OMR <?= CardCatalogPricing::decimal('premium') ?> per 100 cards.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    },
    {
      "@type": "Offer",
      "name": "Luxury Printed Cards",
      "price": "<?= CardCatalogPricing::decimal('luxury') ?>",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "<?= CardCatalogPricing::decimal('luxury') ?>",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "100", "unitText": "cards" }
      },
      "description": "450gsm with spot UV or foil accents. From OMR <?= CardCatalogPricing::decimal('luxury') ?> per 100 cards.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    },
    {
      "@type": "Offer",
      "name": "NFC Tap Cards",
      "price": "<?= CardCatalogPricing::decimal('nfc') ?>",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "<?= CardCatalogPricing::decimal('nfc') ?>",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "1", "unitText": "card" }
      },
      "description": "Re-programmable NFC chip plus QR. Tap-to-share on any phone. OMR <?= CardCatalogPricing::decimal('nfc') ?> per card.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    }
  ]
}
</script>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "Product",
  "@id": "https://cardify.om/#product",
  "name": "Cardify Business Card Platform",
  <?php /* r328: sku follows the CARDIFY-<KEY> convention Seo::product()
     already uses for the four print tiers. No aggregateRating and no review
     here on purpose: print_shop_reviews and employee_card_testimonials both
     hold ZERO rows and nothing in the tree writes to either, so any rating
     published under this @id would be invented. It stays absent until a
     real one is collected. */ ?>
  "sku": "CARDIFY-PLATFORM",
  "isRelatedTo": { "@id": "https://cardify.om/#webapp" },
  "image": "https://cardify.om/assets/images/cardify-og.png",
  "description": "SaaS for creating, managing, and printing branded digital + printed business cards for teams in Oman.",
  "brand": { "@type": "Brand", "name": "Cardify", "@id": "https://cardify.om/#brand" },
  "offers": {
    "@type": "AggregateOffer",
    "priceCurrency": "OMR",
    "lowPrice": "0",
    "highPrice": "15",
    "offerCount": "5",
    "availability": "https://schema.org/InStock",
    "hasMerchantReturnPolicy": {
      "@type": "MerchantReturnPolicy",
      "@id": "https://cardify.om/#returnpolicy",
      "applicableCountry": "OM",
      "returnPolicyCategory": "https://schema.org/MerchantReturnNotPermitted"
    },
    "shippingDetails": {
      "@type": "OfferShippingDetails",
      "@id": "https://cardify.om/#shipping-oman",
      "shippingDestination": { "@type": "DefinedRegion", "addressCountry": "OM" },
      "deliveryTime": {
        "@type": "ShippingDeliveryTime",
        "handlingTime": { "@type": "QuantitativeValue", "minValue": 0, "maxValue": 1, "unitCode": "DAY" },
        "transitTime": { "@type": "QuantitativeValue", "minValue": 2, "maxValue": 4, "unitCode": "DAY" }
      }
    }
  }
}
</script>

<?php
    require INCLUDES_DIR . '/ui-footer.php';
    ?>
