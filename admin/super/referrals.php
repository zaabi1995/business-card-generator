<?php
/**
 * Referrals Dashboard (Super Admin), BHD-234.
 *
 * Lists every user who has referred at least one signup, along with their
 * total signups, paid conversions, and last activity. Read-only, all the
 * crediting happens automatically on paid conversion.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/Referral.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$rows = Referral::adminStats(500);

// Top-level totals.
$totals = [
    'referrers'         => count($rows),
    'signups'           => 0,
    'paid_conversions'  => 0,
    'reward_months'     => 0,
];
foreach ($rows as $r) {
    $totals['signups']          += (int)$r['signups'];
    $totals['paid_conversions'] += (int)$r['paid_conversions'];
    $totals['reward_months']    += ((int)$r['paid_conversions']) * Referral::REWARD_MONTHS;
}

// Recent events feed.
$recentEvents = [];
try {
    $recentEvents = $db->fetchAll("
        SELECT re.*, u.name AS referrer_name, u.email AS referrer_email
        FROM referral_events re
        LEFT JOIN users u ON u.id = re.referrer_user_id
        ORDER BY re.created_at DESC
        LIMIT 50
    ") ?: [];
} catch (Throwable $e) {
    $recentEvents = [];
}

adminHeader('Referrals', 'referrals');
?>

<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Referrals</h1>
            <p class="text-gray-600">Who's sharing Cardify and converting friends to paid.</p>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-2xl font-bold text-gray-900"><?= (int)$totals['referrers'] ?></p>
            <p class="text-sm text-gray-500">Active referrers</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-2xl font-bold text-blue-600"><?= (int)$totals['signups'] ?></p>
            <p class="text-sm text-gray-500">Signups via referral</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-2xl font-bold text-green-600"><?= (int)$totals['paid_conversions'] ?></p>
            <p class="text-sm text-gray-500">Paid conversions</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <p class="text-2xl font-bold text-purple-600"><?= (int)$totals['reward_months'] ?></p>
            <p class="text-sm text-gray-500">Free months granted</p>
        </div>
    </div>

    <!-- Referrers Table -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-900">Top referrers</h3>
            <span class="text-xs text-gray-500"><?= count($rows) ?> people have referred at least 1 signup</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Referrer</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Code</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide">Signups</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wide">Paid</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Last activity</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-sm text-gray-500">
                            No referrals yet, once users start sharing their link, they'll show up here.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($row['name'] ?? 'Unnamed') ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($row['email'] ?? '') ?></p>
                            <?php if (!empty($row['phone'])): ?>
                            <p class="text-xs text-gray-400"><?= htmlspecialchars($row['phone']) ?></p>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3">
                            <code class="text-xs bg-gray-100 px-2 py-1 rounded font-mono"><?= htmlspecialchars($row['referral_code'] ?? '') ?></code>
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-semibold text-gray-900"><?= (int)$row['signups'] ?></td>
                        <td class="px-4 py-3 text-right">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?= ((int)$row['paid_conversions']) > 0 ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' ?>">
                                <?= (int)$row['paid_conversions'] ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            <?= !empty($row['last_activity']) ? date('M j, Y H:i', dbTs($row['last_activity'])) : ',' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent events -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100">
            <h3 class="font-semibold text-gray-900">Recent referral events</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">When</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Referrer</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Event</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wide">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($recentEvents)): ?>
                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-gray-500">No events yet.</td></tr>
                    <?php else: ?>
                    <?php foreach ($recentEvents as $ev): ?>
                    <?php
                        $type = $ev['event_type'] ?? '';
                        $pill = [
                            'signup'           => 'bg-blue-100 text-blue-700',
                            'paid_conversion'  => 'bg-amber-100 text-amber-700',
                            'reward_granted'   => 'bg-green-100 text-green-700',
                        ][$type] ?? 'bg-gray-100 text-gray-600';
                        $details = $ev['details'] ?? null;
                        $parsed  = $details ? (json_decode($details, true) ?: null) : null;
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                            <?= htmlspecialchars(date('M j, Y H:i', dbTs($ev['created_at']))) ?>
                        </td>
                        <td class="px-4 py-3">
                            <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($ev['referrer_name'] ?? '') ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($ev['referrer_email'] ?? '') ?></p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?= $pill ?>">
                                <?= htmlspecialchars($type) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600 font-mono">
                            <?php if (is_array($parsed)): ?>
                                <?php foreach ($parsed as $k => $v): ?>
                                    <span class="mr-2"><?= htmlspecialchars($k) ?>=<?= htmlspecialchars(is_scalar($v) ? (string)$v : json_encode($v)) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                ,
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php adminFooter(); ?>
