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

// This POST spends real OpenRouter credit, so it carries the same CSRF guard as
// every other super-admin write.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    die('Invalid request');
}

// Run a refine batch on demand. POST + redirect so a refresh cannot re-run it.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'categorize') {
    $result = ScanCategorizer::refineBatch($pdo, 20);
    if (!empty($result['errors'])) {
        // NOT escaped here: the value is escaped once at the render site. Doing
        // it in both places printed raw &amp;quot; entities in the banner.
        $notice = 'Categorisation failed: ' . implode('; ', $result['errors']);
        $noticeKind = 'error';
    } else {
        $notice = sprintf(
            'Examined %d, updated %d, skipped %d.',
            $result['examined'], $result['updated'], $result['skipped']
        );
    }
    $_SESSION['scan_intel_notice'] = $notice;
    $_SESSION['scan_intel_notice_kind'] = $noticeKind;
    // HARD: this page lives in admin/super/, but getAdminBasePath() returns the
    // TENANT admin base (/admin/). Redirecting there 404s, so every AI run
    // dead-ended on a missing page and the operator never saw the result even
    // though the batch had already been charged and written. Keep the current
    // filters too, or the redirect silently resets the view.
    $back = getBasePath() . 'admin/super/scan-intelligence.php';
    $keep = array_filter([
        'category' => (string)($_POST['back_category'] ?? ''),
        'source'   => (string)($_POST['back_source'] ?? ''),
        'q'        => (string)($_POST['back_q'] ?? ''),
    ], static fn($v) => $v !== '');
    if ($keep) $back .= '?' . http_build_query($keep);
    header('Location: ' . $back);
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
    // THREE placeholders, not one reused three times: this connection runs with
    // ATTR_EMULATE_PREPARES = 0, and a native prepare rejects a repeated named
    // parameter with "Invalid parameter number". The search box 500'd on every
    // query before this.
    // ESCAPE as well, or searching for "_" matches any character and "%"
    // returns the whole table.
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
    $where[] = "(s.parsed LIKE :q1 ESCAPE '\\\\'"
             . " OR s.tags LIKE :q2 ESCAPE '\\\\'"
             . " OR s.met_where LIKE :q3 ESCAPE '\\\\')";
    $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $escaped . '%';
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

$pendingCapped = false;
$pendingCount = ScanCategorizer::pendingCount($pdo, 500, $pendingCapped);

$scanners = $pdo->query(
    "SELECT s.employee_id,
            COUNT(*) AS scans,
            SUM(s.category IS NOT NULL) AS categorised,
            MAX(s.created_at) AS last_scan,
            e.name_en, e.name_ar, e.email,
            c.name AS company_name
     FROM scans s
     LEFT JOIN employees e ON e.id = s.employee_id
     LEFT JOIN companies c ON c.id = s.company_id
     GROUP BY s.employee_id, e.name_en, e.name_ar, e.email, c.name
     ORDER BY scans DESC
     LIMIT 100"
)->fetchAll(PDO::FETCH_ASSOC);

// What each scanner actually collects, so the table can say "mostly finance"
// rather than only how many cards they took.
$mixRows = $pdo->query(
    "SELECT employee_id, COALESCE(category, '__none') AS category, COUNT(*) AS n
     FROM scans
     GROUP BY employee_id, COALESCE(category, '__none')"
)->fetchAll(PDO::FETCH_ASSOC);
$mix = [];
foreach ($mixRows as $row) {
    $mix[$row['employee_id']][$row['category']] = (int)$row['n'];
}



function intel_label(?string $category): string
{
    if ($category === null || $category === '' || $category === '__none') return 'Uncategorised';
    return ucwords(str_replace('_', ' ', $category));
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
      // "500+" rather than a confidently wrong exact number once the scan
      // count outgrows the cap.
      ['Awaiting AI', $pendingCapped ? number_format($pendingCount) . '+' : $pendingCount, 'Weak or missing category'],
      ['Scanners', (int)($totals['scanners'] ?? 0), 'People collecting cards'],
    ];
    foreach ($tiles as [$label, $value, $hint]): ?>
      <div class="bg-white rounded-2xl border border-gray-200 p-5">
        <div class="text-3xl font-semibold text-gray-900 tabular-nums"><?= is_string($value) ? htmlspecialchars($value) : number_format($value) ?></div>
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
        <?= csrfField() ?>
        <input type="hidden" name="action" value="categorize">
        <?php // Carry the current view through the redirect. ?>
        <input type="hidden" name="back_category" value="<?= htmlspecialchars($filterCategory) ?>">
        <input type="hidden" name="back_source" value="<?= htmlspecialchars($filterSource) ?>">
        <input type="hidden" name="back_q" value="<?= htmlspecialchars($search) ?>">
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
          <?php // Keep the other active filters, or clicking a bar silently
                // widened the result set the operator was looking at. ?>
          <a href="?<?= htmlspecialchars(http_build_query(array_filter([
                'category' => $b['category'],
                'source'   => $filterSource,
                'q'        => $search,
             ], static fn($v) => (string)$v !== ''))) ?>" class="flex items-center gap-3 group">
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

  <!-- Who is scanning -->
  <div class="bg-white rounded-2xl border border-gray-200">
    <div class="p-5 border-b border-gray-100">
      <h2 class="text-base font-semibold text-gray-900">Who is scanning</h2>
      <p class="text-xs text-gray-500">Every person collecting cards, what they collect, and when they last scanned.</p>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500">
          <tr>
            <th class="text-left px-4 py-3 font-medium">Person</th>
            <th class="text-left px-4 py-3 font-medium">Tenant</th>
            <th class="text-left px-4 py-3 font-medium">Scans</th>
            <th class="text-left px-4 py-3 font-medium">Categorised</th>
            <th class="text-left px-4 py-3 font-medium">Mostly</th>
            <th class="text-left px-4 py-3 font-medium">Last scan</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <?php if (!$scanners): ?>
            <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Nobody has scanned yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($scanners as $sc):
            $nameEn = trim((string)($sc['name_en'] ?? ''));
            $nameAr = trim((string)($sc['name_ar'] ?? ''));
            // A scan can carry no employee at all (guest / pre-account rows).
            // Falling through to a null employee_id printed a blank Person cell.
            $primary = $nameEn ?: ($nameAr ?: (trim((string)($sc['email'] ?? '')) ?: (trim((string)($sc['employee_id'] ?? '')) ?: 'Unknown scanner')));
            $rowMix = $mix[$sc['employee_id']] ?? [];
            arsort($rowMix);
            $topCategory = null;
            foreach ($rowMix as $cat => $n) {
                if ($cat !== '__none') { $topCategory = [$cat, $n]; break; }
            }
          ?>
            <tr class="hover:bg-gray-50">
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900"><?= htmlspecialchars((string)$primary) ?></div>
                <?php if ($nameEn && $nameAr && $nameEn !== $nameAr): ?>
                  <div class="text-sm text-gray-600" dir="rtl"><?= htmlspecialchars($nameAr) ?></div>
                <?php endif; ?>
                <div class="text-xs text-gray-400"><?= htmlspecialchars((string)($sc['email'] ?? '')) ?></div>
              </td>
              <td class="px-4 py-3 text-xs text-gray-500"><?= htmlspecialchars((string)($sc['company_name'] ?? '-')) ?></td>
              <td class="px-4 py-3 tabular-nums text-gray-900"><?= number_format((int)$sc['scans']) ?></td>
              <td class="px-4 py-3 tabular-nums text-gray-500"><?= number_format((int)$sc['categorised']) ?></td>
              <td class="px-4 py-3">
                <?php if ($topCategory): ?>
                  <span class="inline-flex items-center px-2 py-1 rounded-lg text-xs font-medium bg-indigo-50 text-indigo-700">
                    <?= htmlspecialchars(intel_label($topCategory[0])) ?>
                  </span>
                  <span class="ml-1 text-xs text-gray-400 tabular-nums"><?= (int)$topCategory[1] ?></span>
                <?php else: ?>
                  <span class="text-xs text-gray-400">-</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap"><?= htmlspecialchars((string)$sc['last_scan']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
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
            <option value="<?= htmlspecialchars($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= htmlspecialchars(intel_label($cat)) ?></option>
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
