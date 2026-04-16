<?php
/**
 * Cardify - NFC Business Cards for Oman Executives
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'NFC Business Cards for Oman Executives — Cardify';
$pageDescription = 'Metal, wood or premium matte-black NFC cards for Oman C-suite. Tap to share — no app required. Delivered to Muscat, Sohar, Salalah within 3 working days.';
$canonicalUrl = 'https://cardify.om/solutions/nfc-business-cards-oman-executives';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'NFC Business Cards for Oman Executives', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'NFC Business Cards for Oman Executives',
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
                <span class="text-gray-700">NFC Cards for Executives</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">NFC Business Cards for Oman Executives</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                A single tap. The recipient's phone buzzes. Your name, title, CR number, WhatsApp, LinkedIn, and company profile appear — no app installed, no friction. For C-suite in Oman who meet ministers, ambassadors, and chairmen weekly.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Why NFC Became the Executive Standard</h2>
        <p>
            An Oman chairman or CEO — whether leading a family conglomerate like Al Nahda Group, a sovereign-linked platform under Oman Investment Authority, or a founder-led company in Knowledge Oasis Muscat — doesn't actually need 500 paper cards. They need perhaps five meaningful exchanges per day with the right people: a board member, a visiting minister, a delegation from a Gulf sovereign wealth fund, an international investor doing due diligence on an Oman Vision 2040 project. In each of those encounters, the exchange needs to feel premium, be instant, and leave behind something the recipient can actually use three weeks later.
        </p>
        <p>
            A premium NFC card — metal, wood, or matte-black printed PVC with an embedded NFC chip — does this elegantly. You tap it to the top of the recipient's phone (iPhone XR and later, every modern Android), their phone buzzes, and a notification appears prompting "Open cardify.om/ali-alzaabi-ceo". They tap it, your bilingual Arabic/English profile opens full-screen. No app, no QR code to scan awkwardly in a Ministry meeting room, no fumbling. The whole exchange is under 3 seconds, and the recipient remembers the card itself — cold metal, weighty, a small statement of taste.
        </p>

        <h2>What Cardify NFC Cards Include</h2>
        <p>
            Every <?php echo $brandName; ?> NFC card is manufactured with an NTAG216 chip (928 bytes of user memory, globally-readable by every modern smartphone), laser-engraved or UV-printed with your brand, and programmed to resolve to your <?php echo $brandName; ?> profile URL. Because the URL is hosted on your side, you can change what loads at that URL any time — a new job title, a new company, a promotion, without ever reprinting. A chairman who was CEO of one entity and moves to chair a different board can keep using the same cards.
        </p>
        <p>
            Finish options include: <strong>Stainless steel</strong> (2mm brushed, laser-engraved, weighs ~18g, a serious statement piece), <strong>Walnut or oak wood</strong> (sustainable, warm, unique Omani aesthetic when paired with incense-inspired branding), <strong>Matte black PVC</strong> (stealth-chic, commonly chosen by tech founders in KOM), and <strong>Gold-plated</strong> (specifically requested by senior Omani executives attending delegations abroad — the card becomes a small diplomatic gift). Pricing starts around OMR 12 per card for PVC and scales to OMR 45 for stainless steel.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> When you're ready, order premium NFC cards through our Muscat partner. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free &rarr;</a></p>

        <h2>Use Cases Specific to Oman C-Suite</h2>
        <p>
            During state visits to the Sultanate — of which Oman hosts dozens each year via the Royal Office and Ministry of Foreign Affairs — delegations exchange cards in structured gift-ceremony fashion. A matte black or steel NFC card feels appropriate for these settings; a flimsy 350gsm paper card does not. At the Future Investment Initiative (FII) summit delegations to Riyadh, COP climate meetings, the IMF spring meetings in Washington, or the World Economic Forum in Davos, Oman's executives carry a stack of six to eight NFC cards and dispense them with precision — not a box of 200 paper cards.
        </p>
        <p>
            At the Oman Convention & Exhibition Centre during Duqm Economic Forum or Oman Energy Transition Week, an executive can walk the floor carrying one NFC card, tap it to any interested counterpart, and continue. No need to remember whom you gave a paper card to — every tap is logged in your Cardify dashboard with the date, time, and rough location (derived from the recipient's device locale). Back at the office the next morning, you have a clean list of 14 meaningful conversations to follow up on.
        </p>

        <h2>Security & Privacy — Important for GLEs and Family Offices</h2>
        <p>
            Executives in government-linked entities or family offices are rightly cautious about digital footprint. Cardify NFC cards do not require the recipient to install anything, do not harvest their phone number, and do not auto-subscribe them to a newsletter. The NFC chip is read-only after manufacturing (it cannot be re-written by a malicious third party), and the URL it resolves to is served over HTTPS with a privacy-first tracking stance: we count scans, we do not track individuals. For executives who need an even harder privacy stance, we offer "private profile" mode where the card URL requires a 4-digit PIN (shared verbally) to unlock the full contact details.
        </p>

        <h2>Delivery Across Oman</h2>
        <p>
            Printed NFC cards are produced in Muscat by our partner print shop (ISO 9001 certified). Standard delivery is 3 working days anywhere in Oman — Muscat, Sohar, Nizwa, Sur, Ibri, Duqm, Salalah. Express same-day delivery available within Muscat governorate (Ruwi, Qurum, Al Khuwair, Al Ghubra, Al Khoudh, Seeb, Al Mawaleh, Al Mouj). You can include personalised engraving — name in Arabic calligraphy by a local Omani calligrapher (often Nihan Abdulaziz's studio) for a truly unique executive card.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Meet with the card that matches your role</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Upgrade to metal NFC when you're ready; delivered across Oman within 3 working days.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Set Up Your Executive Card
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
