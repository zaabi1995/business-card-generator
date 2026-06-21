<?php
/**
 * Self-healing card-preview warmer.
 *
 * CardRenderer::invalidateForCompany() NULLs every generated_cards.*_file_path
 * row and deletes the on-disk card_*.png for a company (correct: a name /
 * template / renderer change must not leave stale previews). But nothing
 * regenerates them, so after ANY invalidation the whole roster reverts to the
 * "Generate Card" empty state on admin/employees.php until each card is opened
 * in the browser. This warmer rebuilds the missing preview PNGs server-side
 * from the canonical vector render, so the roster self-heals.
 *
 * Run every 5 min via cron (AFTER warm-vector-cache.php so the vector PDF is
 * already warm):
 *   /www/server/php/83/bin/php /www/wwwroot/cardify.om/scripts/warm-card-previews.php >> /var/log/cardify-preview-warm.log 2>&1
 *
 * Flags:
 *   --company=<id>   only this company (otherwise all vector-capable companies)
 *   --budget=<secs>  wall-time budget (default 240; cron-safe)
 *   --limit=<n>      stop after N regenerations (0 = no limit)
 *   --dpi=<n>        raster DPI for the preview PNG (default 200)
 *
 * Idempotent: an employee whose latest generated_cards row already has a
 * front_file_path is skipped, so repeated runs only fill gaps.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardPDFRenderer.php';
require_once INCLUDES_DIR . '/functions.php';
require_once INCLUDES_DIR . '/DatabaseAdapter.php';

$db     = Database::getInstance();
$start  = microtime(true);
$BUDGET = 240;
$LIMIT  = 0;
$DPI    = 200;
$ONLY   = null;
foreach ($argv as $a) {
    if (strncmp($a, '--company=', 10) === 0) $ONLY   = substr($a, 10);
    if (strncmp($a, '--budget=',  9) === 0) $BUDGET = max(10, (int)substr($a, 9));
    if (strncmp($a, '--limit=',   8) === 0) $LIMIT  = max(0,  (int)substr($a, 8));
    if (strncmp($a, '--dpi=',     6) === 0) $DPI    = max(72, (int)substr($a, 6));
}

// Disk guard: never fill the volume.
$free = @disk_free_space(BASE_DIR); $total = @disk_total_space(BASE_DIR);
if ($free !== false && $total !== false && $total > 0 && ($free / $total) < 0.20) {
    echo date('c') . " skip: disk free < 20%\n"; exit(0);
}

$pdftoppm = trim((string)@shell_exec('command -v pdftoppm 2>/dev/null'));
if ($pdftoppm === '') { echo date('c') . " ERROR: pdftoppm not found\n"; exit(1); }

// Vector-capable companies (front + back), optionally scoped.
$sql = "SELECT company_id FROM templates
         WHERE has_vector_source = 1 AND is_active = 1 AND deleted_at IS NULL";
$params = [];
if ($ONLY) { $sql .= " AND company_id = :c"; $params['c'] = $ONLY; }
$sql .= " GROUP BY company_id HAVING COUNT(DISTINCT side) >= 2";
$companies = $db->fetchAll($sql, $params);

$done = 0; $skipped = 0; $failed = 0;
foreach ($companies as $co) {
    $cid = $co['company_id'];

    // Active front/back template ids (rule 45 resolution order).
    $tpls = $db->fetchAll(
        "SELECT id, side FROM templates
          WHERE company_id = :c AND deleted_at IS NULL AND side IN ('front','back')
          ORDER BY has_vector_source DESC, created_at DESC",
        ['c' => $cid]
    );
    $frontTpl = null; $backTpl = null;
    foreach ($tpls as $t) {
        if ($t['side'] === 'front' && $frontTpl === null) $frontTpl = $t['id'];
        if ($t['side'] === 'back'  && $backTpl  === null) $backTpl  = $t['id'];
    }

    // Employees with no current preview (no generated_cards row carrying a path).
    $emps = $db->fetchAll(
        "SELECT e.id FROM employees e
          WHERE e.company_id = :c AND e.deleted_at IS NULL
            AND (e.status = 'active' OR e.status IS NULL)
            AND NOT EXISTS (
                SELECT 1 FROM generated_cards g
                 WHERE g.employee_id = e.id AND g.company_id = :c2
                   AND g.front_file_path IS NOT NULL AND g.front_file_path <> ''
            )",
        ['c' => $cid, 'c2' => $cid]
    );

    $cardsDir = getCompanyCardsDir($cid);
    foreach ($emps as $e) {
        if (microtime(true) - $start > $BUDGET) {
            echo date('c') . " stopped: time budget {$BUDGET}s reached\n";
            break 2;
        }
        if ($LIMIT > 0 && $done >= $LIMIT) { break 2; }

        $eid = $e['id'];
        $r = CardPDFRenderer::render((string)$eid, 'web');
        if (empty($r['success']) || !is_file($r['path'])) { $failed++; continue; }
        $pdf = $r['path'];

        $uniq  = time() . '_' . bin2hex(random_bytes(3));
        $front = 'card_front_' . $uniq . '.png';
        $back  = 'card_back_'  . $uniq . '.png';
        $fPre  = $cardsDir . '/card_front_' . $uniq;   // pdftoppm -singlefile adds .png
        $bPre  = $cardsDir . '/card_back_'  . $uniq;

        // Page 1 = front, page 2 = back.
        @exec(escapeshellarg($pdftoppm) . " -r {$DPI} -png -f 1 -l 1 -singlefile "
              . escapeshellarg($pdf) . ' ' . escapeshellarg($fPre) . ' 2>/dev/null');
        @exec(escapeshellarg($pdftoppm) . " -r {$DPI} -png -f 2 -l 2 -singlefile "
              . escapeshellarg($pdf) . ' ' . escapeshellarg($bPre) . ' 2>/dev/null');

        $haveFront = is_file($fPre . '.png');
        $haveBack  = is_file($bPre . '.png');
        if (!$haveFront) { $failed++; continue; } // need at least a front for the preview

        foreach ([$fPre . '.png', $bPre . '.png'] as $f) {
            if (is_file($f)) { @chmod($f, 0644); @chown($f, 'www'); @chgrp($f, 'www'); }
        }
        logGeneratedCard((string)$eid, $frontTpl, $backTpl,
                         $front, $haveBack ? $back : null, null, $cid);
        $done++;
        echo date('c') . " ok {$cid} {$eid}\n";
    }
}

echo date('c') . " done: regenerated={$done} failed={$failed} elapsed="
   . round(microtime(true) - $start, 1) . "s\n";
