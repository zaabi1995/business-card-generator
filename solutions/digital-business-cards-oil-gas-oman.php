<?php
/**
 * Cardify - Digital Business Cards for Oman's Oil & Gas Industry
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = "Digital Business Cards for Oman's Oil & Gas Industry — Cardify";
$pageDescription = 'Built for PDO, OQ, Daleel, Mazoon and oilfield contractors. HSE-compliant formats, CR + ICV codes, site-safe NFC rugged cards. Free tier for small contractors.';
$canonicalUrl = 'https://cardify.om/solutions/digital-business-cards-oil-gas-oman';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => "Digital Cards for Oman's Oil & Gas", 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => "Digital Business Cards for Oman's Oil & Gas Industry",
    'description' => $pageDescription,
    'mainEntityOfPage' => $canonicalUrl,
    'author' => ['@type' => 'Organization', 'name' => $brandName],
    'publisher' => [
        '@type' => 'Organization',
        'name' => $brandName,
        'logo' => ['@type' => 'ImageObject', 'url' => 'https://cardify.om/assets/images/logo.svg', 'creditText' => 'Cardify', 'copyrightNotice' => '© Cardify', 'license' => 'https://cardify.om/terms'],
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
                <span class="text-gray-700">Oil & Gas Oman</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Digital Business Cards for Oman's Oil & Gas Industry</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                PDO contracts, OQ tenders, Daleel Petroleum, Mazoon, BP Oman. The Oman hydrocarbons ecosystem runs on specifications, CR numbers, ICV certificates and H2S-safe procedures. Your business card should talk that language.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>The Anatomy of an Oil & Gas Business Card in Oman</h2>
        <p>
            A business card handed over at a Petroleum Development Oman (PDO) vendor day in Mina Al Fahal, or at an OQ procurement meeting in Muscat's Madinat Al Sultan Qaboos, is scrutinised for specifics. A head of procurement will quickly check: (1) Commercial Registration (CR) number — to confirm you're an active Omani entity; (2) In-Country Value (ICV) score band — because PDO, OQ, Daleel, Mazoon and others now weight ICV significantly in tender evaluation under the Ministry of Energy and Minerals' ICV strategy; (3) your ISO 9001, ISO 14001, ISO 45001 certification status; (4) pre-qualified vendor number with the specific operator if you have one; and (5) HSE contact person directly, not a generic admin number.
        </p>
        <p>
            A <?php echo $brandName; ?> digital card for oil & gas contractors includes all of these as structured fields. They render cleanly on the profile, and they download into the recipient's vCard properly, meaning a PDO procurement engineer can search their phone three months later for "ICV Band 2 welding" and your card surfaces. Paper cards can't do this.
        </p>

        <h2>Field-Safe NFC Cards for Rig and Plant Environments</h2>
        <p>
            Standard NFC business cards don't survive the heat of a Nimr rig in July or the dust of Suwaihat. Cardify offers a ruggedised NFC option — polycarbonate with an epoxy seal, tested to IP67, rated to 85°C. Clip it to your coveralls or HSE vest lanyard and it survives wash-downs, the shamal dust storms that roll through the Oman interior, and the salt spray of an offshore operation in the Musandam Strait. The engraving is a CNC laser job (not surface print) so logos don't fade after 18 months of UV exposure.
        </p>
        <p>
            Inside the chip, we encode an intrinsically-safe compatible NDEF record — which means when scanned in an ATEX/IECEx Zone 1 environment (with your phone of course on airplane mode as per site rules), the card is fully passive, no RF emission beyond the read event itself.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> Upgrade to ruggedised NFC for field crews. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free &rarr;</a></p>

        <h2>Tender Submission & Vendor Pre-Qualification Workflow</h2>
        <p>
            When you respond to a PDO, OQ, or Daleel tender, the submission folder typically includes a vendor profile. <?php echo $brandName; ?> can export your entire team's bilingual cards as a single PDF "team contact sheet" — one page per key person (Managing Director, Operations Manager, HSE Lead, Project Engineer, Commercial Manager), with photo, title in Arabic and English, direct mobile, office email, CR number, and pre-qualification references. This single PDF slots into Section 4 of most Oman operator pre-qualification templates.
        </p>
        <p>
            At the bid clarification meeting — typically held at the operator's head office in Mina Al Fahal (PDO), Duqm Operations Centre (OQ), or Muscat head office — your bid team tap their NFC cards to the procurement team's phones. Every bid team member is instantly in their contact list, making the follow-up Q&A correspondence smoother. No lost business cards in a bid meeting room.
        </p>

        <h2>ICV, Omanisation & Workforce Identity Cards</h2>
        <p>
            The ICV (In-Country Value) and Omanisation agenda, driven by the Ministry of Energy and Minerals and tracked through the ICV Oman programme, means operators want visibility into your Omani workforce percentage. Cardify can issue bulk employee cards for your Omani staff — each card shows the employee photo, role, Civil ID-matched Arabic name, and an optional "Omani national" indicator (at the employee's consent). When a PDO or OQ representative walks your yard, they can tap cards of your Omani welders, technicians and engineers, building a visible trust signal that your ICV claim is real.
        </p>
        <p>
            For non-Omani staff on work visas, cards include visa/labour card expiry (visible only to HR) with a 30-day warning email — practical HR use of the same platform.
        </p>

        <h2>Specific Oman Oil & Gas Use Cases</h2>
        <p>
            At the Oman Petroleum & Energy Show (OPES) biennial event at OCEC, or the Oman Energy Transition Week, exhibitors report handing out 1,000+ paper cards in a single week. Most never get followed up. With Cardify, each rep's scan is logged, the prospect's details are captured via a "Save Contact" two-way exchange, and the export feeds straight into your bid pipeline. One Cardify customer — a subsurface services contractor working with PDO and Daleel — measured 312 meaningful scans during OPES 2024 compared to about 40 follow-up emails after distributing 800 paper cards at the 2022 edition.
        </p>
        <p>
            For Oman's growing energy transition plays — hydrogen projects in Duqm (Hyport Duqm, H2 Oman), solar farms like Manah, and the Rabigh and Ibri utility-scale renewables — the new supply chain is global. Your card needs to translate to Korean, Japanese, and European procurement teams instantly. Cardify's digital card auto-detects language and offers English, Arabic, and optional Korean/Japanese/Chinese/German rendering for supplier-facing profiles.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Tender-ready contact identity</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Add CR, VAT, ICV band, ISO certifications and HSE contact as structured fields that recipients can verify.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Team's Free Account
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
