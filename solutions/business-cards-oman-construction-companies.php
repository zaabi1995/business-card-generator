<?php
/**
 * Cardify - Business Cards for Oman Construction & Contracting
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$pageTitle = 'Business Cards for Oman Construction & Contracting, Cardify';
$pageDescription = 'MoH-graded contractors, MEP subcontractors, site supervisors. CR + grade + ICV on the card. Rugged NFC for site use. Built for Sultan Haitham City-era Oman.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-oman-construction-companies';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Construction & Contracting Oman', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = Seo::articleNode(
    __FILE__,
    'Business Cards for Oman Construction & Contracting',
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
                <span class="text-gray-700">Construction & Contracting</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Oman Construction & Contracting</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Contractors working on Sultan Haitham City, Madinat Al Irfan, and Duqm mega-projects know tender season: CR, grade, ICV, ISO, HSE. Put all of it on a card the client can tap and verify on the spot.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Contracting in Oman, Grade, Scope, and Specialisation</h2>
        <p>
            The Oman construction market is structured around contractor grading. The Tender Board (now part of the Secretariat General of Oman's public tendering ecosystem under Etimad) and Ministry of Housing and Urban Planning classify contractors into Excellent, First, Second, Third, and Fourth grades depending on capital, completed projects, technical staff, and equipment ownership. Your grade determines which tenders you can bid on, a Second Grade contractor cannot bid on an Excellent-grade infrastructure package for Sultan Haitham City or Madinat Al Irfan, but can absolutely subcontract on specialty packages. MEP contractors (mechanical, electrical, plumbing), civil subcontractors, finishing specialists (ceiling, partitioning, flooring), facade and glazing subcontractors, and landscaping contractors all have their own grading bands.
        </p>
        <p>
            A business card for a contracting company in Oman needs to display: company name in Arabic and English (your MoCIIP trade name), CR number, Tender Board grade with classification date, ICV (In-Country Value) band, ISO 9001/14001/45001 certification, Oman Society of Engineers (OSE) registered engineer count, and your specialisations (civil, MEP, finishing, steel structures, roads, bridges, water networks). Cardify turns each of these into a structured verified field, your prospect, usually a main contractor's procurement head or a consultant's project manager at Arab Architects, Atkins, WS Atkins, WSP Middle East, or KEO International, can verify your status in seconds.
        </p>

        <h2>Site-Ready Rugged NFC Cards</h2>
        <p>
            A paper card on a construction site lasts ten minutes. Between the dust of Muscat's wadi-side sites, the heat of a July afternoon in Rusayl Industrial Estate, the humidity of a Duqm coastal project, and the oil-and-grease of an MEP rough-in, paper cards crumple, smudge, and get thrown out with coffee cups. Cardify's ruggedised NFC card, IP67-rated polycarbonate with laser-engraved branding, is designed to sit in a site engineer's hi-vis vest pocket for a year and still scan cleanly. Clip-on lanyard versions are popular for site supervisors, foremen, and QA/QC engineers who need hands-free.
        </p>
        <p>
            When the resident engineer on a Sultan Haitham City infrastructure package stops by your portable cabin on the site, a single card tap gets them your site manager's details, the project's HSE manager's number, and your company's pre-qualification documents, all downloadable instantly to their phone for the weekly site report.
        </p>

        <p><strong>Create digital business cards for your team, free to start with <?php echo $brandName; ?>.</strong> Add CR, grade, ICV, ISO cert numbers as structured fields. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free &rarr;</a></p>

        <h2>Tender & Pre-Qualification Workflow</h2>
        <p>
            Bidding on a construction package in Oman usually means a three-stage process: expression of interest (EoI), pre-qualification (PQ) submission, and final tender. In the EoI and PQ stages, the client circulates their request via Etimad or direct email, and asks for a "key personnel" list. Cardify exports a "team contact sheet" as a single PDF suitable for direct inclusion in your PQ document. Each key person, Managing Director, Projects Director, Commercial Director, QA/QC Manager, HSE Manager, Chief Engineer, has a one-page bilingual profile with photo, CV download link, and NFC-scannable digital card reference.
        </p>
        <p>
            When the client runs clarification meetings at their head office in Muscat, your team walks in carrying NFC cards, taps exchanges with the technical evaluators, and everybody on both sides has each other's contacts within the meeting. This matters, projects where the client's engineer has the contractor's QA/QC Manager's direct mobile from day one consistently run smoother than projects where that contact goes through three layers.
        </p>

        <h2>Subcontractor Pipeline Management</h2>
        <p>
            If you're a main contractor managing 30+ subcontractors on a Duqm hydrogen project or a Madinat Al Irfan commercial tower, Cardify lets you create "verified subcontractor cards" for each of your approved subcontractors. When a site visit happens, each subcontractor's site lead taps their card against your procurement officer's phone, confirming they're the approved vendor for that package. No more Excel spreadsheets of approved subcontractor lists, just a live directory of NFC cards that your project teams scan and verify on-site.
        </p>

        <h2>Salalah, Sohar, Duqm, Outside-Muscat Projects</h2>
        <p>
            Oman construction work is increasingly distributed. The Khareef 2026 push in Salalah brings tourism and infrastructure work; Sohar Free Zone continues to expand warehouses, industrial facilities, and SOHAR Port berths; Duqm Special Economic Zone is building on multiple fronts, the hydrogen export terminals, the refinery expansion, the new tourism complex. Your teams deploy for 6–12 month stretches outside Muscat. Cardify gives every deployed engineer a card with their temporary site phone number (updated in the dashboard when they're rotated), accommodation and site address, and their reporting manager's contact, reducing the "who do I call on this site?" confusion that plagues distributed contracting operations.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Tender-ready, site-rugged, bilingual</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team, free to start with <?php echo $brandName; ?>. Rugged NFC for site supervisors, premium print for project directors.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Contracting Account
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
