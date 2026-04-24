<?php
/**
 * Cardify, Digital Business Cards for Logistics & Shipping in Oman / GCC
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle       = 'Business Cards for Logistics & Shipping in Oman + GCC, Cardify';
$pageDescription = 'Digital business cards for freight forwarders, port teams, shipping agents, and logistics operators across Oman and the GCC. Works at Sohar, Duqm, Salalah, and free zones.';
$canonicalUrl    = 'https://cardify.om/industries/logistics';
$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$showNavigation  = true;

$faq = [
    ['q' => 'Who uses Cardify in logistics?',         'a' => 'Teams at Asyad, Sohar Port, Oman Dry Dock, freight forwarders, customs brokers, shipping agents, warehousing operators, and last-mile dispatch teams across Oman and the wider GCC.'],
    ['q' => 'Does it work on-site at ports?',         'a' => 'Yes. Cards are a single QR a forwarder scans once from their phone camera, no app install, no typing. Works at Sohar, Duqm, Salalah, Jebel Ali, King Abdullah Port, and anywhere with basic mobile data.'],
    ['q' => 'Can we show IATA/FIATA credentials?',    'a' => 'Yes. Credential fields are part of the template; you can show IATA, FIATA, AEO, and bonded-warehouse numbers as trust signals on every team card.'],
    ['q' => 'How do we handle bilingual vessel/port names?', 'a' => 'Every field supports Arabic + English. Templates ship bilingual by default, no two card versions to maintain.'],
    ['q' => 'Can we cross-reference port logos?',     'a' => 'Yes. The Omani Logo Library includes ASYAD Group, Sohar Port and Freezone, and related sovereign entities (MoT, Customs Authority). All downloadable in SVG + PNG.'],
];

$serviceLd = [
    '@context' => 'https://schema.org', '@type' => 'Service',
    'name' => 'Cardify, Digital Business Cards for Logistics & Shipping',
    'provider' => ['@type' => 'Organization', 'name' => 'Cardify', 'url' => 'https://cardify.om'],
    'areaServed' => [['@type' => 'Country', 'name' => 'Oman'], ['@type' => 'Country', 'name' => 'Saudi Arabia'], ['@type' => 'Country', 'name' => 'UAE']],
    'serviceType' => 'Digital Business Card Platform',
    'audience' => ['@type' => 'BusinessAudience', 'name' => 'Freight forwarders, port teams, shipping agents, customs brokers, warehousing, last-mile dispatch'],
];
$faqLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a']]], $faq)];
$crumbLd = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Cardify',    'item' => 'https://cardify.om'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Industries', 'item' => 'https://cardify.om/industries'],
    ['@type' => 'ListItem', 'position' => 3, 'name' => 'Logistics & Shipping', 'item' => $canonicalUrl],
]];
$extraHead =
      '<script type="application/ld+json">' . json_encode($serviceLd, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($faqLd,     JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($crumbLd,   JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . '</script>';

function logEsc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

require_once INCLUDES_DIR . '/ui-header.php';
?>

<section class="bg-gradient-to-b from-sky-50 via-white to-white pt-28 pb-16 border-b border-gray-100">
  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
    <div class="w-16 h-16 rounded-full bg-sky-100 flex items-center justify-center mx-auto mb-5">
      <i class="fa-solid fa-ship text-2xl text-sky-700"></i>
    </div>
    <span class="inline-block px-3 py-1 rounded-full bg-sky-100 text-sky-700 text-xs font-semibold uppercase tracking-wide mb-3">Industry · Logistics &amp; Shipping</span>
    <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 mb-4">Digital cards for port, shipping &amp; freight teams</h1>
    <p class="text-lg text-gray-600 mb-7 max-w-2xl mx-auto">
      Built for forwarders, port agents, customs brokers, and warehousing teams across the GCC. One QR, bilingual EN/AR, works on quaysides from Sohar to Salalah.
    </p>
    <div class="flex flex-wrap justify-center gap-3">
      <a href="/get-started" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold shadow-lg shadow-blue-600/30 transition">
        Start a pilot for your team <i class="fa-solid fa-arrow-right text-xs"></i>
      </a>
      <a href="/logos/logistics-shipping" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white border border-gray-300 text-gray-800 font-semibold hover:border-sky-400 hover:text-sky-700 transition">
        Browse logistics logos
      </a>
    </div>
  </div>
</section>

<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
  <div class="grid lg:grid-cols-2 gap-12 items-start">
    <div>
      <span class="text-sky-700 font-semibold text-sm uppercase tracking-wider">Why logistics needs this</span>
      <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-5">Logistics moves fast. Your contact info shouldn't lag.</h2>
      <div class="space-y-4 text-gray-700 leading-relaxed">
        <p>The freight-forwarder at Sohar this week is at Salalah next week. Customs brokers rotate between Ruwi, Port Sultan Qaboos, and Muscat Airport cargo. Between Jebel Ali, King Abdullah Port, and Hamad Port, a single shipper might deal with 10 agents across 4 countries.</p>
        <p>A Cardify digital card keeps everyone current. When a port agent transfers from a Sohar desk to a Salalah one, the card updates, no reprinted stack, no client with an out-of-date number.</p>
        <p>Add IATA/FIATA credentials, your HS-code specialty, languages spoken, and a scan QR that opens a WhatsApp chat with a pre-filled "RE: BL/<?= 'AWB' ?>" subject line. That's how logistics runs today.</p>
      </div>
    </div>
    <div class="bg-gradient-to-br from-sky-50 to-cyan-50 rounded-2xl p-6 space-y-3">
      <?php foreach ([
        ['fa-container-storage', 'Port &amp; terminal-ready',    'Single QR scan works on any smartphone, no app install needed at the quayside.'],
        ['fa-qrcode',            'Printed QR on lanyards',       'Print a card with QR + attach to a lanyard / hi-vis vest. Still on-brand when a surveyor taps it.'],
        ['fa-certificate',       'IATA / FIATA / AEO display',   'Credential fields are template-level, not afterthought. Trust signals stay visible.'],
        ['fa-language',          'Bilingual vessel / port names','Arabic + English rendered proper RTL with tabular numerals for BL numbers.'],
        ['fa-truck-fast',        'Last-mile dispatch',           'Driver cards embed dispatch WhatsApp + live tracking link. Customers hit one button, not "did the driver text you?"'],
        ['fa-shield-halved',     'Single-source brand',          'Forwarders, port ops, and customs all on one template, so the Asyad standard looks the same everywhere.'],
      ] as [$icon, $title, $body]): ?>
        <div class="flex items-start gap-4 p-3 rounded-xl bg-white">
          <div class="w-10 h-10 rounded-lg bg-sky-100 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid <?= logEsc($icon) ?> text-sky-700"></i>
          </div>
          <div>
            <h3 class="font-semibold text-gray-900"><?= $title ?></h3>
            <p class="text-gray-600 text-sm"><?= $body ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-8">
    <div class="max-w-2xl">
      <span class="text-sky-700 font-semibold text-sm uppercase tracking-wider">Logistics logos library</span>
      <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-2">GCC port &amp; shipping logos, indexed</h2>
      <p class="text-gray-600">ASYAD Group, Sohar Port and Freezone, and related entities. SVG + PNG, free for identification use.</p>
    </div>
    <a href="/logos/logistics-shipping" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-sky-600 hover:bg-sky-700 text-white font-semibold whitespace-nowrap">
      Open Logistics library <i class="fa-solid fa-arrow-right text-xs"></i>
    </a>
  </div>
  <?php
    try { $db = Database::getInstance();
      $logos = $db->fetchAll("SELECT slug, name_en, logo_svg_path, logo_png_path, logo_webp_path, logo_png_512_path FROM om_companies WHERE sector='logistics-shipping' AND logo_status IN ('indexed','verified') AND (logo_svg_path IS NOT NULL OR logo_png_path IS NOT NULL) ORDER BY FIELD(logo_status,'verified','indexed'), logo_updated_at DESC LIMIT 8");
    } catch (Throwable $e) { $logos = []; }
  ?>
  <?php if ($logos): ?>
    <div class="grid grid-cols-4 md:grid-cols-8 gap-3">
      <?php foreach ($logos as $l): $src = $l['logo_webp_path'] ?: $l['logo_png_512_path'] ?: $l['logo_png_path'] ?: $l['logo_svg_path']; ?>
        <a href="/companies/<?= logEsc($l['slug']) ?>" class="group aspect-square bg-white border border-gray-200 rounded-xl p-3 flex items-center justify-center hover:border-sky-300 hover:shadow transition">
          <img src="<?= logEsc($src) ?>" alt="<?= logEsc($l['name_en']) ?> logo" loading="lazy" class="max-h-full max-w-full object-contain">
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="bg-gray-50 border-y border-gray-100">
  <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <span class="text-sky-700 font-semibold text-sm uppercase tracking-wider">FAQ</span>
    <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-6">Logistics team questions</h2>
    <div class="space-y-3">
      <?php foreach ($faq as $i => $q): ?>
        <details class="group bg-white border border-gray-200 rounded-xl hover:border-sky-300 transition overflow-hidden"<?= $i===0 ? ' open':'' ?>>
          <summary class="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer list-none">
            <span class="font-semibold text-gray-900"><?= logEsc($q['q']) ?></span>
            <i class="fa-solid fa-chevron-down text-gray-400 text-sm transition group-open:rotate-180"></i>
          </summary>
          <div class="px-5 pb-4 text-gray-600 leading-relaxed"><?= logEsc($q['a']) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
  <h2 class="text-3xl font-extrabold text-gray-900 mb-4">Ready to roll out Cardify across your ops team?</h2>
  <p class="text-gray-600 mb-7">Start with one port office and scale to the whole network.</p>
  <div class="flex flex-wrap justify-center gap-3">
    <a href="/get-started" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-sky-600 hover:bg-sky-700 text-white font-semibold shadow-lg shadow-sky-600/30 transition">Start free <i class="fa-solid fa-arrow-right text-xs"></i></a>
    <a href="/tools/whatsapp-qr-generator" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white border border-gray-300 text-gray-800 font-semibold hover:border-sky-300 transition">Free WhatsApp QR tool</a>
  </div>
  <div class="mt-10 pt-10 border-t border-gray-200 text-sm text-gray-500 space-x-3">
    <a href="/oman-business-index" class="text-sky-600 hover:underline">Oman Business Index</a> ·
    <a href="/gcc-business-index" class="text-sky-600 hover:underline">GCC Business Index</a> ·
    <a href="/logos/logistics-shipping" class="text-sky-600 hover:underline">Logistics logos</a> ·
    <a href="/companies/sector/logistics-shipping" class="text-sky-600 hover:underline">Logistics companies in Oman</a>
  </div>
</section>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
