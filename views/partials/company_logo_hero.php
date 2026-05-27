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
                    <!-- Download buttons, available whether indexed or verified -->
                    <?php if ($canDownload): ?>
                        <div class="cardify-logo-downloads mt-5 <?= $isAr ? 'is-rtl' : '' ?>">
                            <?php
                                $formats = [
                                    'svg'      => ['SVG',         'logo_svg_path',      'fa-bezier-curve'],
                                    'png_1024' => ['PNG · 1024',  'logo_png_path',      'fa-image'],
                                    'png_2048' => ['PNG · 2048',  'logo_png_2048_path', 'fa-image'],
                                    'png_512'  => ['PNG · 512',   'logo_png_512_path',  'fa-image'],
                                    'webp'     => ['WebP',        'logo_webp_path',     'fa-image'],
                                    'zip'      => ['ZIP bundle',  null,                 'fa-box-archive'],
                                ];
                                $primaryPlaced = false;
                                foreach ($formats as $fmt => [$label, $col, $icon]):
                                    $available = $fmt === 'zip' ? true : !empty($company[$col] ?? null);
                                    if (!$available) continue;
                                    $primary = !$primaryPlaced && ($fmt === 'svg' || $fmt === 'png_1024');
                                    $primaryPlaced = $primaryPlaced || $primary;
                            ?>
                                <a href="/logo-download?company=<?= $companyId ?>&format=<?= logo_hero_esc($fmt) ?>"
                                   class="cardify-dl-btn <?= $primary ? 'cardify-dl-btn--primary' : '' ?>"
                                   rel="nofollow"
                                   download>
                                    <i class="fa-solid <?= logo_hero_esc($icon) ?>" aria-hidden="true"></i>
                                    <span><?= logo_hero_esc($label) ?></span>
                                </a>
                            <?php endforeach; ?>
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
            <!-- Logo metadata strip -->
            <?php
                $formats = [];
                if (!empty($company['logo_svg_path']))      $formats[] = 'SVG';
                if (!empty($company['logo_png_path']))      $formats[] = 'PNG';
                if (!empty($company['logo_webp_path']))     $formats[] = 'WebP';
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

<?php
// Lead-capture gate, only render when this hero will show download buttons
$_isUnlocked = !empty($_COOKIE['cardify_logo_unlock_v1'])
    && preg_match('/^[a-f0-9]{32}$/i', (string) $_COOKIE['cardify_logo_unlock_v1']);
if ($src && $canDownload):
?>
<dialog id="cardify-logo-unlock"
        class="rounded-2xl p-0 backdrop:bg-black/40 max-w-md w-[92vw] border border-gray-200 shadow-2xl"
        data-unlocked="<?= $_isUnlocked ? '1' : '0' ?>">
    <form id="cardify-logo-unlock-form" class="p-6 space-y-4" novalidate>
        <input type="hidden" name="company_id" value="<?= (int) $companyId ?>">
        <input type="hidden" name="format" id="cardify-logo-unlock-format" value="">

        <div>
            <h3 class="text-lg font-bold text-gray-900">
                <?= $isAr
                    ? 'لتحميل الشعار، اترك رقمك أو بريدك'
                    : 'One quick step to download' ?>
            </h3>
            <p class="text-sm text-gray-600 mt-1">
                <?= $isAr
                    ? 'نستخدم هذا لمتابعة استخدام مكتبة الشعارات العمانية. تعبئة واحدة تكفي لـ 90 يوماً، تنزيلات لاحقة لن تطلب منك أي شيء.'
                    : 'We use it to keep the Omani Logo Library honest. One fill, 90 days of friction-free downloads after.' ?>
            </p>
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">
                <?= $isAr ? 'الاسم (اختياري)' : 'Name (optional)' ?>
            </label>
            <input type="text" name="name" maxlength="120"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                   placeholder="<?= $isAr ? 'فلان الفلاني' : 'Your name' ?>">
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">
                <?= $isAr ? 'الجوال (واتساب)' : 'Mobile (WhatsApp)' ?>
            </label>
            <input type="tel" name="phone" inputmode="tel"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono"
                   placeholder="+968 9XXX XXXX" pattern="^\+?[0-9 \-\(\)]{7,20}$">
        </div>

        <div class="flex items-center gap-2 text-xs text-gray-400">
            <hr class="flex-1 border-gray-200">
            <span><?= $isAr ? 'أو' : 'or' ?></span>
            <hr class="flex-1 border-gray-200">
        </div>

        <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1">
                <?= $isAr ? 'البريد الإلكتروني' : 'Email' ?>
            </label>
            <input type="email" name="email" maxlength="160"
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                   placeholder="you@company.com">
        </div>

        <p id="cardify-logo-unlock-error" class="hidden text-sm text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2"></p>

        <div class="flex items-center justify-end gap-2 pt-1">
            <button type="button" class="cardify-logo-unlock-cancel px-4 py-2 text-sm text-gray-600 hover:text-gray-900">
                <?= $isAr ? 'إلغاء' : 'Cancel' ?>
            </button>
            <button type="submit" id="cardify-logo-unlock-submit"
                    class="px-4 py-2 text-sm font-semibold bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition disabled:opacity-60">
                <?= $isAr ? 'تنزيل الشعار' : 'Unlock & download' ?>
            </button>
        </div>

        <p class="text-[11px] text-gray-400 leading-relaxed">
            <?= $isAr
                ? 'بإرسالك التفاصيل توافق على أن يتواصل معك فريق Cardify بشأن مكتبة الشعارات. لن نبيع بياناتك.'
                : 'By submitting you agree that Cardify may contact you about the Omani Logo Library. We never sell your details.' ?>
        </p>
    </form>
</dialog>

<script>
(function () {
    var dlg     = document.getElementById('cardify-logo-unlock');
    var form    = document.getElementById('cardify-logo-unlock-form');
    var errEl   = document.getElementById('cardify-logo-unlock-error');
    var fmtIn   = document.getElementById('cardify-logo-unlock-format');
    var btn     = document.getElementById('cardify-logo-unlock-submit');
    var cancel  = dlg.querySelector('.cardify-logo-unlock-cancel');
    var pending = null;

    function isUnlocked() { return dlg.dataset.unlocked === '1'; }

    function showError(msg) {
        errEl.textContent = msg;
        errEl.classList.remove('hidden');
    }

    function hideError() {
        errEl.textContent = '';
        errEl.classList.add('hidden');
    }

    // Intercept every download button on the page that points at logo-download
    document.querySelectorAll('a[href*="logo-download"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            if (isUnlocked()) return; // pass through
            e.preventDefault();
            pending = a.getAttribute('href');
            var u = new URL(a.href, location.origin);
            fmtIn.value = u.searchParams.get('format') || '';
            hideError();
            try { dlg.showModal(); } catch (_) { dlg.setAttribute('open', ''); }
        });
    });

    cancel.addEventListener('click', function () { dlg.close(); pending = null; });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        hideError();
        var fd = new FormData(form);
        var phone = (fd.get('phone') || '').toString().trim();
        var email = (fd.get('email') || '').toString().trim();
        if (!phone && !email) {
            showError(<?= $isAr ? "'يرجى تعبئة الجوال أو البريد الإلكتروني'" : "'Please enter a mobile or an email'" ?>);
            return;
        }
        var payload = {
            company_id: fd.get('company_id'),
            format:     fd.get('format'),
            name:       (fd.get('name') || '').toString().trim(),
            phone:      phone,
            email:      email
        };
        btn.disabled = true;
        btn.dataset.label = btn.textContent;
        btn.textContent = <?= $isAr ? "'لحظة…'" : "'Working…'" ?>;
        fetch('/api/logo-unlock.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, status: r.status, body: j }; });
        }).then(function (res) {
            btn.disabled = false;
            btn.textContent = btn.dataset.label;
            if (!res.ok) {
                var map = {
                    missing_contact: <?= $isAr ? "'يرجى تعبئة الجوال أو البريد'" : "'Please enter mobile or email'" ?>,
                    invalid_phone:   <?= $isAr ? "'رقم جوال غير صالح'" : "'That phone number looks wrong'" ?>,
                    invalid_email:   <?= $isAr ? "'بريد إلكتروني غير صالح'" : "'That email looks wrong'" ?>,
                    rate_limited:    <?= $isAr ? "'حاول مرة أخرى بعد ساعة'" : "'Too many tries, try again in an hour'" ?>
                };
                showError(map[res.body && res.body.error] || <?= $isAr ? "'لم نتمكن من المتابعة، حاول مرة أخرى'" : "'Could not continue, please try again'" ?>);
                return;
            }
            dlg.dataset.unlocked = '1';
            dlg.close();
            if (pending) {
                window.location = pending;
                pending = null;
            }
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = btn.dataset.label;
            showError(<?= $isAr ? "'مشكلة في الاتصال، حاول مرة أخرى'" : "'Network glitch, please try again'" ?>);
        });
    });

    // Auto-pop when redirected back from logo-download.php with ?unlock=required
    var params = new URLSearchParams(location.search);
    if (params.get('unlock') === 'required' && !isUnlocked()) {
        var fmt = params.get('format') || '';
        fmtIn.value = fmt;
        pending = '/logo-download?company=<?= (int) $companyId ?>&format=' + encodeURIComponent(fmt);
        try { dlg.showModal(); } catch (_) { dlg.setAttribute('open', ''); }
    }
})();
</script>
<?php endif; ?>
