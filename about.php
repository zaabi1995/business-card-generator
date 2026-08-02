<?php
/**
 * Cardify - About Us
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/PlatformStats.php';

// Real, DB-derived figures. Until 28 Jul 2026 these four tiles were
// hardcoded marketing round-numbers that overstated the truth by up to
// two orders of magnitude, and they had been indexed. Never hardcode a
// public number on this page again: read it from the database.
$stats = PlatformStats::all();

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = t('about.page_title');
$pageDescription = t('about.page_desc');
$canonicalUrl = 'https://cardify.om/about';

// Enable dynamic navigation
$showNavigation = true;

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Hero Section -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('about.hero_h1', ['brand' => $brandName])) ?></h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto">
                <?= htmlspecialchars(t('about.hero_sub')) ?>
            </p>
        </div>
    </div>

    <!-- Our Story -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="grid lg:grid-cols-2 gap-12 items-center mb-20">
            <div>
                <span class="text-blue-600 font-semibold text-sm uppercase tracking-wider"><?= htmlspecialchars(t('about.story_kicker')) ?></span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2 mb-6"><?= htmlspecialchars(t('about.story_h2')) ?></h2>
                <p class="text-gray-600 leading-relaxed mb-4">
                    <?= htmlspecialchars(t('about.story_p1', ['brand' => $brandName])) ?>
                </p>
                <p class="text-gray-600 leading-relaxed mb-4">
                    <?= htmlspecialchars(t('about.story_p2')) ?>
                </p>
                <p class="text-gray-600 leading-relaxed">
                    <?= htmlspecialchars(t('about.story_p3')) ?>
                </p>
            </div>
            <div class="relative">
                <div class="bg-gradient-to-br from-blue-100 to-blue-50 rounded-2xl p-8">
                    <div class="grid grid-cols-2 gap-6 text-center">
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <div class="text-3xl font-bold text-blue-600 mb-2"><?= number_format($stats['directory']) ?></div>
                            <div class="text-gray-500 text-sm"><?= htmlspecialchars(t('about.stats_directory')) ?></div>
                        </div>
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <div class="text-3xl font-bold text-blue-600 mb-2"><?= number_format($stats['issuing']) ?></div>
                            <div class="text-gray-500 text-sm"><?= htmlspecialchars(t('about.stats_companies')) ?></div>
                        </div>
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <div class="text-3xl font-bold text-blue-600 mb-2"><?= number_format($stats['cards']) ?></div>
                            <div class="text-gray-500 text-sm"><?= htmlspecialchars(t('about.stats_cards')) ?></div>
                        </div>
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <div class="text-3xl font-bold text-blue-600 mb-2"><?= number_format($stats['events']) ?></div>
                            <div class="text-gray-500 text-sm"><?= htmlspecialchars(t('about.stats_scans')) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Connect Section -->
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 mb-16">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <span class="text-blue-600 font-semibold text-sm uppercase tracking-wider"><?= htmlspecialchars(t('about.connect_kicker')) ?></span>
                    <h2 class="text-3xl font-bold text-gray-900 mt-2 mb-6"><?= htmlspecialchars(t('about.connect_h2')) ?></h2>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        <?= htmlspecialchars(t('about.connect_p1', ['brand' => $brandName])) ?>
                    </p>
                    <p class="text-gray-600 leading-relaxed mb-6">
                        <?= htmlspecialchars(t('about.connect_p2')) ?>
                    </p>
                    <div class="flex flex-wrap gap-4">
                        <a href="https://instagram.com/cardifyom" target="_blank" rel="noopener noreferrer"
                           class="inline-flex items-center gap-2 px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors">
                            <i class="fa-brands fa-instagram"></i>
                            <?= htmlspecialchars(t('about.follow_instagram')) ?>
                        </a>
                    </div>
                </div>
                <div class="bg-gray-50 rounded-xl p-8">
                    <div class="flex items-center gap-4 mb-6">
                        <div class="w-16 h-16 rounded-xl bg-gradient-to-br from-blue-600 to-blue-700 flex items-center justify-center">
                            <img src="<?php echo getBasePath(); ?>assets/images/logo.svg" alt="<?= htmlspecialchars($brandName) ?>" class="h-10 w-auto">
                        </div>
                        <div>
                            <h3 class="font-bold text-xl text-gray-900"><?= htmlspecialchars($brandName) ?></h3>
                            <p class="text-gray-500"><?= htmlspecialchars(t('about.card_subtitle')) ?></p>
                        </div>
                    </div>
                    <ul class="space-y-3 text-gray-600">
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-location-dot text-blue-600"></i>
                            <span><?= htmlspecialchars(t('about.based_muscat')) ?></span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-envelope text-blue-600"></i>
                            <a href="<?php echo getBasePath(); ?>contact.php" class="text-blue-600 hover:underline"><?= htmlspecialchars(t('about.contact_us')) ?></a>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-globe text-blue-600"></i>
                            <span>cardify.om</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Our Values -->
        <div class="mb-16">
            <div class="text-center mb-12">
                <span class="text-blue-600 font-semibold text-sm uppercase tracking-wider"><?= htmlspecialchars(t('about.values_kicker')) ?></span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2"><?= htmlspecialchars(t('about.values_h2')) ?></h2>
            </div>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-lightbulb text-2xl text-blue-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('about.values_innovation_title')) ?></h3>
                    <p class="text-gray-600">
                        <?= htmlspecialchars(t('about.values_innovation_body')) ?>
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-leaf text-2xl text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('about.values_sustainability_title')) ?></h3>
                    <p class="text-gray-600">
                        <?= htmlspecialchars(t('about.values_sustainability_body')) ?>
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-handshake text-2xl text-blue-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('about.values_trust_title')) ?></h3>
                    <p class="text-gray-600">
                        <?= htmlspecialchars(t('about.values_trust_body')) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- CTA -->
        <div class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl p-8 lg:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4"><?= htmlspecialchars(t('about.cta_h2')) ?></h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto">
                <?= htmlspecialchars(t('about.cta_body')) ?>
            </p>
            <a href="<?php echo getBasePath(); ?>company/register.php"
               class="inline-flex items-center gap-2 px-8 py-4 bg-white text-blue-600 font-semibold rounded-xl hover:bg-blue-50 transition-colors">
                <?= htmlspecialchars(t('about.cta_button')) ?>
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <!-- Back to Home -->
        <div class="mt-8 text-center">
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                <?= htmlspecialchars(t('about.back_home')) ?>
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
