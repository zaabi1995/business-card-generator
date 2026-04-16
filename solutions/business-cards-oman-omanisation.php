<?php
/**
 * Cardify - Business Cards and Omanisation: Onboarding New Employees
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards and Omanisation: Onboarding New Employees — Cardify';
$pageDescription = 'Cut your Omanisation onboarding from days to minutes. Every new Omani hire gets a bilingual digital card on day one — no print wait, no template hunting.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-oman-omanisation';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Omanisation & Employee Onboarding', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Business Cards and Omanisation: Onboarding New Employees',
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
                <span class="text-gray-700">Omanisation & Onboarding</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards and Omanisation: Onboarding New Employees</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                A new Omani graduate joining your company should walk out of day one with their business card already shared to their family WhatsApp. Cardify makes that the default, not a three-week print-shop delay.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Omanisation — Why Onboarding Speed Is a Retention Signal</h2>
        <p>
            The Oman Vision 2040 Omanisation agenda, enforced through the Ministry of Labour and backed by sector-specific quotas (very high in banking, insurance, oil & gas, and retail; graduated quotas in construction, hospitality, and logistics), has pushed every private-sector employer to hire, onboard, and retain more Omani nationals. Hiring Omanis is easier than retaining them — and retention is dramatically influenced by the first-week experience. Research across Oman HR practitioners (Oman HR Society, Oman Talent Transformation forums) consistently finds that Omani graduates who feel immediately integrated into the company during their first week stay 40–60% longer than those who experience a slow, paperwork-heavy onboarding.
        </p>
        <p>
            A small but high-signal part of that first-week experience is the business card. A graduate who joins on Sunday and has a professional card to share by Tuesday tells their family, posts on their LinkedIn and Instagram, and owns their new identity. A graduate who is told "we'll order your cards, they take 2–3 weeks" effectively remains in identity limbo — they can't fully project their new role at networking events, family weddings, or the weekly majlis with cousins who ask "so what do you do now?".
        </p>

        <h2>Day-One Cards Through Cardify</h2>
        <p>
            With Cardify's HR integration, your onboarding flow runs like this: an HR officer adds the new hire's details (name in Arabic and English, job title, department, office phone extension, company email) through the Cardify admin panel or via a CSV upload. Within 60 seconds, the employee's digital card is live at a URL like cards.yourcompany.om/fatma-alhinai, the URL is texted to their Oman number via SMS (or pushed to the company's HR app), and they receive a welcome email with instructions on saving their card to their phone's home screen. If your company issues NFC cards, the order is automatically placed with the Muscat print partner — NFC card in hand within 3 working days.
        </p>
        <p>
            For larger rollouts — say a Sohar Port tenant onboarding 40 Omani technicians in a batch, or a bank running a graduate intake of 60 Omani management trainees — Cardify handles bulk upload from HR's spreadsheet and creates all 60 cards simultaneously with consistent branding. HR moves from "print shop logistics and proofreading 60 cards" to "upload CSV, sip coffee".
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> Scale from 1 to 1,000 hires without changing process. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start your HR integration &rarr;</a></p>

        <h2>Omanisation Compliance & Ministry of Labour Signaling</h2>
        <p>
            When Ministry of Labour inspectors visit — or when an Omanisation officer from a major counterparty (OQ, PDO, Bank Muscat, Omantel) audits your staff mix as a prelude to contract renewal — you can produce a live "active employee card directory" in 5 seconds. Every card shows the employee's name, role, and (optional) an Omani national indicator. It's a verifiable, tamper-resistant signal that your Omanisation percentage claim is real.
        </p>
        <p>
            For sectors with high Omanisation quotas — banking (45%+), insurance (45%), retail (over 50% in certain categories) — this real-time visibility helps your HR leadership manage quota compliance proactively rather than scrambling at audit time. A simple dashboard filter shows "Omani vs non-Omani by department" and flags departments drifting below target.
        </p>

        <h2>Graduate Programs & the First Professional Identity</h2>
        <p>
            Major Omani graduate programs — the Bank Muscat Graduate Program, the NBO Talent Management Program, Omantel's Graduate Accelerator, the PDO Engineering Graduate Scheme, the OQ Nikhil program, Ministry graduate absorption programs — each year absorb hundreds of Omani university and diploma graduates from Sultan Qaboos University, German University of Technology (GUtech), Muscat University, University of Buraimi, and international returnees. Their first business card is often their first professional artifact. Giving them a professional, brand-compliant, bilingual card on day one of orientation — alongside their company laptop and corporate email — is a small dignity that matters.
        </p>

        <h2>Retention Over Time — Updating Cards As Roles Evolve</h2>
        <p>
            Omani graduates rotate through departments, promote, sometimes switch divisions. A paper card printed at hire becomes obsolete 12 months later. A Cardify card updates in real-time — an HR update to role/title flows to the card URL immediately. This means an employee promoted from "Assistant Relationship Manager" to "Relationship Manager" doesn't spend their first two months at the new title handing out old cards while waiting for the print shop. The visual signal of an up-to-date, role-current card reinforces the employee's sense of progress and recognition, which correlates strongly with retention.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Day-one cards for every Omani hire</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. CSV bulk upload, HR integration, Omanisation audit dashboard.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your HR Rollout
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
