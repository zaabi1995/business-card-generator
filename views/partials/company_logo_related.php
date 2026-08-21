<?php
/**
 * "More logos from {sector}" strip. Renders below the About section on
 * /companies/{slug} when the company has an indexed/verified logo.
 *
 * @var array  $company
 * @var bool   $isAr
 * @var array  $SECTORS
 * @var string $basePrefix
 * @var Database $db  (from companies.php scope)
 */
if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }
if (!function_exists('logos_related_esc')) {
    function logos_related_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

$status = $company['logo_status'] ?? 'none';
if (!in_array($status, ['indexed', 'verified'], true)) return;

$sectorSlug  = $company['sector'];
$sectorLabel = $SECTORS[$sectorSlug][$isAr ? 'ar' : 'en'] ?? ucfirst(str_replace('-', ' ', $sectorSlug));
$currentSlug = $company['slug'] ?? '';

try {
    $related = $db->fetchAll(
        "SELECT slug, name_en, name_ar,
                logo_svg_path, logo_png_path, logo_png_512_path, logo_webp_path,
                logo_dominant_color, logo_status
           FROM om_companies
          WHERE sector = :s
            AND slug  != :cur
            AND logo_status IN ('indexed','verified')
            AND (logo_svg_path IS NOT NULL
                 OR logo_png_path IS NOT NULL
                 OR logo_webp_path IS NOT NULL)
          ORDER BY FIELD(logo_status,'verified','indexed'), name_en ASC, slug ASC
          LIMIT 12",
        [':s' => $sectorSlug, ':cur' => $currentSlug]
    );
} catch (Throwable $e) { return; }
if (!$related) return;
?>

<section class="mt-10 pt-8 border-t border-gray-200">
    <div class="flex items-end justify-between mb-5 gap-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900">
                <?= $isAr ? "المزيد من شعارات {$sectorLabel}" : "More {$sectorLabel} logos" ?>
            </h2>
            <p class="mt-1 text-sm text-gray-500">
                <?= $isAr ? 'من نفس القطاع في مكتبة الشعارات العمانية.' : 'From the same sector in the Omani Logo Library.' ?>
            </p>
        </div>
        <a href="<?= logos_related_esc(ArTwins::navLink('logos/' . $sectorSlug, '/', $isAr)) ?>"
           class="hidden sm:inline-flex items-center gap-1.5 text-sm text-blue-600 hover:text-blue-700 font-medium whitespace-nowrap">
            <?= $isAr ? 'عرض القطاع كاملاً' : 'View full sector' ?>
            <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?> text-xs"></i>
        </a>
    </div>

    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 gap-2.5">
        <?php foreach ($related as $r):
            $src = $r['logo_webp_path']
                ?: $r['logo_png_512_path']
                ?: $r['logo_png_path']
                ?: $r['logo_svg_path'];
            $bg = $r['logo_dominant_color'] ?: '#f9fafb';
        ?>
            <a href="<?= logos_related_esc($basePrefix . '/' . $r['slug']) ?>"
               class="group block bg-white rounded-lg border border-gray-200 hover:border-blue-300 hover:shadow-md transition overflow-hidden">
                <div class="aspect-square flex items-center justify-center p-3 bg-gradient-to-br from-gray-50 to-white">
                    <img src="<?= logos_related_esc($src) ?>"
                         alt="<?= logos_related_esc($r['name_en']) ?> logo"
                         loading="lazy"
                         class="max-h-full max-w-full object-contain">
                </div>
                <div class="px-2 pb-2">
                    <div class="text-[11px] font-medium text-gray-700 truncate text-center">
                        <?= logos_related_esc($isAr ? ($r['name_ar'] ?: $r['name_en']) : $r['name_en']) ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="mt-5 sm:hidden">
        <a href="<?= logos_related_esc(ArTwins::navLink('logos/' . $sectorSlug, '/', $isAr)) ?>"
           class="inline-flex items-center gap-2 text-sm text-blue-600 hover:text-blue-700 font-medium">
            <?= $isAr ? 'عرض القطاع كاملاً' : 'View full sector' ?>
            <i class="fa-solid fa-arrow-<?= $isAr ? 'left' : 'right' ?> text-xs"></i>
        </a>
    </div>
</section>
