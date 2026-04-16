<?php
/**
 * Cardify - Bilingual Arabic-English Business Cards for Oman
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Bilingual Arabic-English Business Cards for Oman Businesses — Cardify';
$pageDescription = 'Proper bilingual business cards with Arabic on one side and English on the other — Noto Naskh Arabic, right-to-left layout, MoCIIP-compliant Arabic company name. Free to create.';
$canonicalUrl = 'https://cardify.om/solutions/bilingual-arabic-english-business-cards';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Bilingual Arabic-English Business Cards for Oman', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Bilingual Arabic-English Business Cards for Oman Businesses',
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
                <span class="text-gray-700">Bilingual Arabic-English Cards</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Bilingual Arabic-English Business Cards for Oman Businesses</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                In Oman, a card that shows only English signals you haven't done business here before. Our bilingual template flips instantly between Arabic and English — the same profile, the right language for every recipient.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Why Bilingual Isn't Optional in Oman</h2>
        <p>
            Oman is officially an Arabic-speaking country. While almost all business is transacted in English in the private sector, formal interactions — with a director at the Ministry of Commerce, Industry and Investment Promotion (MoCIIP), a senior officer at the Royal Oman Police Traffic & Licensing, a procurement head at OQ Exploration & Production, or a senior judge at Oman Chamber arbitration — expect Arabic first. Your Commercial Registration (CR) certificate from Invest Easy is issued in Arabic with an English translation; your VAT registration with the Oman Tax Authority lists your Arabic entity name as the primary. A business card that doesn't carry the Arabic equivalent of your company name implicitly tells a government counterpart that you didn't bother.
        </p>
        <p>
            At a more practical level: a majority of Omani SME owners, especially in traditional sectors like trading (the sougs in Mutrah, Nizwa, and Ibra), livestock, fisheries (Barka, Sur), and agriculture, prefer reading Arabic first. A bilingual card creates rapport in the first second of the meeting. We've had Cardify customers close deals they'd been chasing for months simply because they finally handed over a properly set Arabic card that included the Arabic transliteration of the rep's name.
        </p>

        <h2>What "Properly Bilingual" Actually Means</h2>
        <p>
            Most business card tools bolt on a translation and call it done. In practice, an Arabic business card needs: (1) a true right-to-left (RTL) layout — name on the right, icons mirrored; (2) a properly designed Arabic typeface like Noto Naskh Arabic, Dubai Font, or 29LT Zawi, not a default Arial which renders disconnected letters; (3) diacritics-safe rendering so ك ج ح م ه don't collapse into boxes; (4) the same visual weight as the English side so the card doesn't feel like an afterthought; and (5) an Arabic phone number format with Eastern Arabic numerals (٩٦٨+) as an option.
        </p>
        <p>
            <?php echo $brandName; ?> handles all of this automatically. When you enter your company's Arabic CR name (e.g., شركة بن حيدر درويش ش.م.م. for Bin Haider Darwish LLC), we store it as an attributed field and render it correctly both on the digital card and on any printed NFC card you order from our Muscat print partner. Arabic names are bidi-aware — Latin numbers mid-sentence (like a P.O. Box) render in the correct direction without manual Unicode markers.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start your bilingual card &rarr;</a></p>

        <h2>Instant Language Switch on the Digital Card</h2>
        <p>
            On a printed card the two languages live on the front and back. On a <?php echo $brandName; ?> digital card, the viewer sees the language that matches their phone setting — Arabic speakers see the Arabic profile first, English speakers see English. There's also a prominent "العربية / English" toggle at the top, so an Omani procurement head receiving the card on WhatsApp can switch if their phone is in English but they'd rather read Arabic.
        </p>
        <p>
            The same applies to the vCard download. When an Arabic user taps "Save to Contacts", the generated .vcf file contains Arabic FN (Full Name), ORG (Organization), and TITLE fields — which means the contact shows up in their phone in Arabic, searchable via Arabic letters. This is a quiet detail most tools miss, and it's the difference between being remembered or disappearing into a "Sales Guy UK" contact buried in a phonebook of 2,000 people.
        </p>

        <h2>Compliance & Formal Use</h2>
        <p>
            Your Cardify bilingual card includes fields for: Arabic and English trade name, CR number, VAT number (Tax Identification Number), P.O. Box and postal code in both scripts, and a QR code that — when scanned — opens the English profile by default but includes an `hl=ar` URL parameter your partners can share to open directly in Arabic. If you're preparing documents for a tender on the eTendering portal (Ejadah) or attending a meeting at the Oman Investment Authority (OIA), you can download a high-resolution printable PDF of the bilingual card for your submission folder. The Arabic side is typographically correct enough to include in official documentation.
        </p>

        <h2>Typography That Matches Your Brand</h2>
        <p>
            Choose between classical Naskh (formal, government-facing), modern Kufi (sharp, tech-forward brands like KOM tenants), or humanist Ruq'ah (warm, hospitality). We've preset pairings: if your English font is Inter, we pair Arabic with Cairo; if English is Poppins, we pair with Tajawal; if English is Playfair Display, we pair with Amiri. You can override if your brand book specifies a licensed font — upload the .ttf/.otf and we embed it server-side so Arabic render is pixel-identical on every device.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">A card Oman actually respects</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. Full Arabic + English, RTL-correct, and rendered properly on every WhatsApp share.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Create Your Bilingual Card
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
