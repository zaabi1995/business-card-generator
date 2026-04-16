<?php
/**
 * Cardify Free Tools — SEO hub page
 *
 * Lists the free client-side tools Cardify offers: vCard QR, email
 * signature, WhatsApp QR, NFC guide. Each tool is a standalone SEO
 * landing with commercial intent and a soft CTA back to Cardify.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Free Business Card Tools — Cardify';
$pageDescription = 'Free tools from Cardify: vCard QR generator, email signature builder, WhatsApp QR, NFC card setup guide. No signup required.';
$canonicalUrl = 'https://cardify.om/tools';
$showNavigation = true;
$bodyClass = 'bg-white';

$tools = [
    [
        'slug' => 'vcard-qr-generator',
        'title' => 'vCard QR Code Generator',
        'desc' => 'Create a downloadable QR code that saves your contact straight to any phone. Works on iPhone + Android, no app required.',
        'icon' => 'fa-qrcode',
        'color' => 'blue',
        'badge' => 'Most popular',
    ],
    [
        'slug' => 'email-signature-generator',
        'title' => 'Email Signature Generator',
        'desc' => 'Build a Gmail + Outlook-compatible HTML signature in 30 seconds. Bilingual EN/AR option for Oman professionals.',
        'icon' => 'fa-envelope',
        'color' => 'indigo',
        'badge' => null,
    ],
    [
        'slug' => 'whatsapp-qr-generator',
        'title' => 'WhatsApp QR Generator',
        'desc' => 'Generate a QR that opens a WhatsApp chat with your number and a pre-filled message — perfect for shopfronts and trade shows.',
        'icon' => 'fa-whatsapp',
        'color' => 'emerald',
        'brand' => 'fa-brands',
        'badge' => null,
    ],
    [
        'slug' => 'nfc-business-card-guide',
        'title' => 'NFC Business Cards Guide',
        'desc' => 'Everything about NFC-enabled business cards in Oman: how they work, iPhone vs Android, setup, and where to buy.',
        'icon' => 'fa-wifi',
        'color' => 'purple',
        'badge' => 'Guide',
    ],
];

$siteLd = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => 'Cardify Free Tools',
    'url' => $canonicalUrl,
    'description' => $pageDescription,
    'isPartOf' => ['@type' => 'WebSite', 'name' => 'Cardify', 'url' => 'https://cardify.om/'],
];
$crumbLd = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => 'https://cardify.om/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Tools', 'item' => $canonicalUrl],
    ],
];
$itemListLd = [
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'name' => 'Cardify Free Tools',
    'itemListElement' => array_map(function ($i, $t) {
        return [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'url' => 'https://cardify.om/tools/' . $t['slug'],
            'name' => $t['title'],
        ];
    }, array_keys($tools), $tools),
];
$extraHead = '<script type="application/ld+json">' . json_encode($siteLd, JSON_UNESCAPED_SLASHES) . '</script>'
           . '<script type="application/ld+json">' . json_encode($crumbLd, JSON_UNESCAPED_SLASHES) . '</script>'
           . '<script type="application/ld+json">' . json_encode($itemListLd, JSON_UNESCAPED_SLASHES) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <span class="inline-block px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide mb-4">Free · No signup</span>
            <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 mb-4">Free Business Card Tools</h1>
            <p class="text-lg text-gray-600 max-w-2xl mx-auto">
                Quick utilities from the Cardify team. Generate QR codes, signatures, and WhatsApp links in seconds — no account, no paywall.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach ($tools as $t):
                $colorClass = [
                    'blue' => 'from-blue-500 to-blue-600 text-blue-700 bg-blue-50',
                    'indigo' => 'from-indigo-500 to-indigo-600 text-indigo-700 bg-indigo-50',
                    'emerald' => 'from-emerald-500 to-emerald-600 text-emerald-700 bg-emerald-50',
                    'purple' => 'from-purple-500 to-purple-600 text-purple-700 bg-purple-50',
                ][$t['color']] ?? 'from-gray-500 to-gray-600 text-gray-700 bg-gray-50';
                $parts = explode(' ', $colorClass);
                $gradient = $parts[0] . ' ' . $parts[1];
                $textColor = $parts[2];
                $bgColor = $parts[3];
                $iconLib = $t['brand'] ?? 'fa-solid';
            ?>
            <a href="/tools/<?= htmlspecialchars($t['slug']) ?>" class="group relative bg-white rounded-2xl border border-gray-200 p-6 hover:border-blue-300 hover:shadow-lg transition-all">
                <?php if ($t['badge']): ?>
                    <span class="absolute top-4 right-4 px-2.5 py-1 rounded-full bg-gray-900 text-white text-[11px] font-semibold"><?= htmlspecialchars($t['badge']) ?></span>
                <?php endif; ?>
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br <?= $gradient ?> flex items-center justify-center text-white text-lg mb-5">
                    <i class="<?= $iconLib ?> <?= htmlspecialchars($t['icon']) ?>"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars($t['title']) ?></h2>
                <p class="text-gray-600 leading-relaxed text-sm"><?= htmlspecialchars($t['desc']) ?></p>
                <div class="mt-5 inline-flex items-center gap-2 text-sm font-semibold <?= $textColor ?> group-hover:gap-3 transition-all">
                    Open tool
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="mt-16 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-8 lg:p-10 text-center text-white">
            <h2 class="text-2xl sm:text-3xl font-bold mb-3">Running a team in Oman?</h2>
            <p class="text-blue-100 max-w-2xl mx-auto mb-6">
                Free tools are great for individuals. For team-wide digital cards with central branding, analytics, and ordering printed cards — use Cardify.
            </p>
            <a href="/get-started" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white text-blue-700 font-semibold hover:bg-blue-50 transition">
                Start free with Cardify
                <i class="fa-solid fa-arrow-right text-sm"></i>
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
