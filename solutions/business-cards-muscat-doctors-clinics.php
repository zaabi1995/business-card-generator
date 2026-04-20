<?php
/**
 * Cardify - Business Cards for Muscat Doctors and Clinics
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Business Cards for Muscat Doctors and Clinics — Cardify';
$pageDescription = 'MoH-compliant doctor business cards for Oman — licence number, specialty, clinic/hospital affiliation, direct appointment link. Bilingual, HIPAA-style private.';
$canonicalUrl = 'https://cardify.om/solutions/business-cards-muscat-doctors-clinics';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

$showNavigation = true;

$breadcrumbJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Solutions', 'item' => 'https://cardify.om/solutions'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Cards for Muscat Doctors and Clinics', 'item' => $canonicalUrl],
    ],
];
$articleJsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => 'Business Cards for Muscat Doctors and Clinics',
    'description' => $pageDescription,
    'mainEntityOfPage' => $canonicalUrl,
    'author' => ['@type' => 'Organization', 'name' => $brandName],
    'publisher' => [
        '@type' => 'Organization',
        'name' => $brandName,
        'logo' => ['@type' => 'ImageObject', 'url' => 'https://cardify.om/assets/images/logo.svg', 'creditText' => 'Cardify', 'copyrightNotice' => '© Cardify', 'license' => 'https://cardify.om/terms'],
    ],
    'inLanguage' => 'en-OM',
];
$extraHead = '<script type="application/ld+json">' . json_encode($breadcrumbJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($articleJsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <div class="bg-white pt-28 pb-12">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <nav class="text-sm text-gray-500 mb-4" aria-label="Breadcrumb">
                <a href="<?php echo getBasePath(); ?>" class="hover:text-blue-600">Home</a>
                <span class="mx-2">/</span>
                <a href="<?php echo getBasePath(); ?>solutions" class="hover:text-blue-600">Solutions</a>
                <span class="mx-2">/</span>
                <span class="text-gray-700">Doctors & Clinics</span>
            </nav>
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Business Cards for Muscat Doctors and Clinics</h1>
            <p class="text-gray-600 text-lg max-w-3xl">
                From a consultant cardiologist at Royal Hospital to a family dentist in Al Khoud, your card needs to carry MoH licence, specialty, hospital affiliation, bilingual credentials — and a way for patients to book you without calling reception.
            </p>
        </div>
    </div>

    <article class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-12 prose prose-lg">

        <h2>Healthcare in Oman — A Regulated, Bilingual Profession</h2>
        <p>
            Medical practice in Oman is tightly regulated by the Ministry of Health (MoH) and, for Muscat governorate, supervised through Oman Medical Specialty Board (OMSB) for specialist registration. Every practising doctor holds an MoH licence number, every clinic and hospital holds an MoH facility licence, and every prescription carries the licence details. Patients — increasingly educated, insurance-literate, and willing to compare consultants — check credentials before they book. Your card needs to show: full name in Arabic and English, medical degree (MBBS, MD, MBChB), specialty (internal medicine, obstetrics, paediatrics, orthopaedics, dermatology, psychiatry, etc.), OMSB/fellowship credentials (FRCS, MRCP, American Board, RCSEd, etc.), MoH licence number, and current hospital/clinic affiliation with its MoH facility number.
        </p>
        <p>
            Cardify's healthcare profile template has all of these as structured fields. They render on the public card, download into vCards, and appear correctly in Arabic — critical because Omani patients searching their phone for "الدكتور" (doctor) followed by specialty find you cleanly.
        </p>

        <h2>The Muscat Healthcare Landscape</h2>
        <p>
            The Muscat medical ecosystem is diverse: public-sector giants like Sultan Qaboos University Hospital (SQUH) in Al Khoud, the Royal Hospital in Bawshar, and the Al Nahdha and Khoula Hospitals; leading private hospitals including Badr Al Samaa (multiple branches across Ruwi, Al Khuwair, Mabelah, Sohar, Salalah), Al Raffah Hospital, Muscat Private Hospital, Starcare, KIMSHealth, NMC Royal Hospital, Apollo; and hundreds of specialty clinics across Al Khuwair (Zakher Mall, Panorama Mall), Al Ghubra, Qurum, Al Mouj, Mabelah, and Al Khoud. Doctors move between these institutions — a consultant radiologist might have morning slots at a private hospital, afternoon sessions at a visiting-consultant clinic, and teach weekly at SQUH. Your business card needs to show which facility the patient can book you at, on which days, and at what times.
        </p>
        <p>
            Cardify's "visiting consultant" template lets you list multiple facility affiliations with day/time availability, each with a direct booking link. A patient meeting you at a Badr Al Samaa dermatology slot can scan your card, see that you also do consultations at a Qurum specialty clinic on Wednesday evenings, and book directly into the right facility's system.
        </p>

        <p><strong>Create digital business cards for your team — free to start with <?php echo $brandName; ?>.</strong> Perfect for a solo GP practice or a 50-doctor hospital rollout. <a href="<?php echo getBasePath(); ?>company/register.php" class="text-blue-600 font-semibold">Start free &rarr;</a></p>

        <h2>Patient Appointment Integration</h2>
        <p>
            The number-one patient complaint in Oman private healthcare is "I couldn't get through to reception". A Cardify digital card solves this with a "Book Appointment" action that integrates with your clinic's appointment system — whether that's TrakCare, Cerner (at SQUH and the MoH public hospitals), or a smaller platform like Clinic Master used by many Omani private clinics. For clinics without a formal booking system, Cardify generates a simple scheduling page tied to your card: patients pick a time from your available slots, enter their name and phone number, and receive a confirmation SMS via Oman's SMSC gateway. No reception phone tag required.
        </p>
        <p>
            Your card can also include a WhatsApp Business link pre-filled with "Hello Dr [Name], I'd like to book an appointment for [reason]". For Omani patients who strongly prefer WhatsApp over calling (especially women patients consulting female doctors for OB-GYN, dermatology, or mental health concerns), this lowers the friction enormously. We see booking rates 2–3x higher on WhatsApp than on traditional phone-reception for these specialties.
        </p>

        <h2>Patient Data Privacy — A Serious Matter</h2>
        <p>
            Oman's Personal Data Protection Law (PDPL) (Royal Decree 6/2022) imposes strict data handling obligations on healthcare providers. Cardify is designed with this in mind: we store only the minimum necessary contact data, we do not collect or store any patient health information (PHI), card scans are counted but not linked to individuals unless explicit consent is given, and our hosting is in-region to keep data residency issues simple. For hospital-issued doctor cards, we can operate in "facility-managed" mode where the hospital IT/admin team controls profile content and revocation, ensuring the doctor's card stays compliant with the facility's policies even when the doctor travels or leaves.
        </p>

        <h2>Referrals, Conferences & Pharma Reps</h2>
        <p>
            Doctors in Oman spend significant time in cross-specialty referrals, CME events at OCEC (Oman Convention & Exhibition Centre) and the Muscat Hills medical forums, and meetings with pharmaceutical and device representatives. A Cardify card makes all three smoother: referral letters include your digital card QR at the bottom so the receiving doctor has you in their phone instantly; at the Oman Urology Congress, Oman Cardiac Society meeting, or SQUH Medical Grand Rounds, you tap-exchange with peers; and for pharma/device reps, your card shows exactly which days you accept reps and how to book a slot, protecting your clinic time.
        </p>

        <div class="not-prose bg-blue-50 border-l-4 border-blue-500 rounded-lg p-6 my-8">
            <p class="text-blue-900 font-semibold mb-2">A card your patients can trust</p>
            <p class="text-blue-800 mb-4">Create digital business cards for your team — free to start with <?php echo $brandName; ?>. MoH licence, specialty, appointment booking, Arabic/English — all in one.</p>
            <a href="<?php echo getBasePath(); ?>company/register.php" class="inline-flex items-center gap-2 px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition-colors">
                Start Your Clinic's Account
                <i class="fa-solid fa-arrow-right"></i>
            </a>
        </div>

        <p class="text-sm text-gray-500">
            <a href="<?php echo getBasePath(); ?>solutions" class="text-blue-600 hover:underline">&larr; Back to all Oman solutions</a>
        </p>
    </article>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
