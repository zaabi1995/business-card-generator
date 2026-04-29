<?php
/**
 * Cardify - Business Cards for Oman Government & Public Sector
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Oman Government & Public Sector, Cardify';
$pageDescription = 'For ministries, RAFO, ROP, ITA/OTA, OIA, municipalities. Protocol-compliant bilingual cards with Arabic-first rendering, titles in full, and central IT control.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-oman-government-employees';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Oman Government & Public Sector', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Business Cards for Oman Government & Public Sector',
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
                <span class="text-gray-700">Government & Public Sector</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Oman Government & Public Sector</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Ministries, authorities, governorates, municipalities. Cards that respect protocol, Arabic-first, full formal title, bilingual QR, and central IT control for 100 to 10,000 employees.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Protocol Matters in Oman's Public Sector</h2>
        <p>
            A business card in the Omani government context is a formal artifact. At the Ministry of Foreign Affairs, the Diwan of Royal Court, the Supreme Council for Planning, or the State Audit Institution, a card is handed over, not tossed across a table, and read carefully before being placed on the right side of the recipient's notebook. Everything on the card signals rank, office, and protocol: full Arabic name with honorifics where appropriate (الدكتور، المهندس), the ministry's full Arabic name in the official style (e.g., وزارة الخارجية, Ministry of Foreign Affairs, not a shortened form), directorate and section hierarchy, direct dial number (PBX + extension), email on the government @*.om domain, and the ministry's seal where permitted.
        </p>
        <p>
            A <?php echo $brandName; ?> card for a government employee follows these conventions precisely. Arabic is rendered in Naskh (formal Arabic script, not the casual Kufic styles used in tech branding), titles appear in full (Director General, المدير العام, not "DG"), and the card is structured so a recipient, often a visiting foreign delegation, a GCC counterpart, or a senior official from another ministry, can read the relevant hierarchy at a glance.
        </p>

        <h2>Security, Compliance & Data Residency</h2>
        <p>
            Government entities in Oman operate under the Oman National Cyber Security Strategy, overseen by the Cyber Defence Center (under the Ministry of Transport, Communications and IT, MTCIT), and individual ministries often have their own additional security frameworks. Cardify runs on Oman-resident TRA-licensed infrastructure, can operate in an "on-premises" mode deployed into the ministry's own data centre if required, and supports signed vCards so the recipient's phone confirms the contact came from a verified government source. For classified staff, Cardify offers a "private card" mode where the card requires an authorised recipient to authenticate (via government SSO) before the full contact details render.
        </p>
        <p>
            Data residency is absolute, no card data, scan data, or contact exchange leaves the Sultanate. For ministries that need this formally stated, we sign Data Processing Agreements referencing Oman's Personal Data Protection Law (PDPL, Royal Decree 6/2022) and can complete a full security clearance questionnaire for the Ministry of Defence, Royal Oman Police, or the Internal Security Service where appropriate.
        </p>

        <p><strong>Create digital business cards for your team, free to start with <?php echo $brandName; ?>.</strong> Pilot with a directorate; scale to the full ministry with central IT controls. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start a pilot &rarr;</a></p>

        <h2>Use Cases Across the Omani Government</h2>
        <p>
            <strong>MoCIIP / Invest Easy:</strong> inspectors and investment officers carry cards that include the Invest Easy online-service portal link, their CR-verification authority, and a bilingual vCard for investors to save. <strong>Oman Tax Authority (OTA):</strong> auditors' cards link to the taxpayer portal with direct case references. <strong>Royal Oman Police (ROP):</strong> community-policing officers, traffic officers, and Directorate General of Passports and Residency staff carry cards with their service number and direct line, simplifying citizen-government interaction. <strong>Ministry of Health (MoH):</strong> senior physicians' cards link to their MoH licence record. <strong>Muscat Municipality, Dhofar Municipality, Al Batinah Municipality:</strong> cards include the specific wilayat jurisdiction and the direct municipality service request link (e.g., building permit, public health complaints).
        </p>
        <p>
            <strong>Diplomatic & delegation contexts:</strong> For the Ministry of Foreign Affairs, the Oman-embassy network, and bilateral delegations to events like the GCC Summit, Arab League meetings, the UN General Assembly, and climate conferences (COPs), Cardify generates diplomatic-grade NFC cards, metal-finish, with the Sultanate of Oman's emblem (following MoFA protocol guidelines on emblem use), appropriate for exchange with counterpart ministers, ambassadors, and senior foreign officials.
        </p>

        <h2>ITA & Digital Transformation Context</h2>
        <p>
            Oman's digital transformation agenda, driven by the Information Technology Authority (now part of MTCIT) and accelerated by the Oman Vision 2040 digital economy goals, means ministries are under pressure to replace paper processes with secure digital alternatives. Business cards are a small but highly visible part of this. A single ministry switching its 2,000 employees from quarterly paper card printing to digital-plus-NFC cards saves an estimated OMR 12,000–18,000 per year in direct print costs, plus thousands of kilograms of cardstock waste, plus the hidden cost of outdated contact information circulating for months after staff rotations. Cardify, free for unlimited ministry employees with optional bulk print orders routed through verified Omani print shops, has been designed with this exact use case in mind.
        </p>

        <h2>Emblem & Logo Use, Important Compliance Note</h2>
        <p>
            The use of the Sultanate's national emblem, the Royal Family insignia, and specific ministry logos is governed by protocol and is not something Cardify applies without written authorisation from the ministry's authorised communications office. Our implementation process for government entities includes an emblem-use verification step: we accept the specific emblem asset and usage guidelines directly from your Office of the Under-Secretary (or equivalent), store it in a restricted asset library, and ensure no employee can apply it without admin approval. This preserves protocol compliance at scale.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Protocol-grade cards, centrally managed</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team, free with <?php echo $brandName; ?>. Unlimited employees, central admin, emblem controls, and on-demand print orders through verified Omani shops.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start a Ministry Pilot
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
