<?php
/**
 * Cardify - Terms of Service
 * Part of BHD Group (Bin Haider Darwish S.P.C.)
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Terms of Service — Cardify';
$pageDescription = 'Terms and conditions for using Cardify\'s business card platform. Read our terms of service for creating and managing business cards in Oman.';
$canonicalUrl = 'https://cardify.om/terms';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-gradient-to-br from-blue-600 to-blue-800 text-white py-16 pt-28">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-4xl font-bold mb-4">Terms of Service</h1>
            <p class="text-blue-100">Last updated: <?php echo date('F j, Y'); ?></p>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 prose prose-lg max-w-none">
            
            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Agreement to Terms</h2>
                <p class="text-gray-600 leading-relaxed">
                    By accessing or using <?php echo $brandName; ?>, a service provided by BHD Group (Bin Haider Darwish S.P.C. - C.R. No. 1334733), 
                    you agree to be bound by these Terms of Service. If you do not agree to these terms, please do not use our service.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Description of Service</h2>
                <p class="text-gray-600 leading-relaxed">
                    <?php echo $brandName; ?> provides a digital business card platform that allows users to create, manage, and share 
                    professional digital business cards. Our services include card design tools, sharing capabilities, analytics, 
                    and team management features.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">User Accounts</h2>
                <ul class="list-disc list-inside text-gray-600 space-y-2 ml-4">
                    <li>You must provide accurate and complete registration information</li>
                    <li>You are responsible for maintaining the security of your account</li>
                    <li>You must notify us immediately of any unauthorized use</li>
                    <li>You may not use another person's account without permission</li>
                    <li>We reserve the right to suspend or terminate accounts that violate these terms</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Acceptable Use</h2>
                <p class="text-gray-600 leading-relaxed mb-4">You agree not to:</p>
                <ul class="list-disc list-inside text-gray-600 space-y-2 ml-4">
                    <li>Use the service for any unlawful purpose</li>
                    <li>Upload content that infringes on intellectual property rights</li>
                    <li>Transmit malware, viruses, or harmful code</li>
                    <li>Attempt to gain unauthorized access to our systems</li>
                    <li>Interfere with or disrupt the service</li>
                    <li>Impersonate any person or entity</li>
                    <li>Use the service to send spam or unsolicited communications</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Intellectual Property</h2>
                <p class="text-gray-600 leading-relaxed">
                    The <?php echo $brandName; ?> platform, including its design, features, and content, is owned by BHD Group and 
                    protected by intellectual property laws. You retain ownership of the content you create but grant us a license 
                    to use it for providing our services.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Payment Terms</h2>
                <ul class="list-disc list-inside text-gray-600 space-y-2 ml-4">
                    <li>Subscription fees are billed in advance on a monthly or annual basis</li>
                    <li>All fees are non-refundable unless otherwise stated</li>
                    <li>We reserve the right to change pricing with 30 days notice</li>
                    <li>Failure to pay may result in service suspension</li>
                </ul>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Limitation of Liability</h2>
                <p class="text-gray-600 leading-relaxed">
                    To the maximum extent permitted by law, <?php echo $brandName; ?> and BHD Group shall not be liable for any indirect, 
                    incidental, special, consequential, or punitive damages, including loss of profits, data, or business opportunities, 
                    arising from your use of the service.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Disclaimer of Warranties</h2>
                <p class="text-gray-600 leading-relaxed">
                    The service is provided "as is" and "as available" without warranties of any kind, either express or implied, 
                    including but not limited to implied warranties of merchantability, fitness for a particular purpose, and non-infringement.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Termination</h2>
                <p class="text-gray-600 leading-relaxed">
                    We may terminate or suspend your account immediately, without prior notice or liability, for any reason, 
                    including breach of these Terms. Upon termination, your right to use the service will immediately cease.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Governing Law</h2>
                <p class="text-gray-600 leading-relaxed">
                    These Terms shall be governed by and construed in accordance with the laws of the Sultanate of Oman, 
                    without regard to its conflict of law provisions.
                </p>
            </section>

            <section class="mb-10">
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Contact Information</h2>
                <p class="text-gray-600 leading-relaxed">
                    For questions about these Terms, please contact us:
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
