<?php
/**
 * Cardify - Security
 * Part of BHD Group (Bin Haider Darwish S.P.C.)
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Security — Cardify';
$pageDescription = 'Learn how Cardify keeps your business card data safe with enterprise-grade security measures and data protection practices.';
$canonicalUrl = 'https://cardify.om/security';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-gradient-to-br from-blue-600 to-blue-800 text-white py-16 pt-28">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
            <h1 class="text-4xl font-bold mb-4">Security</h1>
            <p class="text-blue-100">How we protect your data and keep your information safe</p>
        </div>
    </div>

    <!-- Content -->
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        
        <!-- Security Overview -->
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 mb-8">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-14 h-14 rounded-xl bg-green-100 flex items-center justify-center">
                    <i class="fa-solid fa-shield-halved text-2xl text-green-600"></i>
                </div>
                <div>
                    <h2 class="text-2xl font-bold text-gray-900">Your Security is Our Priority</h2>
                    <p class="text-gray-500">Enterprise-grade protection for your data</p>
                </div>
            </div>
            <p class="text-gray-600 leading-relaxed">
                At <?php echo $brandName; ?>, operated by BHD Group (Bin Haider Darwish S.P.C. - C.R. No. 1334733), 
                we take the security of your data seriously. We implement industry-leading security measures to ensure 
                your information is protected at all times.
            </p>
        </div>

        <!-- Security Features Grid -->
        <div class="grid md:grid-cols-2 gap-6 mb-8">
            
            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-lock text-xl text-blue-600"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Data Encryption</h3>
                <p class="text-gray-600 text-sm">
                    All data is encrypted in transit using TLS 1.3 and at rest using AES-256 encryption. 
                    Your sensitive information is never stored in plain text.
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="w-12 h-12 rounded-lg bg-purple-100 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-key text-xl text-purple-600"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Secure Authentication</h3>
                <p class="text-gray-600 text-sm">
                    We use secure password hashing (bcrypt) and support two-factor authentication (2FA) 
                    to protect your account from unauthorized access.
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-server text-xl text-green-600"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Secure Infrastructure</h3>
                <p class="text-gray-600 text-sm">
                    Our servers are hosted in secure data centers with 24/7 monitoring, 
                    redundant power, and physical security measures.
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="w-12 h-12 rounded-lg bg-orange-100 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-rotate text-xl text-orange-600"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Regular Backups</h3>
                <p class="text-gray-600 text-sm">
                    Your data is automatically backed up daily with point-in-time recovery options. 
                    Backups are encrypted and stored in geographically separate locations.
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="w-12 h-12 rounded-lg bg-red-100 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-bug-slash text-xl text-red-600"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Vulnerability Management</h3>
                <p class="text-gray-600 text-sm">
                    We conduct regular security audits, penetration testing, and vulnerability assessments 
                    to identify and address potential security issues proactively.
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-sm p-6">
                <div class="w-12 h-12 rounded-lg bg-cyan-100 flex items-center justify-center mb-4">
                    <i class="fa-solid fa-eye text-xl text-cyan-600"></i>
                </div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Activity Monitoring</h3>
                <p class="text-gray-600 text-sm">
                    We monitor all system activity for suspicious behavior and maintain detailed audit logs 
                    for security analysis and compliance.
                </p>
            </div>

        </div>

        <!-- Security Practices -->
        <div class="bg-white rounded-2xl shadow-sm p-8 lg:p-12 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Our Security Practices</h2>
            
            <div class="space-y-6">
                <div class="flex items-start gap-4">
                    <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0 mt-1">
                        <i class="fa-solid fa-check text-green-600 text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-1">Secure Development Lifecycle</h3>
                        <p class="text-gray-600 text-sm">Security is integrated into every stage of our development process, from design to deployment.</p>
                    </div>
                </div>

                <div class="flex items-start gap-4">
                    <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0 mt-1">
                        <i class="fa-solid fa-check text-green-600 text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-1">Employee Security Training</h3>
                        <p class="text-gray-600 text-sm">All team members undergo regular security awareness training and follow strict access controls.</p>
                    </div>
                </div>

                <div class="flex items-start gap-4">
                    <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0 mt-1">
                        <i class="fa-solid fa-check text-green-600 text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-1">Incident Response Plan</h3>
                        <p class="text-gray-600 text-sm">We have a comprehensive incident response plan to quickly address any security events.</p>
                    </div>
                </div>

                <div class="flex items-start gap-4">
                    <div class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0 mt-1">
                        <i class="fa-solid fa-check text-green-600 text-sm"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900 mb-1">Third-Party Security</h3>
                        <p class="text-gray-600 text-sm">We carefully vet all third-party services and require them to meet our security standards.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Security Issue -->
        <div class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl shadow-sm p-8 lg:p-12 text-white">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center">
                    <i class="fa-solid fa-bug text-xl"></i>
                </div>
                <h2 class="text-2xl font-bold">Report a Security Issue</h2>
            </div>
            <p class="text-blue-100 mb-6">
                If you believe you've found a security vulnerability in our service, we encourage you to report it responsibly. 
                We appreciate your help in keeping <?php echo $brandName; ?> safe for everyone.
            </p>
            <div class="bg-white/10 rounded-lg p-4">
                <p class="font-semibold mb-2">Contact our Security Team:</p>
                <p class="text-blue-100">Email: security@cardify.om</p>
                <p class="text-blue-200 text-sm mt-2">We aim to respond to all security reports within 24 hours.</p>
            </div>
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
