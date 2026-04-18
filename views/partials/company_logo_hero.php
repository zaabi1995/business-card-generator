<?php
/**
 * Logo hero section for /companies/{slug} pages.
 *
 * @var array $company  om_companies row with logo_* fields
 * @var bool  $isAr
 */
if (!class_exists('LogoLibrary')) {
    require_once __DIR__ . '/../../includes/LogoLibrary.php';
}

// Partial is included from both companies.php (defines escq()) and logos.php
// (defines esc()). Provide a local alias so either entry point works.
if (!function_exists('logo_hero_esc')) {
    function logo_hero_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

$status = $company['logo_status'] ?? 'none';
$badge  = LogoLibrary::statusBadge($status);
$src    = $company['logo_webp_path']
       ?: $company['logo_png_path']
       ?: $company['logo_svg_path']
       ?: $company['logo_png_512_path']
       ?: null;
$bg = $company['logo_dominant_color'] ?: '#f8fafc';
$canDownload = LogoLibrary::canDownload($company);

// Only render hero if there's actually something to show or a claim CTA to offer
if (!$src && $status === 'takedown') return;
?>
<section class="my-8 rounded-2xl overflow-hidden border" style="background: <?= logo_hero_esc($bg) ?>12">
  <div class="flex flex-col md:flex-row items-center gap-6 p-6 md:p-8">
    <div class="w-48 h-48 flex items-center justify-center bg-white rounded-xl shadow-sm flex-shrink-0">
      <?php if ($src): ?>
        <img src="<?= logo_hero_esc($src) ?>" alt="<?= logo_hero_esc($company['name_en']) ?> logo"
             class="max-h-full max-w-full object-contain">
      <?php else: ?>
        <div class="text-gray-400 text-4xl font-bold">
          <?= logo_hero_esc(mb_substr($company['name_en'] ?? '??', 0, 2)) ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="flex-1 text-center md:text-left">
      <div class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
           style="color: <?= logo_hero_esc($badge['color']) ?>; background: <?= logo_hero_esc($badge['color']) ?>15">
        <span class="w-1.5 h-1.5 rounded-full" style="background: <?= logo_hero_esc($badge['color']) ?>"></span>
        <?= logo_hero_esc($badge['label']) ?>
      </div>
      <h2 class="text-2xl font-bold mt-2">
        <?= logo_hero_esc($isAr ? ($company['name_ar'] ?? '') : ($company['name_en'] ?? '')) ?>
      </h2>
      <?php if ($src): ?>
        <div class="mt-4 flex flex-wrap gap-2 justify-center md:justify-start">
          <?php if ($canDownload): ?>
            <?php
              $formats = [
                  'svg'      => ['Download SVG',  'logo_svg_path'],
                  'png_512'  => ['PNG 512',       'logo_png_512_path'],
                  'png_1024' => ['PNG 1024',      'logo_png_path'],
                  'png_2048' => ['PNG 2048',      'logo_png_2048_path'],
                  'zip'      => ['ZIP bundle',    null],
              ];
              foreach ($formats as $fmt => [$label, $col]):
                $available = $fmt === 'zip' ? true : !empty($company[$col] ?? null);
            ?>
              <?php if ($available): ?>
                <a href="/logo-download?company=<?= (int) ($company['id'] ?? 0) ?>&format=<?= logo_hero_esc($fmt) ?>"
                   class="px-3 py-1.5 text-sm bg-white border rounded-lg hover:bg-gray-50">
                  <?= logo_hero_esc($label) ?>
                </a>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php else: ?>
            <a href="/logo-claim?company=<?= (int) ($company['id'] ?? 0) ?>"
               class="px-4 py-2 bg-cyan-600 text-white rounded-lg text-sm font-medium">
              <?= $isAr ? 'احصل على ملكية هذا الشعار' : 'Claim this logo' ?>
            </a>
            <span class="text-xs text-gray-500 self-center">
              <?= $isAr ? 'التنزيل متاح بعد التوثيق' : 'Downloads available after verification' ?>
            </span>
          <?php endif; ?>
          <a href="/logo-takedown?company=<?= (int) ($company['id'] ?? 0) ?>"
             class="text-xs text-gray-500 underline self-center">
            <?= $isAr ? 'إبلاغ / إزالة' : 'Report / takedown' ?>
          </a>
        </div>
      <?php else: ?>
        <div class="mt-4">
          <a href="/logo-claim?company=<?= (int) ($company['id'] ?? 0) ?>"
             class="inline-block px-4 py-2 bg-cyan-600 text-white rounded-lg text-sm font-medium">
            <?= $isAr ? 'أضف شعار هذه الشركة' : 'Add a logo for this company' ?>
          </a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>
