<?php
/**
 * Bilingual grayscale trust-signals logo strip. Include with:
 *   require INCLUDES_DIR . '/../views/partials/trust_logo_strip.php';
 * Expects I18n globals + TrustLogos class loaded.
 */
if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }
if (!class_exists('TrustLogos')) {
    require_once __DIR__ . '/../../includes/TrustLogos.php';
}
if (!class_exists('PlatformStats')) {
    require_once __DIR__ . '/../../includes/PlatformStats.php';
}
$__trustLogos = TrustLogos::recent(12);
if (empty($__trustLogos)) return;
$__isAr = function_exists('currentLocale') && currentLocale() === 'ar';
$__dir  = $__isAr ? 'rtl' : 'ltr';
?>
<section class="trust-logo-strip py-8 bg-gray-50 border-y border-gray-100" dir="<?= $__dir ?>">
    <div class="max-w-6xl mx-auto px-4">
        <?php
        /*
         * These logos come from om_companies, the public Oman Business Index,
         * selected by `curated = 1 AND logo_status IN ('verified','indexed')`
         * ordered by updated_at. There is no join to customer status anywhere
         * in that query, so the row must not claim these companies are
         * customers.
         *
         * It used to read ":shown of the :total companies issuing cards with
         * Cardify" with :total taken from PlatformStats['issuing'], the real
         * tenant count. Verified 20 Aug 2026: of the 12 logos then rendering,
         * eleven were BHD Group's own companies and the twelfth, OQ Gas
         * Networks SAOG, has no tenant at all. Worse, the ordering is by
         * updated_at over 2,502 rows, so the next curated logo could put any
         * unrelated company under a customer claim.
         *
         * Now it describes what it actually is, and counts the directory it
         * actually draws from. The genuine customer roster (Otech, MHD, ITHCA,
         * Al Maha Petroleum, Oman Housing Bank and others) is far stronger
         * proof, but publishing a customer's logo needs that customer's
         * permission, so that belongs in a separate, rights-cleared strip.
         */
        ?>
        <p class="text-center text-xs uppercase tracking-widest text-gray-500 mb-5">
            <?= htmlspecialchars(t('trust.headline', [
                'shown' => number_format(count($__trustLogos)),
                'total' => number_format(PlatformStats::all()['directory']),
            ])) ?>
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
