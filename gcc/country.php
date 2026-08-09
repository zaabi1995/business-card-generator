<?php
/**
 * Cardify, GCC Country Landing Page (shared template)
 *
 * Rewrites:
 *   /gcc/saudi-arabia  → this file with ?country=saudi-arabia
 *   /gcc/uae           → ?country=uae
 *   /gcc/qatar         → ?country=qatar
 *   /gcc/bahrain       → ?country=bahrain
 *   /gcc/kuwait        → ?country=kuwait
 *   /gcc/oman          → ?country=oman  (links back to /oman-business-index)
 *
 * Each page carries a full schema.org JSON-LD stack (WebPage +
 * BreadcrumbList + LocalBusiness + Service + FAQPage + Dataset)
 * plus hreflang and areaServed to that country.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$countries = [
    'saudi-arabia' => [
        'name'    => 'Saudi Arabia',
        'name_ar' => 'المملكة العربية السعودية',
        'code'    => 'SA',
        'iso'     => 'SAU',
        'currency'=> 'SAR',
        'capital' => 'Riyadh',
        'lang'    => 'ar-SA',
        'flag'    => '🇸🇦',
        'accent'  => 'from-green-700 to-green-900',
        'tint'    => 'bg-green-50 text-green-800',
        'registrar_name' => 'Ministry of Commerce (MC)',
        'registrar_url'  => 'https://mc.gov.sa',
        'registry_name'  => 'Saudi Business Center',
        'company_count_approx' => '1,200,000',
        'hero_headline'  => 'Digital business cards for Saudi Arabia, from Riyadh to Jeddah',
        // bhd-group-seo-llm27-44: was "building the first GCC-wide digital
        // business card platform", a first-claim with nothing behind it, on
        // four surfaces of this page (meta description, og, twitter, hero).
        // superlative_gate has been RED on it, not blind to it. Replaced with
        // the estate's own already-published Organization description, so the
        // page repeats a claim the graph makes rather than inventing a
        // stronger one: "Built in Oman, expanding across Saudi Arabia, UAE,
        // Qatar, Bahrain, and Kuwait through 2026."
        'hero_sub'       => 'Cardify is a bilingual Arabic and English digital business card platform, built in Oman and expanding across the GCC. Saudi launch is next, register your company for the Saudi Business Index and get early access to bilingual EN/AR cards tuned for KSA regulations, NFC-ready and Apple Wallet compatible.',
        'sectors'        => ['Oil & Gas', 'Banking (SAMA-licensed)', 'Real Estate', 'Retail', 'Technology', 'Healthcare', 'Construction', 'Hospitality'],
        'majors'         => ['Saudi Aramco', 'SABIC', 'Al Rajhi Bank', 'STC', 'SABB', 'Ma\'aden', 'NEOM', 'ROSHN'],
        'value_props'    => [
            'Vision 2030-aligned, modern, digital-first, NFC and QR on every card',
            'Bilingual Arabic/English, right-to-left layouts native to KSA',
            'Designed for SAMA-regulated bankers, Aramco vendors, NEOM partners',
            'Per-seat pricing in SAR with local invoice compliance',
        ],
        'faq' => [
            ['q' => 'When does Cardify launch in Saudi Arabia?', 'a' => 'We are actively onboarding pilot companies in Riyadh, Jeddah, and Dammam through 2026. Register your company (below) for early access and your logo/name to be added to the Saudi Business Index when it launches.'],
            ['q' => 'Is Cardify compliant with SAMA and Saudi data residency rules?', 'a' => 'For personal and business contact data, yes, Cardify stores only what you choose to put on your card (public-by-design), which falls outside SAMA sensitive-data categories. We can provide Data Processing Agreements for regulated entities on request.'],
            ['q' => 'Can I get Arabic-first cards for my Saudi team?', 'a' => 'Yes. Every Cardify card renders bilingual EN/AR with correct RTL layout and typography. The Arabic side can be the primary, the English can be the primary, or both sides can mirror.'],
            ['q' => 'Do you integrate with Absher or other Saudi government services?', 'a' => 'Not currently, but our roadmap includes identity verification via Absher once we complete our KSA compliance review. For now, corporate identity verification is handled through CR-number validation against the Ministry of Commerce.'],
        ],
    ],
    'uae' => [
        'name'    => 'United Arab Emirates',
        'name_ar' => 'الإمارات العربية المتحدة',
        'code'    => 'AE',
        'iso'     => 'ARE',
        'currency'=> 'AED',
        'capital' => 'Abu Dhabi',
        'lang'    => 'ar-AE',
        'flag'    => '🇦🇪',
        'accent'  => 'from-red-700 to-black',
        'tint'    => 'bg-red-50 text-red-800',
        'registrar_name' => 'Ministry of Economy (MoE)',
        'registrar_url'  => 'https://moec.gov.ae',
        'registry_name'  => 'UAE Ministry of Economy',
        'company_count_approx' => '700,000',
        'hero_headline'  => 'Digital business cards for the UAE, built for Dubai, Abu Dhabi, and every free zone in between',
        'hero_sub'       => 'Cardify is expanding into the UAE with bilingual EN/AR digital cards, DIFC-ready Apple Wallet passes, and an Emirates Business Index seeded from MoE data. Perfect for hospitality, real estate, finance, and every free-zone entity.',
        'sectors'        => ['Real Estate', 'Hospitality & Tourism', 'Financial Services (DIFC/ADGM)', 'Trading', 'Logistics', 'Technology', 'Media', 'Oil & Gas (ADNOC ecosystem)'],
        'majors'         => ['Emirates Group', 'ADNOC', 'Emaar', 'DAMAC', 'Mashreq', 'ENBD', 'Etisalat', 'du'],
        'value_props'    => [
            'Free-zone-friendly, DIFC, DMCC, JAFZA, ADGM all supported',
            'Arabic / English / French / Hindi languages on a single card',
            'Apple Wallet + Google Wallet compatible (no app needed)',
            'Per-seat AED pricing with VAT-compliant invoicing',
        ],
        'faq' => [
            ['q' => 'Does Cardify work for DIFC or ADGM regulated entities?', 'a' => 'Yes. Business cards are public-by-design and do not fall under DIFC or ADGM data-subject restrictions. We also provide an optional branded card template for licensed firms so every employee card is compliance-reviewed once.'],
            ['q' => 'Can I use Cardify on Apple Wallet or Google Wallet in the UAE?', 'a' => 'Yes. Every Cardify card generates an Apple Wallet .pkpass and a Google Wallet pass. Customers scan a QR, tap "Add to Wallet", and the card is on the lock screen, no app install, no friction at a networking event.'],
            ['q' => 'Is bulk provisioning available for Emirates Group, ADNOC, or similar large employers?', 'a' => 'Absolutely, enterprise pilots are how we prefer to launch in any new market. We ingest your HRIS (Workday, SuccessFactors, custom) and produce cards for 500 or 50,000 employees with brand-locked templates.'],
            ['q' => 'What about Arabic calligraphy and premium fonts?', 'a' => 'Cardify ships with 40+ premium Arabic fonts including Diwani, Thuluth, and modern sans-serif cuts. We can license a corporate font on request for full brand consistency.'],
        ],
    ],
    'qatar' => [
        'name'    => 'Qatar',
        'name_ar' => 'قطر',
        'code'    => 'QA',
        'iso'     => 'QAT',
        'currency'=> 'QAR',
        'capital' => 'Doha',
        'lang'    => 'ar-QA',
        'flag'    => '🇶🇦',
        'accent'  => 'from-purple-800 to-red-900',
        'tint'    => 'bg-purple-50 text-purple-800',
        'registrar_name' => 'Ministry of Commerce and Industry (MOCI)',
        'registrar_url'  => 'https://www.moci.gov.qa',
        'registry_name'  => 'Qatar Single Window',
        'company_count_approx' => '65,000',
        'hero_headline'  => 'Digital business cards for Qatar, Doha-ready, bilingual, World-Cup-tested',
        'hero_sub'       => 'Cardify is expanding into Qatar with bilingual EN/AR digital business cards designed for the Qatari business environment, QFC-compliant, QCB-aware, and tuned for the post-2022 global Qatar.',
        'sectors'        => ['Oil & Gas (QatarEnergy)', 'Finance (QFC)', 'Construction', 'Hospitality', 'Aviation', 'Government & Defense', 'Real Estate'],
        'majors'         => ['QatarEnergy', 'Qatar Airways', 'QNB', 'Ooredoo', 'Qatar Islamic Bank', 'Barwa Real Estate', 'Qatar Insurance'],
        'value_props'    => [
            'QFC-aware templates for finance entities',
            'Bilingual Arabic/English with premium Arabic typography',
            'Designed for QatarEnergy\'s vendor ecosystem (QSOB onboarding friendly)',
            'Per-seat QAR pricing, VAT-ready for 2027 introduction',
        ],
        'faq' => [
            ['q' => 'Does Cardify work with Qatar Financial Centre firms?', 'a' => 'Yes. QFC firms use Cardify for their relationship managers and advisors, bilingual cards with QFC license numbers on the digital version, brand-locked templates reviewed once by compliance.'],
            ['q' => 'Can I print Cardify cards locally in Doha?', 'a' => 'Yes. Our network includes print partners in Doha for on-demand bulk printing. Same PDF goes to our Muscat, Dubai, or Doha partners.'],
        ],
    ],
    'bahrain' => [
        'name'    => 'Bahrain',
        'name_ar' => 'البحرين',
        'code'    => 'BH',
        'iso'     => 'BHR',
        'currency'=> 'BHD',
        'capital' => 'Manama',
        'lang'    => 'ar-BH',
        'flag'    => '🇧🇭',
        'accent'  => 'from-red-700 to-red-900',
        'tint'    => 'bg-red-50 text-red-800',
        'registrar_name' => 'Ministry of Industry and Commerce (MOIC)',
        'registrar_url'  => 'https://www.moic.gov.bh',
        'registry_name'  => 'Sijilat',
        'company_count_approx' => '90,000',
        'hero_headline'  => 'Digital business cards for Bahrain, Sijilat-aware, bilingual, built for the financial hub of the Gulf',
        'hero_sub'       => 'Cardify is launching in Bahrain with bilingual EN/AR digital business cards, CBB-friendly compliance posture, and a Bahrain Business Index seeded from Sijilat. Perfect for the kingdom\'s finance, insurance, and tech sectors.',
        'sectors'        => ['Financial Services (CBB)', 'Insurance', 'Technology & ICT', 'Manufacturing (Alba)', 'Tourism', 'Real Estate', 'Logistics'],
        'majors'         => ['NBB', 'Ahli United Bank', 'BBK', 'Alba', 'Bapco', 'Batelco', 'GFH', 'Investcorp'],
        'value_props'    => [
            'CBB-aware templates for licensed banks and fintechs',
            'Bilingual Arabic/English with crisp mobile rendering',
            'Designed for Bahrain\'s dense SME & fintech ecosystem',
            'Per-seat BHD pricing with VAT-compliant invoicing',
        ],
        'faq' => [
            ['q' => 'Does Cardify support Sijilat CR lookup?', 'a' => 'On the Bahrain Business Index launch, yes, every company card can be cross-linked to its Sijilat CR number, so recipients can verify the company exists in one click.'],
            ['q' => 'Is Cardify usable by CBB-licensed banks?', 'a' => 'Yes. The nature of a business card (public contact info) falls outside CBB\'s Personal Data Protection Law sensitive categories. We provide DPAs on request.'],
        ],
    ],
    'kuwait' => [
        'name'    => 'Kuwait',
        'name_ar' => 'الكويت',
        'code'    => 'KW',
        'iso'     => 'KWT',
        'currency'=> 'KWD',
        'capital' => 'Kuwait City',
        'lang'    => 'ar-KW',
        'flag'    => '🇰🇼',
        'accent'  => 'from-red-700 to-green-800',
        'tint'    => 'bg-emerald-50 text-emerald-800',
        'registrar_name' => 'Ministry of Commerce and Industry (MOCI)',
        'registrar_url'  => 'https://www.moci.gov.kw',
        'registry_name'  => 'Kuwait Business Center',
        'company_count_approx' => '180,000',
        'hero_headline'  => 'Digital business cards for Kuwait, built for KD-priced teams from Ahmadi to Salmiya',
        'hero_sub'       => 'Cardify is expanding into Kuwait with bilingual EN/AR digital business cards designed for KPC\'s vendor network, the NBK and CBK-regulated banking sector, and Kuwait\'s diversified trading economy.',
        'sectors'        => ['Oil & Gas (KPC)', 'Banking (CBK-licensed)', 'Trading', 'Real Estate', 'Healthcare', 'Construction', 'Government'],
        'majors'         => ['KPC', 'Kuwait Petroleum', 'NBK', 'KFH', 'Zain', 'Agility', 'Alghanim Industries', 'Americana Group'],
        'value_props'    => [
            'KPC vendor-ecosystem friendly',
            'Bilingual Arabic/English with premium RTL typography',
            'Designed for Kuwait\'s banking & insurance regulatory posture',
            'Per-seat KWD pricing (3-decimal, like OMR) with invoice compliance',
        ],
        'faq' => [
            ['q' => 'Does Cardify support Kuwait Dinar pricing?', 'a' => 'Yes. KWD is 3-decimal like OMR, and we support it natively in the billing engine. Invoices meet Kuwait VAT-ready formats for 2027.'],
            ['q' => 'Do you work with KPC vendor onboarding?', 'a' => 'We can generate branded vendor cards that match the KPC supplier visual guidelines. Talk to us for bulk deployments.'],
        ],
    ],
    'oman' => [
        'name'    => 'Oman',
        'name_ar' => 'عُمان',
        'code'    => 'OM',
        'iso'     => 'OMN',
        'currency'=> 'OMR',
        'capital' => 'Muscat',
        'lang'    => 'ar-OM',
        'flag'    => '🇴🇲',
        'accent'  => 'from-red-700 to-green-700',
        'tint'    => 'bg-red-50 text-red-800',
        'registrar_name' => 'Ministry of Commerce, Industry & Investment Promotion (MoCIIP)',
        'registrar_url'  => 'https://business.gov.om',
        'registry_name'  => 'Oman Commercial Registry',
        'company_count_approx' => '{OBI} indexed (full CR: ~260,000)',
        'hero_headline'  => 'Digital business cards for Oman, live since 2024, powering {OBI} indexed companies',
        'hero_sub'       => 'Cardify is based in Oman. The Omani Logo Library and Oman Business Index are both live with verified CR data. Start using bilingual EN/AR digital business cards today, used by teams from Muscat to Salalah to Sohar.',
        'sectors'        => ['Oil & Gas', 'Banking (CBO)', 'Finance & Insurance', 'Real Estate', 'Logistics', 'Tourism', 'Government & Defense', 'Construction'],
        'majors'         => ['PDO', 'OQ', 'Bank Muscat', 'Asyad', 'Ominvest', 'NBO', 'Sohar International', 'Orpic'],
        'value_props'    => [
            '{OBI} Omani companies already in the index, free data download',
            'Bilingual Arabic/English, tuned for CBO / MoCIIP compliance',
            'Used by bankers, oil & gas, government, and SMEs across all governorates',
            'Per-seat OMR pricing with Paymob integration for local payments',
        ],
        'faq' => [
            ['q' => 'Is the Oman Business Index live?', 'a' => 'Yes. {OBI} companies with real CR data, directly from MoCIIP, refreshed on a rolling quarterly cadence against the live MoCIIP register. Free download in CSV.'],
            ['q' => 'Does Cardify work with Omani banks?', 'a' => 'Yes. Bank Muscat, NBO, Sohar International, Bank Dhofar, and ahlibank all have team members using Cardify. Talk to us for enterprise rollouts.'],
        ],
    ],
];

$slug = $_GET['country'] ?? 'oman';
if (!isset($countries[$slug])) {
    http_response_code(404);
    require_once INCLUDES_DIR . '/ui-header.php';
    echo '<main class="max-w-xl mx-auto p-16 text-center"><h1 class="text-2xl font-bold mb-4">Country not found</h1><p class="text-gray-600 mb-6">We cover the six GCC countries. Pick one:</p>';
    foreach ($countries as $s => $c) echo '<a href="' . htmlspecialchars(getBasePath() . 'gcc/' . $s) . '" class="inline-block m-1 px-4 py-2 rounded-lg bg-blue-600 text-white">' . $c['flag'] . ' ' . $c['name'] . '</a>';
    echo '</main>';
    require_once INCLUDES_DIR . '/ui-footer.php';
    exit;
}

$c = $countries[$slug];

// r6-35 / r6-92: the Oman Business Index size is a moving number and its refresh
// cadence is stated once, in lang/{en,ar}/obi.php. Every count here is a {OBI}
// placeholder resolved from the same table the index page counts, at render time.
// If the query cannot run we say 'thousands of' rather than ship a stale figure.
if ($slug === 'oman') {
    $obiCount = null;
    try {
        if (isset($db) && $db->isConnected()) {
            $r = $db->fetchOne("SELECT COUNT(*) c FROM om_companies");
            if ($r && isset($r['c'])) $obiCount = (int) $r['c'];
        }
    } catch (Throwable $e) { /* leave null */ }
    $obiText = $obiCount !== null ? number_format($obiCount) : 'thousands of';
    $subObi = function ($v) use ($obiText) {
        return is_array($v) ? array_map(fn($x) => is_string($x) ? str_replace('{OBI}', $obiText, $x) : $x, $v)
                            : (is_string($v) ? str_replace('{OBI}', $obiText, $v) : $v);
    };
    $c['company_count_approx'] = $subObi($c['company_count_approx']);
    $c['hero_headline']        = $subObi($c['hero_headline']);
    $c['value_props']          = $subObi($c['value_props']);
    foreach ($c['faq'] as $i => $q) { $c['faq'][$i]['a'] = $subObi($q['a']); }
}
$canonicalUrl    = 'https://cardify.om/gcc/' . $slug;
$pageTitle       = $c['hero_headline'] . ' | Cardify';
$pageDescription = mb_substr(strip_tags($c['hero_sub']), 0, 190);
$showNavigation  = true;

$escq = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$faqLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'FAQPage',
    'mainEntity' => array_map(fn($q) => [
        '@type' => 'Question',
        'name'  => $q['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a']],
    ], $c['faq']),
];

$breadcrumbLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'GCC Business Index', 'item' => 'https://cardify.om/gcc-business-index'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $c['name'], 'item' => $canonicalUrl],
    ],
];

$serviceLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'Service',
    'name'     => 'Cardify, Digital Business Cards for ' . $c['name'],
    'description' => 'Bilingual Arabic/English digital business card platform for companies and professionals in ' . $c['name'] . '. NFC, QR, Apple Wallet, Google Wallet compatible. Bulk provisioning for enterprises.',
    // r154 / llm153-2: a reference; the owner body is emitted by ui-header.
    'provider' => ['@id' => 'https://cardify.om/#organization'],
    'areaServed' => [
        '@type' => 'Country',
        'name'  => $c['name'],
        'alternateName' => $c['name_ar'],
        'identifier' => $c['iso'],
    ],
    'serviceType' => 'Digital Business Cards',
    'availableChannel' => ['@type' => 'ServiceChannel', 'serviceUrl' => $canonicalUrl],
];

$webPageLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'WebPage',
    'name'     => $pageTitle,
    'url'      => $canonicalUrl,
    'inLanguage' => ['en', $c['lang']],
    'about' => [
        '@type' => 'Country',
        'name' => $c['name'],
        'alternateName' => $c['name_ar'],
        'identifier' => $c['iso'],
    ],
    'isPartOf' => [
        '@type' => 'WebSite',
        'name' => 'Cardify',
        'url' => 'https://cardify.om',
    ],
    // r154 / llm153-2: a reference; the owner body is emitted by ui-header.
    'publisher' => ['@id' => 'https://cardify.om/#organization'],
];

$extraHead = ''
    . '<script type="application/ld+json">' . json_encode($webPageLd,    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
    . '<script type="application/ld+json">' . json_encode($breadcrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
    . '<script type="application/ld+json">' . json_encode($serviceLd,    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
    . '<script type="application/ld+json">' . json_encode($faqLd,        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n"
    . '<meta property="og:locale:alternate" content="' . $escq(str_replace('-', '_', $c['lang'])) . '">';

require_once INCLUDES_DIR . '/ui-header.php';
?>
<div class="min-h-screen bg-white">
    <section class="relative overflow-hidden bg-gradient-to-b from-blue-50 via-white to-white border-b border-gray-100 pt-28 pb-16">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
                <a href="<?= getBasePath() ?>" class="hover:text-blue-600 transition">Home</a>
                <span class="mx-2">/</span>
                <a href="<?= getBasePath() ?>gcc-business-index" class="hover:text-blue-600 transition">GCC Business Index</a>
                <span class="mx-2">/</span>
                <span class="text-gray-700"><?= $escq($c['name']) ?></span>
            </nav>
            <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white border border-blue-200 text-blue-700 text-xs font-semibold tracking-wide uppercase shadow-sm mb-5">
                <span class="text-base leading-none"><?= $c['flag'] ?></span>
                <?= $escq($c['code']) ?> · <?= $escq($c['name_ar']) ?>
                <?php if ($slug === 'oman'): ?>
                    <span class="inline-flex items-center gap-1 ml-1 bg-green-100 text-green-700 rounded-full px-2 py-0.5 text-[10px] uppercase"><i class="fa-solid fa-circle text-[6px]"></i> Live</span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1 ml-1 bg-amber-100 text-amber-700 rounded-full px-2 py-0.5 text-[10px] uppercase">2026</span>
                <?php endif; ?>
            </div>
            <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 leading-tight mb-4"><?= $escq($c['hero_headline']) ?></h1>
            <p class="text-gray-600 text-lg max-w-3xl leading-relaxed mb-7"><?= $escq($c['hero_sub']) ?></p>
            <div class="flex flex-wrap gap-3">
                <?php if ($slug === 'oman'): ?>
                    <a href="<?= getBasePath() ?>oman-business-index" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition">Browse Oman Business Index <i class="fa-solid fa-arrow-right text-xs"></i></a>
                    <a href="<?= getBasePath() ?>intro" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold shadow-sm hover:border-blue-300 hover:text-blue-700 transition">Get started with Cardify</a>
                <?php else: ?>
                    <a href="<?= getBasePath() ?>intro?ref=gcc-<?= $escq($slug) ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 text-white font-semibold shadow-sm hover:bg-blue-700 transition">Request early access <i class="fa-solid fa-arrow-right text-xs"></i></a>
                    <a href="<?= getBasePath() ?>gcc-business-index" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-white border border-gray-200 text-gray-700 font-semibold shadow-sm hover:border-blue-300 hover:text-blue-700 transition">Explore GCC Business Index</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-1 bg-white rounded-2xl p-6 border border-gray-200 shadow-sm">
                <div class="flex items-center gap-2 mb-4">
                    <span class="text-2xl"><?= $c['flag'] ?></span>
                    <h2 class="text-lg font-bold text-gray-900"><?= $escq($c['name']) ?> at a glance</h2>
                </div>
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3 pb-3 border-b border-gray-100"><dt class="text-gray-500">Capital</dt><dd class="font-semibold text-gray-900"><?= $escq($c['capital']) ?></dd></div>
                    <div class="flex justify-between gap-3 pb-3 border-b border-gray-100"><dt class="text-gray-500">Currency</dt><dd class="font-semibold text-gray-900"><?= $escq($c['currency']) ?></dd></div>
                    <div class="flex justify-between gap-3 pb-3 border-b border-gray-100"><dt class="text-gray-500">Companies (est.)</dt><dd class="font-semibold text-gray-900 text-right"><?= $escq($c['company_count_approx']) ?></dd></div>
                    <div class="flex justify-between gap-3 pb-3 border-b border-gray-100"><dt class="text-gray-500">Registry</dt><dd class="font-semibold text-gray-900 text-right"><?= $escq($c['registry_name']) ?></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-gray-500">Regulator</dt><dd class="font-semibold text-gray-900 text-right"><?= $escq($c['registrar_name']) ?></dd></div>
                </dl>
                <a href="<?= $escq($c['registrar_url']) ?>" target="_blank" rel="noopener nofollow" class="inline-flex items-center gap-1.5 text-sm font-semibold text-blue-700 hover:text-blue-800 mt-5">Visit official registrar <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i></a>
            </div>

            <div class="lg:col-span-2">
                <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">Why us</span>
                <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-5">Why Cardify for <?= $escq($c['name']) ?></h2>
                <ul class="space-y-3">
                    <?php foreach ($c['value_props'] as $vp): ?>
                        <li class="flex items-start gap-3 bg-white border border-gray-200 rounded-2xl p-5 shadow-sm hover:shadow-md hover:border-blue-200 transition">
                            <div class="w-8 h-8 shrink-0 rounded-lg bg-green-50 text-green-600 flex items-center justify-center">
                                <i class="fa-solid fa-check text-sm"></i>
                            </div>
                            <span class="text-gray-800 leading-relaxed pt-0.5"><?= $escq($vp) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </section>

    <section class="bg-gray-50 border-y border-gray-100 py-16">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">Sectors</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-3">Sectors we cover in <?= $escq($c['name']) ?></h2>
            <p class="text-gray-600 mb-7 max-w-3xl">Every sector gets a dedicated industry page with pricing, examples, and templates.</p>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($c['sectors'] as $sec): ?>
                    <span class="inline-flex items-center gap-2 bg-white border border-gray-200 rounded-full px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm hover:border-blue-300 hover:text-blue-700 transition">
                        <i class="fa-solid fa-industry text-xs text-gray-400"></i> <?= $escq($sec) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">Target companies</span>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-3">Major <?= $escq($c['name']) ?> companies in our sights</h2>
        <p class="text-gray-600 mb-7 max-w-3xl">We will launch <?= $escq($c['name']) ?> with logo entries and team cards for many of these firms. Know someone there? <a href="<?= getBasePath() ?>contact" class="text-blue-700 font-semibold hover:text-blue-800">Introduce us &rarr;</a></p>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <?php foreach ($c['majors'] as $m): ?>
                <div class="bg-white border border-gray-200 rounded-2xl p-4 text-center shadow-sm hover:shadow-md hover:border-blue-200 transition">
                    <div class="font-bold text-gray-900 text-sm"><?= $escq($m) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="bg-white border-t border-gray-100 py-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">FAQ</span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-gray-900 mt-2 mb-6">Frequently asked about <?= $escq($c['name']) ?></h2>
            <div class="space-y-3">
                <?php foreach ($c['faq'] as $i => $q): ?>
                    <details class="group bg-white border border-gray-200 rounded-2xl shadow-sm hover:border-blue-300 transition overflow-hidden"<?= $i === 0 ? ' open' : '' ?>>
                        <summary class="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer list-none">
                            <span class="font-semibold text-gray-900"><?= $escq($q['q']) ?></span>
                            <i class="fa-solid fa-chevron-down text-gray-400 text-sm transition group-open:rotate-180"></i>
                        </summary>
                        <div class="px-5 pb-5 text-gray-600 leading-relaxed"><?= $escq($q['a']) ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="bg-gradient-to-br from-blue-600 to-indigo-700 rounded-3xl p-10 sm:p-14 text-center text-white shadow-xl">
            <div class="text-5xl mb-4"><?= $c['flag'] ?></div>
            <h2 class="text-2xl sm:text-3xl font-extrabold mb-3">Building something in <?= $escq($c['name']) ?>?</h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto">Get early access to Cardify for <?= $escq($c['name']) ?>. We pilot 1-on-1 with a few anchor enterprises in each country before opening general availability.</p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="<?= getBasePath() ?>intro?ref=gcc-<?= $escq($slug) ?>" class="inline-flex items-center justify-center gap-2 bg-white text-blue-700 font-bold px-6 py-3 rounded-lg hover:bg-blue-50 shadow-sm transition">Request early access <i class="fa-solid fa-arrow-right text-xs"></i></a>
                <a href="<?= getBasePath() ?>contact" class="inline-flex items-center justify-center gap-2 bg-white/10 border border-white/30 text-white font-semibold px-6 py-3 rounded-lg hover:bg-white/20 transition">Talk to our team</a>
            </div>
            <p class="text-blue-200 text-sm mt-6">Oman is live · KSA, UAE, Qatar, Bahrain, Kuwait in onboarding through 2026</p>
        </div>
    </section>

    <section class="bg-gray-50 border-t border-gray-100 py-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <span class="text-blue-700 font-semibold text-xs uppercase tracking-wider">Other markets</span>
            <h3 class="text-xl sm:text-2xl font-extrabold text-gray-900 mt-2 mb-5">Explore other GCC markets</h3>
            <div class="grid sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <?php foreach ($countries as $s => $oc): if ($s === $slug) continue; ?>
                    <a href="<?= getBasePath() ?>gcc/<?= $escq($s) ?>" class="group block bg-white hover:border-blue-300 border border-gray-200 rounded-2xl p-4 text-center shadow-sm hover:shadow-md transition">
                        <div class="text-3xl mb-2"><?= $oc['flag'] ?></div>
                        <div class="font-semibold text-gray-900 group-hover:text-blue-700 text-sm transition"><?= $escq($oc['name']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
</div>
<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
