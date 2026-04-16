<?php
/**
 * Cardify - Business Cards for Omani Freelancers & Consultants
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Omani Freelancers & Consultants — Cardify';
$pageDescription = 'For self-employed and freelance-licensed Omanis under MoCIIP. Bilingual personal brand cards with portfolio, booking, invoice and payment links. Free to start.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-oman-freelancers-consultants';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Omani Freelancers & Consultants', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Business Cards for Omani Freelancers & Consultants',
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
                <span class="text-gray-700">Freelancers & Consultants</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Omani Freelancers & Consultants</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Your brand is you. Your card should be your portfolio, your calendar, your Apple Pay invoice button, and your Instagram link — in one scan. Built for Oman's fast-growing self-employed economy.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>The Omani Freelance Economy in 2026</h2>
        <p>
            Self-employment has gone from niche to mainstream in Oman over the last five years. The Ministry of Commerce, Industry and Investment Promotion (MoCIIP) introduced the Self-Employment Card (بطاقة العمل الحر) under Riyada (Oman SME Development Authority), making it straightforward for Omani nationals to register as self-employed freelancers — photographers, trainers, consultants, graphic designers, content creators, personal trainers, stylists, nutritionists, architects, software developers, videographers, wedding planners. There are now tens of thousands of registered self-employment cardholders across the Sultanate, alongside a much larger informal economy of side-hustlers running businesses from Instagram and WhatsApp.
        </p>
        <p>
            For this population, a traditional business card is almost irrelevant: printing 500 cards is overkill, storing them is a burden, and the card format doesn't show what you actually do. A freelance photographer needs their portfolio on the card; a personal trainer needs a training-slot booking link; a management consultant needs their case studies; a content creator needs their Instagram and TikTok. Cardify is designed from the ground up for exactly this.
        </p>

        <h2>Your Portfolio on the Card</h2>
        <p>
            Cardify's freelancer profile includes a portfolio section — a visual grid of up to 12 recent works with captions. A photographer might show their recent Omani wedding shoot at Bait Al Zubair, a lifestyle session at Al Mouj Marina, an architecture project at the Oman Across Ages Museum, and a product shoot for a local cosmetics brand. A content creator might show their last four Reels with view counts. A designer might show their top logo/brand projects. Every portfolio piece links to the full project (Instagram post, Behance, Dribbble, Vimeo, YouTube) and the portfolio updates in real time when you publish new work.
        </p>

        <h2>Booking, Payment & Invoice — A One-Person Business System</h2>
        <p>
            Freelancers lose jobs to friction: a potential client messages on Instagram, asks the price, gets no clear answer, gets nudged to send a WhatsApp, the freelancer is between shoots, two days pass, the client goes to someone else. Cardify eliminates this. Your card includes: a rate card (either fixed prices or "from OMR X" ranges), a one-click booking link (your Google Calendar availability, filtered by service type), and an Apple Pay / Google Pay / Paymob payment link for deposit collection. A client reading your card on Thursday evening can book a Friday morning 90-minute strategy call, pay the OMR 30 deposit, and be in your calendar — all before you see the notification.
        </p>
        <p>
            Once the work is done, Cardify can generate a simple PDF invoice with your CR number (or self-employment card number), VAT registration if applicable (the Oman Tax Authority's 5% VAT threshold means not all freelancers need to register — Cardify handles both cases), bank details (we never auto-prefill bank numbers — you enter them once carefully), and a payment link. The client taps the invoice, pays, and you get paid without the endless "have you sent the bank details?" back-and-forth.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> The free tier includes everything a solo freelancer needs. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start your freelance card &rarr;</a></p>

        <h2>Personal Brand, Not Corporate Template</h2>
        <p>
            A corporate card template would be the wrong fit for a freelancer — it signals generic. Cardify's "personal brand" card design gives you a cleaner, more Instagram-compatible look: a large profile photo (professional headshot or on-brand lifestyle shot), your name in your chosen typography, a single crisp tagline ("Muscat wedding photographer — capturing your Big Day"), and a vertical card layout that shares beautifully in WhatsApp status and Instagram Stories. Omani freelancers in visual industries — photography, video, design, fashion, food styling — report that the vertical card converts 2–3x better for Instagram-sourced leads.
        </p>
        <p>
            Your bilingual name matters. If you go by a professional name different from your Civil ID name (common for content creators and artists), Cardify lets you display the working name prominently while preserving the formal name for invoices and contracts.
        </p>

        <h2>Networking at Riyada, Knowledge Oasis, and StartOman Events</h2>
        <p>
            Events like the Riyada SME forums, StartOman pitch nights at Knowledge Oasis Muscat, the Sultan Qaboos Higher Centre for Culture and Science events, and the growing coworking community at Amwaj Hub, Selen, and The Workroom regularly put freelancers face-to-face with potential clients. Cardify's event mode for freelancers is lightweight — tap a card, capture the client's name and project interest, Cardify auto-drafts a follow-up WhatsApp for you to send the next morning. The measurable lift from structured follow-up on freelancer bookings is significant: we see freelancers on Cardify close 3–4 additional paid jobs per month specifically traced to better post-event follow-through.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Your one-person business, on a card</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Portfolio, booking, payment, invoices — all on your personal card.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Create Your Freelance Card
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
