<?php
/**
 * Cardify - Business Cards for Hotels & Tourism in Oman
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Hotels & Tourism in Oman — Cardify';
$pageDescription = 'Create professional business cards for hotels, tour operators, and travel agencies in Oman. Multilingual cards, concierge sharing, and digital guest connections — free with Cardify.';
$canonicalUrl = 'https://cardify.om/industries/tourism';
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
    'name' => 'Cardify — Business Cards for Hotels & Tourism',
    'description' => 'Professional digital and printed business cards for hotels, tour operators, and travel agencies in Oman.',
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
    <div class="bg-gradient-to-br from-emerald-500 via-emerald-600 to-green-700 text-white py-20 pt-32">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto">
                <div class="w-20 h-20 rounded-full bg-white/20 flex items-center justify-center mx-auto mb-6">
                    <i class="fa-solid fa-plane-departure text-4xl"></i>
                </div>
                <h1 class="text-4xl md:text-5xl font-bold mb-6">Business Cards for Hotels & Tourism in Oman</h1>
                <p class="text-xl text-emerald-100 leading-relaxed mb-8">
                    Elevate your hospitality brand with multilingual digital business cards. Perfect for hotel staff, tour operators, travel agents, and concierge teams across Oman.
                </p>
                <a href="<?php echo getBasePath(); ?>company/register.php"
                   class="inline-flex items-center gap-2 px-8 py-4 bg-white text-emerald-600 font-semibold rounded-xl hover:bg-emerald-50 transition-colors text-lg">
                    Create Tourism Business Cards Free
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

        <!-- Why Tourism Needs Cards -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-emerald-600 font-semibold text-sm uppercase tracking-wider">Why It Matters</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Why Tourism & Hospitality Businesses in Oman Need Digital Cards</h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        Oman's tourism sector is experiencing unprecedented growth. From luxury resorts in Musandam to desert camps in Wahiba Sands, the hospitality industry needs to connect with guests from around the world seamlessly.
                    </p>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        With <?php echo $brandName; ?>, your hotel concierge, tour guide, or travel consultant can share a digital card with guests in any language. The guest saves the contact instantly — no language barrier, no paper waste.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        Imagine a tourist visiting Oman for the first time. Your tour guide shares a digital card with the hotel's WhatsApp, emergency contacts, and tour booking links. That guest becomes a returning customer and a brand advocate.
                    </p>
                </div>
                <div class="bg-gradient-to-br from-emerald-50 to-green-50 rounded-2xl p-8">
                    <div class="space-y-4">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-language text-emerald-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Multilingual Cards</h3>
                                <p class="text-gray-600 text-sm">Arabic, English, and more — serve international guests in their preferred language.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-concierge-bell text-emerald-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Concierge Cards</h3>
                                <p class="text-gray-600 text-sm">Hotel concierge shares their card with guests — instant access to recommendations, bookings, and support.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-share-from-square text-emerald-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Digital Guest Sharing</h3>
                                <p class="text-gray-600 text-sm">Guests share your card with friends planning Oman trips — digital word of mouth.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-star text-emerald-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Review & Rating Links</h3>
                                <p class="text-gray-600 text-sm">Include Google Reviews and TripAdvisor links — happy guests leave reviews before they leave.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feature Highlights -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-emerald-600 font-semibold text-sm uppercase tracking-wider">Features</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Built for Tourism & Hospitality</h2>
            </div>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-emerald-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-hotel text-2xl text-emerald-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Hotel Staff Cards</h3>
                    <p class="text-gray-600">
                        From front desk to management — create branded cards for your entire hotel team. Guests save the right contact for their needs.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-compass text-2xl text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Tour Guide Cards</h3>
                    <p class="text-gray-600">
                        Tour guides share their card with groups at the start of every tour. Tourists have the guide's WhatsApp, emergency number, and next tour schedule.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-teal-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-earth-americas text-2xl text-teal-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Travel Agency Cards</h3>
                    <p class="text-gray-600">
                        Travel consultants include links to holiday packages, visa services, and booking portals — turning every interaction into a potential booking.
                    </p>
                </div>
            </div>
        </div>

        <!-- Use Cases -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-emerald-600 font-semibold text-sm uppercase tracking-wider">Use Cases</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">How Tourism Businesses Use <?php echo $brandName; ?></h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-emerald-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Hotel Check-In Experience</h3>
                    <p class="text-gray-600">
                        During check-in, the front desk shares a digital card with the concierge's contact, Wi-Fi details, restaurant hours, and local recommendations. Guests feel welcomed and informed.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-green-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Desert Safari & Adventure Tours</h3>
                    <p class="text-gray-600">
                        Tour operators share cards before the trip with emergency contacts, meeting points, and WhatsApp groups. After the tour, guests have everything they need to book again or refer friends.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-teal-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Travel & Tourism Exhibitions</h3>
                    <p class="text-gray-600">
                        At ATM, WTM, or local tourism fairs, share unlimited digital cards with travel agents, DMCs, and airline partners. No printed cards to run out of.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-blue-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Cruise Ship & Port Services</h3>
                    <p class="text-gray-600">
                        Port service providers, shore excursion operators, and transport companies share cards with cruise passengers — a market that's growing rapidly in Oman.
                    </p>
                </div>
            </div>
        </div>

        <!-- Social Proof -->
        <div class="mb-20">
            <div class="bg-gradient-to-br from-emerald-50 to-green-50 rounded-2xl p-8 lg:p-12">
                <div class="text-center mb-10">
                    <h2 class="text-3xl font-bold text-gray-900">Trusted by Tourism Businesses Across Oman</h2>
                </div>
                <div class="grid md:grid-cols-4 gap-6 text-center">
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <div class="text-3xl font-bold text-emerald-600 mb-2">500+</div>
                        <div class="text-gray-500 text-sm">Companies Trust <?php echo $brandName; ?></div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <div class="text-3xl font-bold text-emerald-600 mb-2">50K+</div>
                        <div class="text-gray-500 text-sm">Cards Created</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <div class="text-3xl font-bold text-emerald-600 mb-2">Multi</div>
                        <div class="text-gray-500 text-sm">Language Support</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <div class="text-3xl font-bold text-emerald-600 mb-2">Free</div>
                        <div class="text-gray-500 text-sm">To Get Started</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CTA -->
        <div class="bg-gradient-to-br from-emerald-500 to-green-700 rounded-2xl p-8 lg:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4">Create Tourism Business Cards Free</h2>
            <p class="text-emerald-100 mb-8 max-w-2xl mx-auto">
                Join hotels, tour operators, and travel agencies across Oman who use <?php echo $brandName; ?> to connect with guests and partners professionally.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-emerald-600 font-semibold rounded-xl hover:bg-emerald-50 transition-colors">
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
                <a href="<?php echo getBasePath(); ?>blog" class="text-emerald-600 hover:text-emerald-700 font-medium text-sm">Read Our Blog</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>faq" class="text-emerald-600 hover:text-emerald-700 font-medium text-sm">FAQs</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>intro" class="text-emerald-600 hover:text-emerald-700 font-medium text-sm">How It Works</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>about" class="text-emerald-600 hover:text-emerald-700 font-medium text-sm">About Us</a>
            </div>
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-emerald-600 hover:text-emerald-700 font-medium mt-4">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
