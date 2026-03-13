<?php
/**
 * Cardify - About Us
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'About Cardify — Oman\'s Business Card Platform';
$pageDescription = 'Cardify helps Omani businesses create stunning digital and printed business cards. Serving 500+ companies across the Sultanate of Oman.';
$canonicalUrl = 'https://cardify.om/about';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Hero Section -->
    <div class="bg-gradient-to-br from-blue-600 via-blue-700 to-blue-800 text-white py-20 pt-32">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto">
                <h1 class="text-4xl md:text-5xl font-bold mb-6">About <?php echo $brandName; ?></h1>
                <p class="text-xl text-blue-100 leading-relaxed">
                    Transforming the way professionals connect through innovative digital business card solutions.
                </p>
            </div>
        </div>
    </div>

    <!-- Our Story -->
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
        <div class="grid lg:grid-cols-2 gap-12 items-center mb-20">
            <div>
                <span class="text-blue-600 font-semibold text-sm uppercase tracking-wider">Our Story</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2 mb-6">Building the Future of Professional Networking</h2>
                <p class="text-gray-600 leading-relaxed mb-4">
                    <?php echo $brandName; ?> was born from a simple idea: business cards should evolve with the digital age. 
                    Traditional paper cards get lost, become outdated, and contribute to unnecessary waste.
                </p>
                <p class="text-gray-600 leading-relaxed mb-4">
                    We set out to create a platform that makes sharing professional information seamless, sustainable, 
                    and always up-to-date. What started as a solution for our own team has grown into a comprehensive 
                    platform used by thousands of professionals worldwide.
                </p>
                <p class="text-gray-600 leading-relaxed">
                    Based in Muscat, Oman, we combine local business understanding with cutting-edge 
                    technology to deliver a product that truly serves modern professionals.
                </p>
            </div>
            <div class="relative">
                <div class="bg-gradient-to-br from-blue-100 to-blue-50 rounded-2xl p-8">
                    <div class="grid grid-cols-2 gap-6 text-center">
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <div class="text-3xl font-bold text-blue-600 mb-2">10K+</div>
                            <div class="text-gray-500 text-sm">Active Users</div>
                        </div>
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <div class="text-3xl font-bold text-blue-600 mb-2">500+</div>
                            <div class="text-gray-500 text-sm">Companies</div>
                        </div>
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <div class="text-3xl font-bold text-blue-600 mb-2">50K+</div>
                            <div class="text-gray-500 text-sm">Cards Created</div>
                        </div>
                        <div class="bg-white rounded-xl p-6 shadow-sm">
                            <div class="text-3xl font-bold text-blue-600 mb-2">99.9%</div>
                            <div class="text-gray-500 text-sm">Uptime</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Connect Section -->
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 mb-16">
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <span class="text-blue-600 font-semibold text-sm uppercase tracking-wider">Connect With Us</span>
                    <h2 class="text-3xl font-bold text-gray-900 mt-2 mb-6">Follow Our Journey</h2>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        Stay updated with the latest features, tips, and news from <?php echo $brandName; ?>. 
                        We're constantly improving our platform to help professionals connect more effectively.
                    </p>
                    <p class="text-gray-600 leading-relaxed mb-6">
                        Join our community and be part of the digital business card revolution.
                    </p>
                    <div class="flex flex-wrap gap-4">
                        <a href="https://instagram.com/cardifyom" target="_blank" rel="noopener noreferrer" 
                           class="inline-flex items-center gap-2 px-4 py-2 bg-pink-600 text-white rounded-lg hover:bg-pink-700 transition-colors">
                            <i class="fa-brands fa-instagram"></i>
                            Follow on Instagram
                        </a>
                    </div>
                </div>
                <div class="bg-gray-50 rounded-xl p-8">
                    <div class="flex items-center gap-4 mb-6">
                        <div class="w-16 h-16 rounded-xl bg-gradient-to-br from-blue-600 to-blue-700 flex items-center justify-center">
                            <img src="<?php echo getBasePath(); ?>assets/images/logo.svg" alt="<?php echo $brandName; ?>" class="h-10 w-auto">
                        </div>
                        <div>
                            <h3 class="font-bold text-xl text-gray-900"><?php echo $brandName; ?></h3>
                            <p class="text-gray-500">Digital Business Cards</p>
                        </div>
                    </div>
                    <ul class="space-y-3 text-gray-600">
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-location-dot text-blue-600"></i>
                            <span>Based in Muscat, Oman</span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-envelope text-blue-600"></i>
                            <a href="/contact.php" class="text-blue-600 hover:underline">Contact us</a>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-globe text-blue-600"></i>
                            <span>cardify.om</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Our Values -->
        <div class="mb-16">
            <div class="text-center mb-12">
                <span class="text-blue-600 font-semibold text-sm uppercase tracking-wider">What We Stand For</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Our Values</h2>
            </div>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-lightbulb text-2xl text-blue-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Innovation</h3>
                    <p class="text-gray-600">
                        We constantly push boundaries to create solutions that anticipate and meet the evolving needs of professionals.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-leaf text-2xl text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Sustainability</h3>
                    <p class="text-gray-600">
                        By eliminating paper waste, we help businesses reduce their environmental footprint one card at a time.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-purple-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-handshake text-2xl text-purple-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Trust</h3>
                    <p class="text-gray-600">
                        We build lasting relationships with our customers through transparency, reliability, and exceptional service.
                    </p>
                </div>
            </div>
        </div>

        <!-- CTA -->
        <div class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl p-8 lg:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4">Ready to Transform Your Networking?</h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto">
                Join thousands of professionals who have already made the switch to digital business cards.
            </p>
            <a href="<?php echo getBasePath(); ?>company/register.php" 
               class="inline-flex items-center gap-2 px-8 py-4 bg-white text-blue-600 font-semibold rounded-xl hover:bg-blue-50 transition-colors">
                Get Started Free
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <!-- Back to Home -->
        <div class="mt-8 text-center">
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-blue-600 hover:text-blue-700 font-medium">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
