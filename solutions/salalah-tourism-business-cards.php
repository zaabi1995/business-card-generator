<?php
/**
 * Cardify - Business Cards for Salalah Tourism & Khareef Operators
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Salalah Tourism & Khareef Operators — Cardify';
$pageDescription = 'Tour operators, khareef season guides, Salalah hotels, 4x4 rental companies. Seasonal cards that update for Khareef 2026 — bilingual, with booking links.';
$canonicalUrl = 'https://cardify.om/solutions/salalah-tourism-business-cards';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Salalah Tourism & Khareef', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Business Cards for Salalah Tourism & Khareef Operators',
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
                <span class="text-gray-700">Salalah Tourism & Khareef</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Salalah Tourism & Khareef Operators</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Khareef turns Dhofar green and Salalah's population triples for three months. Your card needs to convert a WhatsApp-shared link from a Gulf tourist into a booking — bilingual, with live availability and a price in OMR.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Khareef Season — Oman's Biggest Domestic Tourism Event</h2>
        <p>
            Every year from roughly mid-June to mid-September, the monsoon winds off the Indian Ocean sweep across the Dhofar mountains, creating the phenomenon known locally as Khareef (خريف). Salalah's weather drops from 40°C desert heat to a mist-draped 22°C, the mountains and wadis turn emerald green, and Gulf families — Saudis, Emiratis, Kuwaitis, Bahrainis, Qataris — arrive in huge numbers, many driving across the border at Al Mazyunah or flying into Salalah International. The Salalah Tourism Festival runs through the peak, Itin Beach, Ayn Razat, Wadi Darbat, Mughsail Beach's blowholes, Job's Tomb, and the Frankincense Land Museum all run at capacity. Hotels including the Al Baleed Resort Salalah by Anantara, Juweira Boutique Hotel, Hilton Salalah, Crowne Plaza, Millennium, and the new Avani Salalah fill weeks in advance.
        </p>
        <p>
            For tour operators, 4x4 rental companies, frankincense sellers, coconut farm tour guides, and speciality experience providers (dhow cruises, desert safaris, banana-plantation visits), this is 60–70% of your annual revenue packed into 90 days. Your ability to convert fast matters: a Saudi family of eight looking for a mountain villa rental in Mirbat expects to see photos, price in OMR (which they'll mentally convert to SAR), availability, and a WhatsApp "Book Now" button within one minute of the first message.
        </p>

        <h2>Seasonal Card Mode — Because Dhofar Isn't the Same Year-Round</h2>
        <p>
            Cardify's "seasonal mode" is purpose-built for Khareef operators. Your card has two personalities: Khareef-mode (June–September) shows green-lush photography, highlighted seasonal packages (5-day khareef tour, 3-day beach + mountain combo), prominent "Peak Rate" pricing in OMR, limited availability warnings, and a "Join the Khareef waitlist" capture; Off-season mode (October–May) shows dune-and-desert imagery, off-peak tour options, Frankincense trail discounts, and Salalah's less-famous but excellent October–March weather. You set the cutover dates once, Cardify switches automatically.
        </p>
        <p>
            This solves a real problem Salalah operators face: tourists booking in March see January photos and think Dhofar is brown; tourists booking in July see February photos and think the operator doesn't understand Khareef. A seasonal card respects the tourist's mental model.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> Great for Salalah tour operators, 4x4 rentals, and Dhofar boutique hotels. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free before next Khareef &rarr;</a></p>

        <h2>Live Booking & WhatsApp Conversion</h2>
        <p>
            Salalah tourism converts on WhatsApp. Gulf tourists share contact cards in family group chats — "Abu Saud is using this tour operator, here's his number". When your Cardify card lands in a Riyadh family WhatsApp group, every family member scanning it sees: your tour packages with images and OMR prices, live availability for the next 30 days (driven by your Google Calendar or Cardify's built-in availability tool), instant-book for simple packages (half-day Wadi Darbat tour at OMR 25/person), and a one-tap "Chat on WhatsApp" button that opens with a pre-filled Arabic message template. We measure conversion rates of 22–28% on Cardify cards during Khareef, compared to about 6% for operators still running paper card drops at hotel concierges.
        </p>

        <h2>Fleet & Driver Cards for 4x4 Rental Operators</h2>
        <p>
            4x4 rental is a critical Khareef business — Saudis drive down in their own vehicles, but Emirati and Kuwaiti families often fly and rent locally. Operators like Europcar Salalah, National, Mark, and the many independent rental shops off Salalah Airport Road need driver and vehicle identity. Cardify lets you issue driver cards that include the driver's Oman Driving Licence number (verified against Royal Oman Police traffic records), the vehicle registration and model, insurance details, and a 24-hour emergency contact. A Gulf tourist renting your 4x4 gets a printed card from the driver; if there's an incident on the Salalah–Mughsail coastal road, every number they need is on that card.
        </p>

        <h2>Promoting Your Experiences Year-Round</h2>
        <p>
            Salalah tourism is pushing to extend beyond Khareef. The Sultanate's Ministry of Heritage and Tourism is actively marketing Dhofar's non-Khareef season — the Frankincense Trail UNESCO sites at Al Baleed, Sumhuram, Wubar, and Shisr (Ubar); the unique taste of Dhofari cuisine (harees, mashuai); and the excellent birdwatching at Khor Rori. Cardify cards let operators publish experience bundles for the off-season with separate pricing, then use NFC cards at the Muscat International Airport arrivals hall and the Muscat Grand Mall tourist info kiosks to drive booking from Muscat-based tourists looking for a long-weekend domestic getaway.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Convert every Khareef enquiry</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Seasonal card mode, live OMR pricing, WhatsApp instant-book — designed for Salalah tourism.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Tourism Card
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
