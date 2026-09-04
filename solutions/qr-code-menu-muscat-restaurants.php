<?php
/**
 * Cardify - QR Code Menus for Muscat Restaurants & Cafés
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/JsonLd.php';
require_once INCLUDES_DIR . '/Seo.php';

$pageTitle = 'QR Code Menus for Muscat Restaurants & Cafés, Cardify';
$pageDescription = 'Turn your laminated menu into a scannable QR card. Update prices once, everyone sees live. Built for Muscat cafés, Arabic/English, VAT breakdown, delivery links.';
$canonicalUrl = 'https://cardify.om/solutions/qr-code-menu-muscat-restaurants';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'QR Code Menus for Muscat Restaurants', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = Seo::articleNode(
    __FILE__,
    'QR Code Menus for Muscat Restaurants & Cafés',
    $pageDescription,
    $canonicalUrl,
    $ogImage ?? null,
    'en-OM'
);
$extraHead = '<script type="application/ld+json">' . json_encode($breadcrumbJsonLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($articleJsonLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

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
                <span class="text-gray-700">QR Menus for Muscat Cafés</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">QR Code Menus for Muscat Restaurants & Cafés</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                From an Al Mouj beachfront café to a shawarma spot off the 18th November Street, your menu changes faster than your printed sheets can keep up. A Cardify QR menu updates in seconds and shows up beautifully on every phone, Arabic and English, with Talabat and Akeed delivery links baked in.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Why Muscat Cafés Have Outgrown Paper Menus</h2>
        <p>
            The Muscat F&B scene has exploded post-2022. Areas like Al Mouj marina, Shatti Al Qurum, Al Khuwair, and Al Ghubra now host hundreds of independent cafés, from third-wave speciality coffee at MAQ Coffee, Emilio Coffee, and Abyat, to boutique breakfast spots and late-night dessert bars. These businesses change their menu frequently, weekly specials, seasonal Omani-inspired dishes during Ramadan (harees, shuwa-inspired sliders), khareef-timed Dhofari coffee pop-ups, and a rotating roster of collabs. Reprinting a laminated menu 12 times a year is a hidden cost nobody tracks: OMR 40–120 per print run multiplied by 4–6 branches.
        </p>
        <p>
            A QR menu solves this permanently. Guests scan the small code on the table or card holder, your menu opens in their browser in Arabic or English (auto-detected), they browse with images, and you update one URL from your phone. When a supplier like Muscat Bakery raises the sourdough price, you change the number in the dashboard, the menu on every table reflects it instantly.
        </p>

        <h2>What Makes Cardify Different From A Google Doc QR</h2>
        <p>
            Anyone can generate a free QR that opens a PDF. That's not what guests want. <?php echo $brandName; ?>'s QR menu is a mobile-first, fast-loading HTML menu with: (1) categories with sticky scroll, Breakfast, Hot Drinks, Cold Drinks, Mains, Desserts; (2) item-level photos, description, and price in OMR with the correct 3-decimal format (e.g., 2.150 OMR not 2.15 OMR); (3) allergen icons (vegan, gluten-free, halal-certified, contains nuts), critical for the growing vegan crowd in Muscat and Indian-Omani guests avoiding certain ingredients; (4) the 5% VAT breakdown as required by the Oman Tax Authority so you're visibly compliant; and (5) one-tap actions: "Order on Talabat", "Order on Akeed", "Call for Reservation", "Ask on WhatsApp".
        </p>
        <p>
            The menu loads in under 1.2 seconds on Omantel 4G and under 800ms on an in-café Wi-Fi. No PDF pinch-zoom, no broken fonts on iPhones, no awkward scrolling through a 3MB image.
        </p>

        <p><strong>Create digital business cards for your team, free to start with <?php echo $brandName; ?>.</strong> Add a QR menu to the owner's card and every table gets a scannable link. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free &rarr;</a></p>

        <h2>Bilingual by Default, Arabic Menu That Actually Reads Right</h2>
        <p>
            Your Omani guests expect Arabic. Your expat guests, Indian, Filipino, Bangladeshi, Egyptian, European, want English. Tourists arriving from Muscat International Airport via a Kempinski or W Muscat concierge recommendation need English with clear pictures. Cardify's menu builder accepts both; you fill item name and description once in English, we offer AI-assisted Arabic translation that you approve line by line (we don't silently push auto-translated prose into a menu, every item is human-checked). The Arabic side uses Noto Naskh Arabic with proper right-to-left flow, and numerals can be switched between Arabic-Indic (٢٠٫٠٠) and Latin (20.00) per your preference.
        </p>

        <h2>Real Examples From Muscat Venues</h2>
        <p>
            One Cardify customer, a small coffee and bakery concept in Muscat Hills, prints a tiny QR sticker (3cm square) on the underside of their wooden table tents. The QR resolves to the manager's Cardify digital card, which has a "See Menu" button at the top. Scan-through rate: about 38% of tables. Another customer, a chain of three shawarma spots in Ghubra, Mabelah, and Al Khoud, uses the same URL across all branches with a branch picker, the customer taps "Which branch are you at?" and the menu loads with the correct dine-in price vs delivery price (Talabat adds a 15% markup which the branch owner passes on transparently).
        </p>
        <p>
            For Muscat Grand Mall and Mall of Muscat food court tenants, Cardify offers a "food court layout" template, compact, image-heavy, with a giant "Order Now" button that deep-links into the Mall's app or the tenant's WhatsApp number. Useful during Friday lunch rush when nobody wants to stand reading a menu board.
        </p>

        <h2>Printing the QR Properly</h2>
        <p>
            A QR menu is only useful if the code is scannable in café lighting. We generate your QR at 1200 DPI vector (SVG), with optional your-logo-in-the-middle, and include a 10% error-correction margin, meaning even if the table candle wax drips on a quarter of the code, it still scans. Order printed NFC+QR table tents through our Muscat partner print shop and they'll be at your café within 72 hours. During Ramadan, switch to a crescent-themed QR frame automatically; on National Day, swap to the Omani flag frame.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">Ready to retire the laminated menu?</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team, free to start with <?php echo $brandName; ?>. QR menus are free for every team, no plan required.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your QR Menu Free
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
