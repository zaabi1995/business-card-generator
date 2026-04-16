<?php
/**
 * Cardify - Digital Business Cards for Oman Real Estate Agents
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Digital Business Cards for Oman Real Estate Agents — Cardify';
$pageDescription = 'Agents covering Al Mouj, Muscat Hills, Madinat Al Irfan, Sultan Haitham City. Instant property deep-links, MoH-registered agent ID, bilingual. Start free.';
$canonicalUrl = 'https://cardify.om/solutions/digital-cards-oman-real-estate-agents';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Digital Cards for Real Estate Agents', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Digital Business Cards for Oman Real Estate Agents',
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
                <span class="text-gray-700">Real Estate Agents</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Digital Business Cards for Oman Real Estate Agents</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                From Al Mouj show-homes to Madinat Al Irfan launches, real estate in Oman is a trust game played one viewing at a time. A Cardify digital card puts your licence, your listings, and your WhatsApp into the buyer's phone in one tap.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Oman Real Estate in 2026 — A Market That Runs on Viewings</h2>
        <p>
            Oman's real estate market is having its strongest decade in living memory. Al Mouj Muscat continues to deliver waterfront apartments and villas at scale; Sultan Haitham City in Seeb will add tens of thousands of homes; Madinat Al Irfan is transforming the Al Seeb–Al Mawaleh axis with mixed-use towers; Muscat Hills and The Wave-era clusters are seeing a resale boom; Duqm's Integrated Tourism Complex (ITC) is attracting Gulf and Asian buyers; Salalah's khareef-adjacent developments are drawing seasonal second-home investors. Under the Ministry of Housing and Urban Planning's Integrated Tourism Complex (ITC) framework, foreign buyers can now hold freehold in designated zones — which means your typical buyer pool includes Omanis, long-term expats, GCC nationals, and increasingly Indian, British, Russian, and Chinese investors.
        </p>
        <p>
            This diversity means your business card has to work for all of them — bilingually, across phone operating systems, and with enough structured information (agent licence, brokerage CR, live listings, your WhatsApp) that it converts in the 30 seconds a serious buyer gives you before walking to the next show unit.
        </p>

        <h2>What Paper Cards Cost an Oman Real Estate Agent</h2>
        <p>
            A typical agent with Savills Oman, Hamptons International, Cluttons, Betterhomes, Asteco, or one of the excellent home-grown agencies (Crown Castle Properties, Alawi Enterprises, KOM Realty) hands out 40–80 paper cards a week. The conversion from paper card to booked viewing is about 4–7%. The rest are lost, chucked into hotel room bins in Al Mouj Marina, or stuck on a fridge and forgotten. A Cardify digital card with a "Save Contact" two-way exchange and an attached live listings page converts at 18–24% in our customer data — because the buyer walks away with your listings already saved on their phone.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Set up your agent card in 5 minutes &rarr;</a></p>

        <h2>Live Listings on Every Card</h2>
        <p>
            The most valuable Cardify feature for real estate agents is the live listings block on your digital profile. Paste your Property Finder, Bayut, Dubizzle, or your brokerage's website listing URL — we ingest the photos, price, and details and render them on your card. When a buyer scans your card at the Al Mouj sales office, they see your name, licence, phone, and a carousel: "My 6 active listings in Al Mouj — 2BR from OMR 85,000, 4BR villas from OMR 450,000". They can tap any listing for full photos, and book a viewing through a one-click WhatsApp link that pre-fills: "Hi [agent], I'd like to view [listing]. When can we meet?"
        </p>
        <p>
            Listings update automatically — drop a price, mark a unit sold, add new inventory, the card reflects it in 15 minutes without touching your card. If you're an agent with 20 active listings, your digital card becomes a mini-Property Finder profile that you own.
        </p>

        <h2>Licence, Compliance & Trust Signals for Oman Buyers</h2>
        <p>
            Since 2021, real estate brokerage in Oman is more formally regulated under the Ministry of Housing and Urban Planning's broker licensing regime, in coordination with MoCIIP for CR status. Serious buyers — particularly GCC nationals investing OMR 200,000+ into an ITC property — will ask for your agent licence number and the brokerage's CR. A Cardify card displays both as verified fields (we can link directly to the MoCIIP Invest Easy record for the brokerage's CR), turning your card into a trust document. Compare this to a competing agent's paper card that shows only a name and a mobile — the difference is tangible.
        </p>
        <p>
            For expat buyers unfamiliar with Omani conventions, we include a bilingual short explainer on the back of your card: freehold zones, typical buyer costs (including the 3% transfer fee at the Secretariat General of Taxation), and the standard broker commission structure (usually 2% + VAT on sales, 5% on annual rental).
        </p>

        <h2>After the Viewing — Automated Nurture Without Feeling Cold</h2>
        <p>
            The viewing ends, the buyer leaves, and on most real estate CRMs they enter a generic drip campaign that feels corporate. With Cardify, the buyer's contact is saved to your personal dashboard tagged with the specific viewing (e.g., "Al Mouj Marina Phase 4 — 2BR apt — viewed 12 Apr"). Three days later you send a personal WhatsApp via Cardify's template: "Hi [name], any thoughts on the Marina apartment? Happy to walk through the floor plan again or show you a different unit in Zaha. — [agent name]". Conversion-to-offer on a personal, timed follow-up is roughly 4x a generic email drip.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Your listings in the buyer's pocket, instantly</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Add live listings, licence ID, and one-tap WhatsApp — your card becomes a closing tool.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Agent Card
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
