<?php
/**
 * Public changelog page (Cat U action 496).
 *
 * Data-driven so the marketing page and the repo CHANGELOG.md share
 * the same timeline. Entries live in data/changelog.php as a list of
 * records with title + body in both locales.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';
require_once INCLUDES_DIR . '/ArTwins.php';

$lang   = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr   = $lang === 'ar';
$baseUrl = 'https://cardify.om';

$entries = (function () {
    $f = __DIR__ . '/data/changelog.php';
    return is_file($f) ? (require $f) : [];
})();

$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle       = t('changelog.page_title');
$pageDescription = t('changelog.page_desc');
$canonicalUrl    = $baseUrl . ($isAr ? '/ar' : '') . '/changelog';
$showNavigation  = true;
$bodyClass       = 'bg-gray-50' . ($isAr ? ' font-arabic' : '');
$bodyAttributes  = $isAr ? 'dir="rtl" lang="ar"' : '';

require_once INCLUDES_DIR . '/ui-header.php';
Seo::breadcrumbs([
    [$isAr ? 'الرئيسية' : 'Home', '/'],
    [t('changelog.page_title'), $canonicalUrl],
]);
?>
<main class="min-h-screen bg-gray-50 pt-24 pb-16">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <header class="text-center mb-12">
            <p class="text-xs uppercase tracking-wider text-blue-600 font-semibold mb-3"><?= htmlspecialchars(t('changelog.eyebrow')) ?></p>
            <h1 class="text-3xl sm:text-4xl font-extrabold text-gray-900 mb-4"><?= htmlspecialchars(t('changelog.page_title')) ?></h1>
            <p class="text-gray-600 max-w-xl mx-auto"><?= htmlspecialchars(t('changelog.page_sub')) ?></p>
        </header>

        <?php if (empty($entries)): ?>
            <div class="bg-white rounded-2xl border border-gray-200 p-10 text-center text-gray-500">
                <?= htmlspecialchars(t('changelog.empty')) ?>
            </div>
        <?php else: ?>
        <ol class="relative <?= $isAr ? 'pr-6 border-r-2' : 'pl-6 border-l-2' ?> border-gray-200 space-y-10">
            <?php foreach ($entries as $e):
                $title = $isAr ? ($e['title_ar'] ?? $e['title_en']) : $e['title_en'];
                $body  = $isAr ? ($e['body_ar']  ?? $e['body_en'])  : $e['body_en'];
                $tag   = $e['tag'] ?? 'release';
                $tagCls = [
                    'release'  => 'bg-blue-50 text-blue-700',
                    'feature'  => 'bg-green-50 text-green-700',
                    'fix'      => 'bg-amber-50 text-amber-700',
                    'security' => 'bg-red-50 text-red-700',
                ][$tag] ?? 'bg-gray-50 text-gray-700';
            ?>
            <li>
                <span class="<?= $isAr ? '-right-[9px]' : '-left-[9px]' ?> absolute w-4 h-4 rounded-full bg-white border-2 border-blue-500"></span>
                <div class="bg-white rounded-2xl border border-gray-200 p-6">
                    <div class="flex items-center gap-2 text-xs text-gray-500 mb-2">
                        <time datetime="<?= htmlspecialchars($e['date']) ?>"><?= htmlspecialchars(I18n::formatDate(strtotime($e['date']))) ?></time>
                        <?php if (!empty($e['version'])): ?>
                        <span class="inline-block px-2 py-0.5 rounded-full bg-gray-100 text-gray-700 font-mono"><?= htmlspecialchars($e['version']) ?></span>
                        <?php endif; ?>
                        <span class="inline-block px-2 py-0.5 rounded-full <?= $tagCls ?> font-semibold"><?= htmlspecialchars(t('changelog.tag_' . $tag)) ?></span>
                    </div>
                    <h2 class="text-lg font-bold text-gray-900 mb-2"><?= htmlspecialchars($title) ?></h2>
                    <div class="text-gray-700 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($body) ?></div>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
        <?php endif; ?>

        <p class="text-center text-xs text-gray-400 mt-12">
            <?= htmlspecialchars(t('changelog.rss_note')) ?>
            <a href="<?= htmlspecialchars(ArTwins::navLink('feed', '/', $isAr)) ?>" class="text-blue-600 hover:text-blue-700">RSS</a>
        </p>
    </div>
</main>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
