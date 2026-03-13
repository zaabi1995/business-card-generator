<?php
/**
 * Cardify - Business Cards for Restaurants & Cafés in Oman
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Restaurants & Cafés in Oman — Cardify';
$pageDescription = 'Create professional business cards for your restaurant, café, or F&B business in Oman. QR menu integration, digital cards for staff, and instant sharing — all free with Cardify.';
$canonicalUrl = 'https://cardify.om/industries/restaurants';
$ogType = 'website';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;
$navLinks = [
    ['href' => getBasePath() . '#features', 'label' => 'Features'],
    ['href' => getBasePath() . '#pricing', 'label' => 'Pricing'],
    ['href' => getBasePath() . 'about', 'label' => 'About'],
    ['href' => getBasePath() . 'contact', 'label' => 'Contact'],
];

// JSON-LD Schema
$extraHead = '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => 'Cardify — Business Cards for Restaurants & Cafés',
    'description' => 'Professional digital and printed business cards for restaurants, cafés, and F&B businesses in Oman.',
    'provider' => [
        '@type' => 'Organization',
        'name' => 'Cardify',
        'url' => 'https://cardify.om',
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => 'Muscat',
            'addressCountry' => 'OM',
        ],
    ],
    'areaServed' => [
        '@type' => 'Country',
        'name' => 'Oman',
    ],
    'serviceType' => 'Digital Business Card Platform',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Hero Section -->
    <div class="bg-gradient-to-br from-orange-500 via-orange-600 to-red-600 text-white py-20 pt-32">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto">
                <div class="w-20 h-20 rounded-full bg-white/20 flex items-center justify-center mx-auto mb-6">
                    <i class="fa-solid fa-utensils text-4xl"></i>
                </div>
                <h1 class="text-4xl md:text-5xl font-bold mb-6">Business Cards for Restaurants & Cafés in Oman</h1>
                <p class="text-xl text-orange-100 leading-relaxed mb-8">
                    Give your restaurant a professional edge. Create stunning digital business cards with QR menus, contact details, and social links — all shareable in seconds.
                </p>
                <a href="<?php echo getBasePath(); ?>company/register.php"
                   class="inline-flex items-center gap-2 px-8 py-4 bg-white text-orange-600 font-semibold rounded-xl hover:bg-orange-50 transition-colors text-lg">
                    Create Your Restaurant's Business Cards Free
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

        <!-- Why Restaurants Need Professional Cards -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-orange-600 font-semibold text-sm uppercase tracking-wider">Why It Matters</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Why Every Restaurant in Oman Needs Professional Business Cards</h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        In Oman's competitive F&B scene, first impressions matter. Whether you're a fine dining restaurant in Muscat, a specialty café in Salalah, or a popular shawarma spot in Sohar, a professional business card sets you apart.
                    </p>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        Traditional paper cards get stained, lost, or thrown away. Digital business cards from <?php echo $brandName; ?> stay with your customers forever — accessible on their phone with a single tap or scan.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        Imagine a guest scanning your manager's card and instantly seeing your menu, location on Google Maps, Instagram page, and reservation link. That's the power of a <?php echo $brandName; ?> digital card.
                    </p>
                </div>
                <div class="bg-gradient-to-br from-orange-50 to-red-50 rounded-2xl p-8">
                    <div class="space-y-4">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-orange-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-qrcode text-orange-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">QR Code to Your Menu</h3>
                                <p class="text-gray-600 text-sm">Link directly to your digital menu — guests scan and browse instantly.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-orange-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-map-location-dot text-orange-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Location & Directions</h3>
                                <p class="text-gray-600 text-sm">One-tap Google Maps directions to your restaurant.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-orange-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-brands fa-instagram text-orange-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Social Media Links</h3>
                                <p class="text-gray-600 text-sm">Instagram, TikTok, WhatsApp — all your channels in one card.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-orange-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-phone text-orange-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Reservation & Delivery</h3>
                                <p class="text-gray-600 text-sm">Direct call or WhatsApp link for reservations and delivery orders.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feature Highlights -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-orange-600 font-semibold text-sm uppercase tracking-wider">Features</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Built for the F&B Industry</h2>
            </div>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-orange-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-users text-2xl text-orange-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Cards for Every Role</h3>
                    <p class="text-gray-600">
                        Create unique cards for your manager, chef, head waiter, and delivery team — each with their own contact details and role-specific links.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-palette text-2xl text-red-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Brand-Consistent Design</h3>
                    <p class="text-gray-600">
                        Use your restaurant's logo, colors, and style across all employee cards. Lock the design so your brand stays consistent no matter who creates a card.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-yellow-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-bolt text-2xl text-yellow-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Instant Updates</h3>
                    <p class="text-gray-600">
                        Changed your menu? New branch opening? Update your card once and everyone who has it sees the latest info — no reprinting needed.
                    </p>
                </div>
            </div>
        </div>

        <!-- Use Cases -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-orange-600 font-semibold text-sm uppercase tracking-wider">Use Cases</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">How Restaurants Use <?php echo $brandName; ?></h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-orange-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Networking at Food Festivals</h3>
                    <p class="text-gray-600">
                        At events like the Muscat Food Festival or Salalah Tourism Festival, your team can share digital cards with suppliers, bloggers, and potential partners instantly — no stack of paper cards needed.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-red-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Table-Side QR Cards</h3>
                    <p class="text-gray-600">
                        Place QR codes on tables that link to your manager's digital card. Guests can save your contact, leave reviews, or connect on social media before they leave.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-yellow-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Delivery Driver Cards</h3>
                    <p class="text-gray-600">
                        Equip your delivery drivers with NFC-enabled cards. When they hand over the food, a quick tap lets the customer save your restaurant's details for future orders.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-green-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Multi-Branch Management</h3>
                    <p class="text-gray-600">
                        Run multiple locations? Manage all your branches' cards from one dashboard. Each branch gets its own address, phone, and team — all under one company account.
                    </p>
                </div>
            </div>
        </div>

        <!-- Social Proof -->
        <div class="mb-20">
            <div class="bg-gradient-to-br from-orange-50 to-red-50 rounded-2xl p-8 lg:p-12">
                <div class="text-center mb-10">
                    <h2 class="text-3xl font-bold text-gray-900">Trusted by F&B Businesses Across Oman</h2>
                </div>
                <div class="grid md:grid-cols-4 gap-6 text-center">
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <div class="text-3xl font-bold text-orange-600 mb-2">500+</div>
                        <div class="text-gray-500 text-sm">Companies Trust <?php echo $brandName; ?></div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <div class="text-3xl font-bold text-orange-600 mb-2">50K+</div>
                        <div class="text-gray-500 text-sm">Cards Created</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <div class="text-3xl font-bold text-orange-600 mb-2">Free</div>
                        <div class="text-gray-500 text-sm">To Get Started</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <div class="text-3xl font-bold text-orange-600 mb-2">2 min</div>
                        <div class="text-gray-500 text-sm">Setup Time</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CTA -->
        <div class="bg-gradient-to-br from-orange-500 to-red-600 rounded-2xl p-8 lg:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4">Create Your Restaurant's Business Cards Free</h2>
            <p class="text-orange-100 mb-8 max-w-2xl mx-auto">
                Join hundreds of restaurants and cafés in Oman who use <?php echo $brandName; ?> to connect with customers, suppliers, and partners.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-orange-600 font-semibold rounded-xl hover:bg-orange-50 transition-colors">
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

        <!-- Internal Links -->
        <div class="mt-12 text-center space-y-4">
            <p class="text-gray-500 text-sm">Learn more about <?php echo $brandName; ?></p>
            <div class="flex flex-wrap justify-center gap-4">
                <a href="<?php echo getBasePath(); ?>blog" class="text-orange-600 hover:text-orange-700 font-medium text-sm">Read Our Blog</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>faq" class="text-orange-600 hover:text-orange-700 font-medium text-sm">FAQs</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>intro" class="text-orange-600 hover:text-orange-700 font-medium text-sm">How It Works</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>about" class="text-orange-600 hover:text-orange-700 font-medium text-sm">About Us</a>
            </div>
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-orange-600 hover:text-orange-700 font-medium mt-4">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
