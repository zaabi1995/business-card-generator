<?php
/**
 * Cardify - Digital Business Cards for Sohar Industrial Port Companies
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Digital Business Cards for Sohar Industrial Port Companies — Cardify';
$pageDescription = 'SOHAR Port & Freezone tenants — steel, aluminium, logistics, petrochemicals. Bilingual cards with CR + freezone licence + ISO. Delivered to Sohar in 72 hours.';
$canonicalUrl = 'https://cardify.om/solutions/digital-business-cards-sohar-industrial-port';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Sohar Industrial Port Companies', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Digital Business Cards for Sohar Industrial Port Companies',
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
                <span class="text-gray-700">Sohar Industrial Port</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Digital Business Cards for Sohar Industrial Port Companies</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Steel mills, aluminium smelters, methanol plants, shipping lines, port logistics operators. SOHAR Port & Freezone runs a global supply chain, and your cards need to speak to Korean, Japanese, Indian, and European procurement teams fluently.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Inside SOHAR Port & Freezone</h2>
        <p>
            SOHAR Port & Freezone, a joint venture between the Port of Rotterdam and Asyad Group, has become the beating industrial heart of the Sultanate. It hosts Vale's pelletising plant, Oman Aluminium (Sohar Aluminium), Jindal Shadeed Iron & Steel, Sohar International Urea & Chemicals (SIUCI), OQ Methanol, Orpic's refinery, the Sohar Industrial Port Container Terminal operated by Hutchison Ports, the ro-ro terminal, bulk and break-bulk facilities, and hundreds of supporting logistics, engineering services, and SME tenants in the Freezone. The tenant mix is aggressively international — Jindal is Indian, Vale is Brazilian, major logistics operators include Hutchison (Hong Kong), MSC, CMA CGM, Hapag-Lloyd — and the procurement conversations happen with teams sitting in Singapore, Mumbai, Yokohama, Rotterdam, Houston, and Seoul.
        </p>
        <p>
            A business card for a Sohar-based company must clear two hurdles: (1) instantly communicate local Omani legitimacy — CR number, Freezone licence, ICV band, ISO certifications — for local and regional clients; and (2) be globally readable, with English copy that doesn't rely on regional assumptions, a phone number in international format, and a language switcher that can render the card in Korean, Japanese, Mandarin, or German for the specific trade routes you operate. Cardify handles all of this in a single profile.
        </p>

        <h2>Freezone vs Mainland Licence — Why It Matters on the Card</h2>
        <p>
            Companies operating inside the SOHAR Freezone hold a Freezone licence issued under the Freezone Companies Regulations and SOHAR's specific framework (administered with MoCIIP). Companies operating in the Sohar Industrial Estate mainland hold standard MoCIIP CRs. The distinction matters for customs duty, tax regime, Omanisation, and import/export. Your card should clearly state which regime you operate under — and Cardify adds a "Freezone Tenant — SOHAR" or "Sohar Industrial Estate — MoCIIP" badge field with a direct link to the verification record. Procurement teams in your customer's finance department check this before issuing purchase orders in some categories.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> Ideal for Sohar-based SMEs, global multinationals, and port-adjacent logistics companies. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free &rarr;</a></p>

        <h2>Multi-Language Card for Global Counterparties</h2>
        <p>
            If your export desk at a Sohar steel mill is negotiating with Pohang Iron & Steel (POSCO) in Korea, the Arabic/English card doesn't always land well. Cardify's digital profile can include Korean, Japanese, simplified Chinese, traditional Chinese, German, Dutch, and French renderings — you toggle the language directly on the card URL (e.g., cardify.om/yourfirm-sales-manager?lang=ko). When you share the link in a WhatsApp to your Korean counterparty, they see the card in Korean first. Arabic-English remains the default; alternative languages render only when explicitly requested.
        </p>
        <p>
            For logistics companies operating between Sohar, Jebel Ali, Khalifa Port, and European hubs, Cardify's card can include live shipment tracking widget links, your commercial terms (FOB, CIF, CPT), and your HS code specialisations — turning the card into a mini-capability statement. A chief commercial officer at a bulk breaking operation can hand over one NFC card and have the recipient read a complete service portfolio in under two minutes.
        </p>

        <h2>Onboarding Bulk Operations Staff</h2>
        <p>
            SOHAR Port tenants often run operations teams of 200+ including Omani technicians, engineers, HSE officers, and expat specialists. Rolling out consistent, brand-compliant business cards for that many staff is historically a printing nightmare. Cardify's bulk upload tool accepts a CSV with employee details, auto-generates each bilingual digital card with the employee's MoH-issued labour card photo (optional), and produces print-ready PDFs for all printed NFC cards in a single batch — delivered to the Sohar Port gate via our Muscat print partner within 72 hours.
        </p>
        <p>
            For safety-critical staff (welders, riggers, electricians, confined-space operators), the card can display the staff member's competency certifications — IWCF, OPITO, NEBOSH — which site safety officers can verify at the gate before allowing access. This replaces the paper certificate photocopies that often travel in a worker's back pocket.
        </p>

        <h2>Integration with Asyad Group's Ecosystem</h2>
        <p>
            SOHAR operates as part of Oman's wider Asyad Group logistics platform — connected with Salalah Port, Duqm Port, and the national logistics ecosystem. Companies working across multiple Asyad facilities benefit from a unified card identity. Cardify's multi-site template lets your Head of Logistics list their presence across Sohar, Duqm, and Salalah with a separate local phone number for each port, routing calls to the right on-site contact automatically. One card, three ports, zero confusion.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Global-ready, Sohar-rooted</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Add multi-language profiles, freezone licence verification, and global shipping codes — all in one card.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Sohar Account
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
