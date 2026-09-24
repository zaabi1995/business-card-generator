<?php
/**
 * Shared renderer for the bilingual high-intent landing pages (r26 seo loop,
 * 24 Sep 2026): /apple-wallet-business-card, /qr-code-business-card,
 * /business-cards-for-companies and /compare/cardify-vs-linktree.
 *
 * Each page file owns its own copy, in English AND Arabic, and hands this
 * class one array for the language being served. The class only does layout,
 * head tags and JSON-LD, so no two pages share a sentence of body copy.
 *
 * Rules the page files follow (see the brief in the commit message):
 *   - every fact is read from the codebase, the live site or a fetched
 *     competitor page; prices come from CardCatalogPricing, never typed;
 *   - the FAQ passed here is rendered visibly, so FAQPage JSON-LD is honest;
 *   - paragraphs are trusted, author-written HTML (links only).
 *
 * The EN path must be in ArTwins::PATHS and have its nginx /ar/ rewrite, or
 * the header will not emit the reciprocal hreflang pair.
 */
require_once __DIR__ . '/Seo.php';
require_once __DIR__ . '/ArTwins.php';
require_once __DIR__ . '/CardCatalogPricing.php';

class IntentLanding
{
    /** Resolve ?lang= and set the locale before ui-header.php runs. */
    public static function lang(): string
    {
        $lang = (($_GET['lang'] ?? '') === 'ar') ? 'ar' : 'en';
        if (class_exists('I18n')) I18n::setLocale($lang);
        return $lang;
    }

    /** "OMR 5.000" / "5.000 ريال" for a catalogue product. */
    public static function price(string $key, bool $isAr): string
    {
        $d = CardCatalogPricing::decimal($key);
        return $isAr ? $d . ' ريال عماني' : 'OMR ' . $d;
    }

    /** Internal link in the reader's language, falling back to EN. */
    public static function link(string $slug, bool $isAr): string
    {
        return ArTwins::navLink($slug, '/', $isAr);
    }

    /**
     * Render the whole page. $c keys:
     *   path, title, desc, crumbs [[label,url],...], h1, lede,
     *   cta_primary [label,url], cta_secondary [label,url],
     *   sections [ ['id','h2','p'=>[html..],'ul'=>[html..],'table'=>['head'=>[..],'rows'=>[[..],..]],'after'=>[html..]] ],
     *   faq_h2, faq [[q,a],...], related_h2, related [[label,url],...],
     *   verify (optional html block rendered before the FAQ)
     */
    public static function render(array $c, bool $isAr): void
    {
        $base = ArTwins::SITE;
        $canonicalUrl = $base . ($isAr ? '/ar' : '') . $c['path'];

        // ui-header.php reads these globals.
        $GLOBALS['pageTitle']       = $c['title'];
        $GLOBALS['pageDescription'] = $c['desc'];
        $GLOBALS['canonicalUrl']    = $canonicalUrl;
        $GLOBALS['showNavigation']  = true;
        $GLOBALS['bodyClass']       = 'bg-white' . ($isAr ? ' font-arabic' : '');
        $GLOBALS['bodyAttributes']  = $isAr ? 'dir="rtl" lang="ar"' : 'lang="en"';
        $GLOBALS['brandName']       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

        $crumbs = [];
        foreach ($c['crumbs'] as [$label, $url]) $crumbs[] = [$label, $url];
        $crumbs[] = [$c['crumb'], $canonicalUrl];

        $GLOBALS['extraHead'] = Seo::ldScript(
            Seo::breadcrumbNode($crumbs),
            Seo::articleNode($c['file'], $c['h1'], $c['desc'], $canonicalUrl, null, $isAr ? 'ar-OM' : 'en-OM'),
            Seo::faqNode($c['faq'])
        );

        foreach (['pageTitle','pageDescription','canonicalUrl','showNavigation','bodyClass','bodyAttributes','extraHead','brandName'] as $v) {
            $$v = $GLOBALS[$v];
        }
        require INCLUDES_DIR . '/ui-header.php';

        $e = static function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
        $arrow = $isAr ? 'left' : 'right';
        $align = $isAr ? 'text-right' : 'text-left';
        ?>
<style>
    .il-actions { display: flex; flex-direction: column; gap: .75rem; }
    .il-copy > h2 { margin: 2.75rem 0 1rem; color: #111827; font-size: 1.625rem; font-weight: 800; line-height: 1.3; }
    .il-copy > h2:first-child { margin-top: 0; }
    .il-copy > h3 { margin: 1.75rem 0 .5rem; color: #111827; font-size: 1.125rem; font-weight: 700; line-height: 1.45; }
    .il-copy > p { margin: .9rem 0; color: #374151; font-size: 1.0625rem; line-height: 1.85; overflow-wrap: anywhere; }
    .il-copy > ul, .il-copy > ol { margin: 1rem 0; padding-inline-start: 1.4rem; color: #374151; }
    .il-copy > ul { list-style: disc; } .il-copy > ol { list-style: decimal; }
    .il-copy > ul > li, .il-copy > ol > li { margin: .65rem 0; line-height: 1.75; }
    .il-copy a { color: #1d4ed8; font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
    .il-copy a:hover { text-decoration: none; }
    .il-table { width: 100%; overflow-x: auto; margin: 1.5rem 0; }
    .il-table table { min-width: 100%; font-size: .9rem; border: 1px solid #e5e7eb; background: #fff; }
    .il-table th, .il-table td { padding: .7rem .9rem; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
    @media (min-width: 640px) { .il-actions { flex-direction: row; } }
</style>
<main id="main-content" tabindex="-1" class="min-h-screen bg-gray-50">
    <header class="bg-white pt-28 pb-12 border-b border-gray-100">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="<?= $e($isAr ? 'مسار التنقل' : 'Breadcrumb') ?>">
                <?php foreach ($c['crumbs'] as [$label, $url]): ?>
                    <a href="<?= $e($url) ?>" class="hover:text-blue-600"><?= $e($label) ?></a>
                    <span class="mx-2" aria-hidden="true">/</span>
                <?php endforeach; ?>
                <span class="text-gray-700"><?= $e($c['crumb']) ?></span>
            </nav>
            <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-gray-900 mb-5"><?= $e($c['h1']) ?></h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed" data-speakable="summary"><?= $e($c['lede']) ?></p>
            <div class="il-actions mt-7">
                <a href="<?= $e($c['cta_primary'][1]) ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/25 transition">
                    <?= $e($c['cta_primary'][0]) ?> <i class="fa-solid fa-arrow-<?= $arrow ?>" aria-hidden="true"></i>
                </a>
                <a href="<?= $e($c['cta_secondary'][1]) ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-white border border-gray-300 hover:border-gray-400 text-gray-800 font-semibold rounded-xl transition">
                    <?= $e($c['cta_secondary'][0]) ?>
                </a>
            </div>
        </div>
    </header>

    <article class="il-copy max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12" data-speakable="article-body">
        <?php foreach ($c['sections'] as $s): ?>
            <h2 id="<?= $e($s['id']) ?>"><?= $e($s['h2']) ?></h2>
            <?php foreach ($s['p'] ?? [] as $p): ?><p><?= $p ?></p><?php endforeach; ?>
            <?php if (!empty($s['ol'])): ?><ol><?php foreach ($s['ol'] as $li): ?><li><?= $li ?></li><?php endforeach; ?></ol><?php endif; ?>
            <?php if (!empty($s['ul'])): ?><ul><?php foreach ($s['ul'] as $li): ?><li><?= $li ?></li><?php endforeach; ?></ul><?php endif; ?>
            <?php if (!empty($s['table'])): ?>
                <div class="il-table not-prose">
                    <table>
                        <thead class="bg-gray-50 text-gray-700"><tr>
                            <?php foreach ($s['table']['head'] as $th): ?><th scope="col" class="<?= $align ?> font-semibold"><?= $e($th) ?></th><?php endforeach; ?>
                        </tr></thead>
                        <tbody class="text-gray-600">
                            <?php foreach ($s['table']['rows'] as $row): ?><tr>
                                <th scope="row" class="<?= $align ?> font-medium text-gray-900"><?= $e($row[0]) ?></th>
                                <?php foreach (array_slice($row, 1) as $td): ?><td><?= $e($td) ?></td><?php endforeach; ?>
                            </tr><?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?php foreach ($s['after'] ?? [] as $p): ?><p><?= $p ?></p><?php endforeach; ?>
        <?php endforeach; ?>

        <?php if (!empty($c['verify'])) echo $c['verify']; ?>

        <h2 id="faq"><?= $e($c['faq_h2']) ?></h2>
        <?php foreach ($c['faq'] as [$q, $a]): ?>
            <h3><?= $e($q) ?></h3>
            <p><?= $e($a) ?></p>
        <?php endforeach; ?>

        <h2 id="keep-reading"><?= $e($c['related_h2']) ?></h2>
        <ul>
            <?php foreach ($c['related'] as [$label, $url]): ?>
                <li><a href="<?= $e($url) ?>"><?= $e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </article>
</main>
<?php
        require INCLUDES_DIR . '/ui-footer.php';
    }
}
