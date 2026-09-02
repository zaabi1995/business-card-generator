<?php
/**
 * Cardify - Digital Business Cards for Sales Teams in Oman
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$pageTitle = 'Digital Business Cards for Sales Teams in Oman, Cardify';
$pageDescription = 'Equip your Oman sales team with digital business cards that share instantly, sync to the CRM, and never run out in the middle of a client meeting at PDO or Oman Chamber events.';
$canonicalUrl = 'https://cardify.om/solutions/digital-business-cards-oman-sales-teams';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Digital Business Cards for Sales Teams in Oman', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = Seo::articleNode(
    __FILE__,
    'Digital Business Cards for Sales Teams in Oman',
    $pageDescription,
    $canonicalUrl,
    $ogImage ?? null,
    'en-OM'
);
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
                <span class="text-gray-700">Sales Teams in Oman</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Digital Business Cards for Sales Teams in Oman</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Give every account executive, business development officer, and field sales rep in your Oman team a card that never runs out, updates instantly when they change mobiles, and drops every new contact straight into your CRM.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Why Oman Sales Teams Are Moving Away From Paper</h2>
        <p>
            If your team sells B2B in the Sultanate, whether that's SaaS into Petroleum Development Oman's vendor ecosystem, construction materials to contractors bidding on Sultan Haitham City, or financial services to SME owners in Ruwi, the volume of in-person meetings is still very high. Oman is a relationship-first market. A single week for a mid-size sales team can easily mean 60 to 80 new handshakes between the Oman Chamber of Commerce & Industry meetings at Al Khuwair, client visits to Knowledge Oasis Muscat (KOM), the weekly Ghala Industrial Estate rounds, and a Thursday morning coffee at the Kempinski lobby.
        </p>
        <p>
            Traditional paper business cards cost your team in ways nobody tracks: you print 500 cards per rep every quarter, half end up in a hotel trash bin in Al Mouj, and every time a mobile number or job title changes the whole batch becomes obsolete. With <?php echo $brandName; ?> digital cards, your sales team gets a single URL and QR code per employee that lives in their email signature, their WhatsApp Business profile link, their NFC ring, and the back of an optional printed card, all pointing to a profile you control from one dashboard.
        </p>

        <h2>Built for the Oman B2B Sales Workflow</h2>
        <p>
            Sales in Oman runs on WhatsApp. After a first meeting, 90% of follow-ups happen on WhatsApp within 24 hours. A <?php echo $brandName; ?> digital card has a "Chat on WhatsApp" button that opens with a pre-filled Arabic or English message, so when a prospect meets your rep at the Oman Convention & Exhibition Centre, they scan once, tap "WhatsApp", and your rep's first message ("Thank you for stopping by our stand at OFEX 2026") is already drafted. We've measured a 3x higher follow-up rate compared to handing out a paper card and hoping the prospect types the number later.
        </p>
        <p>
            Each card supports English and Arabic side-by-side. Your regional manager sharing a card with a procurement director at OQ, Asyad Group, or Oman Investment Authority can show the Arabic version automatically based on the recipient's device language. This matters, decision-makers in government-linked entities (GLEs) consistently tell us they trust a bilingual presentation more than an English-only one.
        </p>

        <p><strong>Create digital business cards for your team, free to start with <?php echo $brandName; ?>.</strong> <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Get started in 2 minutes &rarr;</a></p>

        <h2>CRM, Lead Capture & Manager Visibility</h2>
        <p>
            Every time someone scans a rep's card, <?php echo $brandName; ?> logs the scan, city, device, timestamp. As a sales manager, you can see that your rep at the Muscat Grand Mall activation did 47 card shares in one afternoon, while the rep covering Sohar Free Zone only did 6. That's a real, auditable pipeline-activity signal you cannot get from paper. The "Save Contact" action on a digital card captures the visitor's name and number into your Cardify dashboard, which exports to CSV or pushes via webhook into HubSpot, Zoho, Salesforce, Odoo, or your in-house system.
        </p>
        <p>
            Lead handoff is also cleaner. If a rep leaves your company, a common pain point given Oman's active sales talent market, you revoke their card, keep the profile URL, and reassign it to their replacement without losing the scan history or the QR codes already printed on backs of brochures.
        </p>

        <h2>Oman-Specific Touches That Paper Cards Can't Do</h2>
        <p>
            A <?php echo $brandName; ?> card for an Oman sales rep can include: a tap-to-call in Oman's +968 format with extension handling, a direct link to the Commercial Registration (CR) record on MoCIIP's Invest Easy so serious buyers can verify you're a real Omani entity in seconds, an attached PDF company profile, a direct Google Maps pin to your HQ (say, Al Ghubra roundabout or the PDO ring road), and an Apple Wallet / Google Wallet pass so the recipient carries your card in the same place they keep their nol card or Bank Muscat card.
        </p>
        <p>
            Ramadan is another quiet superpower. During the holy month, your rep can switch their card to a "Ramadan Kareem" theme with adjusted working hours automatically, no more awkward "sorry, we're on half-day schedule" back-and-forth. National Day on 18 November, Sultan's Accession Day, and Renaissance Day themes are one click from the card editor.
        </p>

        <h2>Pricing That Fits Oman SME Budgets</h2>
        <p>
            The platform is free forever for unlimited reps. You only pay when you order printed cards, from OMR 5.000 per 100 Standard cards, OMR 6.000 Premium, OMR 15.000 Luxury, or OMR 10.000 per NFC tap card, fulfilled by our verified Muscat print shops and delivered within 3 working days to any address in Oman, including Duqm and Salalah. Every card includes bilingual Arabic/English profiles, custom subdomain (yourcompany.cardify.om), and a branded QR that matches your corporate colours.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Ready to equip your team?</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team, free with <?php echo $brandName; ?>. Roll out to your whole sales org on day one, no seat limits, no subscription.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Create Your Free Account
                <i class="fa-solid fa-arrow-right"></i>
            </a>
            <p class="text-blue-700 text-sm mt-3">Arabic version of this page available on request.</p>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
