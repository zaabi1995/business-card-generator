<?php
/**
 * Cardify, Digital Business Cards for Government & Public Sector in Oman / GCC
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle       = 'Business Cards for Government Teams in Oman + GCC, Cardify';
$pageDescription = 'Bilingual digital business cards for ministries, authorities, and public-sector teams in Oman and the GCC. Omanization-friendly, brand-compliant, built to regional standards.';
$canonicalUrl    = 'https://cardify.om/industries/government';
$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$showNavigation  = true;

$faq = [
    ['q' => 'Is this approved for government use?',         'a' => 'Cardify hosts public-facing identity only, name, title, official phone, work email, department. Nothing classified, nothing restricted. That keeps the tool compatible with every GCC government\'s public-representation guidelines.'],
    ['q' => 'How do ministries control branding?',          'a' => 'Each entity uploads its approved logo, colors, and title list once. Employees can only customize their own name, title (from an approved list), and direct contact info. The sultanate emblem or equivalent stays untouched.'],
    ['q' => 'Can employees keep personal data private?',    'a' => 'Yes. All fields are optional per-employee. Direct phone is common, personal mobile is opt-in, social handles are opt-in.'],
    ['q' => 'Does it support Arabic-first presentation?',   'a' => 'Every card ships bilingual with a locale toggle. Ministries often default to Arabic-first with English as the secondary pane.'],
    ['q' => 'Are our ministry logos in your library?',      'a' => 'Yes, all 19 Omani ministries plus OIA, CBO, Tax Authority, NCSI, and related sovereign bodies are indexed in the Omani Logo Library with curated bilingual summaries.'],
];

$serviceLd = [
    '@context' => 'https://schema.org', '@type' => 'Service',
    'name' => 'Cardify, Digital Business Cards for Government Teams',
    'provider' => ['@type' => 'Organization', 'name' => 'Cardify', 'url' => 'https://cardify.om'],
    'areaServed' => [['@type' => 'Country', 'name' => 'Oman'], ['@type' => 'Country', 'name' => 'Saudi Arabia'], ['@type' => 'Country', 'name' => 'UAE'], ['@type' => 'Country', 'name' => 'Qatar'], ['@type' => 'Country', 'name' => 'Bahrain'], ['@type' => 'Country', 'name' => 'Kuwait']],
    'serviceType' => 'Digital Business Card Platform',
    'audience' => ['@type' => 'BusinessAudience', 'name' => 'Ministries, authorities, municipalities, sovereign entities, regulators, public-sector departments'],
];
$faqLd = ['@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a']]], $faq)];
$crumbLd = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Cardify',    'item' => 'https://cardify.om'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Industries', 'item' => 'https://cardify.om/industries'],
    ['@type' => 'ListItem', 'position' => 3, 'name' => 'Government', 'item' => $canonicalUrl],
]];
$extraHead =
      '<script type="application/ld+json">' . json_encode($serviceLd, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($faqLd,     JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($crumbLd,   JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . '</script>';

function govEsc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

require_once INCLUDES_DIR . '/ui-header.php';
?>

<section class="bg-gradient-to-b from-slate-100 via-white to-white pt-28 pb-16 border-b border-gray-100">
  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
    <div class="w-16 h-16 rounded-full bg-slate-200 flex items-center justify-center mx-auto mb-5">
      <i class="fa-solid fa-landmark text-2xl text-slate-700"></i>
    </div>
    <span class="inline-block px-3 py-1 rounded-full bg-slate-200 text-slate-700 text-xs font-semibold uppercase tracking-wide mb-3">Industry · Government &amp; Public Sector</span>
    <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 mb-4">Digital cards for government teams</h1>
    <p class="text-lg text-gray-600 mb-7 max-w-2xl mx-auto">
      Bilingual, brand-controlled, and built to the standards of Omani and GCC public-sector identity. Used by ministries, authorities, and sovereign-adjacent bodies for protocol-compliant public representation.
    </p>
    <div class="flex flex-wrap justify-center gap-3">
      <a href="mailto:contact@cardify.om?subject=Government%20team%20onboarding" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-slate-800 hover:bg-slate-900 text-white font-semibold shadow-lg transition">
        Contact for government onboarding <i class="fa-solid fa-arrow-right text-xs"></i>
      </a>
      <a href="/logos/government-defense" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white border border-gray-300 text-gray-800 font-semibold hover:border-slate-400 hover:text-slate-700 transition">
        Browse ministry logos
      </a>
    </div>
  </div>
</section>

<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
  <div class="grid lg:grid-cols-2 gap-12 items-start">
    <div>
      <span class="text-slate-700 font-semibold text-sm uppercase tracking-wider">Why government teams use this</span>
      <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-5">Protocol-first identity, not marketing gimmick</h2>
      <div class="space-y-4 text-gray-700 leading-relaxed">
        <p>Public-sector identity is different from commercial branding. The sultanate emblem on the card, the khanjar and crossed swords, is a constitutional symbol, not a logo. It needs to appear exactly right, in exactly the approved composition, every time.</p>
        <p>Cardify's government templates lock the visual identity of each ministry: the emblem, the bilingual name, the standard disclaimer footer. Employees edit their name, title (from the approved HR list), and contact info. The mark stays untouched.</p>
        <p>The result: a protocol-compliant card that still modernizes the citizen-facing experience. A visitor meeting with an authority leaves with the official a QR on their phone, not a crumpled card in a pocket.</p>
      </div>
    </div>
    <div class="bg-gradient-to-br from-slate-50 to-stone-50 rounded-2xl p-6 space-y-3">
      <?php foreach ([
        ['fa-landmark',       'Protocol-compliant',         'Sultanate emblem / national emblem locked in correct composition. No DIY variants.'],
        ['fa-people-roof',    'Title hierarchy',            'Approved HR title list, employees can only pick from it, not invent roles.'],
        ['fa-language',       'Arabic-first',               'Default locale is Arabic; English is secondary. RTL rendering is proper, not an afterthought.'],
        ['fa-qrcode',         'Citizen touchpoint',         'A single QR replaces the paper card for citizens visiting authorities.'],
        ['fa-shield-halved',  'Public data only',           'No classified or restricted information stored. Card carries only what is already public.'],
        ['fa-download',       'Standard exports',           'PDF, vCard, Apple/Google Wallet pass, every channel a ministry already uses.'],
      ] as [$icon, $title, $body]): ?>
        <div class="flex items-start gap-4 p-3 rounded-xl bg-white">
          <div class="w-10 h-10 rounded-lg bg-slate-200 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid <?= govEsc($icon) ?> text-slate-700"></i>
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
      <span class="text-slate-700 font-semibold text-sm uppercase tracking-wider">Government &amp; defense library</span>
      <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-2">Omani ministry logos, curated</h2>
      <p class="text-gray-600">All 19 ministries + OIA, CBO, Tax Authority, NCSI, Oman Vision 2040 Unit. Each with curated bilingual summaries. PNG and SVG.</p>
    </div>
    <a href="/logos/government-defense" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-slate-700 hover:bg-slate-800 text-white font-semibold whitespace-nowrap">
      Open Government library <i class="fa-solid fa-arrow-right text-xs"></i>
    </a>
  </div>
  <?php
    try { $db = Database::getInstance();
      $logos = $db->fetchAll("SELECT slug, name_en, logo_svg_path, logo_png_path, logo_webp_path, logo_png_512_path FROM om_companies WHERE sector='government-defense' AND logo_status IN ('indexed','verified') AND (logo_svg_path IS NOT NULL OR logo_png_path IS NOT NULL) ORDER BY FIELD(logo_status,'verified','indexed'), logo_updated_at DESC LIMIT 12");
    } catch (Throwable $e) { $logos = []; }
  ?>
  <?php if ($logos): ?>
    <div class="grid grid-cols-4 md:grid-cols-6 lg:grid-cols-12 gap-3">
      <?php foreach ($logos as $l): $src = $l['logo_webp_path'] ?: $l['logo_png_512_path'] ?: $l['logo_png_path'] ?: $l['logo_svg_path']; ?>
        <a href="/companies/<?= govEsc($l['slug']) ?>" class="group aspect-square bg-white border border-gray-200 rounded-xl p-3 flex items-center justify-center hover:border-slate-400 hover:shadow transition">
          <img src="<?= govEsc($src) ?>" alt="<?= govEsc($l['name_en']) ?> logo" loading="lazy" class="max-h-full max-w-full object-contain">
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="bg-gray-50 border-y border-gray-100">
  <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <span class="text-slate-700 font-semibold text-sm uppercase tracking-wider">FAQ</span>
    <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-6">Government-team questions</h2>
    <div class="space-y-3">
      <?php foreach ($faq as $i => $q): ?>
        <details class="group bg-white border border-gray-200 rounded-xl hover:border-slate-400 transition overflow-hidden"<?= $i===0 ? ' open':'' ?>>
          <summary class="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer list-none">
            <span class="font-semibold text-gray-900"><?= govEsc($q['q']) ?></span>
            <i class="fa-solid fa-chevron-down text-gray-400 text-sm transition group-open:rotate-180"></i>
          </summary>
          <div class="px-5 pb-4 text-gray-600 leading-relaxed"><?= govEsc($q['a']) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
  <h2 class="text-3xl font-extrabold text-gray-900 mb-4">Ready to discuss ministry onboarding?</h2>
  <p class="text-gray-600 mb-7">Government onboarding is handled directly by our team, not self-service signup.</p>
  <a href="mailto:contact@cardify.om?subject=Government%20team%20onboarding" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-slate-800 hover:bg-slate-900 text-white font-semibold shadow-lg transition">
    Contact Cardify government team <i class="fa-solid fa-arrow-right text-xs"></i>
  </a>
  <div class="mt-10 pt-10 border-t border-gray-200 text-sm text-gray-500 space-x-3">
    <a href="/oman-business-index" class="text-slate-700 hover:underline">Oman Business Index</a> ·
    <a href="/gcc-business-index" class="text-slate-700 hover:underline">GCC Business Index</a> ·
    <a href="/logos/government-defense" class="text-slate-700 hover:underline">Government logos</a>
  </div>
</section>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
