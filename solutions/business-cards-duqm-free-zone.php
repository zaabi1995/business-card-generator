<?php
/**
 * Cardify - Business Cards for Duqm Free Zone Companies
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Duqm Free Zone Companies — Cardify';
$pageDescription = 'SEZAD Duqm Free Zone tenants — hydrogen, refining, fishing, logistics. Multi-language cards, freezone licence verification, delivery to Duqm in 72 hours.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-duqm-free-zone';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Duqm Free Zone Companies', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Business Cards for Duqm Free Zone Companies',
    'description' => $pageDescription,
    'mainEntityOfPage' => $canonicalUrl,
    'author' => ['@type' => 'Organization', 'name' => $brandName],
    'publisher' => [
        '@type' => 'Organization',
        'name' => $brandName,
        'logo' => ['@type' => 'ImageObject', 'url' => 'https://cardify.om/assets/images/logo.svg'],
    ],
    'inLanguage' => 'en-OM',
];
$extraHead = '<script type="application/ld+json">' . json_encode($breadcrumbJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($articleJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
                <a href="<?php echo getBasePath(); ?>" class="hover:text-blue-600">Home</a>
                <span class="mx-2">/</span>
                <a href="<?php echo getBasePath(); ?>solutions" class="hover:text-blue-600">Solutions</a>
                <span class="mx-2">/</span>
                <span class="text-gray-700">Duqm Free Zone</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Duqm Free Zone Companies</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                The most ambitious Special Economic Zone in the Gulf. Hydrogen mega-projects, refineries, fisheries, a dry dock complex, tourism. Your card talks to Korean, Chinese, Kuwaiti, Indian, and European partners — simultaneously.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Duqm — The Sultanate's Single Biggest Economic Bet</h2>
        <p>
            Duqm, on Oman's central east coast roughly 600 km south of Muscat, is the Sultanate's flagship Special Economic Zone, governed by SEZAD (Special Economic Zone Authority at Duqm, now part of the Public Authority for Special Economic Zones and Free Zones — OPAZ). Duqm hosts the Port of Duqm (operated in part by the Consortium of Antwerp Port and Oman's national logistics operator), the Duqm Refinery (OQ8, a JV between OQ and Kuwait Petroleum International), the Duqm Drydock (one of the largest in the region, operated by Drydocks World in partnership with Oman), the Duqm fisheries complex, a growing industrial land bank, a tourism zone with Crowne Plaza and planned luxury resorts, and — most prominently — the green hydrogen mega-projects including HYPORT Duqm, POSCO's green hydrogen project, and multiple utility-scale wind and solar developments tied to Hyport.
        </p>
        <p>
            The tenant mix at Duqm is as international as any zone in the Middle East. Korean conglomerates (POSCO, Samsung C&T), Chinese industrial groups (including entities linked to CMEC and CCCC for infrastructure build-outs), Kuwaiti petroleum entities, Indian fisheries and logistics operators, European engineering firms (Technip Energies, Wood, Fluor), and domestic Omani SMEs all operate within walking distance in Duqm. A business card for a Duqm-based company must work across this cultural and linguistic range — fluently.
        </p>

        <h2>Multi-Language Card for a Multi-National Tenant Base</h2>
        <p>
            Cardify's Duqm-specific configuration supports on-demand language rendering in Arabic, English, Korean, simplified Chinese, Japanese, Dutch, and French. When you share your card with a POSCO procurement officer via KakaoTalk or WhatsApp, the recipient sees the card in Korean first. When you share with a Kuwait-based commercial director, the card lands in Arabic. When you share with a Rotterdam-based logistics partner at the Port of Antwerp's Duqm liaison office, the Dutch or English version loads. The same URL, different language per visitor. Your card becomes a genuine cross-cultural business artifact without you having to maintain seven separate card versions.
        </p>
        <p>
            Your primary contact details — phone (in +968 format), email, office location — remain consistent. What changes is the copy around them: Arabic honorifics for a GCC counterparty, Korean politeness levels (존댓말) for a POSCO interaction, Dutch for a Rotterdam logistics partner.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> Perfect for Duqm-based operators working with global partners. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free &rarr;</a></p>

        <h2>SEZAD Free Zone Licence — Why Verification Matters</h2>
        <p>
            Companies operating inside the Duqm Free Zone hold a SEZAD / OPAZ licence, which carries specific commercial and tax implications (zero corporate tax for a defined period, customs-free import, flexible Omanisation ramp-up, 100% foreign ownership permitted). A procurement team in Rotterdam or Seoul signing a first purchase order with a Duqm-based vendor often wants to verify the licence status before wiring funds. Cardify's business card includes a direct link to the OPAZ registry entry for the licensed entity, giving the counterparty a verifiable trust signal in one click. This reduces the back-and-forth of "can you email us a scanned copy of your licence certificate?" by days.
        </p>

        <h2>Remote Location, Premium Service</h2>
        <p>
            Duqm's geographic remoteness — 600 km from Muscat, 700 km from Salalah — historically made logistics services like same-day business card printing impossible. Cardify's Muscat print partner delivers to Duqm within 72 hours via the Oman Post premium freight service or our direct courier. For larger orders (e.g., 200 employee cards for a new hydrogen project workforce), we batch-deliver via the regular Muscat-Duqm freight trucks that run 4x daily down the Muscat-Duqm highway, keeping costs low while maintaining speed. Onsite NFC card activation kits can also be shipped so an HR lead in Duqm can program and issue cards locally for walk-in new hires.
        </p>

        <h2>Hydrogen, Fisheries, Tourism — Three Very Different Card Needs</h2>
        <p>
            A hydrogen project executive's card emphasises: project stage, technology partner, MW capacity, off-take partner, ESG credentials, and full regulatory licensing stack. A fisheries operator's card (based out of the Duqm fishing port) emphasises: species handled, HS codes, processing capacity, cold-chain capability, certifications (MSC, halal, export approvals for EU and GCC markets). A Duqm tourism operator's card emphasises: location pin, guest experiences (Al Wusta desert safaris, the dramatic Duqm coastline, the new ITC developments), booking availability, seasonal pricing. Cardify handles all three with tailored templates — you pick the one that matches your segment, and the card renders the right fields.
        </p>

        <h2>Supporting Oman's Duqm Ambition</h2>
        <p>
            Duqm's success is central to Oman Vision 2040's economic diversification strategy, and every tenant contributes. Cardify offers a discounted "Duqm Founding Tenant" plan to SMEs that were among the first 200 registered Free Zone tenants, as a modest way to support the ecosystem. Contact our team to verify eligibility against the OPAZ registry.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Global reach, from Oman's east coast</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Multi-language rendering, OPAZ licence verification, Duqm-direct delivery.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Duqm Card
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
