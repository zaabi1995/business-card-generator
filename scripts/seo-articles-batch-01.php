<?php
/**
 * One-shot: insert 2 SEO-targeted blog posts for the global-SEO loop.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require '/www/wwwroot/cardify.om/config.php';

$posts = [

// ─── Article 1 — Omani logo download guide ────────────────────────────
[
'title'   => 'How to Download Omani Government Logos — The Complete Guide (2026)',
'slug'    => 'download-omani-government-logos-complete-guide-2026',
'excerpt' => 'A practical guide for journalists, researchers, and designers: where to find official Omani ministry logos, what licenses apply, and how to download them in SVG and PNG through the Omani Logo Library.',
'content' => <<<'HTML'
<p class="lead">If you've ever needed the Ministry of Commerce logo for a research paper, the Oman Investment Authority mark for an investor deck, or the Asyad Group emblem for a press article — you've probably hit the same wall most professionals in Oman hit: there's no single, clean, downloadable source.</p>

<p>The <a href="/logos">Omani Logo Library</a> was built to fix that. This guide walks through where each type of Omani logo comes from, which licenses apply, and the fastest way to download what you need in SVG or PNG.</p>

<h2>What's in the Omani Logo Library</h2>

<p>As of this writing, the library covers <strong>the Omani brand marks indexed so far</strong>, spanning:</p>

<ul>
  <li><strong>19 ministries</strong> of the Sultanate of Oman — Defence, Foreign Affairs, Finance, Health, Education, Interior, Commerce Industry and Investment Promotion, Transport Communications and IT, Energy and Minerals, Heritage and Tourism, and more. Each includes the national emblem (khanjar and crossed swords) in its approved composition.</li>
  <li><strong>Sovereign and regulatory bodies</strong> — <a href="/companies/oman-investment-authority-8019">Oman Investment Authority (OIA)</a>, Central Bank of Oman, Tax Authority, State Financial and Administrative Audit Authority, National Centre for Statistics and Information (NCSI), Oman Vision 2040 Implementation Follow-Up Unit.</li>
  <li><strong>State-owned corporates</strong> — ASYAD Group (the logistics holding), Sohar Port and Freezone, Oman Chamber of Commerce &amp; Industry, Oman Medical Specialty Board.</li>
  <li><strong>Commercial brands</strong> — Omantel, Ooredoo, Bank Muscat, NBO, Majid Al Futtaim's Oman presence, Centerpoint, Holiday Inn Oman, and dozens more indexed from public sources.</li>
</ul>

<p>Every logo has a dedicated profile page at <code>/companies/{slug}</code> showing its canonical mark, available formats, dimensions, dominant color, and last-updated timestamp.</p>

<h2>The three quickest ways to download an Omani logo</h2>

<h3>1. Search the library directly</h3>

<p>Start at <a href="/logos">cardify.om/logos</a>. The search box accepts both English and Arabic names — typing "وزارة" or "Ministry" both work. Filter by sector (Government &amp; Defense, Finance &amp; Banking, Logistics &amp; Shipping, etc.) or use "Verified only" to see logos claimed and confirmed by their brand owners.</p>

<h3>2. Jump directly to a sector</h3>

<p>If you know you need a government logo, go straight to <a href="/logos/government-defense">/logos/government-defense</a>. Other useful sector pages:</p>

<ul>
  <li><a href="/logos/finance">Finance &amp; Banking</a> — CBO Board, OIA, Bank Muscat, etc.</li>
  <li><a href="/logos/logistics-shipping">Logistics &amp; Shipping</a> — Asyad, Sohar Port, Oman Shipping.</li>
  <li><a href="/logos/telecom">Telecommunications</a> — Omantel, Ooredoo, Vodafone Oman.</li>
  <li><a href="/logos/healthcare">Healthcare</a> — Royal Hospital (via MoH), Oman Medical Specialty Board.</li>
</ul>

<h3>3. Use the public JSON API</h3>

<p>If you're automating a workflow or building a dashboard, the library exposes a free, rate-limited API with no authentication:</p>

<pre><code>GET https://cardify.om/api/logos/list?sector=government-defense&amp;per_page=50
GET https://cardify.om/api/logos/show?slug=oman-investment-authority-8019
GET https://cardify.om/api/logos/sectors
GET https://cardify.om/api/logos/stats</code></pre>

<p>Responses include direct URLs to each logo's SVG and PNG sizes, dominant color metadata, and a pointer back to the canonical page. Rate limit is 60 requests per minute per IP. See the <a href="/logos/press">press kit</a> for full API documentation.</p>

<h2>Which format should you download?</h2>

<p>It depends what you're doing with the logo:</p>

<ul>
  <li><strong>SVG</strong> — Print, pitch decks, any context where the logo will be resized. SVG is vector, so it stays sharp at any scale. Most library logos are available as SVG.</li>
  <li><strong>PNG 2048</strong> — Large-format web use, hero images, large banners. Transparent background, lossless.</li>
  <li><strong>PNG 1024</strong> — Default for most web use cases. Balances quality and file size.</li>
  <li><strong>PNG 512</strong> — Social media thumbnails, grid tiles, app icons.</li>
  <li><strong>WebP</strong> — Modern web display only. Smaller file size than PNG with comparable quality. Not all email clients support WebP.</li>
  <li><strong>ZIP bundle</strong> — Contains every available format for a logo plus a README with attribution. Useful when you're doing brand work across multiple outputs.</li>
</ul>

<h2>What you can and can't do with these logos</h2>

<p>The Omani Logo Library operates under <strong>nominative fair use</strong> — the same posture as Wikipedia, Brands of the World, and established logo archives. This is a reference archive, not a licensing agency.</p>

<p><strong>Permitted:</strong></p>
<ul>
  <li>Identification — showing a logo to identify the entity it represents (journalism, research, case studies, internal documents).</li>
  <li>Reference — linking to or displaying logos in lists, directories, analysis papers.</li>
  <li>Educational and non-commercial use with attribution.</li>
</ul>

<p><strong>Requires permission from the owner:</strong></p>
<ul>
  <li>Commercial reuse — putting a logo on merchandise, ads, or products you sell.</li>
  <li>Implying endorsement or partnership.</li>
  <li>Derivative works — modifying the logo or incorporating it into a new brand.</li>
</ul>

<p>All marks remain the property of their respective owners. The library publishes logos in good faith; brand owners can request removal via the <a href="/logo-takedown">takedown form</a>, and valid requests are hidden within 24 hours.</p>

<h2>Claiming your company's logo</h2>

<p>If you represent an Omani company with a logo in the library, you can claim your profile to unlock:</p>

<ul>
  <li>A "Verified" green badge instead of the standard "Indexed" label.</li>
  <li>Direct upload access — replace the indexed version with an official SVG from your brand team.</li>
  <li>Download analytics — see how often your logo is being pulled.</li>
  <li>The ability to correct profile data (name variants, sector, summary).</li>
</ul>

<p>To claim, open your profile and click "Claim this logo". Sign in with a company email. If the domain matches your <code>website_domain_cache</code>, verification is instant. Otherwise, upload a CR document (PDF/JPG/PNG, up to 5 MB) and our team reviews within 48 hours.</p>

<h2>The bigger picture: why Cardify built this</h2>

<p>Cardify is a <a href="https://cardify.om">business identity platform</a> based in Oman. We rely on clean, accurate company and brand data every day to help professionals network, issue digital cards, and run team rollouts. Publishing the <a href="/oman-business-index">Oman Business Index</a> (2,415 companies) and the <a href="/logos">Omani Logo Library</a> back to the community is the research contribution that comes out of building that product.</p>

<p>The library is part of a larger federation: the <a href="/gcc-business-index">GCC Business Index 2026</a>, which is rolling out to cover Saudi Arabia, UAE, Qatar, Bahrain, and Kuwait under the same methodology through 2026.</p>

<h2>Quick links</h2>

<ul>
  <li><a href="/logos">Browse the Omani Logo Library</a></li>
  <li><a href="/logos/government-defense">Government &amp; Defense sector</a></li>
  <li><a href="/oman-business-index">Oman Business Index</a></li>
  <li><a href="/gcc-business-index">GCC Business Index 2026</a></li>
  <li><a href="/logos/terms">Terms of use</a></li>
  <li><a href="/logos/press">Press kit and API docs</a></li>
  <li><a href="/logo-takedown">Request a takedown</a></li>
</ul>
HTML
],

// ─── Article 2 — GCC business identity guide ──────────────────────────
[
'title'   => 'GCC Business Identity: A Guide to Registering, Branding, and Going Digital',
'slug'    => 'gcc-business-identity-registering-branding-going-digital',
'excerpt' => 'A practical overview of how company registration, brand identity, and digital presence work across the six GCC states — for founders, marketers, and cross-border operators.',
'content' => <<<'HTML'
<p class="lead">The Gulf Cooperation Council (GCC) is six countries, six commercial registers, and dozens of free zones — but one integrated market with shared customs, similar corporate governance patterns, and a common trajectory toward digital-first business identity. This guide walks through the practical essentials of registering, branding, and going digital anywhere in the GCC.</p>

<h2>The six markets at a glance</h2>

<p>Before diving into mechanics, it helps to have the scale in context. These are the six GCC states sorted by nominal GDP:</p>

<ul>
  <li><strong>Saudi Arabia</strong> — $1,067 billion GDP, 33 million people. The largest market by a wide margin. Commercial Register via Ministry of Commerce; additional licensing for regulated sectors via SAMA (financial) and CMA (capital markets).</li>
  <li><strong>United Arab Emirates</strong> — $508 billion, 10.2 million. Seven emirates, each with its own DED (Department of Economic Development), plus 40+ free zones operating under their own authority rules.</li>
  <li><strong>Qatar</strong> — $221 billion, 3.1 million. MoCI (Ministry of Commerce and Industry) runs mainland; QFC and QFZA are the major free zones.</li>
  <li><strong>Kuwait</strong> — $162 billion, 4.3 million. MoCI Kuwait plus KDIPA for foreign investment.</li>
  <li><strong>Oman</strong> — $115 billion, 5 million. MoCIIP operates the register and Invest Oman as the investment agency. See the <a href="/oman-business-index">Oman Business Index</a> for full coverage.</li>
  <li><strong>Bahrain</strong> — $43 billion, 1.5 million. Sijilat is the most open commercial-register platform in the region by some margin.</li>
</ul>

<p>The full comparison with current GDP, population, and company-count data is maintained at <a href="/gcc-business-index">cardify.om/gcc-business-index</a>, updated quarterly.</p>

<h2>Registering a company in the GCC</h2>

<p>Every GCC state lets you incorporate, but the specifics vary. Four rough patterns apply across the region:</p>

<h3>1. Mainland company</h3>

<p>Registered directly with the national commerce ministry. Typically allows you to trade across the whole country without zone restrictions. In most GCC states, mainland formation historically required local sponsorship — that requirement has loosened substantially since 2020, most notably in the UAE (100% foreign ownership in most activities) and Oman (via the Foreign Capital Investment Law 2020).</p>

<h3>2. Free-zone company</h3>

<p>Registered with a specific free-zone authority (DMCC, ADGM, DIFC, DAFZA, KIZAD, Madayn, Duqm SEZ, etc.). Typically offers 100% foreign ownership, customs benefits, and sector clustering. Trade inside the free zone or internationally is straightforward; trade into the local mainland usually requires a local distributor or commercial agent.</p>

<h3>3. Professional license</h3>

<p>For freelancers, consultants, lawyers, and other individual service providers. Faster to set up, lower capital requirements. Available in most GCC states under slightly different names.</p>

<h3>4. Branch office</h3>

<p>A foreign parent registers a GCC branch. No separate legal personality; the parent bears liability. Common for banks, law firms, and international service providers that want GCC presence without a subsidiary.</p>

<p>The chosen structure determines what you show on your commercial-register entry, how you appear in public databases like the <a href="/oman-business-index">Oman Business Index</a>, and what activities you're licensed to perform.</p>

<h2>Building brand identity that travels</h2>

<p>Once registered, the brand identity decisions that pay off across the GCC are fairly consistent:</p>

<h3>Bilingual Arabic and English from day one</h3>

<p>Not as a translation afterthought. The Arabic treatment needs to work at the same quality bar as the English one — same logo weight, same ligature tuning, same kerning discipline. Professional Arabic type work from a designer who actually reads the language is the difference between a brand that feels regional and one that feels transplanted.</p>

<p>Every profile in the <a href="/logos">Omani Logo Library</a> preserves both names, so search works naturally for "وزارة المالية" or "Ministry of Finance".</p>

<h3>A logo system, not a logo</h3>

<p>Horizontal primary. Stacked secondary. Icon-only mark for avatars. Reverse/monochrome variants for print. A clear-space rule and minimum-size spec in a simple one-page brand sheet. This is what good regional brands (OQ, Asyad, OIA, Bank Muscat, Emirates Airline) have in common. A single PNG pasted into a deck template is a <em>logo</em>; what you actually need is a system.</p>

<h3>Name that works in both alphabets</h3>

<p>Pronunciation matters. "Be'ah" (بيئة), "Madayn" (مدائن), "Nama" (نماء) — these are brand names that work phonetically in both languages. When you choose a name, consider how it transliterates and whether the Arabic version carries the meaning or vibe you want.</p>

<h3>Clean digital brand kit</h3>

<p>SVG for anything that scales. PNG at 2048, 1024, 512 for when SVG isn't accepted (Outlook email signatures, some older CRMs). A favicon and a square avatar derived from the icon mark. High-resolution executive headshots if founder-led. This is the kit Cardify's platform helps teams package and distribute — but the principle applies whether you use us or build it yourself.</p>

<h2>Going digital: the 2026 floor</h2>

<p>What "going digital" means in the GCC has changed. In 2020 it mostly meant having a website. In 2026 the floor is much higher:</p>

<ul>
  <li><strong>Every person on the team has a digital business card.</strong> QR codes save contacts directly to a phone — no typing, no misspelled names. Cardify's <a href="/tools/vcard-qr-generator">free vCard QR generator</a> gives you the basic version in 30 seconds.</li>
  <li><strong>Email signatures are bilingual and standardized.</strong> Not everyone reverts to their personal Gmail style. Centralized signature management is common in GCC corporates; smaller teams can use the <a href="/tools/email-signature-generator">email signature generator</a>.</li>
  <li><strong>WhatsApp Business is a real channel.</strong> A <a href="/tools/whatsapp-qr-generator">WhatsApp QR</a> on a printed shopfront or trade-show booth opens a chat with a pre-filled message. Response expectations in the GCC are tighter than most Western markets — typically &lt;1 hour during business hours.</li>
  <li><strong>Printed cards still matter.</strong> Especially in government and oil/gas — handing over a physical card is still a courtesy signal. The shift is that the printed card now carries a QR that opens the digital version, not just a phone number.</li>
  <li><strong>NFC cards are gaining ground.</strong> Tap to share. Especially common in sales-heavy industries (real estate, automotive, financial services).</li>
</ul>

<h2>Where Cardify fits</h2>

<p><a href="https://cardify.om">Cardify</a> is the platform for team-scale digital business cards in the GCC. Built in Oman, operated regionally, bilingual by default. Core workflow:</p>

<ol>
  <li>Upload your brand (logo SVG, colors, fonts).</li>
  <li>Invite your team.</li>
  <li>Each person's card renders consistently with their name, role, contact info, and QR.</li>
  <li>Order matching printed cards from local GCC print shops through the platform.</li>
</ol>

<p>For individuals and small teams, the <a href="/tools">free tools</a> handle the basics without an account. For organizations, the <a href="/get-started">full platform</a> layers on team management, analytics, and bulk printing.</p>

<h2>Further reading</h2>

<ul>
  <li><a href="/gcc-business-index">GCC Business Index 2026</a> — full country-by-country coverage</li>
  <li><a href="/oman-business-index">Oman Business Index</a> — 2,415 Omani enterprises, free and indexed</li>
  <li><a href="/logos">Omani Logo Library</a> — brand marks, searchable, SVG + PNG</li>
  <li><a href="/blog/download-omani-government-logos-complete-guide-2026">How to Download Omani Government Logos — 2026 Guide</a></li>
</ul>
HTML
],

];

// ─── Insert ───────────────────────────────────────────────────────────
$db = Database::getInstance();
$pdo = $db->getConnection();

foreach ($posts as $p) {
    // Skip if slug already exists
    $existing = $db->fetchOne("SELECT id FROM blog_posts WHERE slug = ?", [$p['slug']]);
    if ($existing) {
        printf("  · %s — already exists (id=%d), skip\n", $p['slug'], $existing['id']);
        continue;
    }
    $pdo->prepare(
        "INSERT INTO blog_posts (title, slug, excerpt, content, status, author_name, published_at, created_at, updated_at)
         VALUES (:t, :s, :e, :c, 'published', 'Cardify Editorial', NOW(), NOW(), NOW())"
    )->execute([
        ':t' => $p['title'],
        ':s' => $p['slug'],
        ':e' => $p['excerpt'],
        ':c' => $p['content'],
    ]);
    $id = $pdo->lastInsertId();
    printf("  ✓ %s — id=%d\n", $p['slug'], $id);
}
echo "done\n";
