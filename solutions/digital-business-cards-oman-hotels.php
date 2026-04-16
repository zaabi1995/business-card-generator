<?php
/**
 * Cardify - Digital Business Cards for Oman Hotels & Hospitality Staff
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Digital Business Cards for Oman Hotels & Hospitality Staff — Cardify';
$pageDescription = 'Concierges, F&B managers, event coordinators at Kempinski, Shangri-La, Al Bustan, Anantara. Guest-shareable digital cards with direct booking and WhatsApp.';
$canonicalUrl = 'https://cardify.om/solutions/digital-business-cards-oman-hotels';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Hotels & Hospitality Oman', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Digital Business Cards for Oman Hotels & Hospitality Staff',
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
                <span class="text-gray-700">Hotels & Hospitality</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Digital Business Cards for Oman Hotels & Hospitality Staff</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Guests remember the concierge who solved their Wadi Shab plan, the F&B manager who arranged the private dinner at the Gulf, the event coordinator who made the Omani wedding unforgettable. Give them a card the guest can actually keep.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Oman's Hotel Industry — Premium, Growing, Guest-Obsessed</h2>
        <p>
            Oman's hotel landscape has matured into one of the most distinctive in the Middle East. At the luxury tier, the Al Bustan Palace (Ritz-Carlton), Shangri-La Barr Al Jissah, Kempinski Muscat at Al Mouj, W Muscat, Anantara Al Baleed Salalah, Alila Jabal Akhdar, Six Senses Zighy Bay, and the new St. Regis at Al Mouj define the Sultanate's hospitality identity. Mid-tier business and leisure hotels — Crowne Plaza Muscat, Hilton Salalah, Radisson Blu, Hormuz Grand, InterContinental Muscat, Sheraton Oman, Millennium Resort Mussanah, Jumeirah Muscat Bay — carry enormous volume. Domestic boutique operators like Anantara Jabal Al Akhdar, Dunes by Al Nahda Resort, and the new heritage homestays in Nizwa and Jebel Shams add character the international chains can't replicate.
        </p>
        <p>
            Every one of these properties employs front-of-house staff whose job is, in essence, relationship management: concierges, guest relations, F&B managers, event coordinators, spa managers, GSAs at check-in, and activities coordinators. Their personal service is what pushes a guest from TripAdvisor 4 stars to 5. A Cardify digital card is how guests keep the relationship warm long after checkout.
        </p>

        <h2>Concierge & Guest Relations — The Card That Actually Gets Used</h2>
        <p>
            A concierge at the Chedi Muscat or the Shangri-La doesn't just give recommendations; they plan a guest's week. Wadi Shab, Wadi Bani Khalid, Bimmah Sinkhole, the Omani dhow cruise at Sidab, the Al Hamra heritage walk, the Nizwa Friday goat market, the Jebel Shams balcony hike — a good Muscat concierge arranges 10–15 bookings during a guest's 5-day stay. With a Cardify card, the concierge texts the guest their card on WhatsApp on day 1. For every activity they arrange, they share a follow-up: booking confirmations, directions, weather update, meeting point. The guest keeps the concierge's card pinned in WhatsApp. On returning to Oman a year later, they WhatsApp the same concierge — converting the relationship into direct repeat business that bypasses the OTA commission to Booking.com or Expedia.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> Ideal for hotel teams ranging from boutique 20-room properties to 400-room resorts. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free &rarr;</a></p>

        <h2>F&B Managers & Event Coordinators — Conversion Machines</h2>
        <p>
            An Omani wedding at the Kempinski ballroom can run 400–800 guests at OMR 50–90 per cover, totalling OMR 30,000+. The event coordinator who secures that booking built the relationship over 6–12 months — often starting at a Ramadan iftar, continuing through a bride's family tasting session, and culminating in a signed contract three months before the wedding. Her personal card matters. With Cardify, her card includes: photo, full name in Arabic and English, role, direct line, WhatsApp Business, a photo gallery of recent events she's coordinated (cleared for sharing), and a booking link to a tasting session or a site visit. When a mother-of-the-bride WhatsApps her friend asking "who did my daughter's wedding?", the friend forwards the coordinator's Cardify link — and it arrives with enough visual proof and scheduling friction removed to convert in one message exchange.
        </p>
        <p>
            The same applies to F&B managers running private dining at the Beach Pavilion at Shangri-La, or banquet managers at the Al Bustan. Their cards are effectively micro-landing-pages for their specific space, with seasonal menus, wine list PDFs, and a direct booking link.
        </p>

        <h2>Housekeeping & Back-of-House Recognition</h2>
        <p>
            Many Oman luxury hotels now issue business cards to senior housekeeping and back-of-house staff whose work guests notice — the head housekeeper who arranges a special turndown service, the spa therapist a guest specifically requests, the executive chef whose Omani-inspired tasting menu the guest wants to rave about on Instagram. Cardify lets properties issue these cards centrally, branded to property standards, with the staff member's photo and role, and a simple "Thank & Tip" button linking to an internal tipping system (where properties operate one) or a review link to Google or TripAdvisor. This converts staff appreciation into measurable digital reviews, which matter for the property's OTA ranking.
        </p>

        <h2>Multi-Property Chains — Central Admin Across Oman</h2>
        <p>
            If you run multiple properties in Oman — Anantara operates Al Jabal Al Akhdar and Al Baleed Salalah; Millennium operates multiple resorts; the Oman Hotels and Tourism Company (OHTC) operates a portfolio — Cardify's multi-property mode lets each property have its own branding while maintaining a group-level card directory. A corporate DOSM sitting at the Muscat HQ can pull a scan report across all properties, identify which property's F&B team is driving the most cross-property event referrals, and surface bright spots to replicate.
        </p>

        <h2>Seasonal Features — Khareef, Eid, National Day</h2>
        <p>
            Salalah hotels switch communication style for Khareef; Muscat hotels emphasise diving and heritage in cooler months; during National Day (18 November), staff cards can carry a flag-backed theme for the celebration week. Cardify's seasonal theme engine handles these without manual work — you configure the calendar once, the cards switch on the right day and switch back automatically.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">The card that keeps guests coming back</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Bilingual, guest-shareable on WhatsApp, seasonal themes for Khareef, Eid, and National Day.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Hotel Team
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
