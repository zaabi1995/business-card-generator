<?php
/**
 * Super Admin, Logo Library analytics.
 * Downloads/day chart, top 20 downloaded, claim funnel summary.
 */
require_once __DIR__ . '/../../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();

$downloadsByDay = $db->fetchAll(
    "SELECT DATE(created_at) d, COUNT(*) c
       FROM logo_downloads
      WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
      GROUP BY d ORDER BY d"
);

$topDownloaded = $db->fetchAll(
    "SELECT co.slug, co.name_en, COUNT(*) c
       FROM logo_downloads ld
       JOIN om_companies co ON co.id = ld.company_id
      GROUP BY ld.company_id
      ORDER BY c DESC
      LIMIT 20"
);

$claimFunnel = [
    'submitted' => (int) ($db->fetchOne("SELECT COUNT(*) c FROM logo_claims")['c'] ?? 0),
    'approved'  => (int) ($db->fetchOne("SELECT COUNT(*) c FROM logo_claims WHERE status = 'approved'")['c'] ?? 0),
    'auto'      => (int) ($db->fetchOne("SELECT COUNT(*) c FROM logo_claims WHERE auto_verified = 1")['c'] ?? 0),
];

adminHeader('Logo analytics');

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<h1 class="text-2xl font-bold">Analytics</h1>

<div class="grid grid-cols-3 gap-3 mt-6">
  <div class="bg-white border rounded p-4">
    <div class="text-xs text-gray-500">Claims submitted</div>
    <div class="text-2xl font-bold"><?= number_format($claimFunnel['submitted']) ?></div>
  </div>
  <div class="bg-white border rounded p-4">
    <div class="text-xs text-gray-500">Approved</div>
    <div class="text-2xl font-bold text-green-700"><?= number_format($claimFunnel['approved']) ?></div>
  </div>
  <div class="bg-white border rounded p-4">
    <div class="text-xs text-gray-500">Auto-verified</div>
    <div class="text-2xl font-bold"><?= number_format($claimFunnel['auto']) ?></div>
  </div>
</div>

<h2 class="mt-8 text-lg font-semibold">Downloads (last 30 days)</h2>
<canvas id="chart" class="mt-2" height="80"></canvas>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('chart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($downloadsByDay, 'd')) ?>,
    datasets: [{
      label: 'Downloads',
      data: <?= json_encode(array_map('intval', array_column($downloadsByDay, 'c'))) ?>,
      borderColor: '#0891b2',
      tension: 0.3
    }]
  },
  options: { responsive: true }
});
</script>

<h2 class="mt-8 text-lg font-semibold">Top 20 downloads</h2>
<table class="mt-2 w-full text-sm bg-white border rounded">
  <thead class="bg-gray-50">
    <tr>
      <th class="text-left p-2">Company</th>
      <th class="text-right p-2">Downloads</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($topDownloaded as $r): ?>
      <tr class="border-t">
        <td class="p-2">
          <a class="underline" href="/companies/<?= esc($r['slug']) ?>" target="_blank">
            <?= esc($r['name_en']) ?>
          </a>
        </td>
        <td class="p-2 text-right"><?= (int) $r['c'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$topDownloaded): ?>
      <tr><td colspan="2" class="p-3 text-center text-gray-500">No downloads yet</td></tr>
    <?php endif; ?>
  </tbody>
</table>
<?php adminFooter();
