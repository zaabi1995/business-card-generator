<?php
/**
 * Cardify - Frequently Asked Questions
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'FAQ — Cardify Business Cards in Oman';
$pageDescription = 'Frequently asked questions about creating, designing, and ordering digital and printed business cards with Cardify in Oman.';
$canonicalUrl = 'https://cardify.om/faq';
$brandName = defined('SITE_NAME') ? SITE_NAME : 'Cardify';

// Enable dynamic navigation
$showNavigation = true;
$navLinks = [
    ['href' => getBasePath() . '#features', 'label' => 'Features'],
    ['href' => getBasePath() . '#pricing', 'label' => 'Pricing'],
    ['href' => getBasePath() . 'about.php', 'label' => 'About'],
    ['href' => getBasePath() . 'contact.php', 'label' => 'Contact'],
];

// FAQ data organized by category
$faqCategories = [
    'Getting Started' => [
        [
            'q' => 'What is Cardify?',
            'a' => 'Cardify is Oman\'s leading digital and printed business card platform. It allows professionals and companies to design, create, and share stunning business cards — both digitally and in print — all from one easy-to-use dashboard.'
        ],
        [
            'q' => 'How do I sign up for Cardify?',
            'a' => 'Signing up is simple. Visit our intro page, enter your company details, and create your account in under 2 minutes. No credit card is required to get started — you can explore the platform and create your first card for free.'
        ],
        [
            'q' => 'Is Cardify free to use?',
            'a' => 'Yes, Cardify offers a free tier that lets you create and share digital business cards at no cost. Premium features such as custom branding, bulk card generation, NFC integration, and print ordering are available on paid plans starting from just 5 OMR per month.'
        ],
        [
            'q' => 'Who is Cardify for?',
            'a' => 'Cardify is designed for professionals, startups, SMEs, and large enterprises across Oman. Whether you are a freelancer needing a single card or an HR department managing 500 employees, Cardify scales to fit your needs.'
        ],
    ],
    'Digital Cards' => [
        [
            'q' => 'How do digital business cards work?',
            'a' => 'Each employee gets a unique digital card with a shareable link and QR code. When someone scans the QR code or taps the link, they see a beautifully designed card with your name, title, contact details, and social links — and can save your contact directly to their phone.'
        ],
        [
            'q' => 'Can I share my digital card via WhatsApp?',
            'a' => 'Absolutely. Every digital card has a share button that lets you send it directly via WhatsApp, email, SMS, or any messaging app. You can also copy the link and paste it in your email signature or social media profiles.'
        ],
        [
            'q' => 'Does Cardify support NFC business cards?',
            'a' => 'Yes. Cardify-generated digital cards are fully compatible with NFC-enabled cards and tags. You can program any NFC card or tag with your Cardify card URL so that a simple tap on a phone opens your digital card instantly — no app required.'
        ],
        [
            'q' => 'Can I update my digital card after creating it?',
            'a' => 'Yes, and that is one of the biggest advantages of digital cards. You can update your title, phone number, photo, or any detail at any time. Changes are reflected instantly — no reprinting needed. Anyone who previously received your card link will always see the latest version.'
        ],
    ],
    'Printing' => [
        [
            'q' => 'How do I order printed business cards through Cardify?',
            'a' => 'Once you design your card in Cardify, you can order prints directly from the platform. Choose your paper type, finish, and quantity, then place your order. A local print shop partner in Oman will produce and deliver your cards.'
        ],
        [
            'q' => 'Which print shops does Cardify work with in Oman?',
            'a' => 'Cardify partners with vetted print shops across Muscat and other major cities in Oman. Our print shop network ensures high-quality output with fast turnaround. Print shops can also register on our platform to receive orders.'
        ],
        [
            'q' => 'How long does printing take?',
            'a' => 'Standard printing typically takes 2-3 business days within Muscat. Rush printing options are available for next-day delivery. You will receive status updates as your order progresses from production to delivery.'
        ],
        [
            'q' => 'How much does printing cost?',
            'a' => 'Printing prices start from 5 OMR for 100 standard cards. Prices vary based on paper quality, finish (matte, glossy, or soft-touch), and quantity. You will see the exact price before confirming your order — no hidden fees.'
        ],
    ],
    'For Teams' => [
        [
            'q' => 'Can I manage business cards for multiple employees?',
            'a' => 'Yes. Cardify\'s company dashboard lets you manage all employee cards from one place. Add employees, assign templates, and generate cards in bulk. Each employee gets their own unique digital card while maintaining consistent company branding.'
        ],
        [
            'q' => 'Does Cardify support bulk card generation?',
            'a' => 'Yes. You can upload a spreadsheet of employee details (name, title, email, phone) and generate all their digital cards in one click. This is perfect for onboarding new teams or rolling out updated branding across the company.'
        ],
        [
            'q' => 'How does Cardify ensure brand consistency across employee cards?',
            'a' => 'Company admins set the brand template — logo, colors, fonts, and layout — which is locked for all employee cards. Individual employees can update their personal details but cannot alter the company branding, ensuring every card looks professional and on-brand.'
        ],
    ],
    'Billing & Payment' => [
        [
            'q' => 'What payment methods does Cardify accept?',
            'a' => 'Cardify accepts all major payment methods in Oman including Visa, Mastercard, and local bank transfers. All transactions are processed securely through our payment partner. Prices are displayed in Omani Rial (OMR).'
        ],
        [
            'q' => 'Does Cardify offer credit accounts for businesses?',
            'a' => 'Yes. Qualified businesses can apply for a credit account that allows you to place orders and pay on a monthly billing cycle. This is ideal for companies that order prints regularly or manage large teams. Contact our sales team to set up a credit account.'
        ],
        [
            'q' => 'Are all prices in OMR?',
            'a' => 'Yes. All prices on Cardify are displayed in Omani Rial (OMR) with three decimal places, following Oman\'s standard currency format. What you see is what you pay — no currency conversion surprises.'
        ],
    ],
    'Technical' => [
        [
            'q' => 'What file formats can I download my card in?',
            'a' => 'You can download your business card design as a high-resolution PNG or PDF, ready for professional printing. Digital cards are also available as VCF (vCard) files that recipients can save directly to their phone contacts.'
        ],
        [
            'q' => 'Does Cardify have an API?',
            'a' => 'Yes. Cardify offers a REST API for enterprise customers who want to integrate card generation into their own systems — such as HR platforms, CRMs, or onboarding workflows. Contact us for API documentation and access keys.'
        ],
    ],
];

// Build flat list for JSON-LD schema
$allFaqs = [];
foreach ($faqCategories as $faqs) {
    foreach ($faqs as $faq) {
        $allFaqs[] = $faq;
    }
}

// Extra head content for JSON-LD
$extraHead = '<script type="application/ld+json">' . json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(function($faq) {
        return [
            '@type' => 'Question',
            'name' => $faq['q'],
            'acceptedAnswer' => [
                '@type' => 'Answer',
                'text' => $faq['a'],
            ],
        ];
    }, $allFaqs),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="min-h-screen bg-gray-50">
    <!-- Hero Section -->
    <div class="bg-gradient-to-br from-blue-600 via-blue-700 to-blue-800 text-white py-20 pt-32">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-3xl mx-auto">
                <h1 class="text-4xl md:text-5xl font-bold mb-6">Frequently Asked Questions</h1>
                <p class="text-xl text-blue-100 leading-relaxed">
                    Everything you need to know about creating, sharing, and printing business cards with <?php echo $brandName; ?> in Oman.
                </p>
            </div>
        </div>
    </div>

    <!-- FAQ Content -->
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-16">

        <?php foreach ($faqCategories as $category => $faqs): ?>
        <div class="mb-12">
            <h2 class="text-2xl font-bold text-gray-900 mb-6 flex items-center gap-3">
                <?php
                $icons = [
                    'Getting Started' => 'fa-solid fa-rocket',
                    'Digital Cards' => 'fa-solid fa-mobile-screen',
                    'Printing' => 'fa-solid fa-print',
                    'For Teams' => 'fa-solid fa-users',
                    'Billing & Payment' => 'fa-solid fa-credit-card',
                    'Technical' => 'fa-solid fa-gear',
                ];
                $icon = $icons[$category] ?? 'fa-solid fa-circle-question';
                ?>
                <span class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
                    <i class="<?php echo $icon; ?> text-blue-600"></i>
                </span>
                <?php echo htmlspecialchars($category); ?>
            </h2>

            <div class="space-y-3">
                <?php foreach ($faqs as $faq): ?>
                <details class="group bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <summary class="flex items-center justify-between cursor-pointer px-6 py-5 text-left font-semibold text-gray-900 hover:bg-gray-50 transition-colors list-none [&::-webkit-details-marker]:hidden">
                        <span class="pr-4"><?php echo htmlspecialchars($faq['q']); ?></span>
                        <span class="flex-shrink-0 w-6 h-6 flex items-center justify-center text-blue-600 transition-transform group-open:rotate-45">
                            <i class="fa-solid fa-plus"></i>
                        </span>
                    </summary>
                    <div class="px-6 pb-5 text-gray-600 leading-relaxed border-t border-gray-100 pt-4">
                        <?php echo htmlspecialchars($faq['a']); ?>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- CTA -->
        <div class="bg-gradient-to-br from-blue-600 to-blue-800 rounded-2xl p-8 lg:p-12 text-center text-white mt-16">
            <h2 class="text-3xl font-bold mb-4">Still Have Questions?</h2>
            <p class="text-blue-100 mb-8 max-w-2xl mx-auto">
                Get in touch with our team or start creating your business cards today — it's free to try.
            </p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="<?php echo getBasePath(); ?>intro.php"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 bg-white text-blue-600 font-semibold rounded-xl hover:bg-blue-50 transition-colors">
                    Get Started Free
                    <i class="fa-solid fa-arrow-right"></i>
                </a>
                <a href="<?php echo getBasePath(); ?>contact.php"
                   class="inline-flex items-center justify-center gap-2 px-8 py-4 border-2 border-white text-white font-semibold rounded-xl hover:bg-white/10 transition-colors">
                    Contact Us
                    <i class="fa-solid fa-envelope"></i>
                </a>
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
