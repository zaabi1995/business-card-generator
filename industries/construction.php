<?php
/**
 * Cardify - Business Cards for Construction & Engineering in Oman
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/JsonLd.php';

$pageTitle = 'Business Cards for Construction & Engineering in Oman, Cardify';
$pageDescription = 'Professional business cards for contractors, engineers, and construction companies in Oman. Manage team cards, share project details, and network on-site, free with Cardify.';
$canonicalUrl = 'https://cardify.om/industries/construction';
$ogType = 'website';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

// JSON-LD Schema
$extraHead = '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => 'Cardify, Business Cards for Construction & Engineering',
    'description' => 'Professional digital and printed business cards for construction companies, contractors, and engineers in Oman.',
    // r154 / llm153-2: a reference; the owner body is emitted by ui-header.
    'provider' => ['@id' => 'https://cardify.om/#organization'],
    'areaServed' => [
        '@type' => 'Country',
        'name' => 'Oman',
    ],
    'serviceType' => 'Digital Business Card Platform',
], JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Hero Section -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <div class="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-helmet-safety text-2xl text-amber-600"></i>
            </div>
            <h1 class="text-4xl font-bold text-gray-900 mb-3">Business Cards for Construction & Engineering in Oman</h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto mb-6">
                Equip your entire team, from site managers to project engineers, with professional digital business cards they can share on-site, at tenders, and at industry events.
            </p>
            <a href="<?php echo getBasePath(); ?>company/register.php"
               class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition-colors text-lg">
                Get Your Team's Cards in Minutes
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

        <!-- Why Construction Needs Cards -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-amber-600 font-semibold text-sm uppercase tracking-wider">Why It Matters</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Why Construction Companies in Oman Need Digital Business Cards</h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        Oman's construction sector is booming, from mega infrastructure projects to residential developments. With hundreds of contractors, subcontractors, and consultants working together, exchanging contact details needs to be fast and reliable.
                    </p>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        Paper cards get damaged on construction sites, lost in truck cabins, or buried in drawers. With <?php echo $brandName; ?>, your team's cards are always accessible, always up-to-date, and always professional.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        Whether you're bidding for a government tender, meeting a new subcontractor, or networking at Oman Construction Week, a digital card from <?php echo $brandName; ?> makes the right impression.
                    </p>
                </div>
                <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-2xl p-8">
                    <div class="space-y-4">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-hard-hat text-amber-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Site-Ready</h3>
                                <p class="text-gray-600 text-sm">No paper to damage, your card lives on your phone, ready to share via NFC tap, QR scan, or WhatsApp.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-users-gear text-amber-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Large Crew Management</h3>
                                <p class="text-gray-600 text-sm">Generate cards for 50+ employees in bulk, upload a spreadsheet and you're done.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-building text-amber-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Project-Specific Details</h3>
                                <p class="text-gray-600 text-sm">Add project references, certifications, and specializations to each team member's card.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-shield-halved text-amber-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">HSE Compliance</h3>
                                <p class="text-gray-600 text-sm">Include safety certifications, emergency contacts, and HSE officer details on cards.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feature Highlights -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-amber-600 font-semibold text-sm uppercase tracking-wider">Features</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Built for Construction Teams</h2>
            </div>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-file-import text-2xl text-amber-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Bulk Card Generation</h3>
                    <p class="text-gray-600">
                        Upload your employee roster and generate branded cards for your entire team in one click. Perfect for companies with 50, 100, or 500+ staff.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-yellow-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-id-badge text-2xl text-yellow-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Role-Based Cards</h3>
                    <p class="text-gray-600">
                        Different cards for different roles, project managers show project portfolios, safety officers show certifications, and sales teams show completed works.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-orange-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-print text-2xl text-orange-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Print + Digital</h3>
                    <p class="text-gray-600">
                        Need physical cards for tenders and client meetings? Order high-quality prints directly from <?php echo $brandName; ?> with QR codes linking to your digital version.
                    </p>
                </div>
            </div>
        </div>

        <!-- Use Cases -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-amber-600 font-semibold text-sm uppercase tracking-wider">Use Cases</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">How Construction Companies Use <?php echo $brandName; ?></h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-amber-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Government Tenders & Proposals</h3>
                    <p class="text-gray-600">
                        Include your digital card link in tender submissions. Procurement officers can instantly access your team's credentials, certifications, and project history.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-yellow-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">On-Site Networking</h3>
                    <p class="text-gray-600">
                        When subcontractors, inspectors, or consultants visit your site, share cards instantly via NFC or QR, no need to walk back to the office for paper cards.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-orange-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Industry Events & Exhibitions</h3>
                    <p class="text-gray-600">
                        At Oman Construction Week, BIG 5, or local trade shows, your sales team can share unlimited digital cards, no running out of printed stock.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-green-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">New Employee Onboarding</h3>
                    <p class="text-gray-600">
                        When new engineers or foremen join, generate their branded card in under a minute. They're ready to represent your company from day one.
                    </p>
                </div>
            </div>
        </div>

        <!-- Social Proof -->
        <div class="mb-20">
            <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-2xl p-8 lg:p-12">
                <div class="text-center mb-10">
                    <h2 class="text-3xl font-bold text-gray-900">Built for Construction Companies Across Oman</h2>
                </div>
                <div class="grid md:grid-cols-4 gap-6 text-center">
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Made in Oman</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Bulk team generation</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Tap-to-share ready</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Local support</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CTA -->
        <div class="bg-gradient-to-br from-yellow-600 to-orange-700 rounded-2xl p-8 lg:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4">Get Your Team's Cards in Minutes</h2>
            <p class="text-yellow-100 mb-8 max-w-2xl mx-auto">
                From a 5-person contracting firm to a 500-employee construction company, <?php echo $brandName; ?> scales with your team.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-amber-700 font-semibold rounded-xl hover:bg-yellow-50 transition-colors">
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
                <a href="<?php echo getBasePath(); ?>blog" class="text-amber-600 hover:text-amber-700 font-medium text-sm">Read Our Blog</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>faq" class="text-amber-600 hover:text-amber-700 font-medium text-sm">FAQs</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>intro" class="text-amber-600 hover:text-amber-700 font-medium text-sm">How It Works</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>about" class="text-amber-600 hover:text-amber-700 font-medium text-sm">About Us</a>
            </div>
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-amber-600 hover:text-amber-700 font-medium mt-4">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
