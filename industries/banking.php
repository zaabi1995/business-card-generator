<?php
/**
 * Cardify, Digital Business Cards for Banks & Finance in Oman / GCC
 *
 * Industry landing page targeting "business cards for banks", "digital
 * cards for bankers", "banking team cards oman gcc" long-tail queries.
 *
 * Cross-links to /logos/finance (real Bank Muscat, NBO, CBO, OIA logos
 * already in the library), /companies/sector/finance, /oman-business-index,
 * and /gcc-business-index.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle       = 'Business Cards for Banks & Finance in Oman + GCC, Cardify';
$pageDescription = 'Digital business cards for bankers, relationship managers, investment advisors, and finance teams across Oman and the GCC. Bilingual EN/AR, compliance-ready, works for every branch and every RM.';
$canonicalUrl    = 'https://cardify.om/industries/banking';
$ogType          = 'website';
$brandName       = defined('SITE_NAME') ? SITE_NAME : 'Cardify';
$showNavigation  = true;

$faq = [
    [
        'q' => 'Does Cardify work for regulated entities like banks?',
        'a' => 'Yes. Cardify is used by relationship managers, investment advisors, and bank staff in Oman. Cards can be issued centrally by the brand/HR team so every employee card follows compliance guidelines (approved titles, disclosures, approved social handles). Bulk provisioning, SSO, and audit logs are on the Pro plan.',
    ],
    [
        'q' => 'Which GCC banks are in your logo library?',
        'a' => 'Bank Muscat, National Bank of Oman, Sohar International, Bank Dhofar, ahlibank, HSBC Oman, and the Central Bank of Oman board are already in the Omani Logo Library. GCC expansion through 2026 will add SAMA-licensed banks in Saudi, UAE Central Bank-licensed institutions, QCB-licensed Qatari banks, CBB-licensed Bahraini banks, and CBK-licensed Kuwaiti banks.',
    ],
    [
        'q' => 'Can banks embed their brand assets?',
        'a' => 'Yes. Each bank gets a locked template, logo, colors, fonts, approved layouts, and RMs can only customize their personal details (name, title, direct line, email, LinkedIn). This keeps the brand intact across hundreds of RMs without central bottlenecks.',
    ],
    [
        'q' => 'What about client-sensitive data?',
        'a' => 'Business cards are public by design, they carry only what an RM would hand out in person. No client records, no account numbers, no portfolio data. Cardify complies with CBO and equivalent GCC regulator guidance on public brand representation.',
    ],
    [
        'q' => 'How does this compare to a standard printed card for a banker?',
        'a' => 'Printed cards are still relevant for face-to-face meetings, Cardify produces both. The advantage of the digital card is that when the RM changes branches (common in banking), the client never has an outdated number. Your digital card updates instantly; their printed card from 2019 is the one that gets trashed.',
    ],
];

$serviceLd = [
    '@context' => 'https://schema.org',
    '@type'    => 'Service',
    'name'     => 'Cardify, Digital Business Cards for Banks & Finance',
    'description' => 'Bank-grade digital business cards for relationship managers, advisors, and finance teams in Oman and the GCC. Bilingual EN/AR, brand-locked templates, bulk provisioning.',
    'provider' => ['@type' => 'Organization', 'name' => 'Cardify', 'url' => 'https://cardify.om',
        'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Muscat', 'addressCountry' => 'OM']],
    'areaServed' => [
        ['@type' => 'Country', 'name' => 'Oman'],
        ['@type' => 'Country', 'name' => 'Saudi Arabia'],
        ['@type' => 'Country', 'name' => 'United Arab Emirates'],
        ['@type' => 'Country', 'name' => 'Qatar'],
        ['@type' => 'Country', 'name' => 'Bahrain'],
        ['@type' => 'Country', 'name' => 'Kuwait'],
    ],
    'serviceType' => 'Digital Business Card Platform',
    'audience'    => ['@type' => 'BusinessAudience', 'name' => 'Banks, investment firms, financial advisors, asset managers, insurance brokers'],
];
$faqLd = [
    '@context' => 'https://schema.org', '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q['q'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q['a']]], $faq),
];
$crumbLd = [
    '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Cardify',    'item' => 'https://cardify.om'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Industries', 'item' => 'https://cardify.om/industries'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => 'Banks & Finance', 'item' => $canonicalUrl],
    ],
];

$extraHead =
      '<script type="application/ld+json">' . json_encode($serviceLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($faqLd,     JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>'
    . '<script type="application/ld+json">' . json_encode($crumbLd,   JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

function bankEsc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

require_once INCLUDES_DIR . '/ui-header.php';
?>

<!-- HERO -->
<section class="bg-gradient-to-b from-emerald-50 via-white to-white pt-28 pb-16 border-b border-gray-100">
  <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
    <div class="w-16 h-16 rounded-full bg-emerald-100 flex items-center justify-center mx-auto mb-5">
      <i class="fa-solid fa-building-columns text-2xl text-emerald-700"></i>
    </div>
    <span class="inline-block px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-semibold uppercase tracking-wide mb-3">Industry · Banking &amp; Finance</span>
    <h1 class="text-4xl sm:text-5xl font-extrabold text-gray-900 mb-4">
      Digital business cards for banks &amp; finance teams
    </h1>
    <p class="text-lg text-gray-600 mb-7 max-w-2xl mx-auto">
      Built for relationship managers, advisors, and finance teams across Oman and the GCC. Bilingual EN/AR, brand-locked templates, and bulk provisioning for hundreds of bankers without the IT ticket backlog.
    </p>
    <div class="flex flex-wrap justify-center gap-3">
      <a href="/get-started" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-blue-600 hover:bg-blue-700 text-white font-semibold shadow-lg shadow-blue-600/30 transition">
        Start a pilot for your team
        <i class="fa-solid fa-arrow-right text-xs"></i>
      </a>
      <a href="/logos/finance" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white border border-gray-300 text-gray-800 font-semibold hover:border-emerald-400 hover:text-emerald-700 transition">
        Browse GCC bank logos
      </a>
    </div>
  </div>
</section>

<!-- WHY IT MATTERS -->
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
  <div class="grid lg:grid-cols-2 gap-12 items-start">
    <div>
      <span class="text-emerald-700 font-semibold text-sm uppercase tracking-wider">Why banks need this</span>
      <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-5">
        Bankers switch branches. Clients throw out old cards.
      </h2>
      <div class="space-y-4 text-gray-700 leading-relaxed">
        <p>
          Retail RMs rotate branches across wilayats. Corporate RMs move between corporate, SME, and private banking desks. Every rotation used to mean a new printed card and a client quietly wondering "wait, what's your number now?"
        </p>
        <p>
          A Cardify digital card updates centrally. When an RM moves from Muscat corporate to Sohar retail, HR updates the title and branch; every existing client who scanned the card gets the new info automatically.
        </p>
        <p>
          For compliance teams, this also means every public touchpoint a bank employee gives out is on-brand, same logo treatment, same approved title, same approved disclosure footer. No rogue signatures from Outlook, no DIY cards printed at Instant Print.
        </p>
      </div>
    </div>
    <div class="bg-gradient-to-br from-emerald-50 to-green-50 rounded-2xl p-6 space-y-3">
      <?php foreach ([
        ['fa-shield-halved',    'Brand-locked templates',  'RMs only edit personal fields; logo, colors, fonts stay under brand team control.',                                       'fa-solid'],
        ['fa-arrows-rotate',    'Instant updates',         'Change a title, a branch, a phone, every client who scanned the card sees the update on their next view.',                'fa-solid'],
        ['fa-whatsapp',         'WhatsApp-first for GCC',  'One tap on the card opens a pre-filled WhatsApp chat, the default channel for client comms region-wide.',                'fa-brands'],
        ['fa-chart-line',       'Scan analytics',          'See how many clients viewed each RM card, when, and from where. Actionable for relationship managers.',                   'fa-solid'],
        ['fa-globe',            'Bilingual EN/AR',         'Every card ships in both languages. Arabic is proper RTL, no font fallback flicker.',                                     'fa-solid'],
        ['fa-users-gear',       'Bulk provisioning',       'Onboard 200 RMs from a spreadsheet. No IT ticket queue.',                                                                 'fa-solid'],
      ] as [$icon, $title, $body, $lib]):
      ?>
        <div class="flex items-start gap-4 p-3 rounded-xl bg-white">
          <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center flex-shrink-0">
            <i class="<?= $lib ?> <?= bankEsc($icon) ?> text-emerald-700"></i>
          </div>
          <div>
            <h3 class="font-semibold text-gray-900"><?= bankEsc($title) ?></h3>
            <p class="text-gray-600 text-sm"><?= bankEsc($body) ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- USE CASES -->
<section class="bg-gray-50 border-y border-gray-100">
  <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <div class="max-w-3xl mb-10">
      <span class="text-emerald-700 font-semibold text-sm uppercase tracking-wider">Where it shines</span>
      <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-3">Used by finance teams in Oman today</h2>
      <p class="text-lg text-gray-600">Patterns we see across commercial banks, investment banks, insurance brokers, and asset managers operating in the Sultanate.</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
      <?php foreach ([
        ['Retail branch networks', 'Every branch manager and customer-service representative carries a branded Cardify card. When a client walks in asking "who helped me last time?", the new staffer can look up the previous card holder in one tap.'],
        ['Corporate &amp; private banking', 'Corporate RMs visiting clients across Ruwi, Al Khuwair, and MBD hand over cards that update automatically when their desk moves. Private banking teams share cards at Oman Economic Forum and CFA Society Oman events.'],
        ['Investment banking roadshows', 'Teams from Oman Arab Capital, UGB, and Bank Muscat Capital issue event-specific cards with panel-by-panel WhatsApp group links pre-embedded. One QR scan = one introduction.'],
        ['Insurance &amp; brokerage',      'Dhofar Insurance, National Life agents, and licensed brokers on MSX need to share FSA license numbers alongside contact info. Cardify templates embed license display cleanly.'],
        ['Fintech teams',                  'Startups in the CBO sandbox ship bilingual cards at launch, founder, head of product, head of compliance, all consistent and easy to version.'],
        ['Event networking',               'Roadshows at Oman Convention Centre or Grand Hyatt: attendees scan a QR at your booth and your card lands in their phone. No business-card stacks lost on the floor.'],
      ] as [$title, $body]): ?>
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <h3 class="font-bold text-gray-900 mb-2"><?= $title ?></h3>
          <p class="text-sm text-gray-600 leading-relaxed"><?= $body ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- LOGOS SHOWCASE -->
<section class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-8">
    <div class="max-w-2xl">
      <span class="text-emerald-700 font-semibold text-sm uppercase tracking-wider">Finance logos library</span>
      <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-2">Omani bank &amp; finance logos, already indexed</h2>
      <p class="text-gray-600">Central Bank of Oman Board, Bank Muscat, Oman Investment Authority, Tax Authority, and more. All in SVG / PNG. Free to download for identification use.</p>
    </div>
    <a href="/logos/finance" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold whitespace-nowrap">
      Open Finance logo library
      <i class="fa-solid fa-arrow-right text-xs"></i>
    </a>
  </div>
  <?php
    try {
      $db = Database::getInstance();
      $bankLogos = $db->fetchAll(
        "SELECT slug, name_en, logo_svg_path, logo_png_path, logo_webp_path, logo_png_512_path
           FROM om_companies
          WHERE sector = 'finance' AND logo_status IN ('indexed','verified')
            AND (logo_svg_path IS NOT NULL OR logo_png_path IS NOT NULL OR logo_webp_path IS NOT NULL)
          ORDER BY FIELD(logo_status,'verified','indexed'), logo_updated_at DESC
          LIMIT 8"
      );
    } catch (Throwable $e) { $bankLogos = []; }
  ?>
  <?php if ($bankLogos): ?>
    <div class="grid grid-cols-4 md:grid-cols-8 gap-3">
      <?php foreach ($bankLogos as $l):
          $src = $l['logo_webp_path'] ?: $l['logo_png_512_path'] ?: $l['logo_png_path'] ?: $l['logo_svg_path'];
      ?>
        <a href="/companies/<?= bankEsc($l['slug']) ?>" class="group aspect-square bg-white border border-gray-200 rounded-xl p-3 flex items-center justify-center hover:border-emerald-300 hover:shadow transition" title="<?= bankEsc($l['name_en']) ?>">
          <img src="<?= bankEsc($src) ?>" alt="<?= bankEsc($l['name_en']) ?> logo" loading="lazy" class="max-h-full max-w-full object-contain">
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<!-- FAQ -->
<section class="bg-gray-50 border-y border-gray-100">
  <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-20">
    <span class="text-emerald-700 font-semibold text-sm uppercase tracking-wider">FAQ</span>
    <h2 class="text-3xl font-extrabold text-gray-900 mt-2 mb-6">Common questions from bank teams</h2>
    <div class="space-y-3">
      <?php foreach ($faq as $i => $q): ?>
        <details class="group bg-white border border-gray-200 rounded-xl hover:border-emerald-300 transition overflow-hidden"<?= $i === 0 ? ' open' : '' ?>>
          <summary class="flex items-center justify-between gap-4 px-5 py-4 cursor-pointer list-none">
            <span class="font-semibold text-gray-900"><?= bankEsc($q['q']) ?></span>
            <i class="fa-solid fa-chevron-down text-gray-400 text-sm transition group-open:rotate-180"></i>
          </summary>
          <div class="px-5 pb-4 text-gray-600 leading-relaxed"><?= bankEsc($q['a']) ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- FINAL CTA + CROSS-LINKS -->
<section class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-20 text-center">
  <h2 class="text-3xl font-extrabold text-gray-900 mb-4">Ready to roll out Cardify across your bank?</h2>
  <p class="text-gray-600 mb-7">Pilot a desk, scale across branches, or deploy group-wide. We'll help onboard your RMs and lock your brand guidelines into every card.</p>
  <div class="flex flex-wrap justify-center gap-3">
    <a href="/get-started" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold shadow-lg shadow-emerald-600/30 transition">
      Start free, add your team
      <i class="fa-solid fa-arrow-right text-xs"></i>
    </a>
    <a href="/tools/email-signature-generator" class="inline-flex items-center gap-2 px-6 py-3 rounded-lg bg-white border border-gray-300 text-gray-800 font-semibold hover:border-emerald-300 hover:text-emerald-700 transition">
      Free email signature tool
    </a>
  </div>
  <div class="mt-10 pt-10 border-t border-gray-200 text-sm text-gray-500 space-x-3">
    <a href="/oman-business-index" class="text-emerald-600 hover:underline">Oman Business Index</a> ·
    <a href="/gcc-business-index" class="text-emerald-600 hover:underline">GCC Business Index</a> ·
    <a href="/blog/gcc-business-identity-registering-branding-going-digital" class="text-emerald-600 hover:underline">GCC business identity guide</a> ·
    <a href="/logos/finance" class="text-emerald-600 hover:underline">Finance logos</a>
  </div>
</section>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
