<?php
/**
 * Cardify - Business Cards for Real Estate Agents in Oman
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Real Estate Agents in Oman — Cardify';
$pageDescription = 'Create professional business cards for real estate agents, property developers, and brokers in Oman. QR links to listings, digital sharing at viewings, and team management — free with Cardify.';
$canonicalUrl = 'https://cardify.om/industries/real-estate';
$ogType = 'website';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

// JSON-LD Schema
$extraHead = '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => 'Cardify — Business Cards for Real Estate Agents',
    'description' => 'Professional digital and printed business cards for real estate agents, property developers, and brokers in Oman.',
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
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <div class="w-16 h-16 rounded-full bg-indigo-100 flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-building text-2xl text-indigo-600"></i>
            </div>
            <h1 class="text-4xl font-bold text-gray-900 mb-3">Business Cards for Real Estate Agents in Oman</h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto mb-6">
                Build your personal brand in Oman's real estate market. Share listings, connect at viewings, and stay top-of-mind with stunning digital business cards.
            </p>
            <a href="<?php echo getBasePath(); ?>company/register.php"
               class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition-colors text-lg">
                Build Your Real Estate Brand with Cardify
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

        <!-- Why Real Estate Needs Cards -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-indigo-600 font-semibold text-sm uppercase tracking-wider">Why It Matters</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Why Real Estate Professionals in Oman Need Digital Business Cards</h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        In real estate, relationships are everything. You meet potential buyers at property viewings, network at exhibitions, and connect with developers at industry events. Every interaction is an opportunity — and your business card is the bridge between a meeting and a deal.
                    </p>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        With <?php echo $brandName; ?>, real estate agents in Oman can share their card at a viewing and the buyer instantly sees their photo, listings, WhatsApp, and office location. No paper card lost in a car, no manually typing phone numbers.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        Whether you're selling villas in Muscat Hills, commercial plots in Barka, or apartments in Al Mouj — a <?php echo $brandName; ?> card makes you look professional and keeps you accessible.
                    </p>
                </div>
                <div class="bg-gradient-to-br from-indigo-50 to-purple-50 rounded-2xl p-8">
                    <div class="space-y-4">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-house text-indigo-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">QR to Your Listings</h3>
                                <p class="text-gray-600 text-sm">Link your card to your property portfolio — buyers browse available listings instantly.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-brands fa-whatsapp text-indigo-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">One-Tap WhatsApp</h3>
                                <p class="text-gray-600 text-sm">Buyers tap and message you directly on WhatsApp — the most-used channel in Oman real estate.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-camera text-indigo-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Professional Photo</h3>
                                <p class="text-gray-600 text-sm">Your photo on your card builds trust before the first meeting.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-share-nodes text-indigo-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Easy Referral Sharing</h3>
                                <p class="text-gray-600 text-sm">Happy clients forward your digital card to friends and family — word of mouth, digitized.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feature Highlights -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-indigo-600 font-semibold text-sm uppercase tracking-wider">Features</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Built for Real Estate Professionals</h2>
            </div>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-indigo-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-id-card text-2xl text-indigo-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Personal Brand Cards</h3>
                    <p class="text-gray-600">
                        Your photo, name, title, certifications, and areas of expertise — all presented in a stunning card that reflects your professionalism.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-purple-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-map text-2xl text-purple-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Area Specialization</h3>
                    <p class="text-gray-600">
                        Highlight your coverage areas — Al Mouj, Muscat Hills, Seeb, Barka, Salalah — so clients know your local expertise immediately.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-people-group text-2xl text-blue-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Agency Team Cards</h3>
                    <p class="text-gray-600">
                        Manage cards for your entire agency from one dashboard. Consistent branding across all agents, with individual contact details and specializations.
                    </p>
                </div>
            </div>
        </div>

        <!-- Use Cases -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-indigo-600 font-semibold text-sm uppercase tracking-wider">Use Cases</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">How Real Estate Agents Use <?php echo $brandName; ?></h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-indigo-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Property Viewings</h3>
                    <p class="text-gray-600">
                        At the end of a viewing, share your digital card. The buyer saves your contact instantly and can reach you when they're ready to make an offer — no card lost in a pocket.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-purple-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Real Estate Exhibitions</h3>
                    <p class="text-gray-600">
                        At Oman Real Estate Exhibition (OREX) or Cityscape events, share unlimited cards. No running out of paper stock — just scan, tap, or share a link.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-blue-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Social Media Lead Capture</h3>
                    <p class="text-gray-600">
                        Add your <?php echo $brandName; ?> card link to your Instagram bio, Facebook page, and property listing sites. Leads save your contact with one tap.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-green-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Developer Partnerships</h3>
                    <p class="text-gray-600">
                        When meeting property developers or investors, your digital card with portfolio links demonstrates your professionalism and market presence.
                    </p>
                </div>
            </div>
        </div>

        <!-- Social Proof -->
        <div class="mb-20">
            <div class="bg-gradient-to-br from-indigo-50 to-purple-50 rounded-2xl p-8 lg:p-12">
                <div class="text-center mb-10">
                    <h2 class="text-3xl font-bold text-gray-900">Built for Real Estate Professionals in Oman</h2>
                </div>
                <div class="grid md:grid-cols-4 gap-6 text-center">
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Made in Oman</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Tap-to-share ready</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Free to start</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Local support</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CTA -->
        <div class="bg-gradient-to-br from-indigo-600 to-purple-700 rounded-2xl p-8 lg:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4">Build Your Real Estate Brand with Cardify</h2>
            <p class="text-indigo-100 mb-8 max-w-2xl mx-auto">
                Stand out in Oman's competitive real estate market. Create a professional digital card that turns every meeting into a lasting connection.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-indigo-600 font-semibold rounded-xl hover:bg-indigo-50 transition-colors">
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
                <a href="<?php echo getBasePath(); ?>blog" class="text-indigo-600 hover:text-indigo-700 font-medium text-sm">Read Our Blog</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>faq" class="text-indigo-600 hover:text-indigo-700 font-medium text-sm">FAQs</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>intro" class="text-indigo-600 hover:text-indigo-700 font-medium text-sm">How It Works</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>about" class="text-indigo-600 hover:text-indigo-700 font-medium text-sm">About Us</a>
            </div>
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-indigo-600 hover:text-indigo-700 font-medium mt-4">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
