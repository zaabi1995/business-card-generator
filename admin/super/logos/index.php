<?php
/**
 * Super Admin — Logo Library overview.
 * Stat cards, claim/takedown queue links, analytics link.
 */
require_once __DIR__ . '/../../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();

$stats = $db->fetchOne(
    "SELECT
        SUM(logo_status = 'verified') AS verified,
        SUM(logo_status = 'indexed')  AS indexed,
        SUM(logo_status = 'pending')  AS pending,
        SUM(logo_status = 'disputed') AS disputed,
        SUM(logo_status = 'takedown') AS takedown
     FROM om_companies"
);
$pendingClaims  = (int) ($db->fetchOne("SELECT COUNT(*) c FROM logo_claims WHERE status = 'pending'")['c'] ?? 0);
$openTakedowns  = (int) ($db->fetchOne("SELECT COUNT(*) c FROM logo_takedowns WHERE status IN ('received','under_review')")['c'] ?? 0);
$downloadsToday = (int) ($db->fetchOne("SELECT COUNT(*) c FROM logo_downloads WHERE created_at > CURDATE()")['c'] ?? 0);
$matchQueue     = (int) ($db->fetchOne("SELECT COUNT(*) c FROM om_companies WHERE logo_match_pending = 1")['c'] ?? 0);

adminHeader('Logo Library — Admin');

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<div class="space-y-6">
  <h1 class="text-2xl font-bold">Omani Logo Library — Admin</h1>

  <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
    <?php foreach ([
        ['Verified', (int) ($stats['verified'] ?? 0), '#16a34a'],
        ['Indexed',  (int) ($stats['indexed']  ?? 0), '#6b7280'],
        ['Pending',  (int) ($stats['pending']  ?? 0), '#d97706'],
        ['Disputed', (int) ($stats['disputed'] ?? 0), '#dc2626'],
        ['Takedown', (int) ($stats['takedown'] ?? 0), '#4b5563'],
    ] as [$label, $n, $color]): ?>
      <div class="bg-white p-4 rounded border">
        <div class="text-xs" style="color: <?= esc($color) ?>"><?= esc($label) ?></div>
        <div class="text-2xl font-bold"><?= number_format($n) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
    <a href="/admin/super/logos/claims.php"
       class="block p-4 bg-white rounded border hover:shadow transition">
      <div class="text-sm text-gray-500">Pending claims</div>
      <div class="text-2xl font-bold"><?= number_format($pendingClaims) ?></div>
    </a>
    <a href="/admin/super/logos/takedowns.php"
       class="block p-4 bg-white rounded border hover:shadow transition">
      <div class="text-sm text-gray-500">Open takedowns</div>
      <div class="text-2xl font-bold"><?= number_format($openTakedowns) ?></div>
    </a>
    <a href="/admin/super/logos/match-queue.php"
       class="block p-4 bg-white rounded border hover:shadow transition">
      <div class="text-sm text-gray-500">Match queue</div>
      <div class="text-2xl font-bold"><?= number_format($matchQueue) ?></div>
    </a>
    <a href="/admin/super/logos/analytics.php"
       class="block p-4 bg-white rounded border hover:shadow transition">
      <div class="text-sm text-gray-500">Downloads today</div>
      <div class="text-2xl font-bold"><?= number_format($downloadsToday) ?></div>
    </a>
  </div>

  <div class="flex flex-wrap gap-3 pt-4 border-t">
    <a href="/admin/super/logos/seed-report.php" class="px-3 py-2 bg-white border rounded">Seed reports</a>
    <a href="/admin/super/logos/analytics.php" class="px-3 py-2 bg-white border rounded">Analytics</a>
  </div>
</div>
<?php adminFooter();
