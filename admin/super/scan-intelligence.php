<?php
/**
 * Scan Intelligence (Super Admin) - every scanned card across every tenant,
 * with its business-activity category and where that category came from.
 *
 * Categories arrive from three places and they are NOT equal:
 *   device - the on-device pass in the app (instant, offline, always runs)
 *   server - an OpenRouter refine of a weak or missing device guess
 *   user   - a human decision, which neither of the other two may overwrite
 * The source is shown on every row so a wrong category can be traced to whoever
 * decided it instead of being argued about.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/admin-layout.php';
require_once INCLUDES_DIR . '/ScanCategorizer.php';

Auth::requireRole('super_admin');

$db = Database::getInstance();
$pdo = $db->getConnection();

$notice = null;
$noticeKind = 'success';

// Run a refine batch on demand. POST + redirect so a refresh cannot re-run it.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'categorize') {
    $result = ScanCategorizer::refineBatch($pdo, 20);
    if (!empty($result['errors'])) {
        $notice = 'Categorisation failed: ' . htmlspecialchars(implode('; ', $result['errors']));
        $noticeKind = 'error';
    } else {
        $notice = sprintf(
            'Examined %d, updated %d, skipped %d.',
            $result['examined'], $result['updated'], $result['skipped']
        );
    }
    $_SESSION['scan_intel_notice'] = $notice;
    $_SESSION['scan_intel_notice_kind'] = $noticeKind;
    header('Location: ' . getAdminBasePath() . 'scan-intelligence.php');
    exit;
}
if (!empty($_SESSION['scan_intel_notice'])) {
    $notice = $_SESSION['scan_intel_notice'];
    $noticeKind = $_SESSION['scan_intel_notice_kind'] ?? 'success';
    unset($_SESSION['scan_intel_notice'], $_SESSION['scan_intel_notice_kind']);
}

$filterCategory = trim((string)($_GET['category'] ?? ''));
$filterSource   = trim((string)($_GET['source'] ?? ''));
$search         = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($filterCategory !== '') {
    if ($filterCategory === '__none') {
        $where[] = 's.category IS NULL';
    } else {
        $where[] = 's.category = :cat';
        $params[':cat'] = $filterCategory;
    }
}
if ($filterSource !== '') {
    $where[] = 's.category_source = :src';
    $params[':src'] = $filterSource;
}
if ($search !== '') {
    $where[] = '(s.parsed LIKE :q OR s.tags LIKE :q OR s.met_where LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$totals = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(category IS NOT NULL) AS categorised,
        SUM(category_source = 'device') AS by_device,
        SUM(category_source = 'server') AS by_server,
        SUM(category_source = 'user') AS by_user,
        COUNT(DISTINCT company_id) AS companies,
        COUNT(DISTINCT employee_id) AS scanners
     FROM scans"
)->fetch(PDO::FETCH_ASSOC) ?: [];

$breakdown = $pdo->query(
    "SELECT COALESCE(category, '__none') AS category, COUNT(*) AS n
     FROM scans GROUP BY COALESCE(category, '__none') ORDER BY n DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT s.id, s.company_id, s.employee_id, s.parsed, s.tags, s.met_where,
            s.category, s.category_source, s.category_confidence, s.status,
            s.parse_tier, s.created_at,
            c.name AS company_name
     FROM scans s
     LEFT JOIN companies c ON c.id = s.company_id
     $whereSql
     ORDER BY s.id DESC
     LIMIT 300"
);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendingCount = count(ScanCategorizer::pending($pdo, 1000));

function intel_label(?string $category): string
{
    if ($category === null || $category === '' || $category === '__none') return 'Uncategorised';
    return ucwords(str_replace('_', ' ', $category));
}
function intel_field(array $parsed, string ...$keys): string
{
    foreach ($keys as $key) {
        $value = trim((string)($parsed[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return '';
}

adminHeader('Scan Intelligence', 'scan-intelligence');
?>
<div class="p-4 sm:p-6 space-y-6">

  <?php if ($notice): ?>
    <div class="rounded-xl px-4 py-3 text-sm <?= $noticeKind === 'error'
        ? 'bg-red-50 text-red-700 border border-red-200'
        : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
      <?= htmlspecialchars($notice) ?>
    </div>
  <?php endif; ?>

  <!-- Stat tiles -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <?php
    $tiles = [
      ['Scans', (int)($totals['total'] ?? 0), 'All cards scanned, every tenant'],
      ['Categorised', (int)($totals['categorised'] ?? 0), 'Has a business activity'],
      ['Awaiting AI', $pendingCount, 'Weak or missing category'],
      ['Companies', (int)($totals['companies'] ?? 0), 'Tenants with scans'],
    ];
    foreach ($tiles as [$label, $value, $hint]): ?>
      <div class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="text-3xl font-semibold text-gray-900 tabular-nums"><?= number_format($value) ?></div>
        <div class="mt-1 text-sm font-medium text-gray-700"><?= htmlspecialchars($label) ?></div>
        <div class="text-xs text-gray-400"><?= htmlspecialchars($hint) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Who decided the category -->
  <div class="bg-white rounded-2xl border border-gray-200 p-5">
    <div class="flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h2 class="text-base font-semibold text-gray-900">Where categories came from</h2>
        <p class="text-xs text-gray-500">A human decision is never overwritten by the device or the model.</p>
      </div>
      <form method="post" class="shrink-0">
        <input type="hidden" name="action" value="categorize">
        <button type="submit"
          class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-medium hover:bg-indigo-700 disabled:opacity-50"
          <?= $pendingCount === 0 ? 'disabled' : '' ?>>
          Categorise next <?= min(20, $pendingCount) ?> with AI
        </button>
      </form>
    </div>
    <div class="mt-4 grid grid-cols-3 gap-3 text-center">
      <?php foreach ([['On device', (int)($totals['by_device'] ?? 0)], ['Server AI', (int)($totals['by_server'] ?? 0)], ['Human', (int)($totals['by_user'] ?? 0)]] as [$l, $v]): ?>
        <div class="rounded-xl bg-gray-50 py-3">
          <div class="text-xl font-semibold text-gray-900 tabular-nums"><?= number_format($v) ?></div>
          <div class="text-xs text-gray-500"><?= $l ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Category breakdown -->
  <div class="bg-white rounded-2xl border border-gray-200 p-5">
    <h2 class="text-base font-semibold text-gray-900 mb-3">Business activity</h2>
    <?php if (!$breakdown): ?>
      <p class="text-sm text-gray-500">No scans yet.</p>
    <?php else: $max = max(array_column($breakdown, 'n')) ?: 1; ?>
      <div class="space-y-2">
        <?php foreach ($breakdown as $b): ?>
          <a href="?category=<?= urlencode($b['category']) ?>" class="flex items-center gap-3 group">
            <span class="w-40 shrink-0 text-sm text-gray-700 group-hover:text-indigo-600 truncate"><?= htmlspecialchars(intel_label($b['category'])) ?></span>
            <span class="flex-1 h-2 rounded-full bg-gray-100 overflow-hidden">
              <span class="block h-full bg-indigo-500" style="width: <?= (int)round(($b['n'] / $max) * 100) ?>%"></span>
            </span>
            <span class="w-10 text-right text-sm tabular-nums text-gray-500"><?= (int)$b['n'] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Every scan -->
  <div class="bg-white rounded-2xl border border-gray-200">
    <div class="p-5 border-b border-gray-100">
      <form method="get" class="flex flex-wrap gap-2 items-center">
        <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, company, tag"
               class="flex-1 min-w-[200px] px-3 py-2 rounded-xl border border-gray-200 text-sm">
        <select name="category" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
          <option value="">All activities</option>
          <option value="__none" <?= $filterCategory === '__none' ? 'selected' : '' ?>>Uncategorised</option>
          <?php foreach (ScanCategorizer::CATEGORIES as $cat): ?>
            <option value="<?= $cat ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= htmlspecialchars(intel_label($cat)) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="source" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
          <option value="">Any source</option>
          <?php foreach (['device' => 'On device', 'server' => 'Server AI', 'user' => 'Human'] as $k => $v): ?>
            <option value="<?= $k ?>" <?= $filterSource === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
        <button class="px-4 py-2 rounded-xl bg-gray-900 text-white text-sm">Filter</button>
      </form>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500">
          <tr>
            <th class="text-left px-4 py-3 font-medium">Person</th>
            <th class="text-left px-4 py-3 font-medium">Company</th>
            <th class="text-left px-4 py-3 font-medium">Activity</th>
            <th class="text-left px-4 py-3 font-medium">Source</th>
            <th class="text-left px-4 py-3 font-medium">Tenant</th>
            <th class="text-left px-4 py-3 font-medium">Scanned</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (!$rows): ?>
            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">No scans match.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r):
            $parsed = json_decode((string)$r['parsed'], true) ?: [];
            // Bilingual by design: an Omani card prints the name in BOTH
            // scripts on purpose, so show both instead of picking one.
            $personEn = trim((string)($parsed['name_en'] ?? ''));
            $personAr = trim((string)($parsed['name_ar'] ?? ''));
            $companyEn = trim((string)($parsed['company_en'] ?? ''));
            $companyAr = trim((string)($parsed['company_ar'] ?? ''));
            $titleEn = trim((string)($parsed['title_en'] ?? ''));
            $titleAr = trim((string)($parsed['title_ar'] ?? ''));
            $person = $personEn ?: $personAr;
            $company = $companyEn ?: $companyAr;
            $conf = $r['category_confidence'] !== null ? (float)$r['category_confidence'] : null;
          ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900"><?= htmlspecialchars($person ?: '-') ?></div>
                <?php if ($personEn && $personAr && $personEn !== $personAr): ?>
                  <div class="text-sm text-gray-600" dir="rtl"><?= htmlspecialchars($personAr) ?></div>
                <?php endif; ?>
                <div class="text-xs text-gray-400"><?= htmlspecialchars($titleEn ?: $titleAr) ?></div>
                <?php if ($titleEn && $titleAr && $titleEn !== $titleAr): ?>
                  <div class="text-xs text-gray-400" dir="rtl"><?= htmlspecialchars($titleAr) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-gray-700">
                <div><?= htmlspecialchars($company ?: '-') ?></div>
                <?php if ($companyEn && $companyAr && $companyEn !== $companyAr): ?>
                  <div class="text-xs text-gray-500" dir="rtl"><?= htmlspecialchars($companyAr) ?></div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center px-2 py-1 rounded-lg text-xs font-medium <?= $r['category'] ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-100 text-gray-500' ?>">
                  <?= htmlspecialchars(intel_label($r['category'])) ?>
                </span>
                <?php if ($conf !== null): ?>
                  <span class="ml-1 text-xs text-gray-400 tabular-nums"><?= number_format($conf * 100) ?>%</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs text-gray-500"><?= htmlspecialchars($r['category_source'] ?? '-') ?></td>
              <td class="px-4 py-3 text-xs text-gray-500"><?= htmlspecialchars($r['company_name'] ?? $r['company_id']) ?></td>
              <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap"><?= htmlspecialchars((string)$r['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($rows) >= 300): ?>
      <div class="px-4 py-3 text-xs text-gray-400 border-t border-gray-100">Showing the 300 most recent. Narrow with a filter to see older scans.</div>
    <?php endif; ?>
  </div>
</div>
<?php adminFooter(); ?>
