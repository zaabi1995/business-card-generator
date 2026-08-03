<?php
/**
 * Cardify - Careers
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = t('careers.page_title');
$pageDescription = t('careers.page_desc');
$canonicalUrl = 'https://cardify.om/careers';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

// Fetch career listings from database
$db = Database::getInstance();
$jobs = [];
$singleJob = null;

// Check if viewing single job. Same slug guard as blog.php.
$jobSlug = $_GET['job'] ?? null;
if (is_string($jobSlug) && !preg_match('~^[a-z0-9_-]{1,120}$~i', $jobSlug)) {
    $jobSlug = null;
}

if ($db->tableExists('career_listings')) {
    if ($jobSlug) {
        // Single job view
        $singleJob = $db->fetchOne(
            "SELECT * FROM career_listings WHERE slug = ? AND status = 'open'",
            [$jobSlug]
        );
        if ($singleJob) {
            $pageTitle = t('careers.single_page_title', ['title' => $singleJob['title']]);
            $pageDescription = t('careers.single_page_desc', [
                'title'    => $singleJob['title'],
                'location' => $singleJob['location'] ?? 'Muscat',
            ]) . ' ' . substr(strip_tags($singleJob['description']), 0, 100);
            $canonicalUrl = 'https://cardify.om/careers/' . $singleJob['slug'];
        }
    }
    
    // Get all open listings
    $jobs = $db->fetchAll(
        "SELECT * FROM career_listings WHERE status = 'open' ORDER BY created_at DESC"
    );
}

require_once INCLUDES_DIR . '/ui-header.php';

$benefits = [
    ['icon' => 'fa-laptop-house',    'title' => t('careers.ben_flex_title'),   'desc' => t('careers.ben_flex_desc')],
    ['icon' => 'fa-graduation-cap',  'title' => t('careers.ben_learn_title'),  'desc' => t('careers.ben_learn_desc')],
    ['icon' => 'fa-heart-pulse',     'title' => t('careers.ben_health_title'), 'desc' => t('careers.ben_health_desc')],
    ['icon' => 'fa-umbrella-beach',  'title' => t('careers.ben_pto_title'),    'desc' => t('careers.ben_pto_desc')],
    ['icon' => 'fa-chart-line',      'title' => t('careers.ben_growth_title'), 'desc' => t('careers.ben_growth_desc')],
    ['icon' => 'fa-users',           'title' => t('careers.ben_events_title'), 'desc' => t('careers.ben_events_desc')],
];
?>

<div class="min-h-screen bg-gray-50">
    <?php if ($singleJob): ?>
    <!-- Single Job View -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <a href="<?php echo getBasePath(); ?>careers.php" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 mb-4">
                <i class="fa-solid fa-arrow-left"></i>
                <?= htmlspecialchars(t('careers.back_to_careers')) ?>
            </a>
            <h1 class="text-4xl font-bold text-gray-900 mb-4"><?php echo htmlspecialchars($singleJob['title']); ?></h1>
            <div class="flex flex-wrap gap-4 text-gray-500">
                <?php if ($singleJob['department']): ?>
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-building"></i>
                    <?php echo htmlspecialchars($singleJob['department']); ?>
                </span>
                <?php endif; ?>
                <?php if ($singleJob['location']): ?>
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-location-dot"></i>
                    <?php echo htmlspecialchars($singleJob['location']); ?>
                </span>
                <?php endif; ?>
                <?php if ($singleJob['employment_type']): ?>
                <span class="flex items-center gap-2">
                    <i class="fa-solid fa-clock"></i>
                    <?php echo ucfirst(str_replace('-', ' ', $singleJob['employment_type'])); ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12">
            <div class="prose prose-lg max-w-none">
                <h2 class="text-xl font-bold text-gray-900 mb-4"><?= htmlspecialchars(t('careers.job_description')) ?></h2>
                <p class="text-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($singleJob['description']); ?></p>

                <?php if ($singleJob['requirements']): ?>
                <h2 class="text-xl font-bold text-gray-900 mt-8 mb-4"><?= htmlspecialchars(t('careers.requirements')) ?></h2>
                <p class="text-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($singleJob['requirements']); ?></p>
                <?php endif; ?>

                <?php if ($singleJob['benefits']): ?>
                <h2 class="text-xl font-bold text-gray-900 mt-8 mb-4"><?= htmlspecialchars(t('careers.benefits')) ?></h2>
                <p class="text-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($singleJob['benefits']); ?></p>
                <?php endif; ?>

                <?php if ($singleJob['salary_range']): ?>
                <h2 class="text-xl font-bold text-gray-900 mt-8 mb-4"><?= htmlspecialchars(t('careers.compensation')) ?></h2>
                <p class="text-gray-600"><?php echo htmlspecialchars($singleJob['salary_range']); ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-8 pt-8 border-t border-gray-200">
                <h3 class="font-bold text-gray-900 mb-4"><?= htmlspecialchars(t('careers.ready_to_apply')) ?></h3>
                <a href="<?php echo getBasePath(); ?>contact.php?subject=<?= urlencode(t('careers.apply_subject', ['title' => $singleJob['title']])) ?>"
                   class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition-colors">
                    <i class="fa-solid fa-paper-plane"></i>
                    <?= htmlspecialchars(t('careers.apply_now')) ?>
                </a>
            </div>
        </div>

        <div class="mt-8 text-center">
            <a href="<?php echo getBasePath(); ?>careers.php" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                <?= htmlspecialchars(t('careers.back_to_careers')) ?>
            </a>
        </div>
    </div>

    <?php if (isset($singleJob) && $singleJob): ?>
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'JobPosting',
        'title' => $singleJob['title'],
        'description' => strip_tags($singleJob['description']),
        'datePosted' => $singleJob['created_at'],
        'employmentType' => strtoupper(str_replace('-', '_', $singleJob['employment_type'] ?? 'FULL_TIME')),
        'jobLocation' => [
            '@type' => 'Place',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $singleJob['location'] ?? 'Muscat',
                'addressCountry' => 'OM'
            ]
        ],
        'hiringOrganization' => [
            '@type' => 'Organization',
            'name' => 'Cardify',
            'sameAs' => 'https://cardify.om'
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
    </script>
    <?php endif; ?>

    <?php else: ?>
    <!-- Careers Listing -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3"><?= htmlspecialchars(t('careers.hero_h1')) ?></h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto">
                <?= htmlspecialchars(t('careers.hero_sub')) ?>
            </p>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        
        <!-- About Working Here -->
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 mb-12">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <span class="text-blue-600 font-semibold text-sm uppercase tracking-wider"><?= htmlspecialchars(t('careers.why_kicker', ['brand' => $brandName])) ?></span>
                    <h2 class="text-3xl font-bold text-gray-900 mt-2 mb-6"><?= htmlspecialchars(t('careers.why_h2')) ?></h2>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        <?= htmlspecialchars(t('careers.why_p1', ['brand' => $brandName])) ?>
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        <?= htmlspecialchars(t('careers.why_p2')) ?>
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <?php
                    $benefitIcons = array_slice($benefits, 0, 4); // use the already-translated first four
                    foreach ($benefitIcons as $benefit): ?>
                        <div class="bg-gray-50 rounded-xl p-4 text-center">
                            <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center mx-auto mb-3">
                                <i class="fa-solid <?php echo $benefit['icon']; ?> text-blue-600"></i>
                            </div>
                            <h3 class="font-semibold text-gray-900 text-sm mb-1"><?= htmlspecialchars($benefit['title']) ?></h3>
                            <p class="text-gray-500 text-xs"><?= htmlspecialchars($benefit['desc']) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Open Positions -->
        <div class="mb-12">
            <h2 class="text-2xl font-bold text-gray-900 mb-8"><?= htmlspecialchars(t('careers.open_positions')) ?></h2>

            <?php if (empty($jobs)): ?>
                <div class="bg-white rounded-xl shadow-sm p-12 text-center">
                    <div class="w-20 h-20 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-briefcase text-3xl text-gray-400"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t('careers.no_openings_title')) ?></h3>
                    <p class="text-gray-600 mb-6">
                        <?= htmlspecialchars(t('careers.no_openings_body')) ?>
                    </p>
                    <a href="<?php echo getBasePath(); ?>contact.php" class="text-blue-600 hover:text-blue-700 font-medium">
                        <?= htmlspecialchars(t('careers.send_resume_cta')) ?> <?= isRtl() ? '←' : '→' ?>
                    </a>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($jobs as $job): ?>
                        <div class="bg-white rounded-xl shadow-sm p-6 hover:shadow-md transition-shadow">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                                <div>
                                    <h3 class="text-lg font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($job['title']); ?></h3>
                                    <div class="flex flex-wrap gap-3 text-sm">
                                        <?php if ($job['department']): ?>
                                        <span class="flex items-center gap-1 text-gray-600">
                                            <i class="fa-solid fa-building text-gray-400"></i>
                                            <?php echo htmlspecialchars($job['department']); ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($job['location']): ?>
                                        <span class="flex items-center gap-1 text-gray-600">
                                            <i class="fa-solid fa-location-dot text-gray-400"></i>
                                            <?php echo htmlspecialchars($job['location']); ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($job['employment_type']): ?>
                                        <span class="flex items-center gap-1 text-gray-600">
                                            <i class="fa-solid fa-clock text-gray-400"></i>
                                            <?php echo ucfirst(str_replace('-', ' ', $job['employment_type'])); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-gray-600 mt-2 line-clamp-2"><?php echo htmlspecialchars($job['description']); ?></p>
                                </div>
                                <div class="flex-shrink-0 flex gap-2">
                                    <a href="<?php echo getBasePath(); ?>careers.php?job=<?php echo urlencode($job['slug']); ?>"
                                       class="inline-flex items-center gap-2 px-4 py-2 text-blue-600 bg-blue-50 font-medium rounded-lg hover:bg-blue-100 transition-colors">
                                        <?= htmlspecialchars(t('careers.view_details')) ?>
                                    </a>
                                    <a href="<?php echo getBasePath(); ?>contact.php?subject=<?= urlencode(t('careers.apply_subject', ['title' => $job['title']])) ?>"
                                       class="inline-flex items-center gap-2 px-6 py-2 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                                        <?= htmlspecialchars(t('careers.apply_short')) ?>
                                        <i class="fa-solid fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Don't See Your Role -->
        <div class="bg-gradient-to-br from-blue-600 to-purple-700 rounded-2xl p-8 lg:p-12 text-white text-center">
            <h2 class="text-2xl font-bold mb-4"><?= htmlspecialchars(t('careers.no_role_h2')) ?></h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto">
                <?= htmlspecialchars(t('careers.no_role_body')) ?>
            </p>
            <a href="<?php echo getBasePath(); ?>contact.php"
               class="inline-flex items-center gap-2 px-8 py-4 bg-white text-blue-600 font-semibold rounded-xl hover:bg-blue-50 transition-colors">
                <i class="fa-solid fa-paper-plane"></i>
                <?= htmlspecialchars(t('careers.get_in_touch')) ?>
            </a>
        </div>

        <!-- Back to Home -->
        <div class="mt-12 text-center">
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                <?= htmlspecialchars(t('careers.back_home')) ?>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
