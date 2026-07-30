<?php
/**
 * Cardify - Shared UI Header
 * Include this at the top of all pages for consistent styling
 * 
 * Variables you can set before including:
 * - $pageTitle: Page title (default: 'Cardify')
 * - $pageDescription: Meta description
 * - $htmlClass: HTML tag classes (default: 'scroll-smooth')
 * - $bodyClass: Additional body classes
 * - $bodyAttributes: Extra attributes for body tag
 * - $metaAuthor: Meta author tag
 * - $metaRobots: Meta robots tag
 * - $canonicalUrl: Canonical URL
 * - $ogType: Open Graph type (default: 'website')
 * - $ogImage: Open Graph/Twitter image URL
 * - $enableThemeScript: Enable dark mode script
 * - $extraHead: Extra head markup (styles/scripts/meta)
 */

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Tenant subdomains ({slug}.cardify.om) get auto-branded with the
// company's logo as favicon + a logo + brand-coloured spinner ring on
// the page loader. Falls through to defaults on the apex (cardify.om)
// and on tenants without a theme row yet.
$cardifyTenantTheme = null;
if (class_exists('TenantHost')) {
    try { $cardifyTenantTheme = TenantHost::theme(); } catch (Throwable $e) { $cardifyTenantTheme = null; }
    if ($cardifyTenantTheme && !empty($cardifyTenantTheme['name'])) {
        $brandName = $cardifyTenantTheme['name'];
    }
}

// Cardify's own marketing/app chrome (apex cardify.om) gets the cyan brand remap.
// Tenant subdomains keep their OWN brand color, so the blue->cyan remap in
// cardify-brand-2026.css is gated behind the body `.cardify-brand` class added
// only here. A page may force it off with $forceCardifyBrand = false.
$cardifyIsMarketing = !(class_exists('TenantHost') && TenantHost::isTenantHost());
if (isset($forceCardifyBrand)) {
    $cardifyIsMarketing = (bool) $forceCardifyBrand;
}

$pageTitle = $pageTitle ?? $brandName;
$pageDescription = $pageDescription ?? 'Business Cards Made Simple';
$htmlClass = $htmlClass ?? 'scroll-smooth';
$bodyClass = $bodyClass ?? '';
$bodyAttributes = $bodyAttributes ?? '';
$metaAuthor = $metaAuthor ?? '';
$metaRobots = $metaRobots ?? '';
$canonicalUrl = $canonicalUrl ?? '';
$enableThemeScript = $enableThemeScript ?? false;
$extraHead = $extraHead ?? '';

// Referral tracking, capture ?ref= parameter
if (!empty($_GET['ref']) && empty($_SESSION['referral_source'])) {
    $_SESSION['referral_source'] = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['ref']), 0, 50);
    $_SESSION['referral_landing'] = $_SERVER['REQUEST_URI'] ?? '/';
    $_SESSION['referral_time'] = date('Y-m-d H:i:s');
}
// Also capture UTM parameters
if (!empty($_GET['utm_source']) && empty($_SESSION['utm_source'])) {
    $_SESSION['utm_source'] = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['utm_source']), 0, 50);
    $_SESSION['utm_medium'] = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['utm_medium'] ?? ''), 0, 50);
    $_SESSION['utm_campaign'] = substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['utm_campaign'] ?? ''), 0, 50);
}
?>
<?php
$cardifyLocale = function_exists('currentLocale') ? currentLocale() : 'en';
$cardifyDir    = function_exists('currentDir')    ? currentDir()    : 'ltr';
$cardifyOgLocale = ($cardifyLocale === 'ar') ? 'ar_OM' : 'en_US';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($cardifyLocale) ?>" dir="<?= htmlspecialchars($cardifyDir) ?>" class="<?php echo htmlspecialchars($htmlClass); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
      // Defensively clear legacy language cookies on every page load so a
      // browser-cached HTML body (which never re-hits the server-side
      // Set-Cookie clearing path) cannot keep forcing Arabic. Runs in
      // <head> before any other script so the next navigation is clean.
      (function () {
        try {
          var legacy = ['cardify_lang', 'cardify_lang_v2'];
          for (var i = 0; i < legacy.length; i++) {
            var name = legacy[i];
            if (document.cookie.indexOf(name + '=') !== -1) {
              document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/; samesite=Lax; secure';
            }
          }
        } catch (e) {}
      })();
    </script>
    <title><?php echo htmlspecialchars($pageTitle); ?><?php echo (stripos($pageTitle, $brandName) === false) ? ' | ' . $brandName : ''; ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription); ?>">
    <link rel="alternate" type="application/rss+xml" title="Cardify Blog" href="<?= function_exists('getBasePath') ? getBasePath() : '/' ?>feed">
    <?php if (!empty($metaAuthor)): ?>
    <meta name="author" content="<?php echo htmlspecialchars($metaAuthor); ?>">
    <?php endif; ?>
    <?php if (!empty($metaRobots)): ?>
    <meta name="robots" content="<?php echo htmlspecialchars($metaRobots); ?>">
    <?php endif; ?>
    <?php
    // Locale correction on the canonical, derived from the SERVED path and
    // never from the requested locale. Eleven Arabic twins (/ar/about,
    // /ar/logos, /ar/tools ...) hardcoded their English URL here, so every one
    // of them asked Google to treat the Arabic page as a duplicate of the
    // English one and drop it, while the head directly below told Google the
    // pair was reciprocal. Only the exact same page is rewritten (same
    // normalised path), so paginated and parameterised canonicals keep their
    // query string.
    if (!empty($canonicalUrl)) {
        require_once __DIR__ . '/ArTwins.php';
        $servedPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (ArTwins::isArabic($servedPath)) {
            $arPath   = ArTwins::arPath($servedPath);
            $canParts = parse_url($canonicalUrl);
            $canPath  = $canParts['path'] ?? '/';
            if ($arPath !== null
                && strpos($canPath, '/ar/') !== 0 && $canPath !== '/ar'
                && ArTwins::normalise($canPath) === ArTwins::normalise($servedPath)) {
                $canonicalUrl = ArTwins::SITE . $arPath
                    . (isset($canParts['query']) && $canParts['query'] !== '' ? '?' . $canParts['query'] : '');
            }
        }
    }
    ?>
    <?php if (!empty($canonicalUrl)): ?>
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl); ?>">
    <?php
    // hreflang comes from the ArTwins map, never from $canonicalUrl itself.
    // Self-referencing was the bug: on /ar/pricing this block emitted
    // hreflang="en" AND x-default pointing at the Arabic URL, i.e. it told
    // Google the Arabic page was the English one, while /pricing never named
    // /ar/pricing at all, so the pair had no return tag in either direction.
    // Both twins now emit the identical set. Pages that build their own full
    // hreflang in $extraHead still opt out with $suppressDefaultHreflang.
    if (empty($suppressDefaultHreflang)):
        require_once __DIR__ . '/ArTwins.php';
        foreach (ArTwins::tags($canonicalUrl) as [$hrefLang, $hrefUrl]):
    ?>
    <link rel="alternate" hreflang="<?php echo htmlspecialchars($hrefLang); ?>" href="<?php echo htmlspecialchars($hrefUrl); ?>">
    <?php endforeach; endif; ?>
    <?php endif; ?>
    <?php if (defined('GOOGLE_SITE_VERIFICATION') && GOOGLE_SITE_VERIFICATION): ?>
    <meta name="google-site-verification" content="<?= GOOGLE_SITE_VERIFICATION ?>">
    <?php endif; ?>

    <!-- Open Graph -->
    <meta property="og:site_name" content="Cardify">
    <meta property="og:type" content="<?= htmlspecialchars($ogType ?? 'website') ?>">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle ?? 'Cardify') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDescription ?? 'Create, manage, and print professional business cards for your team in Oman.') ?>">
    <?php if (!empty($canonicalUrl)): ?>
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl) ?>">
    <?php endif; ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImage ?? getBaseUrl() . 'assets/images/cardify-og.png') ?>">
    <meta property="og:locale" content="<?= htmlspecialchars($cardifyOgLocale) ?>">
    <?php if ($cardifyLocale === 'ar'): ?>
    <meta property="og:locale:alternate" content="en_US">
    <?php else: ?>
    <meta property="og:locale:alternate" content="ar_OM">
    <?php endif; ?>

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle ?? 'Cardify') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDescription ?? 'Create, manage, and print professional business cards for your team in Oman.') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage ?? getBaseUrl() . 'assets/images/cardify-og.png') ?>">

    <?php /* r6-100: gtag/js is 478KB decoded, the heaviest resource on the site.
             It no longer leads the critical path: it is fetched after load, and a
             malformed measurement id is refused rather than shipped as a beacon
             that aborts on every page. */ ?>
    <?php if (defined('GA_MEASUREMENT_ID') && preg_match('/^G-[A-Z0-9]{6,}$/', (string) GA_MEASUREMENT_ID)): ?>
    <!-- Google Analytics (deferred to after load) -->
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());
      gtag('config', '<?= GA_MEASUREMENT_ID ?>');
      addEventListener('load', function () {
        var s = document.createElement('script');
        s.async = true;
        s.src = 'https://www.googletagmanager.com/gtag/js?id=<?= GA_MEASUREMENT_ID ?>';
        document.head.appendChild(s);
      }, { once: true });
    </script>
    <?php endif; ?>

    <?php if (!empty($cardifyTenantTheme['favicon'])):
        $__favType = preg_match('/\.svg(\?|$)/i', $cardifyTenantTheme['favicon']) ? 'image/svg+xml'
                    : (preg_match('/\.png(\?|$)/i', $cardifyTenantTheme['favicon']) ? 'image/png'
                    : (preg_match('/\.ico(\?|$)/i', $cardifyTenantTheme['favicon']) ? 'image/x-icon' : 'image/png'));
    ?>
    <link rel="icon" href="<?= htmlspecialchars($cardifyTenantTheme['favicon'], ENT_QUOTES) ?>" type="<?= $__favType ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($cardifyTenantTheme['favicon'], ENT_QUOTES) ?>">
    <?php else: ?>
    <link rel="icon" href="<?php echo getBasePath(); ?>favicon.svg" type="image/svg+xml">
    <link rel="alternate icon" href="<?php echo getBasePath(); ?>favicon.ico">
    <?php endif; ?>
    
    <!-- Fonts via fonts.bhd.om (BHD-Group canonical CDN, not Google) -->
    <link rel="preconnect" href="https://fonts.bhd.om">
    <link rel="preconnect" href="https://fonts.bhd.om" crossorigin>
    <?php if ($cardifyDir === 'rtl'): ?>
    <link href="https://fonts.bhd.om/css2?family=Sora:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php else: ?>
    <link href="https://fonts.bhd.om/css2?family=Sora:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php endif; ?>
    
    <!-- Preconnect to CDNs (parallel DNS+TLS) -->
    <!-- design.bhd.om serves the preloaded FA woff2 (crossorigin) + FA/cmdk/skeleton CSS (same-origin),
         so open both connection pools early. Was a dead cdnjs preconnect (nothing loads from cdnjs). -->
    <link rel="preconnect" href="https://design.bhd.om">
    <link rel="preconnect" href="https://design.bhd.om" crossorigin>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>

    <?php if (!empty($lcpImage)): /* Per-page LCP preload (set $lcpImage before require to prioritize the hero). */ ?>
    <link rel="preload" as="image" href="<?php echo htmlspecialchars($lcpImage, ENT_QUOTES); ?>" fetchpriority="high">
    <?php endif; ?>

    <?php
      /* Sentry browser bootstrap (Cat T action 456). Emits DSN + loads
         /assets/js/cardify-sentry.js if SENTRY_DSN_PUBLIC is defined. */
      if (class_exists('Sentry')) {
          $sentryBootstrap = Sentry::browserBootstrap();
          if ($sentryBootstrap) {
              echo $sentryBootstrap . "\n";
              echo '<script defer src="' . htmlspecialchars(getBasePath()) . 'assets/js/cardify-sentry.js"></script>' . "\n";
          }
      }
    ?>

    <!-- Font Awesome 7.2 Pro, self-hosted on design.bhd.om. Icons are not
         needed for first paint, so load the CSS non-blocking (media=print
         until onload) to keep 4 stylesheets off the critical path. The two
         most-used woff2 faces stay preloaded. ?v busts stale CF cache. -->
    <link rel="preload" href="https://design.bhd.om/fa/v7.2.0/webfonts/fa-brands-400.woff2?c=2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="https://design.bhd.om/fa/v7.2.0/webfonts/fa-solid-900.woff2?c=2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css?v=7.2.0" media="print" onload="this.onload=null;this.media='all'">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css?v=7.2.0" media="print" onload="this.onload=null;this.media='all'">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/regular.min.css?v=7.2.0" media="print" onload="this.onload=null;this.media='all'">
    <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css?v=7.2.0" media="print" onload="this.onload=null;this.media='all'">
    <noscript>
        <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/fontawesome.min.css?v=7.2.0">
        <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/solid.min.css?v=7.2.0">
        <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/regular.min.css?v=7.2.0">
        <link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/brands.min.css?v=7.2.0">
    </noscript>

    <!-- Tailwind CSS (Local, render-critical) -->
    <?php $tailwindVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/techwind/css/tailwind.min.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/techwind/css/tailwind.min.css?v=<?php echo $tailwindVersion; ?>">

    <!-- Flowbite CSS (Local, render-critical) -->
    <?php $flowbiteCssVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.css') ?: time(); ?>
    <link rel="stylesheet" href="/assets/flowbite/app.css?v=<?php echo $flowbiteCssVersion; ?>">

    <!-- Flag Icons CSS, only needed on forms with phone/country selectors; non-blocking -->
    <link rel="preload" as="style" href="/assets/vendor/flag-icons/css/flag-icons.min.css" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="/assets/vendor/flag-icons/css/flag-icons.min.css"></noscript>
    
    <!-- Custom Overrides -->
    <?php $cardifyOverridesVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/cardify-overrides.css') ?: time(); ?>
    <link rel="stylesheet" href="<?php echo assetUrl('css/cardify-tokens.css'); ?>?v=<?php echo $cardifyOverridesVersion; ?>">
    <link rel="stylesheet" href="<?php echo assetUrl('css/cardify-components.css'); ?>?v=<?php echo $cardifyOverridesVersion; ?>">
    <link rel="stylesheet" href="<?php echo assetUrl('css/cardify-overrides.css'); ?>?v=<?php echo $cardifyOverridesVersion; ?>"><?php /* Local fallback assets kept for offline use */ ?>
    <?php $cardifyBrandVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/cardify-brand-2026.css') ?: time(); ?>
    <link rel="stylesheet" href="<?php echo assetUrl('css/cardify-brand-2026.css'); ?>?v=<?php echo $cardifyBrandVersion; ?>"><?php /* Brand 2026: cyan remap + Sora display + view transitions. Loads last. */ ?>
    
    <!-- Alpine.js (self-hosted, pinned; was unpkg @3.x.x render-path dependency) -->
    <script defer src="/assets/js/alpine-3.15.12.min.js"></script>
    
    <style>
        /* Latin pages render in Plus Jakarta Sans (body) + Sora (display,
           set in cardify-brand-2026.css); Arabic pages render in IBM Plex
           Sans Arabic end-to-end. Applying the Arabic face via [dir="rtl"]
           so it wins over any tailwind or theme resets. */
        body { font-family: 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif; }
        html[dir="rtl"], html[dir="rtl"] body,
        html[dir="rtl"] h1, html[dir="rtl"] h2, html[dir="rtl"] h3,
        html[dir="rtl"] h4, html[dir="rtl"] h5, html[dir="rtl"] h6,
        html[dir="rtl"] p,  html[dir="rtl"] a,  html[dir="rtl"] span,
        html[dir="rtl"] button, html[dir="rtl"] input, html[dir="rtl"] select,
        html[dir="rtl"] textarea, html[dir="rtl"] label, html[dir="rtl"] li {
            font-family: 'IBM Plex Sans Arabic', 'Plus Jakarta Sans', ui-sans-serif, system-ui, sans-serif;
        }
        [x-cloak] { display: none !important; }
        .bg-blur { backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        
        /* Page Loader with CSS-only auto-hide fallback */
        .page-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            /* CSS-only auto-hide after 0.3s (fallback if JS fails) */
            animation: loaderAutoHide 0.3s ease-out 0.3s forwards;
        }
        /* Tenant-branded loader: logo in centre + spinner ring in primary
           colour. Falls back to default Cardify-blue gradient ring on the
           apex (no tenant context) so the marketing site keeps its look. */
        .page-loader-ring {
            position: relative;
            width: 120px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .page-loader-ring::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 4px solid rgba(15, 51, 84, 0.10);
            border-top-color: var(--cardify-loader-primary, #0f3354);
            animation: pageLoaderSpin 1s linear infinite;
        }
        .page-loader-ring img {
            max-width: 70px;
            max-height: 70px;
            object-fit: contain;
        }
        @keyframes pageLoaderSpin {
            to { transform: rotate(360deg); }
        }
        @keyframes loaderAutoHide {
            to {
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
            }
        }
        .page-loader.hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            animation: none; /* Stop CSS animation when JS hides it */
        }
        .page-loader-text {
            margin-top: 24px;
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
            letter-spacing: 0.5px;
        }
        .page-loader-brand {
            margin-top: 8px;
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, #009bc1 0%, #067a98 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        /* Content is visible by default - loader overlay covers it */
        .page-loader ~ * {
            transition: opacity 0.3s ease-out;
        }
    </style>
    <?php if ($enableThemeScript): ?>
    <script>
        if (localStorage.getItem('color-theme') === 'dark'
            || (!('color-theme' in localStorage)
                && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    <?php endif; ?>
    <?php if (!empty($extraHead)) echo $extraHead; ?>

    <!-- BHD-Group shared design layer (design.bhd.om).
         Cardify keeps its own brand tokens + Tailwind theme; design.bhd.om
         adds Cmd+K palette + skeleton loaders as a complementary layer. -->
    <link rel="stylesheet" href="https://design.bhd.om/cmdk.css" media="print" onload="this.onload=null;this.media='all'" />
    <link rel="stylesheet" href="https://design.bhd.om/skeleton.css" media="print" onload="this.onload=null;this.media='all'" />
    <noscript>
        <link rel="stylesheet" href="https://design.bhd.om/cmdk.css" />
        <link rel="stylesheet" href="https://design.bhd.om/skeleton.css" />
    </noscript>
    <script src="https://design.bhd.om/cmdk.js" defer></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        if (!window.BHDCmdK) return;
        var isAr = document.documentElement.getAttribute('dir') === 'rtl';
        // Resolve base path the same way PHP's getBasePath() does
        var base = (function () {
          var p = location.pathname.replace(/\/+$/, '');
          // strip /<tenant-slug>/admin/* prefix for slug-routed pages
          var m = p.match(/^\/[^/]+\/(admin|company)\b/);
          if (m) return p.slice(0, p.indexOf(m[1])).replace(/\/$/, '');
          return '';
        })();
        BHDCmdK.init({
          commands: [
            { id: 'home',          label: isAr ? 'الرئيسية' : 'Home',           hint: isAr ? 'تنقل' : 'Navigate',  action: function () { location.href = '/'; } },
            { id: 'dashboard',     label: isAr ? 'لوحة التحكم' : 'Dashboard',    hint: isAr ? 'تنقل' : 'Navigate', action: function () { location.href = base + '/admin/'; } },
            { id: 'employees',     label: isAr ? 'الموظفون' : 'Employees',       hint: isAr ? 'تنقل' : 'Navigate', action: function () { location.href = base + '/admin/employees.php'; } },
            { id: 'orders',        label: isAr ? 'طلبات الطباعة' : 'Print orders', hint: isAr ? 'تنقل' : 'Navigate', action: function () { location.href = base + '/admin/print_orders.php'; } },
            { id: 'analytics',     label: isAr ? 'التحليلات' : 'Analytics',       hint: isAr ? 'تنقل' : 'Navigate', action: function () { location.href = base + '/admin/analytics.php'; } },
            { id: 'live',          label: isAr ? 'التحليلات الحية' : 'Live analytics', hint: isAr ? 'تنقل' : 'Navigate', action: function () { location.href = base + '/admin/live-analytics.php'; } },
            { id: 'billing',       label: isAr ? 'الفواتير' : 'Billing',           hint: isAr ? 'تنقل' : 'Navigate', action: function () { location.href = base + '/admin/billing.php'; } },
            { id: 'settings',      label: isAr ? 'الإعدادات' : 'Settings',         hint: isAr ? 'تنقل' : 'Navigate', action: function () { location.href = base + '/admin/settings.php'; } },
            { id: 'pricing-page',  label: isAr ? 'الأسعار' : 'Pricing',            hint: isAr ? 'تنقل' : 'Navigate', action: function () { location.href = '/pricing'; } },
            { id: 'contact',       label: isAr ? 'تواصل' : 'Contact',              hint: isAr ? 'تنقل' : 'Navigate', action: function () { location.href = '/contact'; } },
            { id: 'theme-toggle',  label: isAr ? 'تبديل المظهر' : 'Toggle theme',  hint: isAr ? 'واجهة' : 'UI',     action: function () {
              var d = document.documentElement;
              var dark = d.classList.toggle('dark');
              try { localStorage.setItem('color-theme', dark ? 'dark' : 'light'); } catch (e) {}
            } }
          ]
        });
      });
    </script>
</head>
<body class="bg-gray-50 text-gray-900 antialiased<?php echo $cardifyIsMarketing ? ' cardify-brand' : ''; ?> <?php echo $bodyClass; ?>" <?php echo $bodyAttributes; ?>>
<style>
html { scroll-padding-top: 5rem; }
.cardify-skip-link { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); clip-path: inset(50%); white-space: nowrap; border: 0; }
.cardify-skip-link:focus { position: fixed; top: 0.5rem; inset-inline-start: 0.5rem; z-index: 10000; width: auto; height: auto; margin: 0; overflow: visible; clip: auto; clip-path: none; white-space: normal; padding: 0.75rem 1.25rem; background: #ffffff; color: #00718c; font-weight: 700; border-radius: 0.5rem; box-shadow: 0 4px 16px rgba(0,0,0,.25); outline: 3px solid #00718c; outline-offset: 2px; }
</style>
<a class="cardify-skip-link" href="#main-content"><?php echo (class_exists('I18n') && I18n::isRtl()) ? 'تخطَّ إلى المحتوى' : 'Skip to main content'; ?></a>
<?php if (defined('SHOW_STAGE_BANNER') && SHOW_STAGE_BANNER): /* Cat T action 468 staging banner */ ?>
<div style="position:fixed;top:0;left:0;right:0;z-index:9999;background:#fbbf24;color:#111827;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;padding:4px 12px;text-align:center;border-bottom:2px solid #b45309;">
  <strong>STAGING</strong> · <?= defined('APP_HOST') ? htmlspecialchars(APP_HOST) : 'stage' ?> · not production data · Paymob sandbox
</div>
<style>body { padding-top: 26px; }</style>
<?php endif; ?>
    <!-- Page Loader (auto-hides via CSS after 2.5s even without JS) -->
    <?php if (!empty($cardifyTenantTheme['logo'])): ?>
    <div class="page-loader" id="pageLoader" style="--cardify-loader-primary: <?= htmlspecialchars($cardifyTenantTheme['primary'] ?? '#0f3354', ENT_QUOTES) ?>;">
        <div class="page-loader-ring">
            <img src="<?= htmlspecialchars($cardifyTenantTheme['logo'], ENT_QUOTES) ?>" alt="<?= htmlspecialchars($brandName, ENT_QUOTES) ?>" onerror="this.style.display='none'">
        </div>
        <div class="page-loader-text">Loading...</div>
        <div class="page-loader-brand" style="background:linear-gradient(135deg, <?= htmlspecialchars($cardifyTenantTheme['primary'] ?? '#0f3354', ENT_QUOTES) ?> 0%, <?= htmlspecialchars($cardifyTenantTheme['secondary'] ?? '#c9a961', ENT_QUOTES) ?> 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;"><?php echo htmlspecialchars($brandName); ?></div>
    </div>
    <?php else: ?>
    <div class="page-loader" id="pageLoader">
        <img src="<?php echo getBasePath(); ?>assets/images/cardify-loader.svg" alt="Loading" width="100" height="100" onerror="this.style.display='none'">
        <div class="page-loader-text">Loading...</div>
        <div class="page-loader-brand"><?php echo $brandName; ?></div>
    </div>
    <?php endif; ?>
<?php
/**
 * Dynamic Navigation Component
 * Can be enabled by setting $showNavigation = true before including ui-header.php
 * 
 * Variables:
 * - $showNavigation: Enable/disable navigation (default: false)
 * - $navTransparent: Use transparent background (default: false)
 * - $navLinks: Custom navigation links array (default: standard links)
 */
if (!function_exists('renderNavigation')) {
    function renderNavigation($options = []) {
        $brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
        $transparent = $options['transparent'] ?? false;
        $customLinks = $options['links'] ?? null;
        
        // Check if Auth class exists and user is logged in
        $isLoggedIn = false;
        $currentUser = null;
        $userRole = null;
        $dashboardUrl = getBasePath() . 'admin/';
        
        if (class_exists('Auth')) {
            $isLoggedIn = Auth::isLoggedIn();
            if ($isLoggedIn) {
                $currentUser = Auth::getCurrentUser();
                $userRole = Auth::getCurrentRole();
                // Determine dashboard URL based on role
                if ($userRole === 'super_admin') {
                    $dashboardUrl = getBasePath() . 'admin/super/';
                } elseif ($userRole === 'employee') {
                    $dashboardUrl = getBasePath() . 'profile.php';
                } else {
                    $dashboardUrl = getBasePath() . 'admin/';
                }
            }
        }
        
        // Default navigation links (used on all non-homepage pages)
        $basePath = function_exists('getBasePath') ? getBasePath() : '/';
        $defaultLinks = [
            ['href' => $basePath . '#features', 'label' => function_exists('t') ? t('footer.link_features') : 'Features'],
            ['href' => $basePath . '#pricing', 'label' => function_exists('t') ? t('footer.link_pricing') : 'Pricing'],
            ['href' => $basePath . 'tools', 'label' => function_exists('t') ? t('footer.link_all_tools') : 'Free Tools'],
            ['href' => $basePath . 'app', 'label' => (class_exists('I18n') && I18n::getLocale() === 'ar') ? 'التطبيق' : 'Mobile App'],
            ['href' => $basePath . 'logos', 'label' => function_exists('t') ? t('footer.link_logos') : 'Logo Library'],
            ['href' => $basePath . 'oman-business-index', 'label' => function_exists('t') ? t('footer.link_oman_index') : 'Oman Business Index'],
            ['href' => $basePath . 'blog', 'label' => function_exists('t') ? t('footer.link_blog') : 'Blog'],
        ];
        
        $navLinks = $customLinks ?? $defaultLinks;
        $bgClass = $transparent ? 'bg-transparent' : 'bg-white/80 bg-blur border-b border-gray-100';
        $linkClass = $transparent ? 'text-white/90 hover:text-white' : 'text-gray-600 hover:text-blue-600';
        
        // Get user display name
        $userName = 'User';
        if ($currentUser) {
            $userName = $currentUser['name'] ?? $currentUser['email'] ?? 'User';
            // Get first name only
            $nameParts = explode(' ', $userName);
            $userName = $nameParts[0];
        }
        ?>
        <nav class="fixed top-0 left-0 right-0 z-50 <?php echo $bgClass; ?> transition-all duration-300" id="navbar">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center gap-6 h-16 lg:h-20">
                    <!-- Logo -->
                    <a href="<?php echo getBasePath(); ?>" class="flex items-center gap-3">
                        <img src="<?php echo assetUrl('images/logo.svg'); ?>" alt="<?php echo $brandName; ?>" class="h-10 w-auto">
                    </a>

                    <!-- Desktop Navigation -->
                    <div class="hidden lg:flex items-center gap-8">
                        <?php foreach ($navLinks as $link): ?>
                        <a href="<?php echo htmlspecialchars($link['href']); ?>" class="<?php echo $linkClass; ?> transition-colors font-medium"><?php echo htmlspecialchars($link['label']); ?></a>
                        <?php endforeach; ?>
                    </div>

                    <!-- CTA Buttons -->
                    <div class="flex items-center gap-3">
                        <?php include __DIR__ . '/currency-selector.php'; ?>
                        <?php if ($isLoggedIn): ?>
                            <!-- Logged In State -->
                            <span class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-gray-700 font-medium">
                                <i class="fa-solid fa-circle-user text-blue-600"></i>
                                <?= function_exists('t') ? htmlspecialchars(t('header.hello_user', ['name' => $userName])) : 'Hello, ' . htmlspecialchars($userName) ?>
                            </span>
                            <a href="<?php echo $dashboardUrl; ?>" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:shadow-blue-600/40">
                                <i class="fa-solid fa-gauge-high"></i>
                                <?= function_exists('t') ? htmlspecialchars(t('header.dashboard')) : 'Dashboard' ?>
                            </a>
                        <?php else: ?>
                            <!-- Logged Out State -->
                            <a href="<?php echo getBasePath(); ?>login.php" class="hidden sm:inline-flex items-center px-4 py-2 text-gray-700 hover:text-blue-600 font-medium transition-colors">
                                <?= function_exists('t') ? htmlspecialchars(t('header.sign_in')) : 'Sign In' ?>
                            </a>
                            <a href="<?php echo getBasePath(); ?>company/register.php" class="hidden sm:inline-flex items-center px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:shadow-blue-600/40">
                                <?= function_exists('t') ? htmlspecialchars(t('header.get_started_free')) : 'Get Started Free' ?>
                            </a>
                        <?php endif; ?>

                        <!-- Language Switcher (always visible, including mobile) -->
                        <span class="inline-flex">
                            <?php require INCLUDES_DIR . '/lang-switcher.php'; ?>
                        </span>

                        <!-- Mobile Menu Button -->
                        <button type="button" class="lg:hidden p-2 text-gray-600 hover:text-blue-600" id="mobile-menu-btn"
                                aria-label="<?= htmlspecialchars(t('common.menu_toggle')) ?>"
                                aria-expanded="false" aria-controls="mobile-menu">
                            <i class="fa-solid fa-bars text-xl" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile Menu -->
            <div class="lg:hidden hidden bg-white border-t border-gray-100 py-4" id="mobile-menu">
                <div class="max-w-7xl mx-auto px-4 space-y-3">
                    <?php foreach ($navLinks as $link): ?>
                    <a href="<?php echo htmlspecialchars($link['href']); ?>" class="block py-2 text-gray-600 hover:text-blue-600 font-medium"><?php echo htmlspecialchars($link['label']); ?></a>
                    <?php endforeach; ?>
                    <hr class="border-gray-200">
                    <?php if ($isLoggedIn): ?>
                        <div class="py-2 text-gray-700 font-medium flex items-center gap-2">
                            <i class="fa-solid fa-circle-user text-blue-600"></i>
                            <?= function_exists('t') ? htmlspecialchars(t('header.hello_user', ['name' => $userName])) : 'Hello, ' . htmlspecialchars($userName) ?>
                        </div>
                        <a href="<?php echo $dashboardUrl; ?>" class="block py-2 text-blue-600 hover:text-blue-700 font-medium">
                            <i class="fa-solid fa-gauge-high"></i>
                            <?= function_exists('t') ? htmlspecialchars(t('header.dashboard')) : 'Dashboard' ?>
                        </a>
                        <a href="<?php echo getBasePath(); ?>logout.php" class="block py-2 text-gray-600 hover:text-red-600 font-medium">
                            <i class="fa-solid fa-right-from-bracket"></i>
                            <?= function_exists('t') ? htmlspecialchars(t('auth.sign_out')) : 'Sign Out' ?>
                        </a>
                    <?php else: ?>
                        <a href="<?php echo getBasePath(); ?>login.php" class="block py-2 text-gray-600 hover:text-blue-600 font-medium">
                            <?= function_exists('t') ? htmlspecialchars(t('header.sign_in')) : 'Sign In' ?>
                        </a>
                        <a href="<?php echo getBasePath(); ?>company/register.php" class="block py-2 text-blue-600 hover:text-blue-700 font-medium">
                            <?= function_exists('t') ? htmlspecialchars(t('header.get_started_free')) : 'Get Started Free' ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        <?php
    }
}

// Auto-render navigation if $showNavigation is set to true
if (isset($showNavigation) && $showNavigation === true) {
    renderNavigation([
        'transparent' => $navTransparent ?? false,
        'links' => $navLinks ?? null
    ]);
}

echo '<div id="main-content" tabindex="-1"></div>';
?>
