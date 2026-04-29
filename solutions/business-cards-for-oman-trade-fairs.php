<?php
/**
 * Cardify - Business Cards for Oman Trade Fairs (OIF, OFEX, IDEX Oman)
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Oman Trade Fairs (OIF, OFEX, IDEX Oman), Cardify';
$pageDescription = 'Never run out of cards at OCEC. Digital cards with event mode, tag scans by booth, capture leads, export to CRM. Built for OIF, OFEX, Oman Food, IDEX Oman.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-for-oman-trade-fairs';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Oman Trade Fairs', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Business Cards for Oman Trade Fairs (OIF, OFEX, IDEX Oman)',
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
                <span class="text-gray-700">Trade Fairs & Exhibitions</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Oman Trade Fairs (OIF, OFEX, IDEX Oman)</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                An Oman exhibition week at OCEC can produce 800 cards handed out and 20 follow-ups completed. Cardify changes that ratio, every scan is a tagged, exportable lead by the time you pack up the booth.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>The Oman Exhibition Calendar, Where Your Cards Go To Die</h2>
        <p>
            Oman runs a surprisingly dense trade-fair calendar through the Oman Convention & Exhibition Centre (OCEC) in Madinat Al Irfan, alongside venues like Oman Convention Centre Seeb and, for larger defence and industry shows, the Al Seeb exhibition grounds. Annual highlights include the Oman International Trade Fair (OIF), Oman Food & Hospitality (OFEX), Oman Green Awards & Sustainability Forum, SPE Oil Show, IDEX Oman (defence and security), Oman Health Expo, the International Property Show Muscat, the Gulf Print & Pack Oman, the Middle East Career Fair Oman, COMEX (tech and IT), Oman Energy Transition Week, OPES (Petroleum & Energy Show), and the biannual Build & Infrastructure Expo. Each of these generates hundreds of exhibitor-to-visitor interactions per day, and each paper-card exchange is a lead that typically gets lost.
        </p>
        <p>
            The math is brutal: a typical exhibitor team of 5 people each hands out 150 cards per day across a 3-day show. That's 2,250 cards distributed. Post-show follow-up rate on paper cards at Oman shows is 3–6%, meaning 2,100+ of those cards are wasted print, design, and booth time. Cardify fixes this at the core.
        </p>

        <h2>Event Mode, Tagging, Lead Capture, Live Dashboard</h2>
        <p>
            When you switch your team's cards to "Event Mode" for an exhibition, three things happen. First, the card URL includes an event identifier (e.g., ?event=ofex2026), so every scan is tagged with the specific show. Second, when a visitor taps "Save Contact" they're prompted to optionally leave their own contact in a two-way exchange, name, title, company, email, WhatsApp, which is captured into your Cardify dashboard. Third, you get a live dashboard accessible from any team member's phone showing scans-per-team-member, geographic breakdown, interest tags (visitor can tap "I'm interested in: [Product A, B, C]" in the exchange flow), and exportable CSV/JSON at any moment.
        </p>
        <p>
            At the end of day 1 at OFEX, your Oman business development director can see: "Ahmed captured 34 leads, 22 of them qualified, 8 asked about our cold chain logistics product. Fatma captured 28 leads, 15 from KSA visitors specifically." That's a level of exhibition ROI measurement most Omani exhibitors have never had access to.
        </p>

        <p><strong>Create digital business cards for your team, free to start with <?php echo $brandName; ?>.</strong> Add Event Mode for your next OCEC show. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free &rarr;</a></p>

        <h2>Booth Design Around NFC, The New Standard</h2>
        <p>
            Leading exhibitors at OCEC have started redesigning their booths around NFC and QR. Instead of a visible stack of printed brochures, the booth has a single prominent "Tap here for brochure" NFC disc on the counter. The visitor taps, they receive the full brochure PDF, the relevant product specs, the company's digital card, and an invitation to book a post-show demo call. This is clean, measurable, and, importantly in Oman's summer heat, doesn't require your team to stand all day under booth lights holding paper. Cardify provides the NFC discs (each programmed with a unique ID so scan-count is attributable to specific booth positions), the backend lead capture, and the brochure hosting with PDF analytics so you see which pages visitors read.
        </p>

        <h2>Coordinating Multi-Person Booths at OFEX, OIF, and IDEX Oman</h2>
        <p>
            A typical exhibitor at a major Oman show fields a booth team of 5–8 people: sales reps (split by product line), technical specialists (for deeper demos), a senior executive for VIP conversations, a marketing manager for social media, and a booth manager handling logistics. With Cardify, each team member's card is role-specific: the sales rep cards emphasise the product catalogue; the technical specialists' cards emphasise specs and datasheets; the senior executive's card includes a direct booking link to a post-show coffee meeting at, say, the nearby Hilton Garden Inn Muscat Al Khuwair. Each scan logs which role-type was engaged, so post-show you see "47 visitors engaged with technical, 89 with sales, we need stronger technical content next year".
        </p>

        <h2>Post-Show Follow-Up That Actually Happens</h2>
        <p>
            The reason post-show follow-up fails is logistics: someone has to type 800 paper cards into a CRM, segment them, write follow-up emails, and send them, all within 10 days before the leads go cold. Cardify eliminates this entirely. Every scan is already in your CRM via webhook (we integrate with Salesforce, HubSpot, Zoho, Odoo, Microsoft Dynamics 365, and via generic CSV/API). You can build email or WhatsApp follow-up sequences tied to the exhibition identifier, "3 days post-OFEX, send the follow-up email; 7 days post-OFEX, send the 'book a demo' SMS; 14 days post-OFEX, send the meeting request from the sales rep who engaged them". The exhibition investment starts generating measurable pipeline within two weeks instead of dying on a pile of business cards in a desk drawer.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Turn your next OCEC show into pipeline</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team, free to start with <?php echo $brandName; ?>. Enable Event Mode before the show; export tagged leads to your CRM while packing up the booth.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Free Before Your Next Show
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
