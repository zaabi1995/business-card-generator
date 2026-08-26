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

// Homepage pricing: public marketing must stay on canonical OMR pricing.
// Do not auto-detect visitor currency here: it can convert and marketing-round
// into invented values that leak into crawlers. Currency switching remains
// available in logged-in and checkout surfaces.
require_once INCLUDES_DIR . '/Currency.php';
$homeCur = 'OMR';
$homeCurName = (function_exists('currentLocale') && currentLocale() === 'ar')
    ? t('currency.names.OMR')
    : 'OMR';

// Cheapest print product, Standard at OMR 5.000 per 100 cards (see /pricing).
$priceStarterFrom = Currency::formatNumber(5.000, 'OMR');

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
$homeJsonLd = '<script type="application/ld+json">' . json_encode($siteLd, JSON_UNESCAPED_SLASHES) . '</script>';
// r81 / r6-99 + llm20-21: this literal WAS one of the two competing
// definitions of the app. It is now the ONE record in includes/AppEntity.php,
// read by this page, /app and /business-card-scanner, so a fourth spelling
// cannot be typed into a fourth file.
$scannerLd = ['@context' => 'https://schema.org'] + AppEntity::node();
$scannerJsonLd = '<script type="application/ld+json">' . json_encode($scannerLd, JSON_UNESCAPED_SLASHES) . '</script>';
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
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
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

// Hint the browser to start downloading the hero screenshot before the
// stylesheet parse completes. Biggest measurable LCP win on /.
$lcpImage = assetUrl('images/landing/light-dash.png');

require_once INCLUDES_DIR . '/ui-header.php';
?>

    <!-- ========== HERO SECTION (Flowbite Style) ========== -->
    <section class="hero-gradient pt-28 lg:pt-36 pb-16 lg:pb-24 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center hero-grid">
                <!-- Left Content -->
                <div class="lg:col-span-6 text-center lg:text-left hero-reserve">
                    <!-- Badge -->
                    <div class="inline-flex items-center gap-2 py-1 pl-1 pr-4 mb-6 text-sm bg-white border border-gray-200 rounded-full shadow-sm">
                        <span class="inline-flex items-center gap-1 bg-blue-50 text-blue-700 font-semibold text-xs px-3 py-1 rounded-full"><span>🇴🇲</span> <?= htmlspecialchars(t('landing.hero_badge_loc')) ?></span>
                        <span class="font-medium text-gray-700"><?= htmlspecialchars(t('landing.hero_badge_copy')) ?></span>
                    </div>

                    <!-- Headline -->
                    <h1 class="hero-h1 text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight leading-tight text-gray-900 mb-6">
                        <?= htmlspecialchars(t('landing.hero_h1_line1')) ?>
                        <span class="text-blue-600 block"><?= htmlspecialchars(t('landing.hero_h1_line2')) ?></span>
                        <span class="text-gray-500 text-3xl sm:text-4xl lg:text-5xl"><?= htmlspecialchars(t('landing.hero_h1_line3')) ?></span>
                    </h1>

                    <!-- Subheadline -->
                    <p class="hero-sub text-lg lg:text-xl text-gray-600 mb-8 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        <?= htmlspecialchars(t('landing.hero_subhead')) ?>
                        <strong class="text-gray-900"><?= htmlspecialchars(t('landing.hero_price_tag')) ?></strong>
                    </p>

                    <!-- CTA Buttons -->
                    <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start mb-10">
                        <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-7 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:-translate-y-0.5 text-lg">
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
                <div class="lg:col-span-6 relative mt-12 lg:mt-0">
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

    <!-- ========== VALUE PROPOSITION BANNER ========== -->
    <section class="py-12 bg-gradient-to-r from-blue-600 via-blue-700 to-indigo-700 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid md:grid-cols-3 gap-8 text-center">
                <div class="flex flex-col items-center">
                    <div class="w-14 h-14 bg-white/10 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-palette text-2xl"></i>
                    </div>
                    <h2 class="text-lg font-bold mb-2"><?= htmlspecialchars(t('landing.vp_design_title')) ?></h2>
                    <p class="text-blue-100 text-sm"><?= htmlspecialchars(t('landing.vp_design_body')) ?></p>
                </div>
                <div class="flex flex-col items-center">
                    <div class="w-14 h-14 bg-white/10 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-print text-2xl"></i>
                    </div>
                    <h2 class="text-lg font-bold mb-2"><?= htmlspecialchars(t('landing.vp_print_title')) ?></h2>
                    <p class="text-blue-100 text-sm"><?= htmlspecialchars(t('landing.vp_print_body')) ?></p>
                </div>
                <div class="flex flex-col items-center">
                    <div class="w-14 h-14 bg-white/10 rounded-2xl flex items-center justify-center mb-4">
                        <i class="fa-solid fa-gift text-2xl"></i>
                    </div>
                    <h2 class="text-lg font-bold mb-2"><?= htmlspecialchars(t('landing.vp_free_title')) ?></h2>
                    <p class="text-blue-100 text-sm"><?= htmlspecialchars(t('landing.vp_free_body')) ?></p>
                </div>
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
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
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
                <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/30 transition-all text-lg">
                    <?= htmlspecialchars(t('landing.how_cta')) ?>
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- ========== SCREENSHOT SECTION (Techwind Style) ========== -->
    <section class="py-16 lg:py-24 bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-800 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <!-- Content -->
                <div class="text-white">
                    <!-- r28: white/80 on white/10 over this gradient measures 3.35:1 at the
                         blue-600 end. Same shape as 27-54: dimmed white on a saturated ground. -->
                    <span class="inline-flex items-center gap-2 py-1 px-3 mb-4 text-xs font-semibold text-white bg-white/20 rounded-full uppercase tracking-wide">
                        <i class="fa-solid fa-desktop"></i>
                        <?= htmlspecialchars(t('landing.dash_kicker')) ?>
                    </span>
                    <h2 class="text-3xl sm:text-4xl font-extrabold mb-6">
                        <?= htmlspecialchars(t('landing.dash_headline')) ?>
                    </h2>
                    <p class="text-lg text-blue-100 mb-8 leading-relaxed">
                        <?= htmlspecialchars(t('landing.dash_body')) ?>
                    </p>
                    
                    <ul class="space-y-4 mb-8">
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-check text-green-400 mt-1"></i>
                            <span class="text-blue-100"><?= htmlspecialchars(t('landing.dash_b1')) ?></span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-check text-green-400 mt-1"></i>
                            <span class="text-blue-100"><?= htmlspecialchars(t('landing.dash_b2')) ?></span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-check text-green-400 mt-1"></i>
                            <span class="text-blue-100"><?= htmlspecialchars(t('landing.dash_b3')) ?></span>
                        </li>
                        <li class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-check text-green-400 mt-1"></i>
                            <span class="text-blue-100"><?= htmlspecialchars(t('landing.dash_b4')) ?></span>
                        </li>
                    </ul>

                    <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-white text-blue-600 font-semibold rounded-lg hover:bg-blue-50 transition-colors">
                        <?= htmlspecialchars(t('landing.dash_cta')) ?>
                        <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>

                <!-- Screenshot -->
                <div class="relative">
                    <div class="relative rounded-xl overflow-hidden shadow-2xl border-8 border-white/10">
                        <img src="<?php echo assetUrl('images/landing/light-dash.png'); ?>" alt="Cardify Dashboard" class="w-full h-auto" width="1175" height="605" fetchpriority="high" decoding="async">
                    </div>
                    <!-- Decorative elements -->
                    <div class="absolute -top-4 -right-4 w-24 h-24 bg-amber-400 rounded-full opacity-20 blur-xl"></div>
                    <div class="absolute -bottom-8 -left-8 w-32 h-32 bg-green-400 rounded-full opacity-20 blur-xl"></div>
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
                        <a href="<?= getBasePath() ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-7 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-500/30 transition hover:-translate-y-0.5">
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
                        <a href="<?= getBasePath() ?>company/register.php" class="text-blue-600 hover:text-blue-700 font-semibold text-sm inline-flex items-center gap-1">
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
    <section class="py-16 lg:py-24 bg-gradient-to-br from-blue-600 to-indigo-700">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <div class="inline-flex items-center gap-2 px-4 py-2 bg-white/10 backdrop-blur-sm rounded-full text-white text-sm font-medium mb-6">
                <span>🇴🇲</span>
                <span><?= htmlspecialchars(t('landing.cta_supporting')) ?></span>
            </div>

            <h2 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-white mb-6">
                <?= htmlspecialchars(t('landing.cta_title')) ?>
            </h2>
            <p class="text-xl text-blue-100 mb-10 max-w-2xl mx-auto">
                <?= htmlspecialchars(t('landing.cta_sub', ['companies' => number_format(PlatformStats::all()['issuing'])])) ?>
            </p>

            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white hover:bg-gray-100 text-blue-600 font-bold rounded-xl shadow-xl transition-all hover:-translate-y-0.5 text-lg">
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

    <!-- ========== FOOTER ========== -->
    <footer id="contact" class="bg-gray-900 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
            <div class="grid md:grid-cols-2 lg:grid-cols-5 gap-10 mb-12">
                <!-- Brand -->
                <div class="lg:col-span-1">
                    <div class="flex items-center gap-3 mb-6">
                        <img src="<?php echo assetUrl('images/logo-light.svg'); ?>" alt="<?php echo $brandName; ?>" class="h-10 w-auto">
                    </div>
                    <?php /* llm75-1: this blurb was hardcoded English and shipped inside a
                             document whose html lang was ar, alone among its siblings, which
                             all read from t('footer.*'). It now reads the SAME key
                             includes/ui-footer.php renders on every other page, so the brand
                             line has one source in both languages instead of a translated
                             copy and an English one. */ ?>
                    <p class="text-gray-400 mb-4 leading-relaxed text-sm">
                        <?= htmlspecialchars(t('footer.tagline')) ?>
                    </p>
                    <div class="flex gap-3 mb-6">
                        <a href="https://instagram.com/cardifyom" target="_blank" rel="noopener noreferrer" class="w-10 h-10 rounded-lg bg-gray-800 hover:bg-pink-600 flex items-center justify-center transition-colors" aria-label="Instagram">
                            <i class="fa-brands fa-instagram"></i>
                        </a>
                    </div>
                </div>

                <!-- Product Links -->
                <div>
                    <h3 class="font-bold text-lg mb-6"><?= htmlspecialchars(t('footer.col_product')) ?></h3>
                    <ul class="space-y-3">
                        <li><a href="#features" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_features')) ?></a></li>
                        <li><a href="#pricing" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_pricing')) ?></a></li>
                        <li><a href="#resources" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_all_tools')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('company/register.php', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('header.get_started_free')) ?></a></li>
                    </ul>
                </div>

                <!-- Free Tools -->
                <div>
                    <h3 class="font-bold text-lg mb-6"><?= htmlspecialchars(t('footer.col_free_tools')) ?></h3>
                    <ul class="space-y-3">
                        <li><a href="<?= ArTwins::navLink('tools/vcard-qr-generator', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_vcard_qr')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('tools/email-signature-generator', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_email_sig')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('tools/whatsapp-qr-generator', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_whatsapp_qr')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('tools/nfc-business-card-guide', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_nfc_guide')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('tools', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_all_tools')) ?></a></li>
                    </ul>
                </div>

                <!-- Directory & Solutions -->
                <div>
                    <h3 class="font-bold text-lg mb-6"><?= htmlspecialchars(t('footer.col_directory')) ?></h3>
                    <ul class="space-y-3">
                        <li><a href="<?= ArTwins::navLink('oman-business-index', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_oman_index')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('companies', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_browse_companies')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('companies/sector/oil-gas', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_oil')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('companies/sector/construction', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_construction')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('companies/wilayat/muscat', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors">Muscat Companies</a></li>
                        <li><a href="<?= ArTwins::navLink('solutions', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_solutions')) ?></a></li>
                    </ul>
                </div>

                <!-- Company + Legal -->
                <div>
                    <h3 class="font-bold text-lg mb-6"><?= htmlspecialchars(t('footer.col_company')) ?></h3>
                    <ul class="space-y-3">
                        <li><a href="<?= ArTwins::navLink('about', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_about')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('blog', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_blog')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('careers', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_careers')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('contact', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_contact')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('privacy', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_privacy')) ?></a></li>
                        <li><a href="<?= ArTwins::navLink('terms', getBasePath(), $_homeIsAr) ?>" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_terms')) ?></a></li>
                    </ul>
                </div>
            </div>

            <!-- Bottom Bar -->
            <div class="pt-8 border-t border-gray-800 flex flex-col md:flex-row justify-between items-center gap-4">
                <p class="text-gray-500 text-sm">
                    <?= htmlspecialchars(t('footer.copyright', ['year' => date('Y'), 'brand' => $brandName])) ?>
                </p>
                <div class="flex items-center gap-6 text-sm text-gray-500">
                    <span class="flex items-center gap-2">
                        <i class="fa-solid fa-globe"></i>
                        <?= (function_exists('currentLocale') && currentLocale() === 'ar') ? 'العربية' : 'English (US)' ?>
                    </span>
                </div>
            </div>
        </div>
    </footer>

    <!-- ========== SCRIPTS ========== -->
    <?php
    $extraScripts = <<<HTML
    <script>
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
      "price": "5.000",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "5.000",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "100", "unitText": "cards" }
      },
      "description": "300gsm matt, full colour both sides. From OMR 5.000 per 100 cards, printed by verified Omani shops.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    },
    {
      "@type": "Offer",
      "name": "Premium Printed Cards",
      "price": "6.000",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "6.000",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "100", "unitText": "cards" }
      },
      "description": "350gsm soft-touch, full colour both sides. From OMR 6.000 per 100 cards.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    },
    {
      "@type": "Offer",
      "name": "Luxury Printed Cards",
      "price": "15.000",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "15.000",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "100", "unitText": "cards" }
      },
      "description": "450gsm with spot UV or foil accents. From OMR 15.000 per 100 cards.",
      "availability": "https://schema.org/InStock",
      "url": "https://cardify.om/pricing"
    },
    {
      "@type": "Offer",
      "name": "NFC Tap Cards",
      "price": "25.000",
      "priceCurrency": "OMR",
      "priceSpecification": {
        "@type": "UnitPriceSpecification",
        "price": "25.000",
        "priceCurrency": "OMR",
        "referenceQuantity": { "@type": "QuantitativeValue", "value": "1", "unitText": "card" }
      },
      "description": "Re-programmable NFC chip plus QR. Tap-to-share on any phone. OMR 25.000 per card.",
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
    "highPrice": "25",
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
