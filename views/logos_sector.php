<?php
/**
 * @var array  $data
 * @var string $sectorSlug
 * @var string $sectorLabel
 * @var string $title
 * @var string $canonical
 * @var array  $SECTORS
 * @var bool   $isAr
 */
?>
<!DOCTYPE html>
<html lang="<?= $isAr ? 'ar' : 'en' ?>" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($title) ?></title>
<meta name="description" content="<?= esc("Browse {$data['total']} Omani {$sectorLabel} company logos. Indexed, searchable, downloadable on claim.") ?>">
<link rel="canonical" href="<?= esc($canonical) ?>">
<meta property="og:title" content="<?= esc($title) ?>">
<meta property="og:image" content="<?= esc("https://cardify.om/storage/og/logos/{$sectorSlug}.png") ?>">
<meta property="og:url" content="<?= esc($canonical) ?>">
<meta property="og:type" content="website">
<script src="https://cdn.tailwindcss.com"></script>
<script type="application/ld+json">
<?= json_encode([
    "@context" => "https://schema.org",
    "@type"    => "CollectionPage",
    "name"     => $title,
    "url"      => $canonical,
    "breadcrumb" => [
        "@type" => "BreadcrumbList",
        "itemListElement" => [
            ["@type" => "ListItem", "position" => 1, "name" => "Logo Library", "item" => "https://cardify.om/logos"],
            ["@type" => "ListItem", "position" => 2, "name" => $sectorLabel, "item" => $canonical],
        ],
    ],
    "numberOfItems" => $data['total'],
], JSON_UNESCAPED_SLASHES) ?>
</script>
</head>
<body class="bg-gray-50">
<?php include __DIR__ . '/../includes/partials/nav.php'; ?>
<header class="bg-white border-b">
  <div class="max-w-6xl mx-auto px-4 py-8">
    <nav class="text-sm text-gray-500 mb-2">
      <a href="/logos" class="hover:underline"><?= $isAr ? 'مكتبة الشعارات' : 'Logo Library' ?></a> /
      <?= esc($sectorLabel) ?>
    </nav>
    <h1 class="text-3xl font-bold"><?= esc($title) ?></h1>
    <p class="mt-1 text-gray-600">
      <?= $isAr ? 'جميع الشركات في القطاع' : 'Companies in the sector' ?>: <?= number_format($data['total']) ?>
    </p>
  </div>
</header>
<main class="max-w-6xl mx-auto px-4 py-8">
  <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
    <?php foreach ($data['rows'] as $r): $badge = LogoLibrary::statusBadge($r['logo_status']); ?>
      <a href="/companies/<?= esc($r['slug']) ?>"
         class="block bg-white rounded-lg border hover:shadow-md overflow-hidden">
        <div class="aspect-square flex items-center justify-center p-4"
             style="background: <?= esc($r['logo_dominant_color'] ?: '#f9fafb') ?>15">
          <?php
            $src = $r['logo_webp_path']
                 ?: $r['logo_png_512_path']
                 ?: $r['logo_png_path']
                 ?: $r['logo_svg_path'];
          ?>
          <?php if ($src): ?>
            <img src="<?= esc($src) ?>" alt="<?= esc($r['name_en']) ?>"
                 loading="lazy" class="max-h-full max-w-full object-contain">
          <?php else: ?>
            <div class="text-gray-400 text-xl"><?= esc(mb_substr($r['name_en'], 0, 2)) ?></div>
          <?php endif; ?>
        </div>
        <div class="p-2">
          <div class="text-xs font-medium truncate">
            <?= esc($isAr ? $r['name_ar'] : $r['name_en']) ?>
          </div>
          <div class="text-[10px]" style="color: <?= esc($badge['color']) ?>">
            <?= esc($badge['label']) ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php
    $page       = $data['page'];
    $totalPages = (int) ceil(max(1, $data['total']) / $data['per_page']);
    if ($totalPages > 1):
  ?>
    <nav class="mt-8 flex justify-center gap-2" aria-label="<?= $isAr ? 'الصفحات' : 'Pagination' ?>">
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++):
            $qs = $_GET; $qs['page'] = $p; ?>
        <a href="?<?= http_build_query($qs) ?>"
           class="px-3 py-1 rounded <?= $p === $page ? 'bg-cyan-600 text-white' : 'bg-white border' ?>">
          <?= $p ?>
        </a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>

  <p class="mt-8 text-sm text-gray-600">
    <?= $isAr ? 'استكشف أيضاً' : 'Explore also' ?>:
    <a href="/logos" class="underline text-cyan-700">
      <?= $isAr ? 'المكتبة كاملة' : 'the full library' ?>
    </a> ·
    <a href="/oman-business-index" class="underline text-cyan-700">Oman Business Index</a>
  </p>
</main>
</body>
</html>
