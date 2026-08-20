<?php
/**
 * One glossary term page. Routed as /glossary/{slug} by the nginx rewrite.
 *
 * r328. Renders from glossary/terms.php, so the definition on this page, the
 * definition on the hub card and the definition inside the DefinedTerm schema
 * are one string and cannot disagree.
 *
 * An unknown slug 404s properly rather than rendering an empty term, because a
 * soft 404 on a definition page is worse than no page: it invites Google to
 * index a shell.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$terms = require __DIR__ . '/terms.php';
$slug  = isset($_GET['term']) ? (string) $_GET['term'] : '';

if (!isset($terms[$slug])) {
    http_response_code(404);
    require __DIR__ . '/../404.php';
    exit;
}

$term      = $terms[$slug];
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$pageTitle       = $term['title'] . ', Definition in English and Arabic';
$pageDescription = $term['short'];
$canonicalUrl    = 'https://cardify.om/glossary/' . $slug;

$showNavigation = true;

$extraHead = Seo::ldScript(
    Seo::breadcrumbNode([
        ['Home', 'https://cardify.om/'],
        ['Glossary', 'https://cardify.om/glossary'],
        [$term['title'], $canonicalUrl],
    ]),
    Seo::definedTermNode($slug, $term['title'], $term['short'], $term['ar_title'], $term['ar_short']),
    Seo::faqNode($term['faq'])
);

require_once INCLUDES_DIR . '/ui-header.php';
$base = getBasePath();
?>

<div class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-12 border-b border-gray-100">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
                <a href="<?= $base ?>" class="hover:text-blue-600">Home</a>
                <span class="mx-2">/</span>
                <a href="<?= $base ?>glossary" class="hover:text-blue-600">Glossary</a>
                <span class="mx-2">/</span>
                <span class="text-gray-700"><?= htmlspecialchars($term['title']) ?></span>
            </nav>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900 mb-2">
                <?= htmlspecialchars($term['title']) ?>
            </h1>
            <p class="text-2xl text-gray-500 font-semibold mb-6" lang="ar" dir="rtl"><?= htmlspecialchars($term['ar_title']) ?></p>
            <?php if (!empty($term['also'])): ?>
                <p class="text-sm text-gray-500">
                    Also called: <?= htmlspecialchars(implode(', ', $term['also'])) ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <div class="not-prose mb-10 rounded-2xl border border-blue-200 bg-blue-50 px-6 py-6">
            <p class="text-xs font-bold uppercase tracking-wider text-blue-700 mb-3">Definition</p>
            <p class="text-lg text-gray-900 leading-relaxed mb-5"><?= htmlspecialchars($term['short']) ?></p>
            <p class="text-xs font-bold uppercase tracking-wider text-blue-700 mb-3">التعريف</p>
            <p class="text-lg text-gray-900 leading-loose" lang="ar" dir="rtl"><?= htmlspecialchars($term['ar_short']) ?></p>
        </div>

        <?php foreach ($term['sections'] as [$heading, $paras]): ?>
            <h2><?= htmlspecialchars($heading) ?></h2>
            <?php foreach ($paras as $p): ?>
                <p><?= htmlspecialchars($p) ?></p>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <h2 id="faq">Common questions</h2>
        <?php foreach ($term['faq'] as [$q, $a]): ?>
            <h3><?= htmlspecialchars($q) ?></h3>
            <p><?= htmlspecialchars($a) ?></p>
        <?php endforeach; ?>

        <?php if (!empty($term['related'])): ?>
            <h2 id="related">Related terms</h2>
            <ul>
                <?php foreach ($term['related'] as $r): if (!isset($terms[$r])) continue; ?>
                    <li>
                        <a href="<?= $base ?>glossary/<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($terms[$r]['title']) ?></a>,
                        <span lang="ar" dir="rtl"><?= htmlspecialchars($terms[$r]['ar_title']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="not-prose mt-12 rounded-2xl bg-blue-600 px-6 py-8 sm:px-10 sm:py-10 text-white">
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">See it working, rather than defined</h2>
            <p class="text-blue-100 mb-6 max-w-2xl">Cardify gives every employee a bilingual digital business card, free for unlimited staff, with printing fulfilled in Oman.</p>
            <a href="<?= $base ?>get-started" class="inline-flex items-center gap-2 px-6 py-3.5 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                Get started free
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <p class="mt-10"><a href="<?= $base ?>glossary">Back to the full glossary</a></p>
    </article>
</div>

<?php require INCLUDES_DIR . '/ui-footer.php'; ?>
