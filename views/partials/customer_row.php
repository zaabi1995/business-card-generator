<?php
/**
 * Customers, by name and mark.
 *
 * Ali confirmed on 20 Aug 2026 that he has these customers' approval to be
 * named and shown. That is his relationship and his call; this file records it,
 * and removing a customer is deleting one line.
 *
 * Every logo here was uploaded by that customer into their own tenant, so the
 * asset arrived with the account rather than being lifted off a website. The
 * path is read live from company_themes, which means a customer who changes
 * their mark changes it here too, and one who is deleted disappears rather than
 * leaving a stale logo behind.
 *
 * No head counts. The row says these companies use Cardify, which is true of
 * all of them, rather than implying a company-wide rollout, which is true of
 * one. The aggregate numbers live in proof_stats.php where they belong.
 *
 * Deliberately excluded, and NOT a judgement about the product:
 *  - Personal signups (Freelancer, Jalmood, a_z.creations) are people, not
 *    companies, and belong in no logo wall.
 *  - Embassy of Malaysia and the MoH Drug Safety Center are held back pending
 *    explicit institutional confirmation. A diplomatic mission's crest and a
 *    ministry's mark are a different consent class from a private company's,
 *    and the person who opened the account is unlikely to be the person who
 *    can grant use of the institution's identity. Add them by uncommenting.
 */
if (!defined('INCLUDES_DIR')) { http_response_code(404); exit; }

/** tenant slug => display name. Approved 20 Aug 2026. */
$__approved = [
    'otech'   => 'Otech',
    'ithca'   => 'ITHCA Group',
    'mhd'     => 'Mohsin Haider Darwish',
    'ohb'     => 'Oman Housing Bank',
    'umsoman' => 'United Media Services',
    // 'vineethpillai' => 'Embassy of Malaysia, Muscat',   // needs institutional sign-off
    // 'moh-gov'       => 'Drug Safety Center',            // needs institutional sign-off
];

try {
    $__db = Database::getInstance()->getConnection();
    $__ph = implode(',', array_fill(0, count($__approved), '?'));
    $__st = $__db->prepare(
        "SELECT c.slug, t.logo_path
           FROM companies c
           JOIN company_themes t ON t.company_id = c.id
          WHERE c.slug IN ({$__ph})
            AND t.logo_path IS NOT NULL AND t.logo_path <> ''"
    );
    $__st->execute(array_keys($__approved));
    $__logos = $__st->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) {
    $__logos = [];
}

$__rows = [];
foreach ($__approved as $__slug => $__name) {
    if (!empty($__logos[$__slug])) {
        $__rows[] = [$__name, '/' . ltrim((string) $__logos[$__slug], '/')];
    }
}
if (count($__rows) < 3) { return; }

$__isAr = function_exists('currentLocale') && currentLocale() === 'ar';
?>
<section class="py-10 bg-gray-50 border-b border-gray-100" dir="<?= $__isAr ? 'rtl' : 'ltr' ?>">
    <div class="max-w-5xl mx-auto px-4">
        <p class="text-center text-xs uppercase tracking-widest text-gray-500 mb-7">
            <?= htmlspecialchars(t('customers.headline')) ?>
        </p>
        <ul class="flex flex-wrap items-center justify-center gap-x-10 gap-y-7 list-none m-0 p-0">
            <?php foreach ($__rows as [$__name, $__src]): ?>
            <li class="flex flex-col items-center gap-2">
                <img src="<?= htmlspecialchars($__src) ?>"
                     alt="<?= htmlspecialchars($__name) ?>"
                     loading="lazy" decoding="async"
                     class="h-9 sm:h-10 w-auto max-w-[150px] object-contain opacity-75 grayscale transition duration-200 hover:opacity-100 hover:grayscale-0">
                <span class="text-[11px] text-gray-500"><?= htmlspecialchars($__name) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
