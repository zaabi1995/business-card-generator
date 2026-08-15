<?php
/**
 * Cardify - Careers
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
// llm75-1: career_listings is a bilingual record since migration 151. An
// untranslated listing is refused on /ar/ rather than printed in English under
// <html lang="ar">, which is what this page did (one job, 141 Latin prose
// letters, zero Arabic, on a page whose MEAN Arabic share was 0.841).
require_once INCLUDES_DIR . '/BilingualRecord.php';

// llm77-1: every URL this page emitted was English, in every locale. The
// canonical below was a hardcoded https://cardify.om/careers..., so
// /ar/careers?job=<slug> (a real Arabic page, lang=ar, dir=rtl, Arabic title,
// 0.947 Arabic letter share) served rel=canonical pointing at the ENGLISH job
// URL and hreflang="en" + x-default on itself, i.e. it told Google the Arabic
// page WAS the English one. That is verbatim the defect ArTwins.php was
// written to kill on /ar/pricing, reappearing on a page that never got the
// treatment. ui-header.php's repair block could not save it: that block only
// rewrites a canonical when ArTwins::normalise($canonical) equals the served
// path, and a HUB path serving a CHILD canonical fails that guard by design.
// Locale-aware base, the same shape blog.php ($blogBase) and companies.php
// ($basePrefix) already use.
$lang    = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr    = $lang === 'ar';
$baseUrl = 'https://cardify.om';
// Relative prefix for on-page links. Every link on this page pointed at
// getBasePath() . '<file>.php', so the only job on the ARABIC careers page
// linked to /careers.php?job=..., walking the reader out of Arabic on the
// single highest-intent click the page has, and the Apply button did the same
// to /contact.php while /ar/contact is a declared, live twin.
$linkBase    = $isAr ? '/ar' : '';
$careersBase = $linkBase . '/careers';

/**
 * The URL a job listing is served at in this locale.
 * EN: /careers/<slug>, the pretty rule .htaccess:120 already serves and the
 *     canonical this page already claimed, but which NOTHING linked to and no
 *     sitemap listed, so it was an orphan.
 * AR: /ar/careers?job=<slug>. The pretty /ar/careers/<slug> 404s (no ^ar/careers
 *     child rule in either rewrite layer) and is deliberately NOT invented here:
 *     ArTwins lists /careers in PATHS but not in AR_SUBTREES, so no channel
 *     claims an Arabic child URL, and a canonical must name a URL that resolves.
 */
$jobUrl = static function (string $slug, bool $ar) use ($baseUrl): string {
    return $ar
        ? $baseUrl . '/ar/careers?job=' . rawurlencode($slug)
        : $baseUrl . '/careers/' . rawurlencode($slug);
};

$pageTitle = t('careers.page_title');
$pageDescription = t('careers.page_desc');
$canonicalUrl = $baseUrl . $careersBase;
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

        // r97 / bhd-group-seo-llm91-1. An unknown slug used to fall straight
        // through to the HUB: /careers/<anything> answered 200 with the
        // careers listing and rel=canonical https://cardify.om/careers, so
        // .htaccess:120's pretty child rule handed every invented suffix a
        // 200 and the crawlable set under /careers was unbounded. blog.php:98
        // has answered this shape correctly since it was written; this is the
        // same three lines, and the only reason careers did not have them is
        // that nobody asked careers the question.
        //
        // The test is the RAW row, deliberately, and it is asked before
        // BilingualRecord::row() resolves anything: a REAL job with no Arabic
        // twin resolves to null further down and renders the page's own "no
        // such job" body on /ar/, which is a decision r-earlier made on
        // purpose (a half-Arabic job ad is worse than none). 404ing that would
        // be a different change to a different question. Only a slug NO open
        // listing carries in ANY locale is a path that is not a page.
        if (!$singleJob) {
            http_response_code(404);
            header('Cache-Control: no-store');
            include __DIR__ . '/404.php';
            exit;
        }
        // A single-job URL for an untranslated listing must not render half in
        // Arabic and half in English; on /ar/ it resolves to null and the page
        // falls through to its own "no such job" branch, exactly as it does for
        // a closed job. SELECT * already carries the _ar columns.
        $jobTwinFields    = ['title', 'description'];
        $jobTwinOptional  = ['requirements', 'benefits', 'location', 'department', 'salary_range'];
        // Asked BEFORE row() resolves the columns in place, and asked of the
        // ARABIC twin regardless of the locale being served, because the
        // ENGLISH page has to know whether it may name an Arabic URL.
        $jobHasArTwin = $singleJob
            ? BilingualRecord::hasTwin($singleJob, $jobTwinFields, 'ar', $jobTwinOptional)
            : false;
        if ($singleJob) {
            $singleJob = BilingualRecord::row(
                $singleJob,
                $jobTwinFields,
                'career_listings',
                null,
                $jobTwinOptional
            );
        }
        if ($singleJob) {
            // r254 / bhd-r6-95 #67. Same shape as blog.php one file over, and
            // it cost one live URL: /careers/full-stack-developer published
            // 2026-08-12 (the render closure) against its own sitemap leg of
            // 2026-08-05 (career_listings.updated_at, sitemap.php:497). The row
            // that renders the route is the row that dates it.
            $jobLastMod = strtotime((string) ($singleJob['updated_at']
                ?? $singleJob['created_at'] ?? ''));
            if ($jobLastMod) {
                $GLOBALS['pageContentDate'] = $jobLastMod;
            }
            $pageTitle = t('careers.single_page_title', ['title' => $singleJob['title']]);
            $pageDescription = t('careers.single_page_desc', [
                'title'    => $singleJob['title'],
                'location' => $singleJob['location'] ?? 'Muscat',
            ]) . ' ' . substr(strip_tags($singleJob['description']), 0, 100);
            $canonicalUrl = $jobUrl($singleJob['slug'], $isAr);

            // The pair is emitted only when BOTH sides exist. A listing with no
            // Arabic twin never renders on /ar/ at all (BilingualRecord refuses
            // it), so an ar leg would be an hreflang aimed at a page that says
            // "no such job", the fabricated pair Seo.php warns about. Without
            // one, ui-header.php's default set gives en + x-default on this URL,
            // which is the honest "English only" signal.
            if ($jobHasArTwin) {
                $enJob = $jobUrl($singleJob['slug'], false);
                $arJob = $jobUrl($singleJob['slug'], true);
                $suppressDefaultHreflang = true;
                $extraHead = ($extraHead ?? '')
                    . '<link rel="alternate" hreflang="en" href="' . htmlspecialchars($enJob, ENT_QUOTES) . '">'
                    . '<link rel="alternate" hreflang="ar" href="' . htmlspecialchars($arJob, ENT_QUOTES) . '">'
                    . '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($enJob, ENT_QUOTES) . '">';
            }
        }
    }
    
    // Get all open listings. On a non-default locale, listings without a
    // complete twin drop out and the page renders its existing empty state
    // ("no open roles right now" + the speculative-application CTA), which is
    // true copy in Arabic rather than an English job ad on an Arabic page.
    $jobs = BilingualRecord::rows(
        $db->fetchAll("SELECT * FROM career_listings WHERE status = 'open' ORDER BY created_at DESC"),
        ['title', 'description'],
        'career_listings',
        null,
        ['requirements', 'benefits', 'location', 'department', 'salary_range']
    );
}

require_once INCLUDES_DIR . '/ui-header.php';

// llm75-1: employment_type is an ENUM, so its chip was rendered by
// ucfirst(str_replace('-',' ')) and printed "Full time" in every locale. It is
// the one displayed job field that is a fixed vocabulary rather than prose, so
// it belongs in the lang files, not in a twinned column. Unknown values (a new
// ENUM member added before its keys) fall back to the old formatting rather
// than printing a raw key at a reader.
$jobType = static function ($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    $key   = 'careers.type_' . $value;
    $label = t($key);
    return ($label === $key) ? ucfirst(str_replace('-', ' ', $value)) : $label;
};

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
            <a href="<?php echo htmlspecialchars($careersBase); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 mb-4">
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
                    <?php echo htmlspecialchars($jobType($singleJob['employment_type'])); ?>
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
                <a href="<?php echo htmlspecialchars($linkBase); ?>/contact?subject=<?= urlencode(t('careers.apply_subject', ['title' => $singleJob['title']])) ?>"
                   class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition-colors">
                    <i class="fa-solid fa-paper-plane"></i>
                    <?= htmlspecialchars(t('careers.apply_now')) ?>
                </a>
            </div>
        </div>

        <div class="mt-8 text-center">
            <a href="<?php echo htmlspecialchars($careersBase); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
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
        // r154 / llm153-2: a reference; the owner body is emitted by
        // ui-header. This slot asserted a company with a sameAs and no @id.
        'hiringOrganization' => ['@id' => 'https://cardify.om/#organization']
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
                    <a href="<?php echo htmlspecialchars($linkBase); ?>/contact" class="text-blue-600 hover:text-blue-700 font-medium">
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
                                            <?php echo htmlspecialchars($jobType($job['employment_type'])); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-gray-600 mt-2 line-clamp-2"><?php echo htmlspecialchars($job['description']); ?></p>
                                </div>
                                <div class="flex-shrink-0 flex gap-2">
                                    <a href="<?php echo htmlspecialchars($jobUrl($job['slug'], $isAr)); ?>"
                                       class="inline-flex items-center gap-2 px-4 py-2 text-blue-600 bg-blue-50 font-medium rounded-lg hover:bg-blue-100 transition-colors">
                                        <?= htmlspecialchars(t('careers.view_details')) ?>
                                    </a>
                                    <a href="<?php echo htmlspecialchars($linkBase); ?>/contact?subject=<?= urlencode(t('careers.apply_subject', ['title' => $job['title']])) ?>"
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
            <a href="<?php echo htmlspecialchars($linkBase); ?>/contact"
               class="inline-flex items-center gap-2 px-8 py-4 bg-white text-blue-600 font-semibold rounded-xl hover:bg-blue-50 transition-colors">
                <i class="fa-solid fa-paper-plane"></i>
                <?= htmlspecialchars(t('careers.get_in_touch')) ?>
            </a>
        </div>

        <!-- Back to Home -->
        <div class="mt-12 text-center">
            <a href="<?php echo htmlspecialchars($linkBase . '/'); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                <?= htmlspecialchars(t('careers.back_home')) ?>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
