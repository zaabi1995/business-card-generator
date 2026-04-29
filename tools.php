<?php
/**
 * Cardify Free Tools, SEO hub page
 *
 * Lists the free client-side tools Cardify offers: vCard QR, email
 * signature, WhatsApp QR, NFC guide. Each tool is a standalone SEO
 * landing with commercial intent and a soft CTA back to Cardify.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = t('tools.page_title');
$pageDescription = t('tools.page_desc');
$canonicalUrl = 'https://cardify.om/tools';
$showNavigation = true;
$bodyClass = 'bg-white';

$tools = [
    [
        'slug' => 'vcard-qr-generator',
        'title_key' => 'tools.tool_vcard_title',
        'desc_key'  => 'tools.tool_vcard_desc',
        'icon' => 'fa-qrcode',
        'color' => 'blue',
        'badge_key' => 'tools.badge_most_popular',
    ],
    [
        'slug' => 'email-signature-generator',
        'title_key' => 'tools.tool_email_title',
        'desc_key'  => 'tools.tool_email_desc',
        'icon' => 'fa-envelope',
        'color' => 'indigo',
        'badge_key' => null,
    ],
    [
        'slug' => 'whatsapp-qr-generator',
        'title_key' => 'tools.tool_whatsapp_title',
        'desc_key'  => 'tools.tool_whatsapp_desc',
        'icon' => 'fa-whatsapp',
        'color' => 'emerald',
        'brand' => 'fa-brands',
        'badge_key' => null,
    ],
    [
        'slug' => 'nfc-business-card-guide',
        'title_key' => 'tools.tool_nfc_title',
        'desc_key'  => 'tools.tool_nfc_desc',
        'icon' => 'fa-wifi',
        'color' => 'purple',
        'badge_key' => 'tools.badge_guide',
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
    'itemListElement' => array_map(function ($i, $tItem) {
        return [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'url' => 'https://cardify.om/tools/' . $tItem['slug'],
            'name' => t($tItem['title_key']),
        ];
    }, array_keys($tools), $tools),
];
$extraHead = '<script type="application/ld+json">' . json_encode($siteLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
           . '<script type="application/ld+json">' . json_encode($crumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
           . '<script type="application/ld+json">' . json_encode($itemListLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

require_once INCLUDES_DIR . '/ui-header.php';
?>

<div class="bg-gradient-to-b from-gray-50 to-white pt-28 pb-16">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <span class="inline-block px-3 py-1 rounded-full bg-blue-100 text-blue-700 text-xs font-semibold uppercase tracking-wide mb-4"><?= htmlspecialchars(t('tools.badge_free_nosignup')) ?></span>
            <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 mb-4"><?= htmlspecialchars(t('tools.h1')) ?></h1>
            <p class="text-lg text-gray-600 max-w-2xl mx-auto">
                <?= htmlspecialchars(t('tools.hero_sub')) ?>
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach ($tools as $tItem):
                $colorClass = [
                    'blue' => 'from-blue-500 to-blue-600 text-blue-700 bg-blue-50',
                    'indigo' => 'from-indigo-500 to-indigo-600 text-indigo-700 bg-indigo-50',
                    'emerald' => 'from-emerald-500 to-emerald-600 text-emerald-700 bg-emerald-50',
                    'purple' => 'from-purple-500 to-purple-600 text-purple-700 bg-purple-50',
                ][$tItem['color']] ?? 'from-gray-500 to-gray-600 text-gray-700 bg-gray-50';
                $parts = explode(' ', $colorClass);
                $gradient = $parts[0] . ' ' . $parts[1];
                $textColor = $parts[2];
                $bgColor = $parts[3];
                $iconLib = $tItem['brand'] ?? 'fa-solid';
            ?>
            <a href="/tools/<?= htmlspecialchars($tItem['slug']) ?>" class="group relative bg-white rounded-2xl border border-gray-200 p-6 hover:border-blue-300 hover:shadow-lg transition-all">
                <?php if (!empty($tItem['badge_key'])): ?>
                    <span class="absolute top-4 right-4 px-2.5 py-1 rounded-full bg-gray-900 text-white text-[11px] font-semibold"><?= htmlspecialchars(t($tItem['badge_key'])) ?></span>
                <?php endif; ?>
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br <?= $gradient ?> flex items-center justify-center text-white text-lg mb-5">
                    <i class="<?= $iconLib ?> <?= htmlspecialchars($tItem['icon']) ?>"></i>
                </div>
                <h2 class="text-xl font-bold text-gray-900 mb-2"><?= htmlspecialchars(t($tItem['title_key'])) ?></h2>
                <p class="text-gray-600 leading-relaxed text-sm"><?= htmlspecialchars(t($tItem['desc_key'])) ?></p>
                <div class="mt-5 inline-flex items-center gap-2 text-sm font-semibold <?= $textColor ?> group-hover:gap-3 transition-all">
                    <?= htmlspecialchars(t('tools.open_tool')) ?>
                    <i class="fa-solid fa-arrow-right text-xs"></i>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="mt-16 bg-gradient-to-br from-blue-600 to-indigo-700 rounded-2xl p-8 lg:p-10 text-center text-white">
            <h2 class="text-2xl sm:text-3xl font-bold mb-3"><?= htmlspecialchars(t('tools.cta_h2')) ?></h2>
            <p class="text-blue-100 max-w-2xl mx-auto mb-6">
                <?= htmlspecialchars(t('tools.cta_body')) ?>
            </p>
            <a href="/get-started" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white text-blue-700 font-semibold hover:bg-blue-50 transition">
                <?= htmlspecialchars(t('tools.cta_button')) ?>
                <i class="fa-solid fa-arrow-right text-sm"></i>
            </a>
        </div>
    </div>
</div>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
