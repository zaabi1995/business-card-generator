<?php
/**
 * Cardify - Privacy Policy
 * Part of BHD Group (Bin Haider Darwish S.P.C.)
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Privacy Policy — Cardify';
$pageDescription = 'How Cardify protects your data. Privacy policy for our business card creation and management platform in Oman.';
$canonicalUrl = 'https://cardify.om/privacy';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-bold text-gray-900 mb-3">Privacy Policy</h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto">Last updated: <?php echo date('F j, Y'); ?></p>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 prose prose-lg max-w-none">
            
            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Introduction</h2>
                <p class="text-gray-600 leading-relaxed">
                    <?php echo $brandName; ?> ("we", "our", or "us"), a product of BHD Group (Bin Haider Darwish S.P.C. - C.R. No. 1334733), 
                    is committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard 
                    your information when you use our digital business card platform.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Information We Collect</h2>
                <h3 class="text-xl font-semibold text-gray-800 mt-6 mb-3">Personal Information</h3>
                <p class="text-gray-600 leading-relaxed mb-4">We may collect personally identifiable information that you voluntarily provide, including:</p>
                <ul class="list-disc list-inside text-gray-600 space-y-2 ml-4">
                    <li>Name, email address, and phone number</li>
                    <li>Company name and job title</li>
                    <li>Profile photos and business card designs</li>
                    <li>Payment and billing information</li>
                    <li>Any other information you choose to provide</li>
                </ul>

                <h3 class="text-xl font-semibold text-gray-800 mt-6 mb-3">Automatically Collected Information</h3>
                <p class="text-gray-600 leading-relaxed mb-4">When you access our platform, we automatically collect:</p>
                <ul class="list-disc list-inside text-gray-600 space-y-2 ml-4">
                    <li>Device information (browser type, operating system)</li>
                    <li>IP address and location data</li>
                    <li>Usage data and analytics</li>
                    <li>Cookies and similar tracking technologies</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">How We Use Your Information</h2>
                <p class="text-gray-600 leading-relaxed mb-4">We use the collected information to:</p>
                <ul class="list-disc list-inside text-gray-600 space-y-2 ml-4">
                    <li>Create and manage your digital business cards</li>
                    <li>Process transactions and send related information</li>
                    <li>Send administrative information and updates</li>
                    <li>Respond to inquiries and offer support</li>
                    <li>Improve our platform and user experience</li>
                    <li>Comply with legal obligations</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Data Sharing and Disclosure</h2>
                <p class="text-gray-600 leading-relaxed mb-4">We may share your information with:</p>
                <ul class="list-disc list-inside text-gray-600 space-y-2 ml-4">
                    <li><strong>Service Providers:</strong> Third parties that help us operate our platform</li>
                    <li><strong>Business Partners:</strong> With your consent, for joint marketing initiatives</li>
                    <li><strong>Legal Requirements:</strong> When required by law or to protect our rights</li>
                    <li><strong>Business Transfers:</strong> In connection with mergers or acquisitions</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Data Security</h2>
                <p class="text-gray-600 leading-relaxed">
                    We implement appropriate technical and organizational security measures to protect your personal information. 
                    However, no method of transmission over the Internet is 100% secure. We strive to use commercially acceptable 
                    means to protect your data but cannot guarantee absolute security.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Your Rights</h2>
                <p class="text-gray-600 leading-relaxed mb-4">You have the right to:</p>
                <ul class="list-disc list-inside text-gray-600 space-y-2 ml-4">
                    <li>Access your personal information</li>
                    <li>Correct inaccurate data</li>
                    <li>Request deletion of your data</li>
                    <li>Object to processing of your data</li>
                    <li>Data portability</li>
                    <li>Withdraw consent at any time</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Contact Us</h2>
                <p class="text-gray-600 leading-relaxed">
                    If you have questions about this Privacy Policy, please contact us:
                </p>
                <div class="mt-4 p-4 bg-gray-50 rounded-lg">
                    <p class="text-gray-700"><strong>BHD Group</strong></p>
                    <p class="text-gray-600">Bin Haider Darwish S.P.C.</p>
                    <p class="text-gray-600">C.R. No. 1334733</p>
                    <p class="text-gray-600 mt-2">Email: <a href="mailto:privacy@cardify.om" class="text-blue-600 hover:text-blue-700 font-medium inline-flex items-center gap-2"><i class="fa-regular fa-envelope"></i> privacy@cardify.om</a></p>
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
