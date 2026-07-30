<?php
/**
 * Cardify - Business Cards for Ramadan Networking Events in Oman
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$pageTitle = 'Business Cards for Ramadan Networking Events in Oman, Cardify';
$pageDescription = 'Ramadan iftars, ghabgas and corporate majlis are where Oman\'s deals get done. Digital cards with Ramadan Kareem themes, adjusted working hours and bilingual greetings, free.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-for-ramadan-networking';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Business Cards for Ramadan Networking', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = Seo::articleNode(
    __FILE__,
    'Business Cards for Ramadan Networking Events in Oman',
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
                <span class="text-gray-700">Ramadan Networking</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Ramadan Networking Events in Oman</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                In Oman, Ramadan is when relationships get deeper. Iftar at the Kempinski, ghabga at the Al Bustan Palace, corporate majlis in Seeb, these are the rooms where deals are tabled. A Ramadan-themed Cardify card greets the recipient in the spirit of the month and never runs out when you're juggling a plate of dates.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>The Ramadan Business Calendar in Oman</h2>
        <p>
            The Holy Month of Ramadan rewires Oman's business rhythm. Ministry working hours compress to roughly 9am to 2pm under Royal Decree for the public sector, the private sector typically adjusts to 9am–3pm or 10am–4pm under Ministry of Labour guidance, and evening activity picks up dramatically after Maghreb prayer when iftars begin. Corporate iftars hosted by Oman Chamber of Commerce, family business groups like Suhail Bahwan, W.J. Towell, Zubair Corp, Bank Muscat, and embassies become the most productive single networking week of the Omani year. Ghabgas, the late-night meals between iftar and suhoor, are hosted at private diwans from 10pm to 1am and are where mid-level executives, heads of procurement, and government counterparts sit with each other for two hours without an agenda. You cannot replace a Ramadan majlis with Zoom, and you cannot hand out the same paper business card you handed out in January.
        </p>

        <h2>Why Your Paper Card Feels Off in Ramadan</h2>
        <p>
            A paper card designed for Q4 looks mismatched when handed across a dates-and-laban table in Ramadan. Digital cards let you seasonally theme the look: a crescent-and-lantern (hilal and fanoos) illustration in muted gold, a "Ramadan Kareem, رمضان كريم" greeting that appears on the card landing page for 30 days and then quietly disappears on Eid, and a note showing your adjusted office hours (important, your contact will want to know whether to call at 11am or after iftar). The Cardify dashboard has a "Ramadan mode" toggle that applies these changes to every team member's card simultaneously, then reverts automatically on 1 Shawwal.
        </p>

        <p><strong>Create digital business cards for your team, free to start with <?php echo $brandName; ?>.</strong> The Ramadan theme is included on every plan. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Set up in 3 minutes &rarr;</a></p>

        <h2>Iftar-Ready Features</h2>
        <p>
            Iftar sharing has unique friction: your hands are full, you're sitting on a low majlis-style sofa, the lighting is warm and dim, and the person across from you is finishing their first cup of Omani coffee (kahwa). Fumbling for a paper card in this moment breaks the mood. With <?php echo $brandName; ?>'s NFC card, you tap it to their phone, done. For those without NFC, the QR on your phone's "digital card" screen scans in under a second even in low light (we use black-on-white high-contrast, avoiding fancy gradients that fail in dim rooms). The recipient sees your name, title, company in Arabic and English, a "Save Contact" button, and a "WhatsApp me after Eid" shortcut that pre-fills: "Ramadan Kareem! Good to meet you at [event]. Shall we catch up after Eid?", a culturally-appropriate follow-up message that most recipients reply to within 24 hours.
        </p>

        <h2>Corporate Iftar Playbook Using Cardify</h2>
        <p>
            If your company is hosting a Ramadan iftar, say for 150 guests at the Crowne Plaza Muscat, Cardify can generate a single event QR code that every attendee scans on arrival. Tap the code, enter your name and company, and you're "introduced" to everyone else who scanned. The host company gets an attendee list exported to CSV with full contact details (name, title, company, phone, email), a mini CRM database from a single evening. This replaces the awkward registration sheet at the entrance and the pile of paper cards everyone collects and then loses.
        </p>
        <p>
            For attendees: your personal card's "event mode" shows the specific event you're at on the card header ("Meeting you at ABC Group Iftar 2026") and adds it to the recipient's saved context. When you reconnect three weeks later, your contact sees "Met at ABC Iftar on 14 Ramadan" and the conversation restarts with context instead of cold awkwardness.
        </p>

        <h2>Eid Follow-Through, The Part Everyone Forgets</h2>
        <p>
            Thirty days of iftars produce 200+ new contacts for an active networker. Most go cold because the first business day after Eid Al Fitr is chaos and nobody remembers to follow up. Cardify's dashboard has a "Ramadan contacts" view, every card scan during Ramadan is tagged, and on day 2 of Eid Al Fitr you get a reminder email: "You met 34 new contacts this Ramadan. Here's a template Eid Mubarak message, shall I send it via WhatsApp?" One tap sends a bilingual Eid Mubarak greeting to all 34, tagged with your Cardify card link. Response rates are typically 60–80%, more than three times the response rate of a cold "circling back" email a week later.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Don't arrive at iftar unprepared</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team, free to start with <?php echo $brandName; ?>. Switch to Ramadan theme with one click; revert on Eid automatically.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Create Your Ramadan Card
                <i class="fa-solid fa-arrow-right"></i>
            </a>
            <p class="text-blue-700 text-sm mt-3">Full Arabic version of this page available.</p>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
