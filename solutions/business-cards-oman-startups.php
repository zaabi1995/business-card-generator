<?php
/**
 * Cardify - Business Cards for Omani Startups & Tech Founders
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Omani Startups & Tech Founders, Cardify';
$pageDescription = 'Startups at KOM, Ejadah, Oman Technology Fund-backed companies. Founder cards with pitch deck link, waitlist capture, investor CV download. Free platform.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-oman-startups';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Omani Startups & Tech Founders', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Business Cards for Omani Startups & Tech Founders',
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
                <span class="text-gray-700">Startups & Tech Founders</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Omani Startups & Tech Founders</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Your card at a KOM event or a Future Fund pitch needs to do five things at once: introduce you, show traction, share the deck, capture the investor's contact, and not look like every other generic SaaS. Cardify is built for that.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>The Oman Startup Scene in 2026</h2>
        <p>
            Oman's startup ecosystem has matured into a credible Gulf player. Knowledge Oasis Muscat (KOM) remains the geographic hub; Riyada (Oman SME Development Authority) runs programs, grants, and networking; Oman Technology Fund (OTF), Future Fund Oman, and a growing set of VCs including Phaze Ventures, Plus VC, Wamda Capital (regional), and Shorooq Partners (regional) actively deploy into Omani and pan-GCC startups. Accelerators include the Oman Vision 2040 Incubator, the Omantel Tech Accelerator, the BankMuscat-backed fintech sandbox, and the growing maker-space community at Bait Al Zubair and Selen. Notable Omani-founded scale-ups, Mamun (fintech), TruKKer (logistics), Liwan (edtech), Ubhar Capital (wealth), Reached (SaaS), CupsByAA (D2C), and Cardify itself, prove the ecosystem ships.
        </p>
        <p>
            Founders at this stage are hyper-networked. A typical week includes: a Tuesday morning office hour at Riyada, a Wednesday pitch night at KOM, a Thursday founder-to-founder coffee at Foxy Cafe or Amwaj Hub, a Sunday investor update call, and a Saturday weekend code sprint. Across that week, a founder meets 30–50 people, angel investors, fellow founders, potential hires, potential customers, corporates exploring partnerships, and government stakeholders. A paper business card misses almost everything the founder needs to communicate in those interactions.
        </p>

        <h2>A Card That Shows Traction, Not Just Title</h2>
        <p>
            "Founder & CEO, Stealth Startup" on a paper card is unhelpful. Cardify's founder card includes: your name, the company name (or "Building in stealth, ask me"), a one-line positioning ("WhatsApp-native CRM for GCC SMEs, 40% MoM growth"), the latest traction number (MRR, users, waitlist size, pilot customers, one metric only, updated weekly), your LinkedIn, Twitter/X, and a "Download the deck" link that gates access to your pitch deck behind an email capture (so you know who downloaded it and can follow up). The card also links to a "Book a 20-minute demo" Calendly-style scheduler, a "Join the waitlist" page, and your investor intro video if you have one.
        </p>
        <p>
            For investors meeting dozens of founders per event, this is transformational. They tap your card, see your traction in 2 seconds, tap the deck download, skim it in the taxi on the way home, and you're the founder they remember from that evening because you were the one whose card told them something other than a job title.
        </p>

        <p><strong>Create digital business cards for your team, free to start with <?php echo $brandName; ?>.</strong> Free platform covers everything, from pre-seed founder to Series C team. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start your founder card &rarr;</a></p>

        <h2>Team Cards That Scale With Hiring</h2>
        <p>
            Series A and beyond, startups hire fast. A 6-person team becomes 20 in a year. Cardify's startup plan gives you unlimited team cards with a consistent brand, same logo, same colours, same typography, all tied to your @yourstartup.com or @yourstartup.om domain. When you hire a new engineer on Monday, HR creates their card in 30 seconds; on Tuesday they're at a KOM meetup with a proper professional identity. When someone leaves, you revoke in one click and the URL returns a graceful "Ali has moved on, here's his replacement" landing page, preserving the professional brand even during churn.
        </p>
        <p>
            For hiring, your team's cards become recruiting tools. A candidate meeting your Head of Engineering at a Muscat tech meetup sees her card, sees the engineering blog link, sees the GitHub, sees the "We're hiring: Senior Backend Engineer" CTA, and applies from the card itself. Card-driven hiring pipeline is measurable in Cardify's dashboard.
        </p>

        <h2>Fundraising Roadshow, Travel-Ready</h2>
        <p>
            Oman founders raising regional or global rounds travel to Riyadh (Saudi VCs), Dubai (pan-GCC funds, Hub71 intros), London and New York (tier-1 VCs), and increasingly Shenzhen and Singapore (Asian LPs). Your card needs to work in every jurisdiction. Cardify's card auto-detects the recipient's language and renders English, Arabic, Mandarin, or Japanese on demand. A Riyadh VC sees the Arabic side first; a Singapore LP sees the English with a Mandarin option prominent. Your card isn't "an Oman card", it's "a global-ready card with a strong Oman story".
        </p>
        <p>
            For practical travel: the NFC card works without internet. If you're meeting a VC at a Riyadh coffee shop with patchy Wi-Fi, the tap still triggers their phone to try the URL; once their phone gets connectivity in the car, the card loads and your deck downloads. No "Sorry the Wi-Fi is weak, let me write my email down" moment.
        </p>

        <h2>Founder Community & Giving Back</h2>
        <p>
            Cardify is built by founders (BHD Group, the company behind Cardify, is led by an Omani founder, Ali Al Zaabi). We offer a free "founder plan" to early-stage Omani startups through partnerships with Riyada, KOM, and Oman Technology Fund, if you're a registered Omani startup pre-Series A, contact us for the founder plan access code. We believe the Oman startup ecosystem succeeds when founders can punch above their weight in every investor meeting. A better business card is a small but real part of that.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Built by Omani founders, for Omani founders</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team, free to start with <?php echo $brandName; ?>. Pitch decks, waitlists, Calendly-style booking, and global language rendering built in.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Create Your Founder Card
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
