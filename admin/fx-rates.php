<?php
/**
 * FX Rates Management (Super Admin) - Cardify
 * Edit OMR-based conversion rates for display currencies.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$message = null;
$messageType = 'success';

// Handle updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_rate') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        die(htmlspecialchars(t('fxrates.invalid_request')));
    }
    $id = $_POST['id'] ?? '';
    $rate = (float)($_POST['rate'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($id && $rate > 0) {
        try {
            $db->update('fx_rates',
                [
                    'rate' => $rate,
                    'notes' => $notes,
                    'updated_by_user_id' => Auth::userId() ?? null,
                ],
                'id = :id',
                ['id' => $id]
            );
            $message = t('fxrates.rate_updated');
        } catch (Exception $e) {
            $message = str_replace(':msg', $e->getMessage(), t('fxrates.update_failed'));
            $messageType = 'error';
        }
    } else {
        $message = t('fxrates.rate_zero');
        $messageType = 'error';
    }
}

// Reset to seeds
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_seeds') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        die(htmlspecialchars(t('fxrates.invalid_request')));
    }
    $seeds = [
        'USD' => 2.598000,
        'AED' => 9.543000,
        'SAR' => 9.742000,
        'EUR' => 2.400000,
    ];
    foreach ($seeds as $target => $rate) {
        $db->update('fx_rates',
            ['rate' => $rate],
            'base_currency = :base AND target_currency = :target',
            ['base' => 'OMR', 'target' => $target]
        );
    }
    $message = t('fxrates.seeds_reset');
}

$rates = $db->fetchAll(
    "SELECT * FROM fx_rates WHERE base_currency = 'OMR' ORDER BY target_currency"
);
$csrfToken = generateCSRFToken();

adminHeader(t('adminchrome.fx_rates'), 'fx-rates');
?>

<div class="max-w-5xl mx-auto px-4 py-8">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars(t('fxrates.page_h1')) ?></h1>
        <form method="post" data-cardify-confirm="<?= htmlspecialchars(t('fxrates.confirm_reset'), ENT_QUOTES) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="reset_seeds">
            <button type="submit" class="text-sm text-gray-600 hover:text-gray-900"><?= htmlspecialchars(t('fxrates.reset_btn')) ?></button>
        </form>
    </div>

    <p class="text-gray-600 mb-6">
        <?= htmlspecialchars(t('fxrates.intro')) ?>
    </p>

    <?php if ($message): ?>
    <div class="mb-4 p-4 rounded-xl <?= $messageType === 'error' ? 'bg-red-50 text-red-800' : 'bg-green-50 text-green-800' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"><?= htmlspecialchars(t('fxrates.col_pair')) ?></th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"><?= htmlspecialchars(t('fxrates.col_one_omr')) ?></th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"><?= htmlspecialchars(t('fxrates.col_notes')) ?></th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider"><?= htmlspecialchars(t('fxrates.col_updated')) ?></th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($rates as $r): ?>
                <tr>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="update_rate">
                        <input type="hidden" name="id" value="<?= htmlspecialchars($r['id']) ?>">
                        <td class="px-6 py-4 text-sm font-mono text-gray-900">OMR &rarr; <?= htmlspecialchars($r['target_currency']) ?></td>
                        <td class="px-6 py-4">
                            <input type="number" step="0.000001" min="0" name="rate" value="<?= htmlspecialchars((string)$r['rate']) ?>" class="w-32 px-3 py-1.5 border border-gray-300 rounded-lg text-sm font-mono">
                            <span class="text-gray-500 text-xs ml-2"><?= htmlspecialchars($r['target_currency']) ?></span>
                        </td>
                        <td class="px-6 py-4">
                            <input type="text" name="notes" value="<?= htmlspecialchars($r['notes'] ?? '') ?>" class="w-full px-3 py-1.5 border border-gray-300 rounded-lg text-sm" placeholder="<?= htmlspecialchars(t('fxrates.notes_ph')) ?>">
                        </td>
                        <td class="px-6 py-4 text-xs text-gray-500"><?= htmlspecialchars($r['updated_at']) ?></td>
                        <td class="px-6 py-4 text-right">
                            <button type="submit" class="text-sm font-semibold text-blue-600 hover:text-blue-800"><?= htmlspecialchars(t('fxrates.save')) ?></button>
                        </td>
                    </form>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php adminFooter(); ?>
