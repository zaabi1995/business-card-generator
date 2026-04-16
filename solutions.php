<?php
/**
 * Cardify - Oman Solutions Hub
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Oman Business Card Solutions — Cardify';
$pageDescription = 'Oman-specific digital business card solutions — by industry, location, use case, and staff type. From Sultan Haitham City contractors to Salalah Khareef operators.';
$canonicalUrl = 'https://cardify.om/solutions';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => $canonicalUrl],
    ],
];
$extraHead = '<script type="application/ld+json">' . json_encode($breadcrumbJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';

$solutions = [
    'By Industry' => [
        ['url' => 'digital-business-cards-oman-sales-teams', 'title' => 'Sales Teams in Oman', 'desc' => 'Equip B2B sales reps with CRM-synced digital cards and WhatsApp follow-ups.'],
        ['url' => 'digital-business-cards-oil-gas-oman', 'title' => "Oil & Gas Industry", 'desc' => 'Tender-ready cards for PDO, OQ, Daleel contractors with CR, ICV and HSE fields.'],
        ['url' => 'business-cards-omani-law-firms', 'title' => 'Omani Law Firms', 'desc' => 'Discreet bilingual cards for advocates, legal consultants, and in-house counsel.'],
        ['url' => 'digital-cards-oman-real-estate-agents', 'title' => 'Real Estate Agents', 'desc' => 'Live listings, agent licence, and one-tap WhatsApp on every card.'],
        ['url' => 'business-cards-muscat-doctors-clinics', 'title' => 'Muscat Doctors & Clinics', 'desc' => 'MoH-compliant cards with specialty, licence, and appointment booking.'],
        ['url' => 'business-cards-oman-construction-companies', 'title' => 'Construction & Contracting', 'desc' => 'Rugged site-safe NFC cards with CR, grade, ICV, and ISO certifications.'],
        ['url' => 'business-cards-oman-bank-employees', 'title' => 'Bank & Finance Employees', 'desc' => 'Phishing-proof cards with central IT admin for Oman banks and finance firms.'],
        ['url' => 'digital-business-cards-oman-hotels', 'title' => 'Hotels & Hospitality Staff', 'desc' => 'Guest-shareable cards for concierges, F&B managers, and event coordinators.'],
    ],
    'By Location' => [
        ['url' => 'qr-code-menu-muscat-restaurants', 'title' => 'QR Menus for Muscat Restaurants', 'desc' => 'Bilingual QR menus with Talabat, Akeed, and VAT breakdown — for Muscat cafés.'],
        ['url' => 'digital-business-cards-sohar-industrial-port', 'title' => 'Sohar Industrial Port', 'desc' => 'Multi-language cards for SOHAR Port & Freezone tenants working with Asian and EU partners.'],
        ['url' => 'salalah-tourism-business-cards', 'title' => 'Salalah Tourism & Khareef', 'desc' => 'Seasonal cards that switch to Khareef mode June–September — built for Dhofar operators.'],
        ['url' => 'business-cards-duqm-free-zone', 'title' => 'Duqm Free Zone Companies', 'desc' => 'Multi-language cards with SEZAD licence verification — for hydrogen, refinery, fisheries.'],
    ],
    'By Use Case' => [
        ['url' => 'bilingual-arabic-english-business-cards', 'title' => 'Bilingual Arabic-English Cards', 'desc' => 'True RTL layout with Noto Naskh Arabic — not a bolt-on translation.'],
        ['url' => 'nfc-business-cards-oman-executives', 'title' => 'NFC Cards for Executives', 'desc' => 'Metal, wood, or matte-black NFC cards for Oman C-suite and boardroom use.'],
        ['url' => 'business-cards-for-ramadan-networking', 'title' => 'Ramadan Networking Events', 'desc' => 'Iftar and ghabga-ready cards with Ramadan Kareem theme and adjusted hours.'],
        ['url' => 'business-cards-for-oman-trade-fairs', 'title' => 'Oman Trade Fairs', 'desc' => 'Event-mode lead capture for OIF, OFEX, IDEX Oman, OPES, and every OCEC show.'],
    ],
    'By Staff Type' => [
        ['url' => 'business-cards-oman-government-employees', 'title' => 'Government & Public Sector', 'desc' => 'Protocol-grade cards for ministries, RAFO, ROP, OTA, municipalities.'],
        ['url' => 'business-cards-oman-freelancers-consultants', 'title' => 'Freelancers & Consultants', 'desc' => 'Portfolio, booking, and payment on your self-employment card.'],
        ['url' => 'business-cards-oman-startups', 'title' => 'Startups & Tech Founders', 'desc' => 'Pitch decks, waitlists, and investor-ready cards for KOM and Future Fund founders.'],
        ['url' => 'business-cards-oman-omanisation', 'title' => 'Omanisation & Onboarding', 'desc' => 'Day-one cards for every Omani hire — bulk CSV upload, no print delay.'],
    ],
];
?>

<div class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Oman Business Card Solutions</h1>
            <p class="text-gray-600 text-lg max-w-2xl mx-auto">
                Specific solutions for specific needs — built for the Sultanate. Browse by industry, location, use case, or staff type.
            </p>
            <div class="mt-6">
                <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                    Start Your Free Account
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16 space-y-16">
        <?php foreach ($solutions as $category => $items): ?>
            <section>
                <h2 class="text-2xl font-bold text-gray-900 mb-6 border-b border-gray-200 pb-3"><?php echo htmlspecialchars($category); ?></h2>
                <div class="grid md:grid-cols-2 gap-6">
                    <?php foreach ($items as $item): ?>
                        <a href="<?php echo getBasePath(); ?>solutions/<?php echo htmlspecialchars($item['url']); ?>"
                           class="block bg-white rounded-xl shadow-sm p-6 border border-gray-100 hover:border-blue-300 hover:shadow-md transition-all">
                            <h3 class="text-lg font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($item['title']); ?></h3>
                            <p class="text-gray-600 text-sm leading-relaxed"><?php echo htmlspecialchars($item['desc']); ?></p>
                            <p class="text-blue-600 font-medium text-sm mt-3 inline-flex items-center gap-1">
                                Read more <i class="fa-solid fa-arrow-right text-xs"></i>
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <div class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl p-8 lg:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4">Don't see your use case?</h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto">
                Cardify adapts to any Omani business — from a solo freelancer in Nizwa to a 3,000-employee conglomerate. Start free, and we'll tailor a solution with you.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-blue-600 font-semibold rounded-xl hover:bg-blue-50 transition-colors">
                    Get Started Free
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
                <a href="<?php echo getBasePath(); ?>contact"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 border-2 border-white text-white font-semibold rounded-xl hover:bg-white/10 transition-colors">
                    Talk to Our Team
                    <i class="fa-solid fa-envelope"></i>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
