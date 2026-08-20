<?php
/**
 * Cardify vs HiHello. Honest comparison page.
 *
 * r328. Every HiHello figure was read off https://www.hihello.com/pricing on
 * 2026-08-20. The page defaults to the annual view; the monthly figures live
 * in the page's own billing-toggle data and are quoted as monthly.
 *
 * KNOWN TRAP, do not "fix" it by picking a nicer number: that same page's
 * <meta name="description"> says subscriptions start at $6.00/month while its
 * JSON-LD on the same page says $3.00/month, and neither matches a plan card.
 * Cite the PLAN CARDS only. If a future round finds a $3 figure somewhere,
 * it came from their structured data, not from their price list.
 *
 * NEVER write "HiHello does not support Arabic". Verified: the words Arabic,
 * RTL, multilingual and localisation were absent from the visible text of
 * their pricing page. Absence of a claim, not absence of a feature.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$pageTitle       = 'Cardify vs HiHello, an Honest Comparison';
$pageDescription = 'Cardify and HiHello compared on price, Arabic and English support, seat bands, printing in Oman and enterprise features. HiHello Business is $5 per user per month billed yearly for 5 to 100 users. Cardify is free for unlimited employees. Checked 20 August 2026.';
$canonicalUrl    = 'https://cardify.om/compare/cardify-vs-hihello';

$showNavigation = true;
$checkedDate = '20 August 2026';
$sources = [
    ['HiHello pricing page', 'https://www.hihello.com/pricing'],
    ['Cardify pricing page', 'https://cardify.om/pricing'],
];

$faq = [
    ['How much does HiHello cost?',
     'From HiHello\'s pricing page on 20 August 2026: Personal is free forever for one user, Professional is $6 per month billed yearly at $72 per year (or $8 billed monthly) for one user, Business is $5 per user per month billed yearly at $60 per user per year (or $6 billed monthly) for 5 to 100 users, and Enterprise is custom pricing for 101 or more users.'],
    ['How much does Cardify cost?',
     'The Cardify web platform is free with no employee limit, no template limit and no card limit, and no credit card is required. You pay only for physical cards: OMR 5.000 per 100 Standard, OMR 6.000 per 100 Premium, OMR 15.000 per 100 Luxury, and OMR 25.000 per NFC tap card.'],
    ['What does HiHello\'s free plan include?',
     'HiHello\'s Personal plan lists four free digital business cards, a personal email signature, virtual backgrounds, five card and badge scans per month, the ability to add cards to Apple and Google Wallet, and QR, widget, email and SMS sharing. It is a one-user plan, so it does not cover a team.'],
    ['Does HiHello have a seat limit?',
     'Yes, in both directions. HiHello\'s Business plan is stated as covering 5 to 100 users, and Enterprise begins at 101 or more users. So a team of three is below the Business floor and a team of two hundred is pushed into a custom Enterprise quote. Cardify has no floor and no ceiling.'],
    ['Is HiHello better than Cardify for anything?',
     'Yes. HiHello advertises single sign-on with directory sync, SAML and SCIM on Enterprise, SOC 2 level security, contact enrichment and badge scanning. Cardify has none of those. If your identity team requires SSO or your security review requires a SOC 2 report, HiHello meets a bar Cardify does not.'],
];

$extraHead = Seo::ldScript(
    Seo::breadcrumbNode([
        ['Home', 'https://cardify.om/'],
        ['Compare', 'https://cardify.om/compare'],
        ['Cardify vs HiHello', $canonicalUrl],
    ]),
    Seo::articleNode(
        __FILE__,
        'Cardify vs HiHello, an Honest Comparison',
        $pageDescription,
        $canonicalUrl,
        null,
        'en-OM'
    ),
    Seo::faqNode($faq)
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
                <a href="<?= $base ?>compare" class="hover:text-blue-600">Compare</a>
                <span class="mx-2">/</span>
                <span class="text-gray-700">Cardify vs HiHello</span>
            </nav>
            <h1 class="text-4xl sm:text-5xl font-extrabold tracking-tight text-gray-900 mb-5">
                Cardify vs HiHello
            </h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed">
                HiHello is a polished digital business card product with clear pricing and strong enterprise features. It also bands its plans by headcount and does not advertise Arabic. Here is the comparison, including where HiHello wins.
            </p>
            <p class="text-sm text-gray-500 mt-4">Competitor facts checked <?= $checkedDate ?>, sources linked at the foot of this page.</p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2 id="short-answer">The short answer</h2>
        <p>
            <strong>Choose HiHello</strong> if you need single sign-on with directory sync, if your security review requires SOC 2 level assurance, if contact enrichment and badge scanning matter to you, and if your headcount sits comfortably inside their five-to-one-hundred band.
        </p>
        <p>
            <strong>Choose Cardify</strong> if your team works in Arabic and English, if you do not want your card platform priced by headcount at all, and if you want the printing handled in Oman by the same system that manages the digital cards.
        </p>

        <h2 id="price">Price, and the bands around it</h2>
        <p>
            HiHello publishes its prices plainly. Read from their pricing page on <?= $checkedDate ?>:
        </p>
        <ul>
            <li><strong>Personal</strong>, one user, free forever, four digital business cards, five card and badge scans per month</li>
            <li><strong>Professional</strong>, one user, $6 per month billed yearly at $72 per year, or $8 billed monthly</li>
            <li><strong>Business</strong>, 5 to 100 users, $5 per user per month billed yearly at $60 per user per year, or $6 billed monthly</li>
            <li><strong>Enterprise</strong>, 101 or more users, custom pricing</li>
        </ul>
        <p>
            The banding is the part worth pausing on, because it is unusual and it has consequences at both ends. A team of three people is below the Business floor of five users. A company of two hundred is above the Business ceiling of one hundred and is pushed into a custom Enterprise conversation. Growth through one hundred staff means a re-quote.
        </p>
        <p>
            Cardify has no band. The web platform is free for unlimited employees, so three people and two thousand people pay the same, which is nothing. The only invoice is for physical cards, and only if you order them.
        </p>
        <p>
            We are not converting HiHello's dollar prices into rials. That would publish an exchange rate as though it were part of their price list. Compare the structures instead: per user per month inside a headcount band, against nothing per user at any headcount.
        </p>

        <h2 id="comparison-table">Feature by feature</h2>
        <div class="not-prose overflow-x-auto my-8">
            <table class="min-w-full text-sm border border-gray-200 rounded-lg overflow-hidden bg-white">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200">&nbsp;</th>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200">Cardify</th>
                        <th scope="col" class="text-left font-semibold px-4 py-3 border-b border-gray-200">HiHello</th>
                    </tr>
                </thead>
                <tbody class="text-gray-600">
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Free tier card limit</td><td class="px-4 py-3 border-b border-gray-100">Unlimited</td><td class="px-4 py-3 border-b border-gray-100">Four cards, one user</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Team cost</td><td class="px-4 py-3 border-b border-gray-100">Free, unlimited employees</td><td class="px-4 py-3 border-b border-gray-100">$5 to $6 per user per month</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Headcount band</td><td class="px-4 py-3 border-b border-gray-100">None</td><td class="px-4 py-3 border-b border-gray-100">Business is 5 to 100 users, Enterprise is 101+</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Arabic and English on one card</td><td class="px-4 py-3 border-b border-gray-100">Yes, by default</td><td class="px-4 py-3 border-b border-gray-100">Not advertised on the page checked</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Right-to-left rendering</td><td class="px-4 py-3 border-b border-gray-100">Yes</td><td class="px-4 py-3 border-b border-gray-100">Not advertised on the page checked</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Apple and Google Wallet</td><td class="px-4 py-3 border-b border-gray-100">Yes</td><td class="px-4 py-3 border-b border-gray-100">Yes, on the free tier</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Printing fulfilled in Oman</td><td class="px-4 py-3 border-b border-gray-100">Yes, from OMR 5.000 per 100</td><td class="px-4 py-3 border-b border-gray-100">No Oman fulfilment advertised</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">NFC cards with published price</td><td class="px-4 py-3 border-b border-gray-100">Yes, OMR 25.000 each</td><td class="px-4 py-3 border-b border-gray-100">NFC supported, no hardware price on the page checked</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Card and badge scanning</td><td class="px-4 py-3 border-b border-gray-100">Free iOS scanner app</td><td class="px-4 py-3 border-b border-gray-100">Yes, metered by tier</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Contact enrichment</td><td class="px-4 py-3 border-b border-gray-100 text-red-700">No</td><td class="px-4 py-3 border-b border-gray-100">Yes, from Professional up</td></tr>
                    <tr><td class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900">Single sign-on and directory sync</td><td class="px-4 py-3 border-b border-gray-100 text-red-700">No</td><td class="px-4 py-3 border-b border-gray-100">Yes, on Business and Enterprise</td></tr>
                    <tr><td class="px-4 py-3 font-medium text-gray-900">SOC 2 level security</td><td class="px-4 py-3 text-red-700">No</td><td class="px-4 py-3">Yes, advertised on Enterprise</td></tr>
                </tbody>
            </table>
        </div>
        <p class="text-sm text-gray-500">
            "Not advertised on the page checked" means the claim did not appear in the visible text of HiHello's pricing page on <?= $checkedDate ?>. It is not a statement that the product lacks the capability.
        </p>

        <h2 id="where-hihello-wins">Where HiHello is genuinely stronger</h2>
        <ul>
            <li><strong>Single sign-on and directory sync,</strong> advertised from Business up, with SAML and SCIM on Enterprise. Cardify has none of this. For a company that provisions software centrally, this alone decides it.</li>
            <li><strong>SOC 2 level security,</strong> advertised on Enterprise. Cardify holds no SOC 2 report.</li>
            <li><strong>Contact enrichment,</strong> from Professional upwards, unlimited on Business. Cardify does not enrich contacts at all.</li>
            <li><strong>Metered badge scanning</strong> as a first-class product feature, including enterprise event lead capture. Cardify's scanning is a separate free iOS app, not an integrated lead pipeline.</li>
            <li><strong>Verified badges and a dedicated account manager</strong> on Enterprise. Cardify offers neither.</li>
            <li><strong>A serious single-user product.</strong> If you are one professional rather than a company, HiHello Professional is well shaped for you and Cardify is not aimed at that buyer.</li>
        </ul>

        <h2 id="where-cardify-wins">Where Cardify is stronger</h2>
        <ul>
            <li><strong>Arabic and English as one card,</strong> with real Arabic fields, correct right-to-left rendering, and both spellings in the saved contact.</li>
            <li><strong>No headcount band and no per-user fee.</strong> Growing past one hundred staff triggers nothing.</li>
            <li><strong>A free tier for a company, not for a person.</strong> Unlimited employees and unlimited cards, against four cards for one user.</li>
            <li><strong>Print and digital in one system,</strong> fulfilled by verified Omani print shops, dispatched within one working day, delivered across Oman in two to four working days.</li>
            <li><strong>Built and hosted in Oman,</strong> a materially different answer for Omani regulators and state-linked counterparties.</li>
        </ul>

        <?php require __DIR__ . '/_verify-note.php'; ?>

        <h2 id="faq">Frequently asked questions</h2>
        <?php foreach ($faq as [$q, $a]): ?>
            <h3><?= htmlspecialchars($q) ?></h3>
            <p><?= htmlspecialchars($a) ?></p>
        <?php endforeach; ?>

        <div class="not-prose mt-12 rounded-2xl bg-blue-600 px-6 py-8 sm:px-10 sm:py-10 text-white">
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">No headcount band to grow out of</h2>
            <p class="text-blue-100 mb-6 max-w-2xl">Three people or three thousand, the platform is free. Card your whole company in Arabic and English today.</p>
            <a href="<?= $base ?>get-started" class="inline-flex items-center gap-2 px-6 py-3.5 bg-white text-blue-700 font-semibold rounded-xl hover:bg-blue-50 transition">
                Get started free
                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <h2 id="other-comparisons" class="mt-12">Other comparisons</h2>
        <ul>
            <li><a href="<?= $base ?>compare/cardify-vs-popl">Cardify vs Popl</a></li>
            <li><a href="<?= $base ?>compare/cardify-vs-blinq">Cardify vs Blinq</a></li>
            <li><a href="<?= $base ?>compare/best-digital-business-card-gcc">Best digital business card in Oman and the GCC</a></li>
        </ul>
    </article>
</div>

<?php require INCLUDES_DIR . '/ui-footer.php'; ?>
