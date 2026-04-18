<?php
/**
 * Super Admin — Seed report viewer.
 * Shows most recent seed run + list of previous runs from /storage/logos/seed-reports/.
 */
require_once __DIR__ . '/../../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';

Auth::requireRole('super_admin');

$reportDir = dirname(__DIR__, 3) . '/storage/logos/seed-reports';
$reports   = is_dir($reportDir) ? array_reverse(glob("$reportDir/*.json")) : [];
$viewingFile = $_GET['file'] ?? ($reports[0] ?? null);

// Security: only allow paths inside the reports dir
if ($viewingFile && (strpos(realpath($viewingFile) ?: '', $reportDir) !== 0)) {
    $viewingFile = null;
}

$viewing = ($viewingFile && is_file($viewingFile))
    ? json_decode(file_get_contents($viewingFile), true)
    : null;

adminHeader('Seed Report');

function esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
?>
<h1 class="text-2xl font-bold">Seed reports</h1>
<div class="grid grid-cols-4 gap-4 mt-4">
  <aside class="col-span-1">
    <ul class="space-y-1">
      <?php foreach ($reports as $r): $bn = basename($r); ?>
        <li>
          <a class="block text-sm px-2 py-1 rounded <?= $viewingFile === $r ? 'bg-cyan-100' : 'hover:bg-gray-100' ?>"
             href="?file=<?= esc($r) ?>"><?= esc($bn) ?></a>
        </li>
      <?php endforeach; ?>
      <?php if (!$reports): ?>
        <li class="text-gray-500 text-sm">No seed runs yet.</li>
      <?php endif; ?>
    </ul>
  </aside>
  <main class="col-span-3">
    <?php if ($viewing): ?>
      <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mb-4">
        <?php foreach (['scraped' => 'Scraped', 'auto_linked' => 'Auto-linked', 'queued' => 'Queued', 'new_rows' => 'New rows'] as $k => $label): ?>
          <div class="bg-white p-3 rounded border">
            <div class="text-xs text-gray-500"><?= esc($label) ?></div>
            <div class="text-xl font-bold"><?= number_format((int) ($viewing[$k] ?? 0)) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <details class="bg-white border rounded p-3">
        <summary class="cursor-pointer text-sm font-medium">Raw JSON</summary>
        <pre class="mt-2 text-xs overflow-x-auto"><?= esc(json_encode($viewing, JSON_PRETTY_PRINT)) ?></pre>
      </details>
    <?php else: ?>
      <p class="text-gray-500">Select a report.</p>
    <?php endif; ?>
  </main>
</div>
<?php adminFooter();
