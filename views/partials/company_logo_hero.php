<?php
/**
 * Logo hero section for /companies/{slug} pages. Slots into companies.php
 * (which uses canonical Cardify chrome) between the header card and the
 * summary content. Styling matches design-showcase: rounded-2xl, gray-200
 * borders, blue-600 primary, emerald-600 verified, subtle shadows.
 *
 * @var array $company  om_companies row with logo_* fields
 * @var bool  $isAr
 */
if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }
if (!class_exists('LogoLibrary')) {
    require_once __DIR__ . '/../../includes/LogoLibrary.php';
}

if (!function_exists('logo_hero_esc')) {
    function logo_hero_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

$status  = $company['logo_status'] ?? 'none';

// Auto-flip to dark monochrome variant when the original would render
// invisibly on the light page background (e.g. white wordmark). Same
// heuristic + priority chain as the /logos grid.
$_palette = json_decode((string) ($company['logo_palette'] ?? ''), true) ?: null;
$_useDarkVar = LogoLibrary::shouldUseDarkVariantOnLight($_palette)
               && !empty($company['logo_webp_dark_path'] ?? $company['logo_png_dark_path'] ?? $company['logo_svg_dark_path']);
if ($_useDarkVar) {
    $src = $company['logo_webp_dark_path']
        ?: $company['logo_png_dark_path']
        ?: $company['logo_svg_dark_path'];
} else {
    $src = $company['logo_webp_path']
        ?: $company['logo_png_path']
        ?: $company['logo_svg_path']
        ?: $company['logo_png_512_path']
        ?: null;
}
// Bust Cloudflare's 30-day immutable cache on retrims/refreshes
if ($src && !empty($company['logo_updated_at'])) {
    $src .= '?v=' . dbTs($company['logo_updated_at']);
}
$bg           = $company['logo_dominant_color'] ?: '#f8fafc';
$canDownload  = LogoLibrary::canDownload($company);

// Hide entirely on takedown
if ($status === 'takedown') return;

// Status pill (matches design-showcase badge pattern)
$badge = match ($status) {
    'verified' => ['label' => $isAr ? 'موثَّق' : 'Verified', 'ring' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'dot' => 'bg-emerald-500'],
    'indexed'  => ['label' => $isAr ? 'مفهرس'  : 'Indexed',  'ring' => 'bg-blue-50 text-blue-700 ring-blue-200',         'dot' => 'bg-blue-500'],
    'pending'  => ['label' => $isAr ? 'قيد المراجعة' : 'Pending review', 'ring' => 'bg-amber-50 text-amber-700 ring-amber-200', 'dot' => 'bg-amber-500'],
    'disputed' => ['label' => $isAr ? 'متنازع عليه' : 'Disputed', 'ring' => 'bg-rose-50 text-rose-700 ring-rose-200',    'dot' => 'bg-rose-500'],
    default    => ['label' => $isAr ? 'بدون شعار' : 'No logo',   'ring' => 'bg-gray-50 text-gray-600 ring-gray-200',      'dot' => 'bg-gray-400'],
};

$companyId = (int) ($company['id'] ?? 0);
?>

<section class="mt-8 mb-10 bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
    <!-- Tinted band using dominant color, kept subtle -->
    <div class="h-1.5" style="background: <?= logo_hero_esc($bg) ?>"></div>

    <div class="p-6 md:p-8">
        <div class="flex flex-col md:flex-row gap-6">

            <?php
                // Brandfetch-style dual preview: show the logo on a LIGHT
                // tile and a DARK tile side-by-side when we have a matching
                // monochrome variant for the inverse side. Falls back to a
                // single tile when no variants exist.
                $_hasLightVar = !empty($company['logo_webp_dark_path'] ?? $company['logo_png_dark_path'] ?? $company['logo_svg_dark_path']);
                $_hasDarkVar  = !empty($company['logo_webp_white_path'] ?? $company['logo_png_white_path'] ?? $company['logo_svg_white_path']);
                $_lightSrc = $_useDarkVar
                    ? ($company['logo_webp_dark_path'] ?: $company['logo_png_dark_path'] ?: $company['logo_svg_dark_path'])
                    : ($company['logo_webp_path'] ?: $company['logo_png_path'] ?: $company['logo_svg_path']);
                $_darkSrc  = $_hasDarkVar
                    ? ($company['logo_webp_white_path'] ?: $company['logo_png_white_path'] ?: $company['logo_svg_white_path'])
                    : ($company['logo_webp_path'] ?: $company['logo_png_path'] ?: $company['logo_svg_path']);
                $_ver = !empty($company['logo_updated_at']) ? '?v=' . strtotime($company['logo_updated_at']) : '';
                $_showDualPreview = $src && ($_hasLightVar || $_hasDarkVar);
            ?>
            <?php if ($_showDualPreview): ?>
                <!-- Brandfetch-style dual preview: light + dark stages -->
                <div class="shrink-0 mx-auto md:mx-0 grid grid-cols-2 gap-2 w-[18.5rem] sm:w-[20rem] md:w-[22rem]">
                    <div class="aspect-square bg-white border border-gray-200 rounded-xl flex items-center justify-center p-3 sm:p-4 group/light hover:border-gray-300 transition">
                        <img src="<?= logo_hero_esc($_lightSrc) . $_ver ?>"
                             alt="<?= logo_hero_esc($company['name_en'] ?? '') ?> logo, light"
                             class="max-h-[75%] max-w-[80%] w-auto h-auto object-contain object-center">
                    </div>
                    <div class="aspect-square bg-gray-900 border border-gray-900 rounded-xl flex items-center justify-center p-3 sm:p-4 group/dark hover:bg-black transition"
                         style="background-color:#0E0F11">
                        <img src="<?= logo_hero_esc($_darkSrc) . $_ver ?>"
                             alt="<?= logo_hero_esc($company['name_en'] ?? '') ?> logo, dark"
                             class="max-h-[75%] max-w-[80%] w-auto h-auto object-contain object-center">
                    </div>
                </div>
            <?php else: ?>
                <!-- Single tile, consistent "stage" sizing for wide wordmarks
                     and square stamps; ~75% × 80% inner safe zone. -->
                <div class="shrink-0 w-36 h-36 sm:w-40 sm:h-40 md:w-44 md:h-44 mx-auto md:mx-0 bg-gradient-to-br from-gray-50 to-white border border-gray-200 rounded-2xl flex items-center justify-center p-4 sm:p-5">
                    <?php if ($src): ?>
                        <img src="<?= logo_hero_esc($src) ?>" alt="<?= logo_hero_esc($company['name_en'] ?? '') ?> logo"
                             class="max-h-[75%] max-w-[80%] w-auto h-auto object-contain object-center">
                    <?php else: ?>
                        <div class="text-gray-300 text-4xl font-extrabold">
                            <?= logo_hero_esc(mb_substr($company['name_en'] ?? '??', 0, 2)) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Meta + actions -->
            <div class="flex-1 min-w-0 text-center md:text-<?= $isAr ? 'right' : 'left' ?>">
                <!-- Status + library label -->
                <div class="flex flex-wrap gap-2 justify-center md:justify-start items-center">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full ring-1 ring-inset text-xs font-semibold <?= logo_hero_esc($badge['ring']) ?>">
                        <span class="w-1.5 h-1.5 rounded-full <?= logo_hero_esc($badge['dot']) ?>"></span>
                        <?= logo_hero_esc($badge['label']) ?>
                    </span>
                    <a href="/logos" class="text-xs text-gray-500 hover:text-blue-600 inline-flex items-center gap-1">
                        <i class="fa-solid fa-folder-open text-[10px]"></i>
                        <?= $isAr ? 'من مكتبة الشعارات العمانية' : 'From the Omani Logo Library' ?>
                    </a>
                </div>

                <h2 class="mt-2 text-xl md:text-2xl font-bold text-gray-900">
                    <?= $isAr
                        ? logo_hero_esc($company['name_ar'] ?: $company['name_en'] ?? '')
                        : logo_hero_esc($company['name_en'] ?? '') ?>
                </h2>

                <?php if ($src): ?>
                    <!-- Brand palette swatches (up to 5 colors extracted from the logo) -->
                    <?php
                        $palette = [];
                        if (!empty($company['logo_palette'])) {
                            $decoded = json_decode($company['logo_palette'], true);
                            if (is_array($decoded)) $palette = array_slice($decoded, 0, 5);
                        }
                        if (empty($palette) && !empty($company['logo_dominant_color'])) {
                            $palette = [$company['logo_dominant_color']];
                        }
                    ?>
                    <?php if (!empty($palette)): ?>
                        <div class="mt-4 flex flex-wrap items-center gap-2 justify-center md:justify-<?= $isAr ? 'end' : 'start' ?>">
                            <span class="text-xs text-gray-500 <?= $isAr ? 'ml-1' : 'mr-1' ?>"><?= $isAr ? 'لوحة الألوان' : 'Brand palette' ?>:</span>
                            <?php foreach ($palette as $hex): $hex = strtoupper((string) $hex); ?>
                                <button type="button"
                                        class="cardify-palette-chip group inline-flex items-center gap-1.5 pl-1.5 pr-2 py-1 rounded-full bg-white border border-gray-200 text-xs font-mono text-gray-700 hover:border-gray-400 transition"
                                        data-copy="<?= logo_hero_esc($hex) ?>"
                                        title="<?= $isAr ? 'انقر للنسخ' : 'Click to copy' ?>">
                                    <span class="w-4 h-4 rounded-full ring-1 ring-inset ring-black/10" style="background: <?= logo_hero_esc($hex) ?>"></span>
                                    <span class="group-[.copied]:hidden"><?= logo_hero_esc($hex) ?></span>
                                    <span class="hidden group-[.copied]:inline text-emerald-600"><?= $isAr ? 'تم النسخ' : 'Copied' ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Download buttons, available whether indexed or verified -->
                    <?php if ($canDownload): ?>
                        <?php
                            // Format catalogue with on-disk file probe for size + dimensions.
                            // The probe is cheap, only 2-6 stat() calls per page.
                            $root = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
                            $fmtCatalogue = [
                                'svg'      => ['SVG',         'logo_svg_path',      'fa-bezier-curve', 'image/svg+xml'],
                                'png_1024' => ['PNG · 1024',  'logo_png_path',      'fa-image',        'image/png'],
                                'png_2048' => ['PNG · 2048',  'logo_png_2048_path', 'fa-image',        'image/png'],
                                'png_512'  => ['PNG · 512',   'logo_png_512_path',  'fa-image',        'image/png'],
                                'webp'     => ['WebP',        'logo_webp_path',     'fa-image',        'image/webp'],
                                'zip'      => ['ZIP',  null,                 'fa-box-archive',  'application/zip'],
                            ];
                            $availFormats = [];
                            foreach ($fmtCatalogue as $fmt => [$label, $col, $icon, $mime]) {
                                if ($fmt === 'zip') {
                                    $availFormats[$fmt] = ['label' => $label, 'icon' => $icon, 'bytes' => null, 'mime' => $mime];
                                    continue;
                                }
                                if (empty($company[$col])) continue;
                                $abs = $root . $company[$col];
                                $availFormats[$fmt] = [
                                    'label' => $label,
                                    'icon'  => $icon,
                                    'bytes' => is_file($abs) ? filesize($abs) : null,
                                    'mime'  => $mime,
                                ];
                            }
                            $primaryPlaced = false;
                        ?>
                        <div class="cardify-logo-downloads mt-5 <?= $isAr ? 'is-rtl' : '' ?>">
                            <?php foreach ($availFormats as $fmt => $meta):
                                $primary = !$primaryPlaced && ($fmt === 'svg' || $fmt === 'png_1024');
                                $primaryPlaced = $primaryPlaced || $primary;
                                $sizeLabel = $meta['bytes']
                                    ? ($meta['bytes'] < 1024
                                        ? $meta['bytes'] . ' B'
                                        : ($meta['bytes'] < 1024 * 1024
                                            ? round($meta['bytes'] / 1024, 1) . ' KB'
                                            : round($meta['bytes'] / 1024 / 1024, 1) . ' MB'))
                                    : null;
                            ?>
                                <a href="/logo-download?company=<?= $companyId ?>&format=<?= logo_hero_esc($fmt) ?>"
                                   class="cardify-dl-btn <?= $primary ? 'cardify-dl-btn--primary' : '' ?>"
                                   rel="nofollow"
                                   download>
                                    <i class="fa-solid <?= logo_hero_esc($meta['icon']) ?>" aria-hidden="true"></i>
                                    <span><?= logo_hero_esc($meta['label']) ?></span>
                                    <?php if ($sizeLabel): ?>
                                        <span class="cardify-dl-btn__size text-[10px] opacity-60 ml-1 font-mono"><?= logo_hero_esc($sizeLabel) ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <!-- Color variants (auto-generated by LogoLibrary::generateMonochromeVariants) -->
                        <?php
                            $darkAvail = !empty($company['logo_svg_dark_path'])
                                      || !empty($company['logo_png_dark_path'])
                                      || !empty($company['logo_webp_dark_path']);
                            $whiteAvail = !empty($company['logo_svg_white_path'])
                                       || !empty($company['logo_png_white_path'])
                                       || !empty($company['logo_webp_white_path']);
                        ?>
                        <?php if ($darkAvail || $whiteAvail): ?>
                            <div class="mt-3 flex flex-wrap items-center gap-1.5 text-xs <?= $isAr ? 'is-rtl flex-row-reverse' : '' ?>">
                                <span class="text-gray-500 <?= $isAr ? 'ml-1' : 'mr-1' ?>">
                                    <i class="fa-solid fa-palette text-[10px]"></i>
                                    <?= $isAr ? 'نسخ أحادية اللون' : 'Color variants' ?>:
                                </span>
                                <?php if ($darkAvail): ?>
                                    <!-- Dark preview chip -->
                                    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-gray-900 text-white">
                                        <span class="w-2 h-2 rounded-full bg-white"></span>
                                        <span><?= logo_hero_esc(t('logos.gov_tone_black')) ?></span>
                                    </span>
                                    <?php if (!empty($company['logo_svg_dark_path'])): ?>
                                        <a href="/logo-download?company=<?= $companyId ?>&format=svg_dark"
                                           class="cardify-dl-btn" rel="nofollow" download>
                                            <i class="fa-solid fa-bezier-curve" aria-hidden="true"></i>
                                            <span>SVG</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($company['logo_png_dark_path'])): ?>
                                        <a href="/logo-download?company=<?= $companyId ?>&format=png_dark"
                                           class="cardify-dl-btn" rel="nofollow" download>
                                            <i class="fa-solid fa-image" aria-hidden="true"></i>
                                            <span>PNG</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($company['logo_webp_dark_path'])): ?>
                                        <a href="/logo-download?company=<?= $companyId ?>&format=webp_dark"
                                           class="cardify-dl-btn" rel="nofollow" download>
                                            <i class="fa-solid fa-image" aria-hidden="true"></i>
                                            <span>WebP</span>
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($whiteAvail): ?>
                                    <!-- White preview chip -->
                                    <span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-white border border-gray-300 text-gray-700">
                                        <span class="w-2 h-2 rounded-full bg-gray-900"></span>
                                        <span><?= $isAr ? 'فاتح' : 'White' ?></span>
                                    </span>
                                    <?php if (!empty($company['logo_svg_white_path'])): ?>
                                        <a href="/logo-download?company=<?= $companyId ?>&format=svg_white"
                                           class="cardify-dl-btn" rel="nofollow" download>
                                            <i class="fa-solid fa-bezier-curve" aria-hidden="true"></i>
                                            <span>SVG</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($company['logo_png_white_path'])): ?>
                                        <a href="/logo-download?company=<?= $companyId ?>&format=png_white"
                                           class="cardify-dl-btn" rel="nofollow" download>
                                            <i class="fa-solid fa-image" aria-hidden="true"></i>
                                            <span>PNG</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($company['logo_webp_white_path'])): ?>
                                        <a href="/logo-download?company=<?= $companyId ?>&format=webp_white"
                                           class="cardify-dl-btn" rel="nofollow" download>
                                            <i class="fa-solid fa-image" aria-hidden="true"></i>
                                            <span>WebP</span>
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Copy / embed row (power-user shortcut, no auth needed because the
                             link itself still goes through logo-download.php + unlock cookie). -->
                        <?php
                            $defaultFmt = isset($availFormats['svg']) ? 'svg' : (isset($availFormats['png_1024']) ? 'png_1024' : array_key_first($availFormats));
                            $defaultUrl = $baseUrl . '/logo-download?company=' . $companyId . '&format=' . $defaultFmt;
                        ?>
                        <div class="mt-3 flex flex-wrap items-center gap-2 text-xs <?= $isAr ? 'is-rtl flex-row-reverse' : '' ?>" data-logo-copy-row="<?= $companyId ?>">
                            <button type="button"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-gray-50 border border-gray-200 hover:border-gray-400 transition text-gray-700"
                                    data-copy="<?= logo_hero_esc($defaultUrl) ?>"
                                    title="<?= $isAr ? 'انسخ رابط الشعار' : 'Copy logo URL' ?>">
                                <i class="fa-solid fa-link text-[11px]"></i>
                                <span><?= $isAr ? 'انسخ الرابط' : 'Copy link' ?></span>
                                <span class="hidden text-emerald-600" data-copy-state="ok"><i class="fa-solid fa-check"></i></span>
                            </button>
                            <button type="button"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-gray-50 border border-gray-200 hover:border-gray-400 transition text-gray-700"
                                    data-copy='<img src="<?= logo_hero_esc($defaultUrl) ?>" alt="<?= logo_hero_esc($company['name_en'] ?? '') ?> logo" width="160" height="160" loading="lazy">'
                                    title="<?= $isAr ? 'انسخ كود التضمين' : 'Copy embed code' ?>">
                                <i class="fa-solid fa-code text-[11px]"></i>
                                <span><?= $isAr ? 'انسخ كود التضمين' : 'Copy embed' ?></span>
                                <span class="hidden text-emerald-600" data-copy-state="ok"><i class="fa-solid fa-check"></i></span>
                            </button>
                            <details class="ml-auto">
                                <summary class="cursor-pointer text-gray-500 hover:text-gray-700 select-none">
                                    <i class="fa-solid fa-circle-info text-[11px]"></i>
                                    <?= $isAr ? 'كيف أستخدمه' : 'How to use' ?>
                                </summary>
                                <div class="mt-2 p-3 rounded-lg bg-gray-50 border border-gray-200 text-gray-600 leading-relaxed text-[11px]" style="max-width: 460px">
                                    <p><?= $isAr
                                            ? 'الرابط يبقى صالحاً ما دامت الكوكي مفعّلة. للاستخدام التحريري أو الصحفي، الإسناد عبر «من مكتبة الشعارات العمانية على Cardify» مرحَّب به ومُقدَّر.'
                                            : 'The link stays valid for 90 days from your unlock. For editorial or press use, attribution to "Omani Logo Library on Cardify" is welcomed but not required.' ?></p>
                                </div>
                            </details>
                        </div>

                        <!-- Secondary row: claim + takedown -->
                        <div class="cardify-logo-meta-actions mt-3 <?= $isAr ? 'is-rtl' : '' ?>">
                            <?php if ($status !== 'verified'): ?>
                                <a href="/logo-claim?company=<?= $companyId ?>" class="cardify-logo-meta-link cardify-logo-meta-link--claim">
                                    <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                    <?= $isAr ? 'هل هذا شعار شركتك؟ طالب به' : 'Is this your company\'s logo? Claim it' ?>
                                </a>
                                <span class="cardify-logo-meta-sep" aria-hidden="true">·</span>
                            <?php endif; ?>
                            <a href="/logo-takedown?company=<?= $companyId ?>" class="cardify-logo-meta-link cardify-logo-meta-link--flag">
                                <i class="fa-solid fa-flag" aria-hidden="true"></i>
                                <?= $isAr ? 'إبلاغ / إزالة' : 'Report / takedown' ?>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- No logo yet -->
                    <div class="mt-5 flex flex-wrap gap-2 justify-center md:justify-<?= $isAr ? 'end' : 'start' ?>">
                        <a href="/logo-claim?company=<?= $companyId ?>" class="cardify-dl-btn cardify-dl-btn--primary">
                            <i class="fa-solid fa-plus" aria-hidden="true"></i>
                            <span><?= $isAr ? 'أضف شعار هذه الشركة' : 'Add a logo for this company' ?></span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($src): ?>
            <?php include __DIR__ . '/government_logo_layouts.php'; ?>
            <!-- Logo metadata strip -->
            <?php
                $formats = [];
                if (!empty($company['logo_svg_path']))      $formats[] = 'SVG';
                if (!empty($company['logo_png_path']))      $formats[] = 'PNG';
                if (!empty($company['logo_webp_path']))     $formats[] = 'WebP';
                if (!empty(LogoLibrary::downloadPaths($company)['pdf'])) $formats[] = 'PDF';
                $formatsStr = $formats ? implode(' · ', $formats) : ',';
                $dimText = ($company['logo_width'] ?? 0) && ($company['logo_height'] ?? 0)
                    ? ((int) $company['logo_width']) . ' × ' . ((int) $company['logo_height']) . ' px'
                    : ',';
                $sourceRaw = (string) ($company['logo_source'] ?? '');
                $sourceLabel = match ($sourceRaw) {
                    '2oman_net'    => $isAr ? '2oman.net (مفهرس)' : 'Indexed from 2oman.net',
                    'company_web'  => $isAr ? 'موقع الشركة' : 'Company website',
                    'user_upload'  => $isAr ? 'مالك الشركة' : 'Uploaded by owner',
                    'admin_upload' => $isAr ? 'محرر المكتبة' : 'Library editor',
                    default        => $isAr ? 'مصدر عام' : 'Public source',
                };
                $updated = !empty($company['logo_updated_at']) ? date('M j, Y', dbTs($company['logo_updated_at'])) : null;
            ?>
            <div class="border-t border-gray-100 px-6 md:px-8 py-4">
                <dl class="grid grid-cols-2 <?= $sourceRaw === 'government_guide' ? 'md:grid-cols-3' : 'md:grid-cols-4' ?> gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500 mb-0.5"><?= $isAr ? 'الصيغ المتوفرة' : 'Formats' ?></dt>
                        <dd class="font-semibold text-gray-900"><?= logo_hero_esc($formatsStr) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 mb-0.5"><?= $isAr ? 'الأبعاد (الأصل)' : 'Dimensions' ?></dt>
                        <dd class="font-semibold text-gray-900"><?= logo_hero_esc($dimText) ?></dd>
                    </div>
                    <?php if ($sourceRaw !== 'government_guide'): ?>
                    <div>
                        <dt class="text-xs text-gray-500 mb-0.5"><?= $isAr ? 'المصدر' : 'Source' ?></dt>
                        <dd class="font-semibold text-gray-900"><?= logo_hero_esc($sourceLabel) ?></dd>
                    </div>
                    <?php endif; ?>
                    <div>
                        <dt class="text-xs text-gray-500 mb-0.5"><?= $isAr ? 'الترخيص' : 'License' ?></dt>
                        <dd class="font-semibold text-gray-900">
                            <a href="/logos/terms" class="text-blue-600 hover:text-blue-700 inline-flex items-center gap-1">
                                <?= $isAr ? 'استخدام تعريفي' : 'Nominative use' ?>
                                <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
                            </a>
                        </dd>
                    </div>
                </dl>
                <?php if ($updated): ?>
                    <p class="mt-2 text-xs text-gray-400">
                        <i class="fa-regular fa-clock text-[10px] <?= $isAr ? 'ml-1' : 'mr-1' ?>"></i>
                        <?= $isAr ? 'آخر تحديث' : 'Last updated' ?> <?= logo_hero_esc($updated) ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php
// Sticky mobile download bar, hidden on md+; shows the brand mark, name,
// and a primary one-tap SVG download. Tap goes through the unlock gate
// like every other download link so the lead-capture flow still fires.
if ($src && $canDownload):
    $_primaryFmt = !empty($company['logo_svg_path'])
        ? 'svg'
        : (!empty($company['logo_png_path']) ? 'png_1024'
            : (!empty($company['logo_webp_path']) ? 'webp' : 'zip'));
    $_primaryUrl = '/logo-download?company=' . $companyId . '&format=' . $_primaryFmt;
    $_primaryLabel = match ($_primaryFmt) {
        'svg'      => 'SVG',
        'png_1024' => 'PNG',
        'webp'     => 'WebP',
        default    => 'ZIP',
    };
?>
<div class="md:hidden fixed inset-x-0 bottom-0 z-40 bg-white/95 backdrop-blur border-t border-gray-200 shadow-[0_-4px_24px_-12px_rgba(15,23,42,0.18)]"
     style="padding-bottom: calc(env(safe-area-inset-bottom) + 0.5rem)">
    <div class="flex items-center gap-3 px-4 pt-2.5">
        <div class="shrink-0 w-10 h-10 rounded-lg bg-gradient-to-br from-gray-50 to-white border border-gray-200 flex items-center justify-center p-1">
            <img src="<?= logo_hero_esc($src) ?>" alt="" class="max-h-[80%] max-w-[80%] object-contain">
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-[10px] uppercase tracking-wider text-gray-400 leading-none mb-0.5">
                <?= $isAr ? 'تنزيل الشعار' : 'Download logo' ?>
            </p>
            <p class="text-sm font-semibold text-gray-900 truncate leading-tight">
                <?= $isAr
                    ? logo_hero_esc($company['name_ar'] ?: ($company['name_en'] ?? ''))
                    : logo_hero_esc($company['name_en'] ?? '') ?>
            </p>
        </div>
        <a href="<?= logo_hero_esc($_primaryUrl) ?>"
           class="shrink-0 inline-flex items-center gap-1.5 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-semibold shadow shadow-blue-600/30 transition"
           rel="nofollow" download>
            <i class="fa-solid fa-download text-[11px]"></i>
            <?= logo_hero_esc($_primaryLabel) ?>
        </a>
    </div>
</div>
<style>
    /* Bottom padding so page content isn't hidden behind the sticky bar on mobile */
    @media (max-width: 767px) {
        body { padding-bottom: calc(72px + env(safe-area-inset-bottom)); }
    }
</style>
<?php endif; ?>

<?php if ($src && $canDownload):
    $logoAccessCompany = (int)$companyId;
    $logoAccessFormat = '';
?>
<link rel="stylesheet" href="/assets/css/logo-access.css?v=<?= filemtime(__DIR__ . '/../../assets/css/logo-access.css') ?>">
<div class="logo-access-allowance">
    <span data-logo-allowance><?= t('logoaccess.distinct') ?></span>
    <a href="/logo-access.php?lang=<?= currentLocale() ?>" data-logo-access-open><?= t('logoaccess.upgrade') ?></a>
</div>
<dialog id="cardify-logo-access" class="logo-access-dialog" aria-labelledby="logo-access-dialog-title">
    <button type="button" data-access-close class="logo-access-close"><?= t('logoaccess.close') ?></button>
    <?php require __DIR__ . '/logo_access_panel.php'; ?>
</dialog>
<?php endif; ?>
