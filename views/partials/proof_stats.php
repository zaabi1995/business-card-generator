<?php
/**
 * Three real numbers, counted live, naming nobody.
 *
 * This exists because the honest version of social proof was unavailable. The
 * logo strip above draws from the public directory and cannot claim customers;
 * a genuine customer strip is impossible because not one of the real customers
 * (Otech, MHD, ITHCA, Al Maha Petroleum, Oman Housing Bank) has a logo asset in
 * the system, and publishing a customer's mark needs that customer's
 * permission regardless.
 *
 * Aggregates need nobody's permission. They come from PlatformStats over the
 * same population as `issuing`: issued at least one card, not a demo or
 * showcase fixture, and not BHD. So the three figures agree with each other and
 * with the "24 companies" claim used elsewhere on the page.
 */
if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }
if (!class_exists('PlatformStats')) {
    require_once __DIR__ . '/../../includes/PlatformStats.php';
}
$__s = PlatformStats::all();
$__cards     = (int) ($__s['issued_cards'] ?? 0);
$__people    = (int) ($__s['issued_people'] ?? 0);
$__companies = (int) ($__s['issuing'] ?? 0);

// Nothing to boast about yet is a reason to stay quiet, not to round up.
if ($__cards < 100 || $__companies < 5) { return; }

$__isAr = function_exists('currentLocale') && currentLocale() === 'ar';
$__figs = [
    [$__cards,     t('proof.cards')],
    [$__people,    t('proof.people')],
    [$__companies, t('proof.companies')],
];
?>
<section class="py-10 bg-white border-b border-gray-100" dir="<?= $__isAr ? 'rtl' : 'ltr' ?>">
    <div class="max-w-5xl mx-auto px-4">
        <div class="grid grid-cols-3 gap-6 text-center">
            <?php foreach ($__figs as [$__n, $__label]): ?>
            <div>
                <p class="font-display font-extrabold text-3xl sm:text-4xl" style="color:#067a98">
                    <?= htmlspecialchars(number_format($__n)) ?>
                </p>
                <p class="mt-1 text-xs sm:text-sm text-gray-600"><?= htmlspecialchars($__label) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="mt-5 text-center text-[11px] text-gray-400 max-w-xl mx-auto">
            <?= htmlspecialchars(t('proof.note')) ?>
        </p>
    </div>
</section>
