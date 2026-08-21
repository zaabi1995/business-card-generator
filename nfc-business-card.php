<?php
/**
 * Cardify commercial landing page for NFC business cards in Oman.
 *
 * The buying page owns the commercial query. The separate setup guide owns
 * tag-writing and compatibility troubleshooting. Both pages link to each
 * other, but they do not compete with the same title or opening answer.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';
require_once INCLUDES_DIR . '/ArTwins.php';

$baseUrl = 'https://cardify.om';
$lang = ($_GET['lang'] ?? '') === 'ar' ? 'ar' : 'en';
$isAr = $lang === 'ar';
if (class_exists('I18n')) I18n::setLocale($lang);

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$pageTitle = t('nfc.page_title');
$pageDescription = t('nfc.page_desc');
$canonicalUrl = $baseUrl . ($isAr ? '/ar' : '') . '/nfc-business-card';
$showNavigation = true;
$bodyClass = 'bg-white' . ($isAr ? ' font-arabic' : '');
$bodyAttributes = $isAr ? 'dir="rtl" lang="ar"' : 'lang="en"';

$faq = [];
for ($i = 1; $i <= 8; $i++) {
    $faq[] = [t('nfc.faq_q' . $i), t('nfc.faq_a' . $i)];
}

$extraHead = Seo::ldScript(
    Seo::breadcrumbNode([
        [t('nfc.breadcrumb_home'), $baseUrl . ($isAr ? '/ar/' : '/')],
        [t('nfc.breadcrumb_current'), $canonicalUrl],
    ]),
    Seo::articleNode(
        __FILE__,
        t('nfc.hero_h1'),
        $pageDescription,
        $canonicalUrl,
        null,
        $isAr ? 'ar-OM' : 'en-OM'
    ),
    Seo::faqNode($faq)
);

$GLOBALS['pageSchemaType'] = 'ItemPage';
$GLOBALS['pageSchemaName'] = t('nfc.hero_h1');
$GLOBALS['pageSchemaDescription'] = $pageDescription;
$GLOBALS['pageSchemaMainEntity'] = ['@id' => $baseUrl . '/pricing#product-nfc'];

require_once INCLUDES_DIR . '/ui-header.php';

$registerUrl = ArTwins::navLink('company/register.php', '/', $isAr);
$pricingUrl = ArTwins::navLink('pricing', '/', $isAr);
$digitalUrl = ArTwins::navLink('digital-business-card', '/', $isAr);
$guideUrl = ArTwins::navLink('tools/nfc-business-card-guide', '/', $isAr);
$arrow = $isAr ? 'left' : 'right';
$align = $isAr ? 'text-right' : 'text-left';

function nfcEsc(string $key): string
{
    return htmlspecialchars(t('nfc.' . $key), ENT_QUOTES, 'UTF-8');
}

function nfcRich(string $key): string
{
    return t('nfc.' . $key);
}
?>

<style>
    .nfc-actions { display: flex; flex-direction: column; gap: .75rem; }
    .nfc-copy > h2 { margin: 2.75rem 0 1rem; color: #111827; font-size: 1.625rem; font-weight: 800; line-height: 1.25; letter-spacing: -.02em; }
    .nfc-copy > h2:first-child { margin-top: 0; }
    .nfc-copy > h3 { margin: 1.75rem 0 .5rem; color: #111827; font-size: 1.125rem; font-weight: 700; line-height: 1.4; }
    .nfc-copy > p { margin: .9rem 0; color: #374151; font-size: 1.0625rem; line-height: 1.85; }
    .nfc-copy > ul { margin: 1rem 0; padding-inline-start: 1.4rem; color: #374151; list-style: disc; }
    .nfc-copy > ul > li { margin: .65rem 0; line-height: 1.75; }
    .nfc-copy > p a, .nfc-copy > ul a { color: #1d4ed8; font-weight: 600; text-decoration: underline; text-underline-offset: 3px; }
    .nfc-copy > p a:hover, .nfc-copy > ul a:hover { text-decoration: none; }
    @media (min-width: 640px) {
        .nfc-actions { flex-direction: row; }
        .nfc-actions > a { width: auto; }
    }
</style>

<main id="main-content" tabindex="-1" class="min-h-screen bg-gray-50">
    <header class="bg-white pt-28 pb-12 border-b border-gray-100">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="<?= nfcEsc('breadcrumb_current') ?>">
                <a href="<?= $isAr ? '/ar/' : '/' ?>" class="hover:text-blue-600"><?= nfcEsc('breadcrumb_home') ?></a>
                <span class="mx-2" aria-hidden="true">/</span>
                <span class="text-gray-700"><?= nfcEsc('breadcrumb_current') ?></span>
            </nav>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900 mb-5">
                <?= nfcEsc('hero_h1') ?>
            </h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed" data-speakable="summary">
                <?= nfcEsc('hero_lede') ?>
            </p>
            <div class="nfc-actions mt-7">
                <a href="<?= htmlspecialchars($registerUrl, ENT_QUOTES) ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-lg shadow-blue-600/25 transition">
                    <?= nfcEsc('cta_build') ?>
                    <i class="fa-solid fa-arrow-<?= $arrow ?>" aria-hidden="true"></i>
                </a>
                <a href="<?= htmlspecialchars($pricingUrl, ENT_QUOTES) ?>" class="inline-flex items-center justify-center gap-2 px-6 py-3.5 bg-white border border-gray-300 hover:border-gray-400 text-gray-800 font-semibold rounded-xl transition">
                    <i class="fa-solid fa-tag" aria-hidden="true"></i>
                    <?= nfcEsc('cta_pricing') ?>
                </a>
            </div>
        </div>
    </header>

    <article class="nfc-copy max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12" data-speakable="article-body">
        <h2 id="what-is-an-nfc-business-card" class="mt-0"><?= nfcEsc('definition_h2') ?></h2>
        <p><?= nfcEsc('definition_p1') ?></p>
        <p><?= nfcEsc('definition_p2') ?></p>
        <p><?= nfcEsc('definition_p3') ?></p>

        <h2 id="which-phones-work"><?= nfcEsc('phones_h2') ?></h2>
        <p><?= nfcEsc('phones_intro') ?></p>
        <ul>
            <?php for ($i = 1; $i <= 4; $i++): ?>
                <li><?= nfcRich('phone_' . $i) ?></li>
            <?php endfor; ?>
        </ul>
        <p><?= nfcEsc('phones_outro') ?></p>

        <h2 id="qr-fallback"><?= nfcEsc('qr_h2') ?></h2>
        <p><?= nfcEsc('qr_p1') ?></p>
        <p><?= nfcEsc('qr_p2') ?></p>
        <p>
            <?= nfcEsc('qr_p3') ?>
            <a href="<?= htmlspecialchars($digitalUrl, ENT_QUOTES) ?>"><?= nfcEsc('digital_link') ?></a>.
            <a href="<?= htmlspecialchars($guideUrl, ENT_QUOTES) ?>"><?= nfcEsc('guide_link') ?></a>.
        </p>

        <h2 id="nfc-vs-qr"><?= nfcEsc('compare_h2') ?></h2>
        <div class="not-prose overflow-x-auto my-8">
            <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden bg-white">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th scope="col" class="<?= $align ?> font-semibold px-4 py-3 border-b border-gray-200"><?= nfcEsc('table_criterion') ?></th>
                        <th scope="col" class="<?= $align ?> font-semibold px-4 py-3 border-b border-gray-200"><?= nfcEsc('table_nfc') ?></th>
                        <th scope="col" class="<?= $align ?> font-semibold px-4 py-3 border-b border-gray-200"><?= nfcEsc('table_qr') ?></th>
                    </tr>
                </thead>
                <tbody class="text-gray-600">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                    <tr>
                        <th scope="row" class="<?= $align ?> px-4 py-3 border-b border-gray-100 font-medium text-gray-900"><?= nfcEsc('row_' . $i . '_label') ?></th>
                        <td class="px-4 py-3 border-b border-gray-100"><?= nfcEsc('row_' . $i . '_nfc') ?></td>
                        <td class="px-4 py-3 border-b border-gray-100"><?= nfcEsc('row_' . $i . '_qr') ?></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <p><?= nfcEsc('compare_summary') ?></p>

        <h2 id="reprogramming"><?= nfcEsc('reprogram_h2') ?></h2>
        <p><?= nfcEsc('reprogram_p1') ?></p>
        <p><?= nfcEsc('reprogram_p2') ?></p>
        <p><?= nfcEsc('reprogram_p3') ?></p>

        <h2 id="who-needs-nfc"><?= nfcEsc('use_h2') ?></h2>
        <ul>
            <?php for ($i = 1; $i <= 5; $i++): ?>
                <li><?= nfcRich('use_' . $i) ?></li>
            <?php endfor; ?>
        </ul>
        <p><?= nfcEsc('use_no') ?></p>

        <h2 id="ordering"><?= nfcEsc('ordering_h2') ?></h2>
        <p><?= nfcEsc('ordering_p1') ?></p>
        <p>
            <?= nfcEsc('ordering_p2') ?>
            <a href="<?= htmlspecialchars($pricingUrl, ENT_QUOTES) ?>"><?= nfcEsc('cta_pricing') ?></a>.
        </p>

        <h2 id="technical-sources"><?= nfcEsc('sources_h2') ?></h2>
        <p><?= nfcEsc('sources_intro') ?></p>
        <ul>
            <li><a href="https://developer.apple.com/documentation/corenfc/adding-support-for-background-tag-reading" target="_blank" rel="noopener noreferrer"><?= nfcEsc('source_apple') ?></a></li>
            <li><a href="https://developer.android.com/develop/connectivity/nfc/nfc" target="_blank" rel="noopener noreferrer"><?= nfcEsc('source_android') ?></a></li>
            <li><a href="https://nfc-forum.org/learn/nfc-technology/" target="_blank" rel="noopener noreferrer"><?= nfcEsc('source_forum') ?></a></li>
        </ul>

        <h2 id="faq"><?= nfcEsc('faq_h2') ?></h2>
        <?php foreach ($faq as [$question, $answer]): ?>
            <h3><?= htmlspecialchars($question, ENT_QUOTES, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars($answer, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endforeach; ?>

        <section class="not-prose mt-12 rounded-2xl bg-blue-600 px-6 py-8 sm:px-10 sm:py-10 text-white" aria-labelledby="nfc-closing-heading">
            <h2 id="nfc-closing-heading" class="text-2xl sm:text-3xl font-bold mb-3"><?= nfcEsc('closing_h2') ?></h2>
            <p class="text-blue-100 mb-6 max-w-2xl"><?= nfcEsc('closing_p') ?></p>
            <a href="<?= htmlspecialchars($registerUrl, ENT_QUOTES) ?>" class="inline-flex items-center gap-2 px-6 py-3.5 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                <?= nfcEsc('closing_cta') ?>
                <i class="fa-solid fa-arrow-<?= $arrow ?>" aria-hidden="true"></i>
            </a>
        </section>

        <h2 id="keep-reading" class="mt-12"><?= nfcEsc('read_h2') ?></h2>
        <ul>
            <li><a href="<?= htmlspecialchars($digitalUrl, ENT_QUOTES) ?>"><?= nfcEsc('read_digital') ?></a></li>
            <li><a href="<?= htmlspecialchars(ArTwins::navLink('virtual-business-card', '/', $isAr), ENT_QUOTES) ?>"><?= nfcEsc('read_virtual') ?></a></li>
            <li><a href="<?= htmlspecialchars(ArTwins::navLink('glossary/nfc', '/', $isAr), ENT_QUOTES) ?>"><?= nfcEsc('read_glossary') ?></a></li>
            <li><a href="<?= htmlspecialchars($guideUrl, ENT_QUOTES) ?>"><?= nfcEsc('read_guide') ?></a></li>
            <li><a href="<?= htmlspecialchars(ArTwins::navLink('solutions/nfc-business-cards-oman-executives', '/', $isAr), ENT_QUOTES) ?>"><?= nfcEsc('read_executives') ?></a></li>
        </ul>
    </article>
</main>

<?php require INCLUDES_DIR . '/ui-footer.php'; ?>
