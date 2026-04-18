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
if (!class_exists('LogoLibrary')) {
    require_once __DIR__ . '/../../includes/LogoLibrary.php';
}

if (!function_exists('logo_hero_esc')) {
    function logo_hero_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

$status  = $company['logo_status'] ?? 'none';
$src     = $company['logo_webp_path']
        ?: $company['logo_png_path']
        ?: $company['logo_svg_path']
        ?: $company['logo_png_512_path']
        ?: null;
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

            <!-- Logo tile -->
            <div class="shrink-0 w-40 h-40 md:w-44 md:h-44 mx-auto md:mx-0 bg-gradient-to-br from-gray-50 to-white border border-gray-200 rounded-2xl flex items-center justify-center p-5">
                <?php if ($src): ?>
                    <img src="<?= logo_hero_esc($src) ?>" alt="<?= logo_hero_esc($company['name_en'] ?? '') ?> logo"
                         class="max-h-full max-w-full object-contain">
                <?php else: ?>
                    <div class="text-gray-300 text-4xl font-extrabold">
                        <?= logo_hero_esc(mb_substr($company['name_en'] ?? '??', 0, 2)) ?>
                    </div>
                <?php endif; ?>
            </div>

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
                    <!-- Download buttons — available whether indexed or verified -->
                    <?php if ($canDownload): ?>
                        <div class="mt-5 flex flex-wrap gap-2 justify-center md:justify-<?= $isAr ? 'end' : 'start' ?>">
                            <?php
                                $formats = [
                                    'svg'      => ['SVG',         'logo_svg_path',      'fa-code'],
                                    'png_1024' => ['PNG · 1024',  'logo_png_path',      'fa-image'],
                                    'png_2048' => ['PNG · 2048',  'logo_png_2048_path', 'fa-image'],
                                    'png_512'  => ['PNG · 512',   'logo_png_512_path',  'fa-image'],
                                    'webp'     => ['WebP',        'logo_webp_path',     'fa-image'],
                                    'zip'      => ['ZIP bundle',  null,                 'fa-box'],
                                ];
                                $primaryPlaced = false;
                                foreach ($formats as $fmt => [$label, $col, $icon]):
                                    $available = $fmt === 'zip' ? true : !empty($company[$col] ?? null);
                                    if (!$available) continue;
                                    $primary = !$primaryPlaced && ($fmt === 'svg' || $fmt === 'png_1024');
                                    $primaryPlaced = $primaryPlaced || $primary;
                                    $cls = $primary
                                        ? 'bg-blue-600 hover:bg-blue-700 text-white shadow-lg shadow-blue-600/30 hover:shadow-xl hover:shadow-blue-600/40'
                                        : 'bg-white border border-gray-200 text-gray-700 hover:border-blue-300 hover:text-blue-600';
                            ?>
                                <a href="/logo-download?company=<?= $companyId ?>&format=<?= logo_hero_esc($fmt) ?>"
                                   class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-sm font-semibold transition <?= $cls ?>"
                                   rel="nofollow"
                                   download>
                                    <i class="fa-solid <?= logo_hero_esc($icon) ?> text-xs"></i>
                                    <?= logo_hero_esc($label) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <!-- Secondary row: claim + takedown -->
                        <div class="mt-3 flex flex-wrap gap-3 items-center justify-center md:justify-<?= $isAr ? 'end' : 'start' ?> text-xs">
                            <?php if ($status !== 'verified'): ?>
                                <a href="/logo-claim?company=<?= $companyId ?>"
                                   class="inline-flex items-center gap-1.5 text-gray-500 hover:text-blue-600 transition">
                                    <i class="fa-solid fa-circle-check text-[10px]"></i>
                                    <?= $isAr ? 'هل هذا شعار شركتك؟ طالب به' : 'Is this your company\'s logo? Claim it' ?>
                                </a>
                                <span class="text-gray-300">·</span>
                            <?php endif; ?>
                            <a href="/logo-takedown?company=<?= $companyId ?>"
                               class="inline-flex items-center gap-1.5 text-gray-400 hover:text-rose-600 transition">
                                <i class="fa-solid fa-flag text-[10px]"></i>
                                <?= $isAr ? 'إبلاغ / إزالة' : 'Report / takedown' ?>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- No logo yet -->
                    <div class="mt-5 flex flex-wrap gap-2 justify-center md:justify-<?= $isAr ? 'end' : 'start' ?>">
                        <a href="/logo-claim?company=<?= $companyId ?>"
                           class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow-lg shadow-blue-600/30 transition">
                            <i class="fa-solid fa-plus text-xs"></i>
                            <?= $isAr ? 'أضف شعار هذه الشركة' : 'Add a logo for this company' ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($src): ?>
            <!-- Logo metadata strip -->
            <?php
                $formats = [];
                if (!empty($company['logo_svg_path']))      $formats[] = 'SVG';
                if (!empty($company['logo_png_path']))      $formats[] = 'PNG';
                if (!empty($company['logo_webp_path']))     $formats[] = 'WebP';
                $formatsStr = $formats ? implode(' · ', $formats) : '—';
                $dimText = ($company['logo_width'] ?? 0) && ($company['logo_height'] ?? 0)
                    ? ((int) $company['logo_width']) . ' × ' . ((int) $company['logo_height']) . ' px'
                    : '—';
                $sourceRaw = (string) ($company['logo_source'] ?? '');
                $sourceLabel = match ($sourceRaw) {
                    '2oman_net'    => $isAr ? '2oman.net (مفهرس)' : 'Indexed from 2oman.net',
                    'company_web'  => $isAr ? 'موقع الشركة' : 'Company website',
                    'user_upload'  => $isAr ? 'مالك الشركة' : 'Uploaded by owner',
                    'admin_upload' => $isAr ? 'محرر المكتبة' : 'Library editor',
                    default        => $isAr ? 'مصدر عام' : 'Public source',
                };
                $updated = !empty($company['logo_updated_at']) ? date('M j, Y', strtotime($company['logo_updated_at'])) : null;
            ?>
            <div class="border-t border-gray-100 px-6 md:px-8 py-4">
                <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="text-xs text-gray-500 mb-0.5"><?= $isAr ? 'الصيغ المتوفرة' : 'Formats' ?></dt>
                        <dd class="font-semibold text-gray-900"><?= logo_hero_esc($formatsStr) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 mb-0.5"><?= $isAr ? 'الأبعاد (الأصل)' : 'Dimensions (source)' ?></dt>
                        <dd class="font-semibold text-gray-900"><?= logo_hero_esc($dimText) ?></dd>
                    </div>
                    <div>
                        <dt class="text-xs text-gray-500 mb-0.5"><?= $isAr ? 'المصدر' : 'Source' ?></dt>
                        <dd class="font-semibold text-gray-900"><?= logo_hero_esc($sourceLabel) ?></dd>
                    </div>
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
