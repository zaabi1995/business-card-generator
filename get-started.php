<?php
/**
 * Cardify - Get Started Landing Page (Google Ads / Paid Traffic)
 * Optimized for conversions with minimal distractions
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Get Started — Free Business Cards for Your Omani Company';
$pageDescription = 'Create professional digital and printed business cards for your team in minutes. Free for up to 5 employees. Start free, upgrade anytime.';
$canonicalUrl = 'https://cardify.om/get-started';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = false; // Minimal nav for landing page
require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-white">
    <!-- Minimal Header -->
    <div class="bg-white border-b border-gray-100 py-4 px-4">
        <div class="max-w-5xl mx-auto flex items-center justify-between">
            <a href="<?= getBasePath() ?>" class="flex items-center gap-2">
                <img src="<?= getBasePath() ?>assets/images/logo.svg" alt="Cardify" class="h-8 w-auto">
            </a>
            <a href="<?= getBasePath() ?>login.php" class="text-sm text-gray-500 hover:text-gray-700">Already have an account? Sign in</a>
        </div>
    </div>

    <!-- Hero Section -->
    <div class="bg-gradient-to-br from-blue-600 via-blue-700 to-blue-900 text-white py-20 px-4">
        <div class="max-w-5xl mx-auto text-center">
            <div class="inline-flex items-center gap-2 bg-blue-500/30 rounded-full px-4 py-1.5 mb-6 text-sm">
                <i class="fa-solid fa-star text-yellow-300"></i>
                <span>Start free — no credit card needed</span>
            </div>
            <h1 class="text-4xl md:text-5xl lg:text-6xl font-bold mb-6 leading-tight">
                Professional Business Cards<br>
                <span class="text-blue-200">in Minutes, Not Days</span>
            </h1>
            <p class="text-xl text-blue-100 max-w-2xl mx-auto mb-10">
                Create stunning digital and printed business cards for your entire team.
                Free for up to 5 employees. No design skills needed.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?= getBasePath() ?>login.php?action=register"
                   class="bg-white text-blue-700 font-bold px-10 py-4 rounded-xl text-lg hover:bg-blue-50 transition-all shadow-lg hover:shadow-xl">
                    Create Free Account
                    <i class="fa-solid fa-arrow-right ml-2"></i>
                </a>
                <a href="<?= getBasePath() ?>intro"
                   class="border-2 border-white/30 text-white font-semibold px-10 py-4 rounded-xl text-lg hover:bg-white/10 transition-all">
                    See How It Works
                </a>
            </div>
            <p class="mt-4 text-blue-200 text-sm">No credit card required. Setup in 2 minutes.</p>
        </div>
    </div>

    <!-- Trust strip -->
    <div class="bg-gray-50 py-8 px-4 border-b border-gray-100">
        <div class="max-w-5xl mx-auto flex flex-wrap items-center justify-center gap-8 md:gap-16 text-center text-gray-700">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                <span class="font-semibold">Built for Oman</span>
            </div>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <span class="font-semibold">Paymob verified</span>
            </div>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <span class="font-semibold">Free forever</span>
            </div>
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                <span class="font-semibold">Print partners ready</span>
            </div>
        </div>
    </div>

    <!-- How It Works -->
    <div class="py-20 px-4">
        <div class="max-w-5xl mx-auto">
            <h2 class="text-3xl font-bold text-center text-gray-900 mb-4">How It Works</h2>
            <p class="text-center text-gray-600 mb-12 max-w-2xl mx-auto">Three simple steps to professional business cards for your entire team.</p>

            <div class="grid md:grid-cols-3 gap-8">
                <div class="text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-blue-600">1</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Sign Up Free</h3>
                    <p class="text-gray-600">Create your company account in seconds. No credit card needed. Free for up to 5 employees.</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-blue-600">2</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Design Your Cards</h3>
                    <p class="text-gray-600">Choose a template, upload your logo, add employee details. Our drag-and-drop editor makes it easy.</p>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <span class="text-2xl font-bold text-blue-600">3</span>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Share or Print</h3>
                    <p class="text-gray-600">Share digital cards instantly via QR code or WhatsApp. Order prints from local Omani print shops.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Features Grid -->
    <div class="bg-gray-50 py-20 px-4">
        <div class="max-w-5xl mx-auto">
            <h2 class="text-3xl font-bold text-center text-gray-900 mb-12">Everything You Need</h2>

            <div class="grid md:grid-cols-2 gap-6">
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-mobile-screen text-blue-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">Digital Cards</h3>
                        <p class="text-gray-600 text-sm">Interactive digital cards with QR codes, NFC sharing, and click-to-call buttons.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-print text-green-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">Print Orders</h3>
                        <p class="text-gray-600 text-sm">Order premium printed cards from verified print shops across Oman. Delivered to your door.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-users text-purple-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">Team Management</h3>
                        <p class="text-gray-600 text-sm">Bulk generate cards for your entire team. Consistent branding across all employees.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-amber-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-qrcode text-amber-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">QR Code Sharing</h3>
                        <p class="text-gray-600 text-sm">Every card gets a unique QR code. Scan to save contacts instantly. Track scan analytics.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-brands fa-whatsapp text-red-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">WhatsApp Sharing</h3>
                        <p class="text-gray-600 text-sm">Share your digital card via WhatsApp with one tap. Perfect for Oman's WhatsApp-first market.</p>
                    </div>
                </div>
                <div class="bg-white rounded-xl p-6 flex gap-4">
                    <div class="w-12 h-12 bg-teal-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i class="fa-solid fa-language text-teal-600 text-xl"></i>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-900 mb-1">Bilingual Support</h3>
                        <p class="text-gray-600 text-sm">Create cards in English and Arabic. Full RTL support for Arabic text and layouts.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CTA Section -->
    <div class="py-20 px-4">
        <div class="max-w-3xl mx-auto text-center">
            <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">
                Start Creating Professional Business Cards Today
            </h2>
            <p class="text-lg text-gray-600 mb-8">
                Free for up to 5 employees. No credit card required. Takes 2 minutes to set up.
            </p>
            <a href="<?= getBasePath() ?>login.php?action=register"
               class="inline-block bg-blue-600 text-white font-bold px-12 py-4 rounded-xl text-lg hover:bg-blue-700 transition-all shadow-lg hover:shadow-xl">
                Create Free Account
                <i class="fa-solid fa-arrow-right ml-2"></i>
            </a>
            <p class="mt-6 text-gray-500 text-sm">
                Questions? <a href="<?= getBasePath() ?>contact" class="text-blue-600 hover:underline">Contact us</a>
                or read our <a href="<?= getBasePath() ?>faq" class="text-blue-600 hover:underline">FAQ</a>
            </p>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
