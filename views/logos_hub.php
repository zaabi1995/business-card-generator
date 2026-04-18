<?php
/**
 * @var array  $data       ['rows'=>…, 'total'=>…, 'page'=>…, 'per_page'=>…]
 * @var array  $counts     Sector counts
 * @var int    $total      Total indexed+verified logos
 * @var string $title
 * @var string $canonical
 * @var array  $SECTORS    slug => label
 * @var bool   $isAr
 */
?>
<!DOCTYPE html>
<html lang="<?= $isAr ? 'ar' : 'en' ?>" dir="<?= $isAr ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc($title) ?></title>
<meta name="description" content="<?= esc(
    ($isAr ? 'أرشيف عام لأكثر من ' : 'A public archive of ') .
    number_format($total) .
    ($isAr ? ' علامة تجارية عمانية.' : ' Omani brand marks. Free to browse, owners can claim.')
) ?>">
<link rel="canonical" href="<?= esc($canonical) ?>">
<meta property="og:title" content="<?= esc($title) ?>">
<meta property="og:image" content="https://cardify.om/storage/og/logos/hub.png">
<meta property="og:url" content="<?= esc($canonical) ?>">
<meta property="og:type" content="website">
<script src="https://cdn.tailwindcss.com"></script>
<script type="application/ld+json">
<?= json_encode([
    "@context"    => "https://schema.org",
    "@type"       => "CollectionPage",
    "name"        => $title,
    "url"         => $canonical,
    "description" => "Public archive of Omani company logos, indexed from public sources and verified by owners.",
    "numberOfItems" => $total,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
</head>
<body class="bg-gray-50 text-gray-900">
<?php include __DIR__ . '/../includes/partials/nav.php'; ?>

<header class="bg-white border-b">
  <div class="max-w-6xl mx-auto px-4 py-10">
    <h1 class="text-4xl font-bold">
      <?= $isAr ? 'مكتبة الشعارات العمانية' : 'The Omani Logo Library' ?>
    </h1>
    <p class="mt-2 text-gray-600 text-lg">
      <?= $isAr
        ? 'أرشيف عام لأكثر من ' . number_format($total) . ' علامة تجارية عمانية. مفهرسة ومفتوحة للتصفح.'
        : number_format($total) . '+ Omani brand marks. Indexed, searchable, ownership-verifiable.'
      ?>
    </p>
    <form method="get" class="mt-6 flex flex-wrap gap-2">
      <input type="search" name="q" value="<?= esc($_GET['q'] ?? '') ?>"
             placeholder="<?= $isAr ? 'ابحث عن شركة' : 'Search a company…' ?>"
             class="flex-1 min-w-[200px] px-4 py-2 border rounded-lg">
      <select name="sector" class="px-3 py-2 border rounded-lg">
        <option value=""><?= $isAr ? 'كل القطاعات' : 'All sectors' ?></option>
        <?php foreach ($SECTORS as $slug => $label): ?>
          <option value="<?= esc($slug) ?>" <?= ($_GET['sector'] ?? '') === $slug ? 'selected' : '' ?>>
            <?= esc($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <label class="inline-flex items-center gap-1 px-3 py-2 border rounded-lg">
        <input type="checkbox" name="verified" value="1" <?= !empty($_GET['verified']) ? 'checked' : '' ?>>
        <?= $isAr ? 'موثّقة فقط' : 'Verified only' ?>
      </label>
      <button class="px-4 py-2 bg-cyan-600 text-white rounded-lg">
        <?= $isAr ? 'تصفية' : 'Filter' ?>
      </button>
    </form>
  </div>
</header>

<main class="max-w-6xl mx-auto px-4 py-8">
  <p class="text-sm text-gray-500 mb-4">
    <?= number_format($data['total']) ?> <?= $isAr ? 'نتيجة' : 'results' ?>
  </p>
  <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3">
    <?php foreach ($data['rows'] as $r): $badge = LogoLibrary::statusBadge($r['logo_status']); ?>
      <a href="/companies/<?= esc($r['slug']) ?>"
         class="group block bg-white rounded-lg border hover:shadow-md transition overflow-hidden">
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
            <div class="text-gray-400 text-2xl font-bold">
              <?= esc(mb_substr($r['name_en'], 0, 2)) ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="p-2">
          <div class="text-xs font-medium truncate">
            <?= esc($isAr ? $r['name_ar'] : $r['name_en']) ?>
          </div>
          <div class="text-[10px] mt-0.5" style="color: <?= esc($badge['color']) ?>">
            <?= esc($badge['label']) ?>
          </div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>

  <?php
    $totalPages = (int) ceil(max(1, $data['total']) / $data['per_page']);
    if ($totalPages > 1):
  ?>
    <nav class="mt-8 flex justify-center gap-2">
      <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++):
            $qs = $_GET; $qs['page'] = $p; ?>
        <a href="?<?= http_build_query($qs) ?>"
           class="px-3 py-1 rounded <?= $p === $page ? 'bg-cyan-600 text-white' : 'bg-white border' ?>">
          <?= $p ?>
        </a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
</main>

<footer class="bg-white border-t mt-12">
  <div class="max-w-6xl mx-auto px-4 py-8 text-sm text-gray-500">
    <p>
      <?= $isAr
        ? 'جميع العلامات التجارية مملوكة لأصحابها. مُفهرسة للتعريف فقط.'
        : 'All marks are property of their respective owners. Indexed for identification only.'
      ?>
    </p>
    <p class="mt-2">
      <a href="/logos/terms" class="underline"><?= $isAr ? 'الشروط' : 'Terms' ?></a> ·
      <a href="/logos/press" class="underline">Press</a> ·
      <a href="/logo-takedown" class="underline"><?= $isAr ? 'طلب إزالة' : 'Takedown request' ?></a>
    </p>
    <p class="mt-2 text-xs">
      Seeded in part from publicly indexed sources including 2oman.net.
    </p>
  </div>
</footer>
</body>
</html>
