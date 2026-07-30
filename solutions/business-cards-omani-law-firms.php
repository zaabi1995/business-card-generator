<?php
/**
 * Cardify - Business Cards for Omani Law Firms
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Seo.php';

$pageTitle = 'Business Cards for Omani Law Firms, Cardify';
$pageDescription = 'Refined, bilingual, discreet. Digital and printed cards for Oman Bar Association lawyers, advocates and legal consultants. MoJ-compliant titles, majlis-ready design.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-omani-law-firms';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Business Cards for Omani Law Firms', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = Seo::articleNode(
    __FILE__,
    'Business Cards for Omani Law Firms',
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
                <span class="text-gray-700">Omani Law Firms</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Omani Law Firms</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                Appearance matters in the Omani legal profession. A discreet, perfectly-typeset card communicates precision, exactly what clients and counterparty lawyers expect when they meet you in Muscat Court or at the Al Bustan palace arbitration room.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>The Legal Card Conventions in Oman</h2>
        <p>
            Oman's legal market is regulated by the Ministry of Justice and Legal Affairs (MoJLA) and organised professionally through the Oman Bar Association (العمل المحامي). Lawyers (محامي) are categorised by jurisdiction of practice, Primary Court, Court of Appeal, and Supreme Court (rare and prestigious). Legal consultants (مستشار قانوني) who are not admitted to practice in local courts have a different licensing regime under MoJLA and increasingly under the FDI/legal services liberalisation provisions. Your business card must show your correct title exactly as registered, "Advocate admitted before the Supreme Court" carries weight; "Senior Associate" without an admission tier can raise eyebrows in a hearing room.
        </p>
        <p>
            Prominent firms, Said Al-Shahry & Partners (SASLO), Al Busaidy Mansoor Jamal (AMJ), Trowers & Hamlins Muscat, Curtis Mallet-Prevost Colt & Mosle, Addleshaw Goddard Oman, set the visual standard: restrained typography, single or two-colour printing, high-quality uncoated stock, understated gold or blind-embossed firm crest. A <?php echo $brandName; ?> bilingual card respects this aesthetic, we offer "legal profession" design presets that mirror exactly this convention: Garamond or Caslon for English, Naskh for Arabic, cream or pale blue background, no gradients, no icons, just crisp hierarchy.
        </p>

        <h2>Fields That Matter on a Legal Business Card</h2>
        <p>
            A Cardify digital card for an Omani advocate includes: bilingual full name (the Arabic version of your name as it appears on your Oman Bar Association membership card is critical, clients and counterparties check), MoJLA admission number, bar association membership number, practice areas (commercial, arbitration, family, labour, corporate, M&A, litigation, intellectual property, banking & finance), court jurisdiction, firm name with CR number, direct dial with private line extension, discreet mobile number (optional, many senior partners don't publish a mobile), fax line (still used by courts for formal service), physical office address (typically Al Qurum, Muttrah, Shatti, or Al Khuwair), and a PDF download of your CV/CV extract. For arbitration practitioners, we include ICC, LCIA, DIAC, GCCAC memberships as separate fields.
        </p>

        <p><strong>Create digital business cards for your team, free to start with <?php echo $brandName; ?>.</strong> Order Luxury uncoated letterpress cards for the senior partners. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Set up your firm &rarr;</a></p>

        <h2>Discretion, Client Confidentiality & Digital Trust</h2>
        <p>
            Lawyers are (correctly) cautious about digital platforms that harvest contact data. <?php echo $brandName; ?> runs on Oman infrastructure with TRA-compliant hosting, does not sell or share contact data with third parties, and offers a "private profile" mode where your card requires a passcode to unlock full contact details. For sensitive matters, corporate raids, internal investigations, family disputes, you can issue a matter-specific card with only the minimum contact details and an automatic 90-day expiry. Once the matter closes, the card URL returns a 404 and the NFC chip becomes a blank reference to your profile directory.
        </p>
        <p>
            For in-house counsel at Oman Oil Company (OQ), PDO Legal, Central Bank of Oman, Capital Market Authority (CMA), or sovereign wealth legal teams, Cardify offers an "internal use" mode that makes your card available only to holders of an official @oman.om or equivalent corporate email, preventing external harvesting.
        </p>

        <h2>Court & Hearing Room Etiquette</h2>
        <p>
            In front of a judge in the Primary or Appeal Court at Muscat's Al Qurum Court complex, you do not hand out cards during proceedings, that's uncouth. But before the hearing, in the advocates' room, or after a judgment, colleagues and opposing counsel do exchange contacts. A single NFC tap in this setting is quieter and more professional than fumbling for paper cards. For counterparty depositions in arbitration at the OCEC Business Centre, Cardify's "meeting mode" logs every card exchange with timestamp and matter reference, useful later when fees are billed by hour and you need defensible records.
        </p>

        <h2>Client-Facing Materials Beyond the Card</h2>
        <p>
            Beyond the business card, Omani law firms typically produce a firm brochure, partner CVs, sector capability statements (energy, banking, family business), and Oman market updates. Cardify lets you attach these PDFs to each partner's profile, the client sees a clean "Download Capability Statement, Energy Practice" button under your card. For the firm's senior partner meeting the GC of a major Omani conglomerate, this means one NFC tap delivers the card, the CV, the energy practice brochure, and a Google Calendar link for a follow-up meeting, instead of a folder of papers the client will never open.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Discretion, precision, bilingual</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team, free to start with <?php echo $brandName; ?>. Designed with the Omani legal profession in mind.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Firm's Account
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
