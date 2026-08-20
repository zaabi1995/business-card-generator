<?php
/**
 * /compare, the comparison hub.
 *
 * r328. Also the landing point for /vs and /alternatives, which 301 here from
 * the nginx rewrite table rather than becoming three thin pages saying the
 * same thing.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$pageTitle       = 'Compare Digital Business Card Platforms';
$pageDescription = 'Honest, sourced comparisons of Cardify against Popl, Blinq and HiHello, plus a roundup of the best digital business card for a team in Oman and the GCC. Every competitor fact is dated and linked to its source.';
$canonicalUrl    = 'https://cardify.om/compare';

$showNavigation = true;

$pages = [
    ['cardify-vs-popl', 'Cardify vs Popl',
     'Popl publishes no pricing and quotes over a meeting. It also owns event lead capture in a way Cardify does not. Where each one wins.'],
    ['cardify-vs-blinq', 'Cardify vs Blinq',
     'Blinq charges per user per month with a five-card minimum on teams, and advertises SSO and SOC 2 Type II that Cardify has no answer to.'],
    ['cardify-vs-hihello', 'Cardify vs HiHello',
     'HiHello bands its Business plan at 5 to 100 users and pushes larger teams to a custom quote. Strong on enrichment and SSO.'],
    ['best-digital-business-card-gcc', 'Best digital business card in Oman and the GCC',
     'All four platforms, organised by the question you are actually asking, including the cases where you should buy a competitor.'],
];

$extraHead = Seo::ldScript(
    Seo::breadcrumbNode([
        ['Home', 'https://cardify.om/'],
        ['Compare', $canonicalUrl],
    ]),
    [
        '@context' => 'https://schema.org',
        '@type' => 'CollectionPage',
        '@id' => $canonicalUrl . '#collection',
        'name' => 'Compare Digital Business Card Platforms',
        'description' => $pageDescription,
        'url' => $canonicalUrl,
        'isPartOf' => ['@id' => 'https://cardify.om/#organization'],
        'mainEntity' => [
            '@type' => 'ItemList',
            'itemListElement' => array_values(array_map(static function (array $p, int $i): array {
                return [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => $p[1],
                    'url' => 'https://cardify.om/compare/' . $p[0],
                ];
            }, $pages, array_keys($pages))),
        ],
    ]
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
                <span class="text-gray-700">Compare</span>
            </nav>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900 mb-5">
                Compare digital business card platforms
            </h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed">
                We publish these because you are going to compare us anyway, and we would rather the comparison were accurate. Every competitor fact below was read off their own live pages, is dated, and links to its source. Where a competitor is the better buy, the page says so.
            </p>
        </div>
    </div>

    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid gap-5 sm:grid-cols-2">
            <?php foreach ($pages as [$slug, $title, $blurb]): ?>
                <a href="<?= $base ?>compare/<?= $slug ?>" class="group block rounded-2xl bg-white border border-gray-200 hover:border-blue-400 hover:shadow-lg transition p-6">
                    <h2 class="text-xl font-bold text-gray-900 group-hover:text-blue-700 mb-2"><?= htmlspecialchars($title) ?></h2>
                    <p class="text-gray-600 text-sm leading-relaxed mb-4"><?= htmlspecialchars($blurb) ?></p>
                    <span class="inline-flex items-center gap-2 text-blue-600 font-semibold text-sm">
                        Read the comparison
                        <i class="fa-solid fa-arrow-right group-hover:translate-x-0.5 transition-transform" aria-hidden="true"></i>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="mt-12 prose prose-lg max-w-none">
            <h2>How to read a digital business card comparison</h2>
            <p>
                Every platform in this category demos the same way: a phone taps a card, a profile opens, a contact saves. The differences that matter show up months later, and they cluster in four places.
            </p>
            <p>
                <strong>The shape of the price, not the number.</strong> A per-user monthly fee is not inherently worse than a free platform, but it behaves differently as you grow. Ask what the bill looks like at the headcount you expect to reach, and check for the two things vendors bury: a seat minimum below which you pay for empty seats, and a headcount band above which you are moved to a custom quote. Blinq states a five-card minimum on Business. HiHello bands Business at 5 to 100 users. Popl publishes no price at all and quotes over a meeting.
            </p>
            <p>
                <strong>Who owns the card when someone resigns.</strong> This is the question nobody asks in a demo and everybody discovers in year two. If the card belongs to the employee's personal account rather than to the company, every departure destroys an asset, and every QR code that person's card was printed on stops resolving.
            </p>
            <p>
                <strong>Whether Arabic is real.</strong> A language switch on the admin interface is not the same as a card that renders right-to-left with a name your Arabic-speaking colleague actually recognises as their own. If this matters to your organisation, ask each vendor to show you a rendered Arabic card rather than a feature list.
            </p>
            <p>
                <strong>What your security review will demand.</strong> If procurement requires a SOC 2 report or your identity team requires SAML single sign-on, that decides the shortlist before anything else does. Popl, Blinq and HiHello all advertise enterprise security assurance. Cardify does not have it, and we would rather you knew that from this page than from a wasted third meeting.
            </p>
            <h2>Where Cardify does not win</h2>
            <p>
                Publishing comparisons as one of the vendors only works if the losses are on the page. Ours are consistent across all three: no SOC 2 report, no SAML or SCIM single sign-on, no native CRM connectors, and no event badge scanning. If any one of those is a requirement rather than a preference, buy one of the others, and the individual pages say which one and why.
            </p>
            <p>
                What Cardify does have is a genuinely bilingual card, no per-employee cost at any headcount, and printing fulfilled in Oman by the same system that manages the digital cards.
            </p>
        </div>

        <div class="mt-10 rounded-xl border border-gray-200 bg-white px-6 py-6 text-sm text-gray-600">
            <p class="font-semibold text-gray-900 mb-2">
                <i class="fa-solid fa-scale-balanced mr-1.5" aria-hidden="true"></i>
                How we write these
            </p>
            <ul class="list-disc pl-5 space-y-1.5">
                <li>Competitor prices are quoted verbatim from the competitor's own pricing page, in the currency they display, with no conversion applied.</li>
                <li>Where a competitor does not publish a price, we say that rather than repeating a number from a review site.</li>
                <li>"Does not advertise X" means the claim was absent from the pages we checked on the date shown. It never means "cannot do X".</li>
                <li>Every page carries a section naming where the competitor is stronger than us, because there always is one.</li>
                <li>Found something wrong? <a href="<?= $base ?>contact" class="text-blue-600 font-medium">Tell us</a> and we will correct it.</li>
            </ul>
        </div>

        <div class="mt-10 rounded-2xl bg-blue-600 px-6 py-8 sm:px-10 sm:py-10 text-white">
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">Or skip the reading and try it</h2>
            <p class="text-blue-100 mb-6 max-w-2xl">Free for unlimited employees, no credit card, no demo booking.</p>
            <a href="<?= $base ?>get-started" class="inline-flex items-center gap-2 px-6 py-3.5 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                Get started free
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>
    </div>
</div>

<?php require INCLUDES_DIR . '/ui-footer.php'; ?>
