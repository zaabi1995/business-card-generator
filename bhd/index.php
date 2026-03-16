<?php
/**
 * BHD Printing Referral Landing Page
 * Dedicated page for BHD Printing customers at cardify.om/bhd
 * Tracks referral source for analytics
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

// Track BHD referral source
if (empty($_SESSION['referral_source'])) {
    $_SESSION['referral_source'] = 'bhd';
    $_SESSION['referral_landing'] = '/bhd';
    $_SESSION['referral_time'] = date('Y-m-d H:i:s');
}

$pageTitle = 'Digital Business Cards — Exclusive for BHD Printing Customers';
$pageDescription = 'Your trusted printer, BHD Printing, now offers digital business cards through Cardify. Design, share, and print professional cards for your whole team — starting free.';
$canonicalUrl = 'https://cardify.om/bhd';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$registerUrl = getBasePath() . 'company/register.php?ref=bhd';
$loginUrl    = getBasePath() . 'login.php';

$showNavigation = false; // Custom nav for this landing page

$extraHead = '<style>
    .bhd-gradient { background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #1e40af 100%); }
    .card-float { animation: cardFloat 5s ease-in-out infinite; }
    @keyframes cardFloat {
        0%, 100% { transform: translateY(0) rotate(-2deg); }
        50%       { transform: translateY(-14px) rotate(1deg); }
    }
    .card-float-2 { animation: cardFloat 5s ease-in-out infinite; animation-delay: -2.5s; }
    .step-line::after {
        content: "";
        position: absolute;
        left: 50%;
        top: 100%;
        width: 2px;
        height: 40px;
        background: #dbeafe;
        transform: translateX(-50%);
    }
    .step-line:last-child::after { display: none; }
</style>';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<!-- ===== NAV ===== -->
<nav class="fixed top-0 inset-x-0 z-50 bg-white/95 backdrop-blur border-b border-gray-100 shadow-sm">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
            <span class="hidden sm:inline text-gray-300">×</span>
            <span class="hidden sm:inline text-sm font-semibold text-gray-600">BHD Printing Partner</span>
        </div>
        <div class="flex items-center gap-3">
            <a href="<?= $loginUrl ?>" class="text-sm text-gray-500 hover:text-gray-800 transition-colors">Sign in</a>
            <a href="<?= $registerUrl ?>" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition-colors shadow-sm">
                Get Started Free
            </a>
        </div>
    </div>
</nav>

<!-- ===== HERO ===== -->
<section class="bhd-gradient pt-16 pb-20 px-4 overflow-hidden relative">
    <!-- Decorative circles -->
    <div class="absolute top-0 right-0 w-96 h-96 bg-blue-500/10 rounded-full -translate-y-1/2 translate-x-1/3 pointer-events-none"></div>
    <div class="absolute bottom-0 left-0 w-64 h-64 bg-blue-700/10 rounded-full translate-y-1/2 -translate-x-1/4 pointer-events-none"></div>

    <div class="max-w-6xl mx-auto grid lg:grid-cols-2 gap-12 items-center py-12">
        <!-- Left: Copy -->
        <div class="text-white text-center lg:text-left">
            <!-- Badge -->
            <div class="inline-flex items-center gap-2 bg-blue-500/20 border border-blue-400/30 rounded-full px-4 py-1.5 mb-6">
                <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                <span class="text-blue-200 text-sm font-medium">Exclusive offer for BHD Printing customers</span>
            </div>

            <h1 class="text-4xl sm:text-5xl font-extrabold leading-tight mb-5">
                Your Printer Now Offers<br>
                <span class="text-blue-300">Digital Business Cards</span>
            </h1>

            <p class="text-lg text-blue-100 max-w-lg mx-auto lg:mx-0 mb-8 leading-relaxed">
                BHD Printing has partnered with Cardify so you can design, share, and reorder your business cards — all in one place. Free to get started.
            </p>

            <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                <a href="<?= $registerUrl ?>" class="inline-flex items-center justify-center gap-2 bg-white hover:bg-blue-50 text-blue-700 font-bold px-7 py-4 rounded-xl shadow-lg transition-all hover:-translate-y-0.5 text-lg">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Start Free — No Card Required
                </a>
                <a href="#how-it-works" class="inline-flex items-center justify-center gap-2 bg-white/10 hover:bg-white/20 text-white font-semibold px-7 py-4 rounded-xl border border-white/20 transition-all text-lg">
                    See How It Works
                </a>
            </div>

            <!-- Trust badges -->
            <div class="flex flex-wrap gap-4 mt-8 justify-center lg:justify-start text-blue-200 text-sm">
                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-green-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    Free forever for small teams
                </span>
                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-green-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    Print from BHD Printing directly
                </span>
                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-green-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                    Made in Oman 🇴🇲
                </span>
            </div>
        </div>

        <!-- Right: Business card mockups -->
        <div class="hidden lg:flex items-center justify-center relative h-80">
            <!-- Card 1 -->
            <div class="card-float absolute left-4 top-4 w-64 bg-white rounded-xl shadow-2xl p-5 z-10">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center text-white font-bold text-sm">AZ</div>
                    <div>
                        <div class="font-bold text-gray-900 text-sm">Ali Al Zaabi</div>
                        <div class="text-gray-500 text-xs">Managing Director</div>
                    </div>
                </div>
                <div class="h-px bg-gray-100 mb-3"></div>
                <div class="text-xs text-gray-500 space-y-1">
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3 h-3 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        ali@bhd.om
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3 h-3 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        +968 9XXX XXXX
                    </div>
                </div>
                <div class="mt-3 bg-blue-600 text-white text-xs text-center py-1.5 rounded-lg font-semibold">BHD Group</div>
            </div>
            <!-- Card 2 -->
            <div class="card-float-2 absolute right-4 bottom-4 w-64 bg-gradient-to-br from-gray-900 to-gray-800 rounded-xl shadow-2xl p-5 z-0">
                <div class="flex items-center gap-3 mb-3">
                    <div class="w-10 h-10 bg-amber-400 rounded-full flex items-center justify-center text-gray-900 font-bold text-sm">SK</div>
                    <div>
                        <div class="font-bold text-white text-sm">Sara Al Kindi</div>
                        <div class="text-gray-400 text-xs">Sales Manager</div>
                    </div>
                </div>
                <div class="h-px bg-gray-700 mb-3"></div>
                <div class="text-xs text-gray-400 space-y-1">
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3 h-3 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        sara@company.om
                    </div>
                    <div class="flex items-center gap-1.5">
                        <svg class="w-3 h-3 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/></svg>
                        company.om
                    </div>
                </div>
                <div class="mt-3 bg-amber-400 text-gray-900 text-xs text-center py-1.5 rounded-lg font-semibold">My Company LLC</div>
            </div>
        </div>
    </div>
</section>

<!-- ===== WHY CARDIFY (for BHD customers) ===== -->
<section class="bg-white py-16 px-4">
    <div class="max-w-6xl mx-auto">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-gray-900 mb-3">Everything You Need, All in One Place</h2>
            <p class="text-gray-500 text-lg max-w-xl mx-auto">BHD Printing customers get the full Cardify experience — design, digital sharing, and physical prints from your trusted printer.</p>
        </div>

        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php
            $features = [
                [
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"/>',
                    'color' => 'blue',
                    'title' => 'Professional Templates',
                    'desc' => 'Start from polished, customizable card designs. Add your logo, colors, and contact info in minutes.',
                ],
                [
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>',
                    'color' => 'violet',
                    'title' => 'Cards for the Whole Team',
                    'desc' => 'Design once, generate personalized cards for every employee automatically. Free for up to 10 people.',
                ],
                [
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>',
                    'color' => 'green',
                    'title' => 'Digital Card & QR Code',
                    'desc' => 'Share your card digitally via link or QR code. Recipients tap to save your contact — no app needed.',
                ],
                [
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>',
                    'color' => 'amber',
                    'title' => 'Order Prints from BHD',
                    'desc' => 'When you\'re ready, order high-quality physical cards straight from BHD Printing — delivered to your door.',
                ],
                [
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
                    'color' => 'red',
                    'title' => 'Always Up to Date',
                    'desc' => 'Change your phone number or title? Update once, every card and digital link reflects it instantly.',
                ],
                [
                    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>',
                    'color' => 'teal',
                    'title' => 'Track Who Scans Your Card',
                    'desc' => 'See when and where people view your card. Understand which networking events drive results.',
                ],
            ];

            $colorMap = [
                'blue'   => ['bg' => 'bg-blue-50',   'icon' => 'text-blue-600'],
                'violet' => ['bg' => 'bg-violet-50', 'icon' => 'text-violet-600'],
                'green'  => ['bg' => 'bg-green-50',  'icon' => 'text-green-600'],
                'amber'  => ['bg' => 'bg-amber-50',  'icon' => 'text-amber-600'],
                'red'    => ['bg' => 'bg-red-50',    'icon' => 'text-red-600'],
                'teal'   => ['bg' => 'bg-teal-50',   'icon' => 'text-teal-600'],
            ];

            foreach ($features as $f):
                $c = $colorMap[$f['color']];
            ?>
            <div class="bg-gray-50 rounded-2xl p-6 hover:shadow-md transition-shadow">
                <div class="w-12 h-12 <?= $c['bg'] ?> rounded-xl flex items-center justify-center mb-4">
                    <svg class="w-6 h-6 <?= $c['icon'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?= $f['icon'] ?>
                    </svg>
                </div>
                <h3 class="font-bold text-gray-900 mb-2"><?= $f['title'] ?></h3>
                <p class="text-gray-500 text-sm leading-relaxed"><?= $f['desc'] ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===== HOW IT WORKS ===== -->
<section id="how-it-works" class="bg-blue-50 py-16 px-4">
    <div class="max-w-4xl mx-auto">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-gray-900 mb-3">Ready in 3 Steps</h2>
            <p class="text-gray-500">From signup to printed cards in your hands — faster than you think.</p>
        </div>

        <div class="space-y-6">
            <?php
            $steps = [
                ['num' => '1', 'title' => 'Create your free account', 'desc' => 'Sign up in 30 seconds. No credit card, no commitment.', 'color' => 'blue'],
                ['num' => '2', 'title' => 'Design your card', 'desc' => 'Pick a template, add your logo and details, and generate cards for your whole team.', 'color' => 'violet'],
                ['num' => '3', 'title' => 'Order prints from BHD Printing', 'desc' => 'Click "Order Prints" and your cards are sent straight to BHD Printing — your trusted printer handles the rest.', 'color' => 'green'],
            ];
            $stepColors = [
                'blue'   => ['badge' => 'bg-blue-600',   'border' => 'border-blue-200'],
                'violet' => ['badge' => 'bg-violet-600', 'border' => 'border-violet-200'],
                'green'  => ['badge' => 'bg-green-600',  'border' => 'border-green-200'],
            ];
            foreach ($steps as $s):
                $sc = $stepColors[$s['color']];
            ?>
            <div class="flex gap-5 items-start bg-white rounded-2xl p-6 shadow-sm border <?= $sc['border'] ?>">
                <div class="flex-shrink-0 w-10 h-10 <?= $sc['badge'] ?> text-white font-bold rounded-full flex items-center justify-center text-lg"><?= $s['num'] ?></div>
                <div>
                    <h3 class="font-bold text-gray-900 text-lg mb-1"><?= $s['title'] ?></h3>
                    <p class="text-gray-500"><?= $s['desc'] ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===== SAMPLE CARDS ===== -->
<section class="bg-white py-16 px-4">
    <div class="max-w-6xl mx-auto">
        <div class="text-center mb-10">
            <h2 class="text-3xl font-bold text-gray-900 mb-3">Sample Card Designs</h2>
            <p class="text-gray-500">Professional templates ready to customize with your branding.</p>
        </div>

        <!-- Card previews (illustrative) -->
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-10">
            <?php
            $sampleCards = [
                ['bg' => 'bg-white', 'text' => 'text-gray-900', 'sub' => 'text-gray-500', 'accent' => 'bg-blue-600', 'accentText' => 'text-white', 'border' => 'border border-gray-200', 'label' => 'Classic Light'],
                ['bg' => 'bg-gray-900', 'text' => 'text-white', 'sub' => 'text-gray-400', 'accent' => 'bg-amber-400', 'accentText' => 'text-gray-900', 'border' => '', 'label' => 'Executive Dark'],
                ['bg' => 'bg-gradient-to-br from-blue-600 to-blue-800', 'text' => 'text-white', 'sub' => 'text-blue-200', 'accent' => 'bg-white', 'accentText' => 'text-blue-700', 'border' => '', 'label' => 'Bold Blue'],
            ];
            foreach ($sampleCards as $i => $card):
            ?>
            <div class="rounded-2xl overflow-hidden shadow-lg hover:shadow-xl transition-shadow">
                <div class="<?= $card['bg'] ?> <?= $card['border'] ?> p-6 aspect-[1.75/1] flex flex-col justify-between">
                    <div class="flex justify-between items-start">
                        <div>
                            <div class="<?= $card['text'] ?> font-bold text-lg">Ahmad Al Busaidi</div>
                            <div class="<?= $card['sub'] ?> text-sm">General Manager</div>
                        </div>
                        <div class="w-10 h-10 <?= $card['accent'] ?> rounded-lg flex items-center justify-center <?= $card['accentText'] ?> font-bold text-sm">AB</div>
                    </div>
                    <div class="<?= $card['sub'] ?> text-xs space-y-0.5">
                        <div>ahmad@mycompany.om</div>
                        <div>+968 9XXX XXXX</div>
                        <div>mycompany.om</div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-2 text-center">
                    <span class="text-gray-600 text-xs font-medium"><?= $card['label'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center">
            <a href="<?= $registerUrl ?>" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-bold px-8 py-4 rounded-xl shadow-lg shadow-blue-600/25 transition-all hover:-translate-y-0.5 text-lg">
                Design Your Card — It's Free
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </a>
            <p class="text-gray-400 text-sm mt-3">Free for up to 10 employees · No credit card required</p>
        </div>
    </div>
</section>

<!-- ===== PRICING SNAPSHOT ===== -->
<section class="bg-gray-50 py-16 px-4">
    <div class="max-w-5xl mx-auto">
        <div class="text-center mb-10">
            <h2 class="text-3xl font-bold text-gray-900 mb-3">Simple Pricing</h2>
            <p class="text-gray-500">Start free. Upgrade only when you need more.</p>
        </div>

        <div class="grid md:grid-cols-3 gap-6">
            <?php
            $plans = [
                [
                    'name' => 'Free',
                    'price' => '0 OMR',
                    'period' => 'forever',
                    'highlight' => false,
                    'features' => ['Up to 10 employees', 'Digital cards & QR codes', 'Print ordering via BHD', 'Basic templates'],
                    'cta' => 'Get Started Free',
                    'ctaUrl' => $registerUrl,
                ],
                [
                    'name' => 'Business',
                    'price' => '15 OMR',
                    'period' => '/ month',
                    'highlight' => true,
                    'features' => ['Unlimited employees', 'Custom branding & templates', 'Analytics dashboard', 'Priority print support', 'Team management'],
                    'cta' => 'Start Free Trial',
                    'ctaUrl' => $registerUrl,
                ],
                [
                    'name' => 'Enterprise',
                    'price' => 'Custom',
                    'period' => '',
                    'highlight' => false,
                    'features' => ['Everything in Business', 'API access', 'Dedicated support', 'Custom integrations', 'SLA guarantee'],
                    'cta' => 'Contact Us',
                    'ctaUrl' => getBasePath() . 'contact',
                ],
            ];
            foreach ($plans as $plan):
            ?>
            <div class="rounded-2xl p-6 <?= $plan['highlight'] ? 'bg-blue-600 text-white shadow-xl shadow-blue-600/25 scale-105' : 'bg-white border border-gray-200' ?>">
                <div class="mb-4">
                    <div class="font-bold text-lg <?= $plan['highlight'] ? 'text-blue-100' : 'text-gray-500' ?>"><?= $plan['name'] ?></div>
                    <div class="text-3xl font-extrabold mt-1 <?= $plan['highlight'] ? 'text-white' : 'text-gray-900' ?>"><?= $plan['price'] ?> <span class="text-base font-normal <?= $plan['highlight'] ? 'text-blue-200' : 'text-gray-400' ?>"><?= $plan['period'] ?></span></div>
                </div>
                <ul class="space-y-2 mb-6">
                    <?php foreach ($plan['features'] as $f): ?>
                    <li class="flex items-center gap-2 text-sm <?= $plan['highlight'] ? 'text-blue-100' : 'text-gray-600' ?>">
                        <svg class="w-4 h-4 flex-shrink-0 <?= $plan['highlight'] ? 'text-blue-200' : 'text-green-500' ?>" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        <?= $f ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <a href="<?= $plan['ctaUrl'] ?>" class="block text-center font-semibold px-5 py-3 rounded-xl transition-all <?= $plan['highlight'] ? 'bg-white text-blue-600 hover:bg-blue-50' : 'bg-gray-100 text-gray-800 hover:bg-gray-200' ?>">
                    <?= $plan['cta'] ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ===== CTA BANNER ===== -->
<section class="bhd-gradient py-16 px-4">
    <div class="max-w-3xl mx-auto text-center text-white">
        <h2 class="text-3xl font-bold mb-4">Ready to upgrade your business cards?</h2>
        <p class="text-blue-200 text-lg mb-8">Join hundreds of Omani businesses using Cardify. Your team deserves professional cards — digital and printed.</p>
        <a href="<?= $registerUrl ?>" class="inline-flex items-center gap-2 bg-white hover:bg-blue-50 text-blue-700 font-bold px-8 py-4 rounded-xl shadow-lg transition-all hover:-translate-y-0.5 text-lg">
            Create Your Free Account
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        </a>
        <p class="text-blue-300 text-sm mt-4">Partnered with BHD Printing — Oman's trusted print shop</p>
    </div>
</section>

<!-- ===== MINI FOOTER ===== -->
<footer class="bg-gray-900 text-gray-400 py-8 px-4">
    <div class="max-w-6xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <img src="<?= getBasePath() ?>assets/images/logo-light.svg" alt="Cardify" class="h-7 w-auto" onerror="this.style.display='none'">
            <span class="text-sm">© <?= date('Y') ?> Cardify. All rights reserved.</span>
        </div>
        <div class="flex gap-6 text-sm">
            <a href="<?= getBasePath() ?>privacy" class="hover:text-white transition-colors">Privacy</a>
            <a href="<?= getBasePath() ?>terms" class="hover:text-white transition-colors">Terms</a>
            <a href="<?= getBasePath() ?>contact" class="hover:text-white transition-colors">Contact</a>
            <a href="<?= getBasePath() ?>" class="hover:text-white transition-colors">Cardify.om</a>
        </div>
    </div>
</footer>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
