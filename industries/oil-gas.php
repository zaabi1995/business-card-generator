<?php
/**
 * Cardify, Digital Business Cards for Oil & Gas in Oman / GCC
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/JsonLd.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle       = 'Business Cards for Oil & Gas Teams in Oman + GCC, Cardify';
$pageDescription = 'Digital business cards for upstream, midstream, and downstream oil & gas teams across Oman and the GCC. PDO, OQ, Shell Oman, service companies, and contractors.';
$canonicalUrl    = 'https://cardify.om/industries/oil-gas';
$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$showNavigation  = true;

$faq = [
    ['q' => 'Which oil & gas teams use Cardify?',      'a' => 'Upstream crews (PDO, OQ Exploration), midstream (OQ Gas Networks, OLNG), downstream (OQ Refineries, Shell Oman retail), service companies (Schlumberger, Baker Hughes, Halliburton Oman), and hundreds of local contractors operating on Omani fields.'],
    ['q' => 'Does it work at rig sites and fields?',   'a' => 'Yes. A single QR scan works with basic mobile data, no app install, no WiFi. Contractor teams often use it on-site in Block 6, Mukhaizna, Harweel, and Khazzan operations.'],
    ['q' => 'Can we embed HSE certifications?',        'a' => 'Yes. Display fields handle H2S, basic offshore survival (BOSIET), confined-space, and other certs as badges on the card.'],
    ['q' => 'What about bilingual Arabic fields?',     'a' => 'Full RTL Arabic, proper kerning, tabular numerals for well IDs and PO numbers.'],
    ['q' => 'Are major Omani energy brands in the logo library?', 'a' => 'Yes, OQ, PDO, Energy & Minerals ministry, OLNG, and related sovereign entities are in the Omani Logo Library at /logos/oil-gas.'],
];

$serviceLd = [
    '@context' => 'https://schema.org', '@type' => 'Service',
    'name' => 'Cardify, Digital Business Cards for Oil & Gas',
    // r154 / llm153-2: a reference; the owner body is emitted by ui-header.
    'provider' => ['@id' => 'https://cardify.om/#organization'],
    'areaServed' => [['@type' => 'Country', 'name' => 'Oman'], ['@type' => 'Country', 'name' => 'Saudi Arabia'], ['@type' => 'Country', 'name' => 'UAE']],
    'serviceType' => 'Digital Business Card Platform',
    'audience' => ['@type' => 'BusinessAudience', 'name' => 'Upstream, midstream, downstream operators, oilfield service companies, contractors'],
];
$faqLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a']]], $faq)];
$crumbLd = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Cardify',    'item' => 'https://cardify.om'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Industries', 'item' => 'https://cardify.om/industries'],
    ['@type' => 'ListItem', 'position' => 3, 'name' => 'Oil & Gas',  'item' => $canonicalUrl],
]];
$extraHead =
      '<script type="application/ld+json">' . json_encode($serviceLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($faqLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($crumbLd, JsonLd::SAFE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

function ogEsc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

require_once INCLUDES_DIR . '/ui-header.php';
?>

<section class="bg-gradient-to-b from-amber-50 via-white to-white pt-28 pb-16 border-b border-gray-100">
  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
    <div class="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-5">
      <i class="fa-solid fa-oil-well text-2xl text-amber-700"></i>
    </div>
    <span class="inline-block px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-xs font-semibold uppercase tracking-wide mb-3">Industry · Oil &amp; Gas</span>
    <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 mb-4">Digital cards for oil &amp; gas teams</h1>
    <p class="text-lg text-gray-600 mb-7 max-w-2xl mx-auto">
      Upstream crews, midstream operators, downstream retail, service companies, and contractors. One card works from an office in Muscat to a rig in Block 6.
    </p>
    <div class="flex flex-wrap justify-center gap-3">
      <a href="/get-started" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold shadow-lg shadow-blue-600/30 transition">
        Start a pilot <i class="fa-solid fa-arrow-right text-xs"></i>
      </a>
      <a href="/logos/oil-gas" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white border border-gray-300 text-gray-800 font-semibold hover:border-amber-400 hover:text-amber-700 transition">
        Browse oil &amp; gas logos
      </a>
    </div>
  </div>
</section>

<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
  <div class="grid lg:grid-cols-2 gap-12 items-start">
    <div>
      <span class="text-amber-800 font-semibold text-sm uppercase tracking-wider">Why energy teams need this</span>
      <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-5">Rotations, secondments, and joint-venture teams</h2>
      <div class="space-y-4 text-gray-700 leading-relaxed">
        <p>PDO rotates crews through Mina Al Fahal, Fahud, and Marmul. OQ teams move between Salalah LPG, Sohar Refinery, and the exploration side. Joint ventures with Shell, BP, Total, and CNPC mean secondments reshape contact directories every quarter.</p>
        <p>A Cardify card updates centrally. The HSE officer transferred from Fahud to Rima? HR flips a field; every contractor who scanned the card in the last year sees the new details instantly. No reprint cycle, no stale printed stacks sitting in a JV partner's desk drawer.</p>
        <p>Contractors on Cardify look more professional than ones still handing out paper cards bent from a month in coveralls. That matters for tender panels and pre-qualifications.</p>
      </div>
    </div>
    <div class="bg-gradient-to-br from-amber-50 to-orange-50 rounded-2xl p-6 space-y-3">
      <?php foreach ([
        ['fa-hard-hat',      'Field-ready QR',          'Works on any site with mobile data. No app install for the person scanning.'],
        ['fa-id-card-clip',  'HSE credentials',         'BOSIET, H2S, confined-space, first-aid certs displayed as badges.'],
        ['fa-language',      'Bilingual by default',    'Arabic + English rendered proper RTL with tabular numerals.'],
        ['fa-shuffle',       'Rotation-proof',          'Central updates, no stale printed cards after a transfer.'],
        ['fa-people-group',  'Contractor directories',  'One team card per contractor company keeps tender contacts consistent.'],
        ['fa-shield-halved', 'Brand-locked',            'Safety-critical brands can lock template so no employee produces off-brand cards.'],
      ] as [$icon, $title, $body]): ?>
        <div class="flex items-start gap-4 p-3 rounded-xl bg-white">
          <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid <?= ogEsc($icon) ?> text-amber-700"></i>
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
      <span class="text-amber-800 font-semibold text-sm uppercase tracking-wider">Energy logos library</span>
      <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-2">Omani oil &amp; gas logos, indexed</h2>
      <p class="text-gray-600">PDO, OQ, Shell Oman, OLNG, Energy &amp; Minerals ministry. SVG + PNG.</p>
    </div>
    <a href="/logos/oil-gas" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-semibold whitespace-nowrap">
      Open Oil &amp; Gas library <i class="fa-solid fa-arrow-right text-xs"></i>
    </a>
  </div>
  <?php
    try { $db = Database::getInstance();
      $logos = $db->fetchAll("SELECT slug, name_en, logo_svg_path, logo_png_path, logo_webp_path, logo_png_512_path FROM om_companies WHERE sector='oil-gas' AND logo_status IN ('indexed','verified') AND (logo_svg_path IS NOT NULL OR logo_png_path IS NOT NULL) ORDER BY FIELD(logo_status,'verified','indexed'), logo_updated_at DESC LIMIT 8");
    } catch (Throwable $e) { $logos = []; }
  ?>
  <?php if ($logos): ?>
    <div class="grid grid-cols-4 md:grid-cols-8 gap-3">
      <?php foreach ($logos as $l): $src = $l['logo_webp_path'] ?: $l['logo_png_512_path'] ?: $l['logo_png_path'] ?: $l['logo_svg_path']; ?>
        <a href="/companies/<?= ogEsc($l['slug']) ?>" class="group aspect-square bg-white border border-gray-200 rounded-xl p-3 flex items-center justify-center hover:border-amber-300 hover:shadow transition">
          <img src="<?= ogEsc($src) ?>" alt="<?= ogEsc($l['name_en']) ?> logo" loading="lazy" class="max-h-full max-w-full object-contain">
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="bg-gray-50 border-y border-gray-100">
  <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <span class="text-amber-800 font-semibold text-sm uppercase tracking-wider">FAQ</span>
    <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-6">Common questions</h2>
    <div class="space-y-3">
      <?php foreach ($faq as $i => $q): ?>
        <details class="group bg-white border border-gray-200 rounded-xl hover:border-amber-300 transition overflow-hidden"<?= $i===0 ? ' open':'' ?>>
          <summary class="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer list-none">
            <span class="font-semibold text-gray-900"><?= ogEsc($q['q']) ?></span>
            <i class="fa-solid fa-chevron-down text-gray-400 text-sm transition group-open:rotate-180"></i>
          </summary>
          <div class="px-5 pb-4 text-gray-600 leading-relaxed"><?= ogEsc($q['a']) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
  <h2 class="text-3xl font-extrabold text-gray-900 mb-4">Start with one crew, scale across the asset</h2>
  <div class="flex flex-wrap justify-center gap-3">
    <a href="/get-started" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-semibold shadow-lg shadow-amber-600/30 transition">Start free <i class="fa-solid fa-arrow-right text-xs"></i></a>
  </div>
  <div class="mt-10 pt-10 border-t border-gray-200 text-sm text-gray-500 space-x-3">
    <a href="/oman-business-index" class="text-amber-700 hover:underline">Oman Business Index</a> ·
    <a href="/gcc-business-index" class="text-amber-700 hover:underline">GCC Business Index</a> ·
    <a href="/logos/oil-gas" class="text-amber-700 hover:underline">Oil &amp; Gas logos</a> ·
    <a href="/companies/sector/oil-gas" class="text-amber-700 hover:underline">Oman energy companies</a>
  </div>
</section>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
