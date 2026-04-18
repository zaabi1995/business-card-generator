<?php /** @var string $title; */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($title) ?></title>
<link rel="canonical" href="https://cardify.om/logos/press">
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/../../includes/partials/nav.php'; ?>
<main class="max-w-3xl mx-auto px-4 py-10 prose prose-slate">
  <h1>Press Kit — Omani Logo Library</h1>
  <p class="lead">The most comprehensive public archive of Omani brand marks. Free to browse, owner-verifiable, built by Cardify.</p>

  <h2>Fast facts</h2>
  <ul>
    <li><strong>2,400+ Omani companies</strong> indexed with sector, wilayat, and CR metadata.</li>
    <li><strong>Bilingual</strong> — EN + AR names, all pages localizable.</li>
    <li><strong>Verified-on-claim</strong> — brand owners can claim their logo via domain-match auto-verify.</li>
    <li><strong>Takedown within 24h</strong> on prima-facie valid requests.</li>
    <li><strong>Public API</strong> at <code>/api/logos</code> — free, rate-limited, no auth.</li>
  </ul>

  <h2>Screenshots</h2>
  <p>High-resolution screenshots available on request.</p>

  <h2>Press contact</h2>
  <p>
    Email <a href="mailto:press@cardify.om">press@cardify.om</a> ·
    WhatsApp +968 9889 9100 (BHD Group)
  </p>

  <h2>Usage</h2>
  <p>Journalists and researchers may use Cardify-generated screenshots and indexed counts with attribution. Individual company logos remain trademarks of their respective owners and are indexed under nominative/reference use.</p>

  <h2>API quick-start</h2>
  <pre><code>GET https://cardify.om/api/logos/list?per_page=20
GET https://cardify.om/api/logos/show?slug=omantel
GET https://cardify.om/api/logos/sectors
GET https://cardify.om/api/logos/stats</code></pre>
  <p class="text-sm">Rate limit: 60 requests/minute per IP. CORS enabled for read endpoints.</p>
</main>
</body>
</html>
