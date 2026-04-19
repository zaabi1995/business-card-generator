<?php
// Site-wide footer (skip on homepage which has its own footer)
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$bp = function_exists('getBasePath') ? getBasePath() : '/';
$bn = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Minimal footer for auth/utility pages (login, signup, register, etc.)
// Opt-in via $minimalFooter = true; in the page before including this file.
if (!empty($minimalFooter)):
?>
    <footer class="border-t border-gray-200 bg-white mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5 flex flex-col sm:flex-row justify-between items-center gap-2 text-sm text-gray-500">
            <p>&copy; <?= date('Y') ?> <?= $bn ?>. Made in Oman.</p>
            <div class="flex items-center gap-5">
                <a href="<?= $bp ?>privacy" class="hover:text-gray-700">Privacy</a>
                <a href="<?= $bp ?>terms" class="hover:text-gray-700">Terms</a>
                <a href="<?= $bp ?>contact" class="hover:text-gray-700">Contact</a>
            </div>
        </div>
    </footer>
<?php elseif ($currentPage !== 'index'): ?>
    <footer class="bg-gray-900 text-white mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid md:grid-cols-2 lg:grid-cols-6 gap-8 mb-8">
                <div class="lg:col-span-1">
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
                    <h4 class="font-semibold mb-4">Free Tools</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>tools" class="text-gray-400 hover:text-white transition-colors">All Tools</a></li>
                        <li><a href="<?= $bp ?>tools/vcard-qr-generator" class="text-gray-400 hover:text-white transition-colors">vCard QR Generator</a></li>
                        <li><a href="<?= $bp ?>tools/email-signature-generator" class="text-gray-400 hover:text-white transition-colors">Email Signature</a></li>
                        <li><a href="<?= $bp ?>tools/whatsapp-qr-generator" class="text-gray-400 hover:text-white transition-colors">WhatsApp QR</a></li>
                        <li><a href="<?= $bp ?>tools/nfc-business-card-guide" class="text-gray-400 hover:text-white transition-colors">NFC Card Guide</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Directory &amp; Data</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>gcc-business-index" class="text-gray-400 hover:text-white transition-colors">GCC Business Index</a></li>
                        <li><a href="<?= $bp ?>oman-business-index" class="text-gray-400 hover:text-white transition-colors">Oman Business Index</a></li>
                        <li><a href="<?= $bp ?>companies" class="text-gray-400 hover:text-white transition-colors">Browse 2,414 Companies</a></li>
                        <li><a href="<?= $bp ?>logos" class="text-gray-400 hover:text-white transition-colors">Omani Logo Library</a></li>
                        <li><a href="<?= $bp ?>solutions" class="text-gray-400 hover:text-white transition-colors">All Solutions</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">For Industries</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>industries/banking" class="text-gray-400 hover:text-white transition-colors">Banking &amp; Finance</a></li>
                        <li><a href="<?= $bp ?>industries/oil-gas" class="text-gray-400 hover:text-white transition-colors">Oil &amp; Gas</a></li>
                        <li><a href="<?= $bp ?>industries/logistics" class="text-gray-400 hover:text-white transition-colors">Logistics</a></li>
                        <li><a href="<?= $bp ?>industries/government" class="text-gray-400 hover:text-white transition-colors">Government</a></li>
                        <li><a href="<?= $bp ?>industries/construction" class="text-gray-400 hover:text-white transition-colors">Construction</a></li>
                        <li><a href="<?= $bp ?>industries/real-estate" class="text-gray-400 hover:text-white transition-colors">Real Estate</a></li>
                        <li><a href="<?= $bp ?>industries/healthcare" class="text-gray-400 hover:text-white transition-colors">Healthcare</a></li>
                        <li><a href="<?= $bp ?>industries/tourism" class="text-gray-400 hover:text-white transition-colors">Tourism</a></li>
                        <li><a href="<?= $bp ?>industries/restaurants" class="text-gray-400 hover:text-white transition-colors">Restaurants</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-semibold mb-4">Company</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="<?= $bp ?>about" class="text-gray-400 hover:text-white transition-colors">About</a></li>
                        <li><a href="<?= $bp ?>contact" class="text-gray-400 hover:text-white transition-colors">Contact</a></li>
                        <li><a href="<?= $bp ?>careers" class="text-gray-400 hover:text-white transition-colors">Careers</a></li>
                        <li><a href="<?= $bp ?>press-kit" class="text-gray-400 hover:text-white transition-colors">Press &amp; Media</a></li>
                        <li><a href="<?= $bp ?>print-shops" class="text-gray-400 hover:text-white transition-colors">Print Shops</a></li>
                        <li><a href="<?= $bp ?>privacy" class="text-gray-400 hover:text-white transition-colors">Privacy</a></li>
                        <li><a href="<?= $bp ?>terms" class="text-gray-400 hover:text-white transition-colors">Terms</a></li>
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
