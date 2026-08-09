<?php
/**
 * Cardify - Business Cards for Doctors & Healthcare in Oman
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Doctors & Healthcare in Oman, Cardify';
$pageDescription = 'Create professional business cards for doctors, dentists, clinics, and hospitals in Oman. QR appointment booking, multi-location support, and digital sharing, free with Cardify.';
$canonicalUrl = 'https://cardify.om/industries/healthcare';
$ogType = 'website';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;

// JSON-LD Schema
$extraHead = '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Service',
    'name' => 'Cardify, Business Cards for Doctors & Healthcare',
    'description' => 'Professional digital and printed business cards for doctors, dentists, clinics, and hospitals in Oman.',
    // r154 / llm153-2: a reference; the owner body is emitted by ui-header.
    'provider' => ['@id' => 'https://cardify.om/#organization'],
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
            <div class="w-16 h-16 rounded-full bg-teal-100 flex items-center justify-center mx-auto mb-4">
                <i class="fa-solid fa-stethoscope text-2xl text-teal-600"></i>
            </div>
            <h1 class="text-4xl font-bold text-gray-900 mb-3">Business Cards for Doctors & Healthcare in Oman</h1>
            <p class="text-gray-500 text-lg max-w-2xl mx-auto mb-6">
                Professional digital business cards for doctors, dentists, specialists, and clinics. Share your credentials, book appointments via QR, and manage multi-location practices, all from one platform.
            </p>
            <a href="<?php echo getBasePath(); ?>company/register.php"
               class="inline-flex items-center gap-2 px-8 py-4 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition-colors text-lg">
                Create Professional Medical Business Cards
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>
    </div>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

        <!-- Why Healthcare Needs Cards -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-teal-600 font-semibold text-sm uppercase tracking-wider">Why It Matters</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Why Healthcare Professionals in Oman Need Digital Business Cards</h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-12 items-center">
                <div>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        In healthcare, trust starts with professionalism. When a patient meets a new specialist or a doctor attends a medical conference, the business card is often the first tangible impression of their practice.
                    </p>
                    <p class="text-gray-600 leading-relaxed mb-4">
                        With <?php echo $brandName; ?>, healthcare professionals in Oman can share their credentials, specializations, clinic locations, and appointment booking links, all from a single digital card that never goes out of date.
                    </p>
                    <p class="text-gray-600 leading-relaxed">
                        Patients can scan a QR code in your waiting room and instantly save your contact, see your qualifications, and book their next visit. It's modern, hygienic, and efficient.
                    </p>
                </div>
                <div class="bg-gradient-to-br from-teal-50 to-cyan-50 rounded-2xl p-8">
                    <div class="space-y-4">
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-teal-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-user-doctor text-teal-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Professional Credentials</h3>
                                <p class="text-gray-600 text-sm">Display your specialization, board certifications, medical license, and years of experience.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-teal-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-calendar-check text-teal-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">QR Appointment Booking</h3>
                                <p class="text-gray-600 text-sm">Link your QR code to your booking system, patients scan and schedule in seconds.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-teal-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-location-dot text-teal-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Multi-Location Support</h3>
                                <p class="text-gray-600 text-sm">Practice at multiple clinics? Show all locations with maps and working hours on one card.</p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4">
                            <div class="w-10 h-10 rounded-lg bg-teal-100 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-hand-sparkles text-teal-600"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-900">Contactless & Hygienic</h3>
                                <p class="text-gray-600 text-sm">No physical exchange needed, share via NFC tap, QR scan, or digital link.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Feature Highlights -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-teal-600 font-semibold text-sm uppercase tracking-wider">Features</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">Built for Healthcare Professionals</h2>
            </div>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-teal-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-hospital text-2xl text-teal-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Clinic & Hospital Cards</h3>
                    <p class="text-gray-600">
                        Create cards for your entire medical team, doctors, nurses, receptionists, and admin staff, all with consistent clinic branding.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-cyan-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-graduation-cap text-2xl text-cyan-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Qualifications & Specializations</h3>
                    <p class="text-gray-600">
                        Showcase your MD, FRCS, board certifications, and sub-specializations. Patients and referring doctors see your credentials at a glance.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 text-center">
                    <div class="w-16 h-16 rounded-full bg-blue-100 flex items-center justify-center mx-auto mb-6">
                        <i class="fa-solid fa-language text-2xl text-blue-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3">Bilingual Cards</h3>
                    <p class="text-gray-600">
                        Arabic and English support out of the box. Serve Oman's diverse patient population with cards in their preferred language.
                    </p>
                </div>
            </div>
        </div>

        <!-- Use Cases -->
        <div class="mb-20">
            <div class="text-center mb-12">
                <span class="text-teal-600 font-semibold text-sm uppercase tracking-wider">Use Cases</span>
                <h2 class="text-3xl font-bold text-gray-900 mt-2">How Healthcare Providers Use <?php echo $brandName; ?></h2>
            </div>
            <div class="grid lg:grid-cols-2 gap-8">
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-teal-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Patient Referrals</h3>
                    <p class="text-gray-600">
                        When a GP refers a patient to a specialist, they share the specialist's digital card. The patient gets the doctor's name, clinic address, phone, and booking link instantly.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-cyan-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Medical Conferences</h3>
                    <p class="text-gray-600">
                        At OMSB events, health symposiums, or pharma meetups, share your card with a QR scan. No fumbling with paper cards, and your contact info is always current.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-blue-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">Waiting Room QR Displays</h3>
                    <p class="text-gray-600">
                        Place QR codes in your waiting area. Patients scan to save your contact, see your working hours, or book follow-up appointments, reducing reception workload.
                    </p>
                </div>
                <div class="bg-white rounded-xl shadow-sm p-8 border-l-4 border-green-500">
                    <h3 class="text-lg font-bold text-gray-900 mb-3">New Doctor Onboarding</h3>
                    <p class="text-gray-600">
                        When a new doctor joins your clinic, create their professional card in minutes with your clinic's branding, their photo, qualifications, and contact details.
                    </p>
                </div>
            </div>
        </div>

        <!-- Social Proof -->
        <div class="mb-20">
            <div class="bg-gradient-to-br from-teal-50 to-cyan-50 rounded-2xl p-8 lg:p-12">
                <div class="text-center mb-10">
                    <h2 class="text-3xl font-bold text-gray-900">Built for Healthcare Providers in Oman</h2>
                </div>
                <div class="grid md:grid-cols-4 gap-6 text-center">
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Made in Oman</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">QR appointment booking</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Privacy conscious</div>
                    </div>
                    <div class="bg-white rounded-xl p-6 shadow-sm">
                        <svg class="w-8 h-8 mx-auto mb-2 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                        <div class="text-gray-700 text-sm font-semibold">Local support</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CTA -->
        <div class="bg-gradient-to-br from-teal-500 to-cyan-700 rounded-2xl p-8 lg:p-12 text-center text-white">
            <h2 class="text-3xl font-bold mb-4">Create Professional Medical Business Cards</h2>
            <p class="text-teal-100 mb-8 max-w-2xl mx-auto">
                Join doctors, dentists, and clinics across Oman who use <?php echo $brandName; ?> to share their credentials and connect with patients professionally.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>company/register.php"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-teal-600 font-semibold rounded-xl hover:bg-teal-50 transition-colors">
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
                <a href="<?php echo getBasePath(); ?>blog" class="text-teal-600 hover:text-teal-700 font-medium text-sm">Read Our Blog</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>faq" class="text-teal-600 hover:text-teal-700 font-medium text-sm">FAQs</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>intro" class="text-teal-600 hover:text-teal-700 font-medium text-sm">How It Works</a>
                <span class="text-gray-300">|</span>
                <a href="<?php echo getBasePath(); ?>about" class="text-teal-600 hover:text-teal-700 font-medium text-sm">About Us</a>
            </div>
            <a href="<?php echo getBasePath(); ?>" class="inline-flex items-center gap-2 text-teal-600 hover:text-teal-700 font-medium mt-4">
                <i class="fa-solid fa-arrow-left"></i>
                Back to Home
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
