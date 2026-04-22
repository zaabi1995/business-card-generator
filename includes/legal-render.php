<?php
/**
 * Cardify shared legal-page renderer. Consumed by /terms and /privacy.
 *
 * Caller sets `$legalKey` to either 'terms' or 'privacy' before
 * requiring this file. Lang data lives in lang/{en,ar}/legal.php as a
 * two-level map keyed by $legalKey with an ordered `sections` list;
 * each section has a heading plus one of a paragraph or a bullet list
 * (or both).
 */

if (empty($legalKey) || !in_array($legalKey, ['terms', 'privacy'], true)) {
    http_response_code(500);
    exit('Legal page misconfigured.');
}

$lang    = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr    = $lang === 'ar';
$baseUrl = 'https://cardify.om';

// t() coerces nested arrays to strings, so load the legal namespace
// directly and fall back to EN if the AR file is missing.
$legalPath = (defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__))
           . '/lang/' . $lang . '/legal.php';
if (!is_file($legalPath)) {
    $legalPath = (defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__)) . '/lang/en/legal.php';
}
$legalAll = include $legalPath;
$legal    = $legalAll[$legalKey] ?? null;
if (!is_array($legal)) {
    http_response_code(500);
    exit('Legal copy missing.');
}

$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle       = $legal['page_title'];
$pageDescription = $legal['page_desc'];
$canonicalUrl    = $baseUrl . ($isAr ? '/ar' : '') . '/' . $legalKey;
$showNavigation  = true;
$bodyClass       = 'bg-gray-50' . ($isAr ? ' font-arabic' : '');
$bodyAttributes  = $isAr ? 'dir="rtl" lang="ar"' : '';

require_once INCLUDES_DIR . '/ui-header.php';

Seo::breadcrumbs([
    [$isAr ? 'الرئيسية' : 'Home', '/'],
    [$legal['h1'], $canonicalUrl],
]);

$lastUpdated = date($isAr ? 'Y-m-d' : 'F j, Y');
?>

<main class="min-h-screen bg-gray-50">
    <header class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3"><?= htmlspecialchars($legal['h1']) ?></h1>
            <p class="text-gray-500 text-sm"><?= htmlspecialchars($legalAll['last_updated']) ?>: <?= htmlspecialchars($lastUpdated) ?></p>
        </div>
    </header>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <article class="bg-white rounded-2xl shadow-sm p-8 lg:p-12">
            <?php foreach ($legal['sections'] as $section): ?>
                <section class="mb-10">
                    <h2 class="text-2xl font-bold text-gray-900 mb-4"><?= htmlspecialchars($section['h']) ?></h2>
                    <?php if (!empty($section['p'])): ?>
                        <p class="text-gray-700 leading-relaxed mb-4"><?= htmlspecialchars($section['p']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($section['list'])): ?>
                        <ul class="list-disc <?= $isAr ? 'pr-6' : 'pl-6' ?> text-gray-700 space-y-2">
                            <?php foreach ($section['list'] as $item): ?>
                                <li><?= htmlspecialchars($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <div class="mt-6 p-5 bg-gray-50 rounded-xl text-sm text-gray-600 leading-relaxed">
                <strong class="text-gray-900"><?= $isAr ? 'مجموعة BHD' : 'BHD Group' ?></strong><br>
                <?= $isAr ? 'بن حيدر درويش ش.ش.و' : 'Bin Haider Darwish S.P.C.' ?><br>
                <?= $isAr ? 'س.ت 1334733' : 'C.R. No. 1334733' ?><br>
                <a href="<?= ($isAr ? '/ar' : '') ?>/contact" class="text-blue-600 hover:text-blue-700 font-medium">
                    <i class="fa-solid fa-envelope <?= $isAr ? 'ml-1.5' : 'mr-1.5' ?>"></i>
                    <?= $isAr ? 'تواصل معنا' : 'Contact us' ?>
                </a>
            </div>
        </article>

        <div class="mt-8 text-center">
            <a href="<?= ($isAr ? '/ar' : '') ?>/" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-<?= $isAr ? 'right' : 'left' ?>"></i>
                <?= htmlspecialchars($legalAll['back_home']) ?>
            </a>
        </div>
    </div>
</main>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
