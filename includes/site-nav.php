<?php
/**
 * The one Cardify site header (renderNavigation).
 *
 * ui-header.php requires this file. Pages that print their own <head>
 * (intro.php, claim.php) require it too and call:
 *     cardifySiteNavHead();   inside <head>, before the page's own <style>
 *     renderNavigation();     first thing in <body>
 * so every public page shows the same header (4 Oct 2026).
 */

if (!function_exists('cardifySiteNavHead')) {
    function cardifySiteNavHead(): void
    {
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $v = static fn(string $f): string => (string) (@filemtime($root . $f) ?: 1);
        echo '<link rel="preconnect" href="https://fonts.bhd.om" crossorigin>' . "\n";
        echo '<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=Sora:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap">' . "\n";
        echo '<link rel="stylesheet" href="https://fonts.bhd.om/css/NotoSansArabic.css">' . "\n";
        foreach (['fontawesome', 'solid', 'regular', 'brands'] as $fa) {
            echo '<link rel="stylesheet" href="https://design.bhd.om/fa/v7.2.0/css/' . $fa . '.min.css?v=7.2.0">' . "\n";
        }
        foreach (['/assets/techwind/css/tailwind.min.css', '/assets/css/cardify-tailwind-supplement.css',
                  '/assets/css/cardify-tokens.css', '/assets/css/cardify-components.css', '/assets/css/cardify-overrides.css'] as $css) {
            echo '<link rel="stylesheet" href="' . $css . '?v=' . $v($css) . '">' . "\n";
        }
    }
}

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
        
        // Default navigation links (used on all non-homepage pages).
        //
        // r79: these were `$basePath . '<slug>'`, and getBasePath() derives the
        // app root from SCRIPT_NAME, which is locale-blind. That is llm78-1
        // exactly, one region over: every Arabic page's nav carried Arabic
        // labels pointing at the ENGLISH tree (6 leaks per page, twice over,
        // because the desktop and mobile menus render the same array). The
        // footer was fixed in r78 and the nav was never measured.
        //
        // ArTwins::navLink() is the same one rule the footers use, so a third
        // copy of this markup cannot carry a fourth copy of the rule. It also
        // refuses to invent a prefix: /blog has no Arabic twin and stays
        // English rather than becoming a manufactured 301.
        require_once __DIR__ . '/ArTwins.php';
        $basePath = function_exists('getBasePath') ? getBasePath() : '/';
        $isAr = ArTwins::servingArabic();
        $nav = static fn(string $slug): string => ArTwins::navLink($slug, $basePath, $isAr);
        $defaultLinks = [
            ['href' => $nav('#features'), 'label' => function_exists('t') ? t('footer.link_features') : 'Features'],
            ['href' => $nav('#pricing'), 'label' => function_exists('t') ? t('footer.link_pricing') : 'Pricing'],
            ['href' => $nav('tools'), 'label' => function_exists('t') ? t('footer.link_all_tools') : 'Free Tools'],
            ['href' => $nav('app'), 'label' => (class_exists('I18n') && I18n::getLocale() === 'ar') ? 'التطبيق' : 'Mobile App'],
            // 'more' links sit in the desktop "More" menu: seven links in one row
            // wrapped to three lines at every desktop width (measured 4 Oct 2026).
            ['href' => $nav('logos'), 'label' => function_exists('t') ? t('footer.link_logos') : 'Logo Library', 'more' => true],
            ['href' => $nav('oman-business-index'), 'label' => function_exists('t') ? t('footer.link_oman_index') : 'Oman Business Index', 'more' => true],
            ['href' => $nav('blog'), 'label' => function_exists('t') ? t('footer.link_blog') : 'Blog'],
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
                    <a href="<?php echo htmlspecialchars($nav('')); ?>" class="flex items-center gap-3 shrink-0">
                        <img src="<?php echo assetUrl('images/logo.svg'); ?>" alt="<?php echo $brandName; ?>" class="h-10 w-auto">
                    </a>

                    <!-- Desktop Navigation: from 1280px only, one line, never wraps -->
                    <?php
                    $primaryLinks = array_values(array_filter($navLinks, fn($l) => empty($l['more'])));
                    $moreLinks    = array_values(array_filter($navLinks, fn($l) => !empty($l['more'])));
                    $moreLabel    = function_exists('t') && t('header.more') !== 'header.more' ? t('header.more') : 'More';
                    ?>
                    <div class="cardify-nav-desktop items-center gap-5 min-w-0">
                        <?php foreach ($primaryLinks as $link): ?>
                        <a href="<?php echo htmlspecialchars($link['href']); ?>" class="<?php echo $linkClass; ?> transition-colors font-medium whitespace-nowrap"><?php echo htmlspecialchars($link['label']); ?></a>
                        <?php endforeach; ?>
                        <?php if ($moreLinks): ?>
                        <details class="cardify-nav-more relative">
                            <summary class="<?php echo $linkClass; ?> transition-colors font-medium whitespace-nowrap cursor-pointer list-none inline-flex items-center gap-1.5">
                                <?php echo htmlspecialchars($moreLabel); ?> <i class="fa-solid fa-chevron-down text-xs" aria-hidden="true"></i>
                            </summary>
                            <div class="cardify-nav-more-panel bg-white border border-gray-100 rounded-xl shadow-lg py-2">
                                <?php foreach ($moreLinks as $link): ?>
                                <a href="<?php echo htmlspecialchars($link['href']); ?>" class="block px-4 py-2.5 text-gray-700 hover:bg-gray-50 hover:text-blue-600 font-medium whitespace-nowrap"><?php echo htmlspecialchars($link['label']); ?></a>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                    </div>

                    <!-- CTA Buttons -->
                    <div class="flex items-center gap-3 shrink-0 whitespace-nowrap">
                        <?php /* Phones: the currency picker lives in the menu, so the bar
                                 holds only logo, language and menu. */ ?>
                        <div class="hidden sm:block"><?php include __DIR__ . '/currency-selector.php'; ?></div>
                        <?php if ($isLoggedIn): ?>
                            <!-- Logged In State -->
                            <span class="cardify-nav-hello items-center gap-2 px-4 py-2 text-gray-700 font-medium">
                                <i class="fa-solid fa-circle-user text-blue-600"></i>
                                <?= function_exists('t') ? htmlspecialchars(t('header.hello_user', ['name' => $userName])) : 'Hello, ' . htmlspecialchars($userName) ?>
                            </span>
                            <a href="<?php echo $dashboardUrl; ?>" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:shadow-blue-600/40">
                                <i class="fa-solid fa-gauge-high"></i>
                                <?= function_exists('t') ? htmlspecialchars(t('header.dashboard')) : 'Dashboard' ?>
                            </a>
                        <?php else: ?>
                            <!-- Logged Out State -->
                            <a href="<?php echo getBasePath(); ?>login" class="hidden sm:inline-flex items-center px-4 py-2 text-gray-700 hover:text-blue-600 font-medium transition-colors">
                                <?= function_exists('t') ? htmlspecialchars(t('header.sign_in')) : 'Sign In' ?>
                            </a>
                            <a href="<?php echo htmlspecialchars($GLOBALS['navCtaHref'] ?? (getBasePath() . 'company/register-otp.php')); ?>" class="hidden sm:inline-flex items-center px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition-all hover:shadow-xl hover:shadow-blue-600/40">
                                <?= function_exists('t') ? htmlspecialchars(t('header.get_started_free')) : 'Get Started Free' ?>
                            </a>
                        <?php endif; ?>

                        <!-- Language Switcher (always visible, including mobile) -->
                        <span class="inline-flex">
                            <?php require INCLUDES_DIR . '/lang-switcher.php'; ?>
                        </span>

                        <!-- Mobile Menu Button -->
                        <button type="button" class="cardify-nav-phone p-2 text-gray-600 hover:text-blue-600" id="mobile-menu-btn"
                                aria-label="<?= htmlspecialchars(t('common.menu_toggle')) ?>"
                                aria-expanded="false" aria-controls="mobile-menu">
                            <i class="fa-solid fa-bars text-xl" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile Menu -->
            <div class="cardify-nav-phone hidden bg-white border-t border-gray-100 py-4" id="mobile-menu">
                <div class="max-w-7xl mx-auto px-4 space-y-3">
                    <?php foreach ($navLinks as $link): ?>
                    <a href="<?php echo htmlspecialchars($link['href']); ?>" class="block py-2 text-gray-600 hover:text-blue-600 font-medium"><?php echo htmlspecialchars($link['label']); ?></a>
                    <?php endforeach; ?>
                    <div class="sm:hidden flex items-center justify-between gap-3 py-1">
                        <span class="text-gray-600 font-medium"><?= htmlspecialchars(t('currency.aria_select') !== 'currency.aria_select' ? t('currency.aria_select') : 'Currency') ?></span>
                        <?php include __DIR__ . '/currency-selector.php'; ?>
                    </div>
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
                        <a href="<?php echo getBasePath(); ?>login" class="block py-2 text-gray-600 hover:text-blue-600 font-medium">
                            <?= function_exists('t') ? htmlspecialchars(t('header.sign_in')) : 'Sign In' ?>
                        </a>
                        <a href="<?php echo htmlspecialchars($GLOBALS['navCtaHref'] ?? (getBasePath() . 'company/register-otp.php')); ?>" class="block py-2 text-blue-600 hover:text-blue-700 font-medium">
                            <?= function_exists('t') ? htmlspecialchars(t('header.get_started_free')) : 'Get Started Free' ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        <style>
        /* Prebuilt Tailwind has no xl: utilities, so the breakpoint lives here. */
        .cardify-nav-desktop{display:none}
        @media (min-width:1280px){.cardify-nav-desktop{display:flex}.cardify-nav-phone{display:none!important}}
        .cardify-nav-hello{display:none}
        @media (min-width:1536px){.cardify-nav-hello{display:inline-flex}}
        .cardify-nav-more summary::-webkit-details-marker{display:none}
        .cardify-nav-more[open] summary i{transform:rotate(180deg)}
        .cardify-nav-more-panel{position:absolute;top:100%;margin-top:.75rem;inset-inline-end:0;min-width:14rem;z-index:60}
        </style>
        <script<?= function_exists('cspNonceAttr') ? cspNonceAttr() : '' ?>>
        // One menu script for every page. Before 4 Oct 2026 only index.php had
        // one, so the phone menu button did nothing anywhere else.
        (function () {
            var btn = document.getElementById('mobile-menu-btn');
            var menu = document.getElementById('mobile-menu');
            if (btn && menu && !btn.dataset.bound) {
                btn.dataset.bound = '1';
                btn.addEventListener('click', function () {
                    var open = menu.classList.toggle('hidden') === false;
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
            }
            document.addEventListener('click', function (e) {
                document.querySelectorAll('.cardify-nav-more[open]').forEach(function (d) {
                    if (!d.contains(e.target)) d.removeAttribute('open');
                });
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') document.querySelectorAll('.cardify-nav-more[open]').forEach(function (d) { d.removeAttribute('open'); });
            });
        })();
        </script>
        <?php
    }
}
