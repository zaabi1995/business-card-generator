<?php
/**
 * Bilingual grayscale trust-signals logo strip. Include with:
 *   require INCLUDES_DIR . '/../views/partials/trust_logo_strip.php';
 * Expects I18n globals + TrustLogos class loaded.
 */
if (!class_exists('TrustLogos')) {
    require_once __DIR__ . '/../../includes/TrustLogos.php';
}
$__trustLogos = TrustLogos::recent(12);
if (empty($__trustLogos)) return;
$__isAr = function_exists('currentLocale') && currentLocale() === 'ar';
$__dir  = $__isAr ? 'rtl' : 'ltr';
?>
<section class="trust-logo-strip py-8 bg-gray-50 border-y border-gray-100" dir="<?= $__dir ?>">
    <div class="max-w-6xl mx-auto px-4">
        <p class="text-center text-xs uppercase tracking-widest text-gray-500 mb-5">
            <?= htmlspecialchars(t('trust.headline', ['n' => count($__trustLogos)])) ?>
        </p>
        <div class="flex flex-wrap items-center justify-center gap-x-8 gap-y-4 opacity-70">
            <?php foreach ($__trustLogos as $c): $label = $__isAr && !empty($c['name_ar']) ? $c['name_ar'] : $c['name_en']; ?>
                <a href="/companies/<?= htmlspecialchars($c['slug']) ?>"
                   title="<?= htmlspecialchars($label) ?>"
                   class="block h-10 grayscale hover:grayscale-0 transition duration-200 opacity-70 hover:opacity-100">
                    <img src="<?= htmlspecialchars($c['logo_src']) ?>"
                         alt="<?= htmlspecialchars($label) ?>"
                         loading="lazy"
                         class="h-10 w-auto max-w-[120px] object-contain">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
