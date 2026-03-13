<?php
/**
 * Cardify - Cookie Policy
 * Part of BHD Group (Bin Haider Darwish S.P.C.)
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Cookie Policy — Cardify';
$pageDescription = 'Cardify cookie policy. Learn how we use cookies to improve your experience on our business card platform.';
$canonicalUrl = 'https://cardify.om/cookies';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-gradient-to-br from-blue-600 to-blue-800 text-white py-16 pt-28">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-4xl font-bold mb-4">Cookie Policy</h1>
            <p class="text-blue-100">Last updated: <?php echo date('F j, Y'); ?></p>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 prose prose-lg max-w-none">
            
            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">What Are Cookies?</h2>
                <p class="text-gray-600 leading-relaxed">
                    Cookies are small text files that are stored on your device when you visit a website. They help websites 
                    remember your preferences, keep you logged in, and provide a better user experience. <?php echo $brandName; ?>, 
                    operated by BHD Group (Bin Haider Darwish S.P.C. - C.R. No. 1334733), uses cookies to improve your experience.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Types of Cookies We Use</h2>
                
                <div class="mt-6 space-y-6">
                    <div class="p-4 bg-green-50 rounded-lg border-l-4 border-green-500">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Essential Cookies</h3>
                        <p class="text-gray-600">
                            Required for the website to function properly. These cookies enable core functionality like security, 
                            network management, and account access. You cannot opt out of these cookies.
                        </p>
                    </div>

                    <div class="p-4 bg-blue-50 rounded-lg border-l-4 border-blue-500">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Performance Cookies</h3>
                        <p class="text-gray-600">
                            Help us understand how visitors interact with our website by collecting and reporting information 
                            anonymously. This helps us improve how our website works.
                        </p>
                    </div>

                    <div class="p-4 bg-purple-50 rounded-lg border-l-4 border-purple-500">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Functionality Cookies</h3>
                        <p class="text-gray-600">
                            Allow the website to remember choices you make (such as your language preference or region) and 
                            provide enhanced, more personalized features.
                        </p>
                    </div>

                    <div class="p-4 bg-orange-50 rounded-lg border-l-4 border-orange-500">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Analytics Cookies</h3>
                        <p class="text-gray-600">
                            Used to collect information about how you use our website. We use this information to compile reports 
                            and improve our services. Analytics cookies collect information anonymously.
                        </p>
                    </div>
                </div>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Cookies We Use</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cookie Name</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Purpose</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Duration</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 text-sm text-gray-600">
                            <tr>
                                <td class="px-4 py-3 font-mono">session_id</td>
                                <td class="px-4 py-3">Maintains your login session</td>
                                <td class="px-4 py-3">Session</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-mono">csrf_token</td>
                                <td class="px-4 py-3">Security - prevents cross-site attacks</td>
                                <td class="px-4 py-3">Session</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-mono">preferences</td>
                                <td class="px-4 py-3">Stores your site preferences</td>
                                <td class="px-4 py-3">1 year</td>
                            </tr>
                            <tr>
                                <td class="px-4 py-3 font-mono">_ga</td>
                                <td class="px-4 py-3">Google Analytics - tracks visitors</td>
                                <td class="px-4 py-3">2 years</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Managing Cookies</h2>
                <p class="text-gray-600 leading-relaxed mb-4">
                    Most web browsers allow you to control cookies through their settings. Here's how to manage cookies in popular browsers:
                </p>
                <ul class="list-disc list-inside text-gray-600 space-y-2 ml-4">
                    <li><strong>Chrome:</strong> Settings → Privacy and security → Cookies and other site data</li>
                    <li><strong>Firefox:</strong> Options → Privacy & Security → Cookies and Site Data</li>
                    <li><strong>Safari:</strong> Preferences → Privacy → Manage Website Data</li>
                    <li><strong>Edge:</strong> Settings → Cookies and site permissions → Manage and delete cookies</li>
                </ul>
                <p class="text-gray-600 leading-relaxed mt-4">
                    Please note that disabling cookies may affect the functionality of our website.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Third-Party Cookies</h2>
                <p class="text-gray-600 leading-relaxed">
                    Some cookies are placed by third-party services that appear on our pages. We do not control the dissemination 
                    of these cookies. You can check the third party websites for more information about their cookies and how to manage them.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Contact Us</h2>
                <p class="text-gray-600 leading-relaxed">
                    If you have questions about our Cookie Policy:
                </p>
                <div class="mt-4 p-4 bg-gray-50 rounded-lg">
                    <p class="text-gray-700"><strong>BHD Group</strong></p>
                    <p class="text-gray-600">Bin Haider Darwish S.P.C.</p>
                    <p class="text-gray-600">C.R. No. 1334733</p>
                    <p class="text-gray-600 mt-2"><a href="/contact.php" class="text-blue-600 hover:underline">Contact us</a></p>
                </div>
            </section>

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
