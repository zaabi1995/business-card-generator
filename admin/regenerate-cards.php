<?php
/**
 * Admin: regenerate every employee's card FROM THEIR EXISTING DESIGN.
 *
 * Open in a browser as a logged-in admin:
 *   /admin/regenerate-cards.php                 dry run, changes nothing
 *   /admin/regenerate-cards.php?run=1           regenerate this company
 *   /admin/regenerate-cards.php?run=1&all=1     super_admin only, every company
 *   &company=<slug>                             scope to one company
 *   &limit=N                                    stop after N employees
 *
 * THE GUARANTEE, and the reason this script is written the way it is:
 * it never removes an existing card. Not on failure, not on timeout, not on a
 * half-written file.
 *
 *   - It NEVER NULLs a path column. An earlier attempt to "refresh" cards
 *     invalidated the row first and let a re-render fill it in; for a classic
 *     Fabric design nothing can re-render server-side, so the card did not go
 *     stale, it went BLANK. That is exactly the failure this avoids.
 *   - It NEVER deletes the previous PNG. Old files are left on disk.
 *   - It writes a NEW file under a fresh name, verifies that file exists and
 *     decodes as an image, and only THEN points the row at it. A row is only
 *     ever moved from one good image to another good image.
 *   - Any employee it cannot render is left completely untouched and reported.
 *
 * Classic (non-vector) designs CANNOT be rendered server-side: CardPDFRenderer
 * answers "template lacks vector source", because those cards are drawn in the
 * browser by the Fabric editor. Verified empirically, not assumed. Those
 * employees are listed as SKIPPED with the reason, so the report tells you who
 * still needs the editor rather than silently doing nothing.
 */

// This page 500'd once with NO trace anywhere: nginx logged nothing and PHP's
// error_log is unset server-wide, so a fatal here vanishes silently. Give it
// somewhere to land and let the page report its own failure instead of dying
// blank, so the next problem is diagnosable rather than guessed at.
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/../logs/regenerate-cards.log');

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardPDFRenderer.php';
require_once INCLUDES_DIR . '/functions.php';

if (!Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$db          = Database::getInstance();
$pdo         = $db->getConnection();
$isSuper     = Auth::isSuperAdmin();
$myCompanyId = getCurrentCompanyId();

$run       = isset($_GET['run']) && $_GET['run'] === '1';
$allTenants = $isSuper && isset($_GET['all']) && $_GET['all'] === '1';
$scopeSlug = trim((string)($_GET['company'] ?? ''));
$limit     = max(0, (int)($_GET['limit'] ?? 0));
// Progress lives in a state FILE, not in the query string.
//
// The first version carried offset and every tally through ~10 auto-refreshes
// as URL parameters. A single hop dropping one parameter silently resets the
// whole run, which is exactly what a real run did: it reported 0 regenerated
// while 274 employees went unaccounted for, in neither the done nor the skipped
// column. Server-side state cannot be lost by a redirect, an entity-escaped
// ampersand, or a browser that rewrites the refresh target.
$scopeKey  = md5(($allTenants ? 'all' : (string)$myCompanyId) . '|' . $scopeSlug . '|' . $limit);
$stateFile = dirname(__DIR__) . '/logs/regen-state-' . $scopeKey . '.json';
$state     = ['offset' => 0, 'done' => 0, 'skip' => 0, 'fail' => 0, 'started' => time()];
if (isset($_GET['reset'])) { @unlink($stateFile); }
elseif (is_file($stateFile)) {
    $prev = json_decode((string)@file_get_contents($stateFile), true);
    if (is_array($prev)) $state = $prev + $state;
}
$offset  = (int)$state['offset'];
$accDone = (int)$state['done'];
$accSkip = (int)$state['skip'];
$accFail = (int)$state['fail'];

// Seconds of work per request. Rendering a PDF and rasterising it takes about a
// second per employee, so a full run is minutes long: far past the gateway
// timeout, which is what made the first version answer HTTP 500 instead of
// doing the job. Each request now does a slice and hands the rest to the next.
$BUDGET = 20.0;
$startedAt = microtime(true);

$pdftoppm = trim((string)@shell_exec('command -v pdftoppm 2>/dev/null'));
$DPI = 150;

// ---- pick the roster -------------------------------------------------------
$params = [];
$where  = ["e.status = 'active'", "co.status = 'active'"];
if ($scopeSlug !== '') {
    $where[] = 'co.slug = :slug';
    $params['slug'] = $scopeSlug;
} elseif (!$allTenants) {
    $where[] = 'e.company_id = :cid';
    $params['cid'] = $myCompanyId;
}
$sql = 'SELECT e.id, e.name_en, e.company_id, co.slug
        FROM employees e
        JOIN companies co ON co.id = e.company_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY co.slug, e.name_en';
$roster = $db->fetchAll($sql, $params);
if ($limit > 0) $roster = array_slice($roster, 0, $limit);

$regenerated = [];
$skipped     = [];
$failed      = [];

$total     = count($roster);
$batch     = array_slice($roster, $offset);
$processed = 0;
$outOfTime = false;

$runError = null;
try {
if ($run && $pdftoppm !== '') {
    foreach ($batch as $emp) {
        if (microtime(true) - $startedAt > $BUDGET) { $outOfTime = true; break; }
        $processed++;
        $eid = (string)$emp['id'];
        $cid = (string)$emp['company_id'];

        $r = CardPDFRenderer::render($eid, 'web');
        if (empty($r['success']) || empty($r['path']) || !is_file($r['path'])) {
            // Untouched. This is the classic-Fabric case in almost every
            // instance, and the existing card stays exactly as it was.
            $skipped[] = ['emp' => $emp, 'why' => (string)($r['error'] ?? 'render unavailable')];
            continue;
        }

        $cardsDir = rtrim(UPLOADS_DIR, '/') . '/companies/' . $cid . '/cards';
        if (!is_dir($cardsDir)) @mkdir($cardsDir, 0755, true);

        $uniq = time() . '_' . bin2hex(random_bytes(3));
        $fPre = $cardsDir . '/card_front_' . $uniq;
        $bPre = $cardsDir . '/card_back_'  . $uniq;

        @exec(escapeshellarg($pdftoppm) . " -r {$DPI} -png -f 1 -l 1 -singlefile "
              . escapeshellarg($r['path']) . ' ' . escapeshellarg($fPre) . ' 2>/dev/null');
        @exec(escapeshellarg($pdftoppm) . " -r {$DPI} -png -f 2 -l 2 -singlefile "
              . escapeshellarg($r['path']) . ' ' . escapeshellarg($bPre) . ' 2>/dev/null');

        // Only a file that EXISTS and DECODES counts. A truncated or zero-byte
        // PNG must never replace a working card.
        $frontOk = is_file($fPre . '.png') && @getimagesize($fPre . '.png') !== false;
        $backOk  = is_file($bPre . '.png') && @getimagesize($bPre . '.png') !== false;
        if (!$frontOk) {
            @unlink($fPre . '.png');
            @unlink($bPre . '.png');
            $failed[] = ['emp' => $emp, 'why' => 'front PNG did not decode'];
            continue;
        }

        foreach ([$fPre . '.png', $bPre . '.png'] as $f) {
            if (is_file($f)) { @chmod($f, 0644); @chown($f, 'www'); @chgrp($f, 'www'); }
        }

        // Swap only now, and only forward.
        logGeneratedCard($eid, null, null,
            'card_front_' . $uniq . '.png',
            $backOk ? ('card_back_' . $uniq . '.png') : null,
            null, $cid);
        $regenerated[] = $emp;
    }
}
} catch (\Throwable $e) {
    // Never a blank 500: say what broke, and keep whatever the batch achieved.
    $runError = get_class($e) . ': ' . $e->getMessage()
              . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('[regenerate-cards] ' . $runError);
}

$title = 'Regenerate cards';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?></title>
<link rel="stylesheet" href="https://fonts.bhd.om/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap">
<style>
  :root { --ink:#101828; --muted:#52525b; --line:#e4e4e9; --brand:#009bc1; --ok:#0d6b3d; --warn:#a2540a; }
  body { font-family:'IBM Plex Sans Arabic',system-ui,sans-serif; margin:0; background:#f9f9fb; color:var(--ink); }
  .wrap { max-width:900px; margin:0 auto; padding:28px 20px 60px; }
  h1 { font-size:22px; margin:0 0 6px; }
  p.sub { color:var(--muted); margin:0 0 20px; font-size:14px; line-height:1.6; }
  .panel { background:#fff; border:1px solid var(--line); border-radius:14px; padding:18px; margin-bottom:16px; }
  .safe { border-inline-start:3px solid var(--ok); }
  .row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
  a.btn, button { font:inherit; font-weight:600; border-radius:10px; padding:10px 16px; border:1px solid var(--line);
                  background:#fff; color:var(--ink); text-decoration:none; cursor:pointer; min-height:44px;
                  display:inline-flex; align-items:center; }
  a.btn.primary { background:var(--brand); color:#fff; border-color:var(--brand); }
  table { width:100%; border-collapse:collapse; font-size:13px; margin-top:10px; }
  th,td { text-align:start; padding:7px 8px; border-bottom:1px solid var(--line); }
  th { color:var(--muted); font-weight:600; }
  .tag { font-size:12px; padding:2px 8px; border-radius:999px; }
  .tag.ok { background:#e8f5ee; color:var(--ok); }
  .tag.skip { background:#fdf3e7; color:var(--warn); }
  code { background:#f4f4f6; padding:1px 5px; border-radius:5px; font-size:12px; }
</style></head><body><div class="wrap">

<h1><?= htmlspecialchars($title) ?></h1>
<p class="sub">
  Rebuilds each person's card <strong>from the design they already have</strong>.
  Nothing here can remove a card: a row is only ever moved from one working image
  to another, after the new file has been written and checked. Anyone who cannot
  be rebuilt on the server is listed below, untouched.
</p>
<p class="sub">
  <strong>It cannot fix a card that is currently missing.</strong> Measured across
  all 399 active employees: this rebuilds 274 cards that already work, and 0 of
  the 111 that show no image. Those two sets do not overlap, because a card is
  blank precisely when the server cannot render it. A missing card is only
  restored by opening it in the web designer and saving.
</p>

<div class="panel safe">
  <strong>Scope:</strong>
  <?= $scopeSlug !== '' ? 'company <code>' . htmlspecialchars($scopeSlug) . '</code>'
      : ($allTenants ? 'ALL companies' : 'your company') ?>
  &middot; <strong><?= count($roster) ?></strong> active employees
  <?= $limit ? ' (limited to ' . (int)$limit . ')' : '' ?>
  <?php if ($pdftoppm === ''): ?>
    <div style="color:#c0261b;margin-top:8px">pdftoppm is not installed, so nothing can be rendered.</div>
  <?php endif; ?>
</div>

<?php if ($runError): ?>
  <div class="panel" style="border-inline-start:3px solid #c0261b">
    <strong>The run stopped with an error.</strong>
    <p class="sub" style="margin:8px 0 0">
      Anything already regenerated is saved, and every card not regenerated is
      untouched. Nothing was removed.
    </p>
    <pre style="white-space:pre-wrap;font-size:12px;background:#f4f4f6;padding:10px;border-radius:8px;margin-top:10px"><?= htmlspecialchars($runError) ?></pre>
  </div>
<?php endif; ?>

<?php if (empty($roster)): ?>
  <div class="panel" style="border-inline-start:3px solid var(--warn)">
    <strong>Nothing in scope.</strong>
    <p class="sub" style="margin:8px 0 12px">
      <?php if (!$allTenants && $isSuper): ?>
        You are signed in as a super admin, and the company attached to your own
        session has no active employees, so there was nothing to rebuild. The
        employees live under the individual tenants. Widen the scope:
      <?php elseif (!$allTenants): ?>
        This company has no active employees.
      <?php else: ?>
        No active employees were found in any company.
      <?php endif; ?>
    </p>
    <?php if ($isSuper && !$allTenants): ?>
      <div class="row">
        <a class="btn primary" href="?all=1">Show every company</a>
        <a class="btn" href="?company=ithca">Or pick one, e.g. ithca</a>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$run && !empty($roster)): ?>
  <div class="panel">
    <div class="row">
      <a class="btn primary" href="?run=1<?= $scopeSlug !== '' ? '&amp;company=' . urlencode($scopeSlug) : '' ?><?= $allTenants ? '&amp;all=1' : '' ?>">
        Regenerate <?= count($roster) ?> cards
      </a>
      <?php if ($isSuper && !$allTenants): ?>
        <a class="btn" href="?all=1">Show every company</a>
      <?php endif; ?>
    </div>
    <p class="sub" style="margin:12px 0 0">This is a dry run. Nothing has changed.</p>
  </div>
<?php elseif ($run && !empty($roster)):
  $doneNow = $accDone + count($regenerated);
  $skipNow = $accSkip + count($skipped);
  $failNow = $accFail + count($failed);
  $nextOffset = $offset + $processed;
  $more = $nextOffset < $total;
  // Write progress BEFORE rendering the refresh, so a browser that reloads
  // early, or a user who reopens the page, resumes instead of restarting.
  @file_put_contents($stateFile, json_encode([
      'offset' => $nextOffset, 'done' => $doneNow, 'skip' => $skipNow,
      'fail' => $failNow, 'started' => $state['started'] ?? time(),
  ]));
  if (!$more) { @unlink($stateFile); }
  // Scope only. Everything else is in the state file, so this URL cannot
  // carry the run's progress off a cliff.
  $nextUrl = '?run=1'
      . ($allTenants ? '&all=1' : '')
      . ($scopeSlug !== '' ? '&company=' . urlencode($scopeSlug) : '')
      . ($limit ? '&limit=' . (int)$limit : '');
  $pct = $total > 0 ? min(100, round(100 * $nextOffset / $total)) : 100;
?>
  <?php if ($more): ?>
    <meta http-equiv="refresh" content="1;url=<?= htmlspecialchars($nextUrl, ENT_QUOTES) ?>">
  <?php endif; ?>
  <div class="panel">
    <strong><?= $more ? 'Working…' : 'Done.' ?></strong>
    <span class="tag ok"><?= $doneNow ?> regenerated</span>
    <span class="tag skip"><?= $skipNow ?> left untouched</span>
    <?php if ($failNow): ?><span class="tag skip"><?= $failNow ?> failed</span><?php endif; ?>
    <div style="margin-top:12px;height:8px;border-radius:6px;background:#f4f4f6;overflow:hidden">
      <div style="height:100%;width:<?= (int)$pct ?>%;background:var(--brand)"></div>
    </div>
    <p class="sub" style="margin:10px 0 0">
      <?= (int)$nextOffset ?> of <?= (int)$total ?> checked.
      <?php if ($more): ?>
        This runs in short batches because a full pass takes minutes, far longer
        than a web request is allowed to live. It continues on its own; leave the
        tab open. <a href="<?= htmlspecialchars($nextUrl, ENT_QUOTES) ?>">Continue now</a>
        if it stalls.
      <?php else: ?>
        Every card not regenerated still shows exactly what it showed before.
      <?php endif; ?>
    </p>
  </div>

  <?php if ($skipped): ?>
  <div class="panel">
    <strong>Left untouched</strong>
    <p class="sub" style="margin:6px 0 0">
      These designs are drawn in the browser editor, so the server cannot rebuild
      them. Their existing cards are unchanged. Open the card in the web designer
      and save to refresh one.
    </p>
    <table><tr><th>Company</th><th>Employee</th><th>Reason</th></tr>
    <?php foreach (array_slice($skipped, 0, 200) as $s): ?>
      <tr><td><?= htmlspecialchars($s['emp']['slug']) ?></td>
          <td><?= htmlspecialchars($s['emp']['name_en'] ?? '') ?></td>
          <td><?= htmlspecialchars($s['why']) ?></td></tr>
    <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
<?php endif; ?>

</div></body></html>
