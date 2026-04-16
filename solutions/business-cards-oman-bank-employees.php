<?php
/**
 * Cardify - Business Cards for Oman Bank & Finance Employees
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Oman Bank & Finance Employees — Cardify';
$pageDescription = 'Bank Muscat, Sohar International, Dhofar, NBO, BankMuscat SME. Compliant digital cards with CBO registration — bilingual, phishing-proof QR, central admin.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-oman-bank-employees';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Oman Bank & Finance Cards', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Business Cards for Oman Bank & Finance Employees',
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
                <span class="text-gray-700">Bank & Finance Employees</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Oman Bank & Finance Employees</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Relationship managers, corporate bankers, SME advisors, branch managers — your customers trust your face and your bank's brand. Cardify delivers compliant, bilingual digital cards with central IT admin for every Oman bank.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Banking in Oman — A Relationship Business</h2>
        <p>
            Oman's banking sector, supervised by the Central Bank of Oman (CBO), is dominated by a set of well-known institutions: Bank Muscat, National Bank of Oman (NBO), Oman Arab Bank (OAB), Bank Dhofar, Sohar International (after its merger with HSBC Oman), Bank Nizwa, Alizz Islamic Bank, Muzn Islamic Banking, and international representative offices including Standard Chartered, HSBC (post-merger), and the KSA-based banks' Oman branches. Add to this the asset managers, advisory firms, and fintech startups in Knowledge Oasis Muscat, and you have a sector where relationship managers play a decisive role in SME, corporate, and private-wealth client retention.
        </p>
        <p>
            Customers pick — and stay with — a bank largely because of their RM. When the RM moves branches, gets promoted, or leaves, the handover is critical. A business card needs to communicate: the RM's full bilingual name, their CBO-registered role, branch location, direct line, WhatsApp Business, LinkedIn profile, and, where the bank allows, scheduling link for a branch visit or a video call. The card also needs to make a clean handover when staff move — the outgoing RM's card should gracefully redirect to the incoming RM's card, preserving customer relationships.
        </p>

        <h2>Central IT Admin — Because Banks Can't Have Rogue Cards</h2>
        <p>
            An Oman bank with 2,500 employees can't have individual staff creating business cards with random logos and inconsistent contact formats — that's a brand and compliance nightmare, and it opens a social-engineering attack vector. Cardify's enterprise tier is built for this: your bank's IT or Corporate Communications team controls the master template (exact logo, exact typography, exact colour of your blue), and individual employees can edit only their role-specific fields (name, direct line, email, photo). Any attempt to edit a locked field requires an admin approval. When an employee leaves, their card is revoked instantly — the URL returns to a centrally-controlled "This employee is no longer with [Bank Name]. Your current relationship manager is [X]" landing page.
        </p>
        <p>
            We integrate with Active Directory / Entra ID for single sign-on, so a Bank Muscat RM logs into Cardify with her bank credentials, the card reflects her current role from HR, and when HR updates her title or branch, her card updates overnight. No stale cards in circulation, no "the number on the card doesn't work" customer complaints.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> Pilot with a branch; roll out to the whole bank via central admin. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start a pilot &rarr;</a></p>

        <h2>Phishing-Proof Design</h2>
        <p>
            Banking customers in Oman are targeted by a constant barrage of phishing SMS pretending to be from their bank. A business card that resolves to a generic URL like cardify.om/j-smith-bank is exactly the kind of thing a phisher could clone. Cardify's banking-grade setup solves this: each bank gets a branded subdomain — e.g., cards.bankmuscat.com or bank.sohar-intl.om — so every RM's card URL carries the bank's own domain. Customers see "Dhofar" or "Sohar" in the URL and trust it. Combined with the bank's own SSL certificate and a green padlock, the card becomes a trust asset rather than a risk.
        </p>
        <p>
            Cardify also supports signed vCards — the .vcf file downloaded by the customer is cryptographically signed by the bank's certificate, so the customer's phone shows "Verified from Bank X" when saving the contact. This is a clean defence against copycat contacts in the customer's phonebook.
        </p>

        <h2>SME & Corporate Banking Use Cases</h2>
        <p>
            The Oman Chamber of Commerce & Industry runs monthly SME networking sessions, the Oman SME Development Authority (Riyada) hosts pitch events, Sohar International's SME club runs sector-focused meetups, Bank Muscat's SME Academy engages thousands of founders each year. RMs attending these events return with 30–60 new potential clients per event. With Cardify, every card scan is logged, auto-synced to the bank's CRM (Salesforce, Siebel, or Finacle-integrated in-house systems), and the RM can filter scans by event to produce a "qualified leads" report their line manager actually reads.
        </p>
        <p>
            For corporate bankers meeting C-suite at companies like OQ, Asyad, Omantel, Ooredoo, and the major family offices, the card serves a trust-building function — it shows your banking seniority (VP, AVP, Senior Manager), your team (Corporate Banking, FI, Global Markets, Trade Finance), and your direct escalation path if the relationship needs senior attention. Premium NFC cards in embossed black with gold CBO-compliant branding are popular at this tier.
        </p>

        <h2>Compliance With CBO & Data Protection</h2>
        <p>
            Cardify runs on Oman-resident infrastructure with TRA-licensed hosting, which satisfies data residency requirements often specified in bank vendor onboarding. We're prepared to complete your Third Party Risk Assessment (TPRA) questionnaire covering SOC 2 controls, ISO 27001, PDPL compliance, penetration testing reports, and business continuity. For banks regulated under CBO's Banking Technology Risk Management circular, Cardify can operate in a "no external data export" mode where card scan data stays inside your bank's tenancy.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">A compliant card for every RM</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Enterprise tier includes central admin, SSO, and signed vCards.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Bank Pilot
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
