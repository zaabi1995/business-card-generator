<?php
/**
 * Named customers.
 *
 * Ali confirmed on 20 Aug 2026 that he has these customers' approval to name
 * them. That is his call and his relationship; this file records it.
 *
 * The threshold is deliberate. Of the 24 companies that have issued a card,
 * measured 20 Aug 2026, Otech alone accounts for 5,423 of 5,544 cards. Most of
 * the rest are one person with one or two cards: a bank where a single employee
 * made six, an embassy where one made two, and a dozen personal signups. Naming
 * those would tell a reader an organisation deployed Cardify when one person
 * tried it, which is the same thing as inventing a customer.
 *
 * So only real rollouts appear here: an organisation with several people
 * actually carrying a card. Figures are read live rather than typed, so this
 * row cannot drift away from the truth the way a hardcoded number does.
 *
 * Removing a customer is deleting a line. Adding one means asking them first.
 */
if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }

/** slug => display name. Approved 20 Aug 2026. */
$__approved = [
    'otech'   => 'Otech',
    'ithca'   => 'ITHCA Group',
    'aedoman' => 'AED-Oman | Silver Tulip Trading',
];

try {
    $__db = Database::getInstance()->getConnection();
    $__ph = implode(',', array_fill(0, count($__approved), '?'));
    $__st = $__db->prepare(
        "SELECT c.slug, COUNT(DISTINCT e.id) AS people
           FROM companies c
           JOIN employees e ON e.company_id = c.id
           JOIN generated_cards g ON g.employee_id = e.id
          WHERE c.slug IN ({$__ph})
          GROUP BY c.slug"
    );
    $__st->execute(array_keys($__approved));
    $__counts = $__st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) {
    $__counts = [];
}

// Only show a customer we can still see carrying cards.
$__rows = [];
foreach ($__approved as $__slug => $__name) {
    $__n = (int) ($__counts[$__slug] ?? 0);
    if ($__n >= 3) { $__rows[] = [$__name, $__n]; }
}
if (count($__rows) < 2) { return; }

$__isAr = function_exists('currentLocale') && currentLocale() === 'ar';
?>
<section class="py-10 bg-gray-50 border-b border-gray-100" dir="<?= $__isAr ? 'rtl' : 'ltr' ?>">
    <div class="max-w-5xl mx-auto px-4">
        <p class="text-center text-xs uppercase tracking-widest text-gray-500 mb-6">
            <?= htmlspecialchars(t('customers.headline')) ?>
        </p>
        <div class="flex flex-wrap items-stretch justify-center gap-3">
            <?php foreach ($__rows as [$__name, $__n]): ?>
            <div class="rounded-xl border border-gray-200 bg-white px-5 py-4 text-center min-w-[10rem]">
                <p class="font-display font-bold text-gray-900 text-sm leading-tight"><?= htmlspecialchars($__name) ?></p>
                <p class="mt-1 text-xs text-gray-500">
                    <?php
                    // Arabic agreement is decided by the last two digits, so 4
                    // takes the plural and 265 takes the singular. English uses
                    // one form for both; the split lives in the lang files.
                    $__mod = $__n % 100;
                    $__key = ($__mod >= 3 && $__mod <= 10) ? 'customers.people_few' : 'customers.people_many';
                    ?>
                    <?= htmlspecialchars(t($__key, ['n' => number_format($__n)])) ?>
                </p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
