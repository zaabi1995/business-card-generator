<?php
// Site-wide footer (skip on homepage which has its own footer)
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
if ($currentPage !== 'index'):
    $bp = function_exists('getBasePath') ? getBasePath() : '/';
    $bn = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
?>
    <footer class="bg-gray-900 text-white mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-8 mb-8">
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <img src="<?= $bp ?>assets/images/logo-light.svg" alt="<?= $bn ?>" class="h-8 w-auto" onerror="this.style.display='none'">
                    </div>
                    <p class="text-gray-400 text-sm leading-relaxed">Business cards for your whole team. Design once, generate for everyone, print from local Omani shops.</p>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Product</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>#features" class="text-gray-400 hover:text-white transition-colors">Features</a></li>
                        <li><a href="<?= $bp ?>#pricing" class="text-gray-400 hover:text-white transition-colors">Pricing</a></li>
                        <li><a href="<?= $bp ?>blog" class="text-gray-400 hover:text-white transition-colors">Blog</a></li>
                        <li><a href="<?= $bp ?>faq" class="text-gray-400 hover:text-white transition-colors">FAQ</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Company</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>about" class="text-gray-400 hover:text-white transition-colors">About</a></li>
                        <li><a href="<?= $bp ?>contact" class="text-gray-400 hover:text-white transition-colors">Contact</a></li>
                        <li><a href="<?= $bp ?>careers" class="text-gray-400 hover:text-white transition-colors">Careers</a></li>
                        <li><a href="<?= $bp ?>print-shops" class="text-gray-400 hover:text-white transition-colors">Print Shops</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Legal</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>privacy" class="text-gray-400 hover:text-white transition-colors">Privacy</a></li>
                        <li><a href="<?= $bp ?>terms" class="text-gray-400 hover:text-white transition-colors">Terms</a></li>
                        <li><a href="<?= $bp ?>cookies" class="text-gray-400 hover:text-white transition-colors">Cookies</a></li>
                        <li><a href="<?= $bp ?>security" class="text-gray-400 hover:text-white transition-colors">Security</a></li>
                    </ul>
                </div>
            </div>
            <div class="pt-6 border-t border-gray-800 flex flex-col sm:flex-row justify-between items-center gap-4 text-sm text-gray-500">
                <p>&copy; <?= date('Y') ?> <?= $bn ?>. All rights reserved.</p>
                <p>Made in Oman</p>
            </div>
        </div>
    </footer>
<?php endif; ?>

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
<script src="/assets/flowbite/app.bundle.js?v=<?php echo $flowbiteJsVersion; ?>"></script>
    
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
