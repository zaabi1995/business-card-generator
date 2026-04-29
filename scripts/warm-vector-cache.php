<?php
/**
 * Async vector PDF cache warmer.
 *
 * Walks every active employee in companies that have both front + back
 * vector-capable templates, calls CardPDFRenderer::render() (idempotent:
 * returns cached path if warm, renders if cold), and prints one status
 * line per employee.
 *
 * Designed to run every 5 minutes via cron:
 *   Every5Min schedule: /www/server/php/83/bin/php /www/wwwroot/cardify.om/scripts/warm-vector-cache.php >> /var/log/cardify-vector-warm.log 2>&1
 *   Cron expression: [star]/5 [star] [star] [star] [star]
 *
 * Safety guards:
 *   - Stops after 240s wall time (cron interval is 5 min, leaves buffer)
 *   - Skips entirely if disk free < 20% (avoids filling the volume)
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CardPDFRenderer.php';

$db    = Database::getInstance();
$start = microtime(true);
$BUDGET = 240; // seconds

// Disk guard: skip if less than 20% free.
$free  = @disk_free_space(BASE_DIR);
$total = @disk_total_space(BASE_DIR);
if ($free !== false && $total !== false && $total > 0 && ($free / $total) < 0.20) {
    echo date('c') . " skip: disk free < 20% ({$free}/{$total})\n";
    exit(0);
}

// Find companies that have both front AND back with has_vector_source=1.
$companies = $db->fetchAll("
    SELECT company_id
    FROM templates
    WHERE has_vector_source = 1
      AND is_active = 1
      AND deleted_at IS NULL
    GROUP BY company_id
    HAVING COUNT(DISTINCT side) >= 2
");

if (empty($companies)) {
    echo date('c') . " no vector-capable companies found\n";
    exit(0);
}

$warmed  = 0;
$cached  = 0;
$skipped = 0;

foreach ($companies as $c) {
    if (microtime(true) - $start > $BUDGET) {
        echo date('c') . " stopped: time budget exceeded\n";
        break;
    }

    $employees = $db->fetchAll(
        "SELECT id FROM employees WHERE company_id = :cid AND status = 'active'",
        ['cid' => $c['company_id']]
    );

    foreach ($employees as $e) {
        if (microtime(true) - $start > $BUDGET) {
            break 2;
        }

        $r = CardPDFRenderer::render((string)$e['id']);

        if (!empty($r['success'])) {
            if (!empty($r['cached'])) {
                $cached++;
                echo "cached  {$e['id']}\n";
            } else {
                $warmed++;
                echo "warmed  {$e['id']}\n";
            }
        } else {
            $skipped++;
            $reason = $r['error'] ?? 'unknown';
            if ($reason === 'template lacks vector source') {
                echo "skip-vector {$e['id']}\n";
            } else {
                echo "skip    {$e['id']}: {$reason}\n";
            }
        }
    }
}

$elapsed = microtime(true) - $start;
printf(
    "%s done in %.1fs: %d warmed, %d cached, %d skipped\n",
    date('c'), $elapsed, $warmed, $cached, $skipped
);
