<?php
// Site-wide footer (skip on homepage which has its own footer)
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$bp = function_exists('getBasePath') ? getBasePath() : '/';
$bn = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// r6-95: freshness. Both the visible line and the schema dateModified come
// from the mtime of the file that produced this page, never from today.
require_once __DIR__ . '/Freshness.php';
$freshIso     = Freshness::isoDate();
$freshDisplay = Freshness::displayDate();

// Pages that own their footer entirely (e.g. branded company portals) can
// set $skipFooter = true; before including this file. Scripts below still run.
if (!empty($skipFooter)):
    // intentionally render nothing here
elseif (!empty($minimalFooter)):
?>
    <footer class="border-t border-gray-200 bg-white mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 flex flex-col sm:flex-row justify-between items-center gap-2 text-sm text-gray-500">
            <p><?= htmlspecialchars(t('footer.minimal_copyright', ['year' => date('Y'), 'brand' => $bn])) ?><?php if ($freshIso): ?> <span class="text-gray-400"><?= htmlspecialchars(t('footer.last_updated', ['date' => $freshDisplay])) ?></span><?php endif; ?></p>
            <div class="flex items-center gap-5">
                <a href="<?= $bp ?>privacy" class="hover:text-gray-700"><?= htmlspecialchars(t('footer.minimal_privacy')) ?></a>
                <a href="<?= $bp ?>terms" class="hover:text-gray-700"><?= htmlspecialchars(t('footer.minimal_terms')) ?></a>
                <a href="<?= $bp ?>contact" class="hover:text-gray-700"><?= htmlspecialchars(t('footer.minimal_contact')) ?></a>
            </div>
        </div>
    </footer>
<?php else: /* every page that does not own its footer ($skipFooter) or opt into $minimalFooter gets the full footer, including sub-directory index.php pages like /industries */ ?>
    <footer class="bg-gray-900 text-white mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid md:grid-cols-2 lg:grid-cols-6 gap-8 mb-8">
                <div class="lg:col-span-1">
                    <div class="flex items-center gap-3 mb-4">
                        <img src="<?= $bp ?>assets/images/logo-light.svg" alt="<?= $bn ?>" class="h-8 w-auto" onerror="this.style.display='none'">
                    </div>
                    <p class="text-gray-400 text-sm leading-relaxed"><?= htmlspecialchars(t('footer.tagline')) ?></p>
                </div>
                <div>
                    <h3 class="font-semibold mb-4"><?= htmlspecialchars(t('footer.col_product')) ?></h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>#features" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_features')) ?></a></li>
                        <li><a href="<?= $bp ?>#pricing" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_pricing')) ?></a></li>
                        <li><a href="<?= $bp ?>blog" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_blog')) ?></a></li>
                        <li><a href="<?= $bp ?>faq" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_faq')) ?></a></li>
                        <li><a href="<?= $bp ?>app" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_app')) ?></a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-semibold mb-4"><?= htmlspecialchars(t('footer.col_free_tools')) ?></h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>tools" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_all_tools')) ?></a></li>
                        <li><a href="<?= $bp ?>tools/vcard-qr-generator" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_vcard_qr')) ?></a></li>
                        <li><a href="<?= $bp ?>tools/email-signature-generator" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_email_sig')) ?></a></li>
                        <li><a href="<?= $bp ?>tools/whatsapp-qr-generator" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_whatsapp_qr')) ?></a></li>
                        <li><a href="<?= $bp ?>tools/nfc-business-card-guide" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_nfc_guide')) ?></a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-semibold mb-4"><?= htmlspecialchars(t('footer.col_directory')) ?></h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>gcc-business-index" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_gcc_index')) ?></a></li>
                        <li><a href="<?= $bp ?>oman-business-index" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_oman_index')) ?></a></li>
                        <li><a href="<?= $bp ?>companies" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_browse_companies')) ?></a></li>
                        <li><a href="<?= $bp ?>logos" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_logos')) ?></a></li>
                        <li><a href="<?= $bp ?>solutions" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_solutions')) ?></a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-semibold mb-4"><?= htmlspecialchars(t('footer.col_industries')) ?></h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>industries/banking" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_banking')) ?></a></li>
                        <li><a href="<?= $bp ?>industries/oil-gas" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_oil')) ?></a></li>
                        <li><a href="<?= $bp ?>industries/logistics" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_logistics')) ?></a></li>
                        <li><a href="<?= $bp ?>industries/government" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_gov')) ?></a></li>
                        <li><a href="<?= $bp ?>industries/construction" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_construction')) ?></a></li>
                        <li><a href="<?= $bp ?>industries/real-estate" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_realestate')) ?></a></li>
                        <li><a href="<?= $bp ?>industries/healthcare" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_healthcare')) ?></a></li>
                        <li><a href="<?= $bp ?>industries/tourism" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_tourism')) ?></a></li>
                        <li><a href="<?= $bp ?>industries/restaurants" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_ind_restaurants')) ?></a></li>
                    </ul>
                </div>
                <div>
                    <h3 class="font-semibold mb-4"><?= htmlspecialchars(t('footer.col_company')) ?></h3>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>about" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_about')) ?></a></li>
                        <li><a href="<?= $bp ?>contact" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_contact')) ?></a></li>
                        <li><a href="<?= $bp ?>careers" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_careers')) ?></a></li>
                        <li><a href="<?= $bp ?>press-kit" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_press')) ?></a></li>
                        <li><a href="<?= $bp ?>print-shops" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_print_shops')) ?></a></li>
                        <li><a href="<?= $bp ?>privacy" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_privacy')) ?></a></li>
                        <li><a href="<?= $bp ?>terms" class="text-gray-400 hover:text-white transition-colors"><?= htmlspecialchars(t('footer.link_terms')) ?></a></li>
                    </ul>
                </div>
            </div>
            <?php
            // r6-50: the entity statement, visible and extractable, in the
            // language the page is rendered in. A disambiguation that lives
            // only in JSON-LD answers a crawler and not a reader, and half the
            // models that got this wrong were reading rendered text.
            require_once __DIR__ . '/Seo.php';
            ?>
            <p class="pt-6 border-t border-gray-800 text-xs text-gray-500 leading-relaxed"><?= htmlspecialchars(Seo::groupDisambiguation()) ?></p>
            <div class="pt-6 flex flex-col sm:flex-row justify-between items-center gap-4 text-sm text-gray-500">
                <p><?= htmlspecialchars(t('footer.copyright', ['year' => date('Y'), 'brand' => $bn])) ?></p>
                <p><?= htmlspecialchars(t('footer.made_oman')) ?></p>
                <?php if ($freshIso): ?>
                <p><time datetime="<?= htmlspecialchars($freshIso) ?>"><?= htmlspecialchars(t('footer.last_updated', ['date' => $freshDisplay])) ?></time></p>
                <?php endif; ?>
            </div>
        </div>
    </footer>
<?php endif; ?>

<?php
// r6-95: the machine-readable half of the same fact. A visible line a parser
// cannot bind to a URL is not a freshness signal, so the date is emitted on a
// WebPage node keyed to this page's own canonical.
if (empty($skipFooter) && $freshIso) {
    $__canon = $GLOBALS['canonicalUrl'] ?? ('https://cardify.om' . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
    // r20-22: the WebPage node carried no inLanguage, so /ar/#webpage was
    // indistinguishable from its English twin to anything reading the graph
    // rather than the <html lang> attribute. Derived from the same ArTwins
    // path test the header uses for canonical + hreflang, so the three can
    // never disagree about which side of the pair this page is.
    require_once __DIR__ . '/ArTwins.php';
    echo '<script type="application/ld+json">' . json_encode([
        '@context'     => 'https://schema.org',
        '@type'        => 'WebPage',
        '@id'          => $__canon . '#webpage',
        'url'          => $__canon,
        'inLanguage'   => ArTwins::isArabic($__canon) ? 'ar' : 'en',
        'dateModified' => $freshIso,
        'isPartOf'     => ['@id' => 'https://cardify.om/#website'],
        'publisher'    => ['@id' => 'https://cardify.om/#organization'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
}
?>

<?php
// Breadcrumb JSON-LD (all pages except homepage).
// Blog single-post pages emit their own breadcrumb in blog.php (with post title),
// so skip them here to avoid duplicate / truncated breadcrumbs.
if ($currentPage !== 'index' && !($currentPage === 'blog' && isset($singlePost))) {
    $breadcrumbs = [['name' => 'Home', 'url' => 'https://cardify.om/']];

    $pageTitles = [
        'about' => 'About', 'blog' => 'Blog', 'contact' => 'Contact', 'careers' => 'Careers',
        'faq' => 'FAQ', 'terms' => 'Terms', 'privacy' => 'Privacy', 'security' => 'Security',
        'cookies' => 'Cookies', 'get-started' => 'Get Started', 'print-shops' => 'Print Shops',
    ];

    if (isset($pageTitles[$currentPage])) {
        $breadcrumbs[] = ['name' => $pageTitles[$currentPage], 'url' => 'https://cardify.om/' . $currentPage];
    }

    if (count($breadcrumbs) > 1) {
        $bcItems = [];
        foreach ($breadcrumbs as $i => $bc) {
            $bcItems[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $bc['name'],
                'item' => $bc['url']
            ];
        }
        echo '<script type="application/ld+json">' . json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $bcItems
        ], JSON_UNESCAPED_SLASHES) . '</script>';
    }
}
?>

    <!-- Flowbite JS (CDN) -->
<?php $flowbiteJsVersion = @filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/flowbite/app.bundle.js') ?: time(); ?>
<script defer src="/assets/flowbite/app.bundle.js?v=<?php echo $flowbiteJsVersion; ?>"></script>
    
    <!-- Page Loader Script (JS enhancement - CSS handles fallback) -->
    <script>
        (function() {
            var loader = document.getElementById('pageLoader');
            var minLoadTime = 200; // Minimum 0.2 seconds for smooth UX
            var startTime = Date.now();
            
            function hideLoader() {
                var elapsed = Date.now() - startTime;
                var remaining = Math.max(0, minLoadTime - elapsed);
                
                setTimeout(function() {
                    if (loader) {
                        loader.classList.add('hidden');
                        document.body.classList.add('loaded');
                    }
                }, remaining);
            }
            
            // Hide loader when everything is loaded
            if (document.readyState === 'complete') {
                hideLoader();
            } else {
                window.addEventListener('load', hideLoader);
            }
        })();
    </script>
    
    <!-- Common Scripts -->
    <script>
        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href !== '#') {
                    e.preventDefault();
                    const target = document.querySelector(href);
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        });
    </script>
    <?php if (!empty($extraScripts)) echo $extraScripts; ?>
</body>
</html>
