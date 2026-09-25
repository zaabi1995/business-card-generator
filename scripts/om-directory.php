<?php
/**
 * Incremental Oman directory jobs. Never loads the full table at once.
 *
 *   php scripts/om-directory.php score
 *   php scripts/om-directory.php queue-matches
 *   php scripts/om-directory.php export-cleartax
 *   php scripts/om-directory.php metrics
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/CompanyDirectory.php';

$cmd = $argv[1] ?? 'metrics';
$db = Database::getInstance();
if (!CompanyDirectory::ensureReady($db)) {
    fwrite(STDERR, "Migration 170 has not been applied.\n");
    exit(1);
}

$started = microtime(true);
try {
    if ($cmd === 'metrics') {
        echo json_encode(CompanyDirectory::metrics($db), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
        exit(0);
    }
    if ($cmd === 'score') {
        $after = 0;
        $batches = 0;
        while (true) {
            $next = CompanyDirectory::scoreBatch($db, $after, 200);
            if ($next === $after) {
                break;
            }
            $after = $next;
            $batches++;
        }
        $ms = (int) round((microtime(true) - $started) * 1000);
        CompanyDirectory::recordJob($db, 'score', 'ok', $batches, null, $ms, 'om_companies');
        echo "scored through id $after in {$batches} batches\n";
        exit(0);
    }
    if ($cmd === 'queue-matches') {
        $n = CompanyDirectory::queueDeterministicCandidates($db);
        $ms = (int) round((microtime(true) - $started) * 1000);
        CompanyDirectory::recordJob($db, 'queue-matches', 'ok', $n, null, $ms, 'normalized_name_and_domain');
        echo "new review pairs: $n\n";
        exit(0);
    }
    if ($cmd === 'export-cleartax') {
        $result = CompanyDirectory::exportSnapshot(
            $db,
            'ClearTax (offer FU-884, not a new send)',
            'cleartax-offer-2026-09-25',
            ['name_en', 'name_ar', 'sector', 'wilayat', 'size_bucket', 'website', 'summary_en'],
            'Internal use only. No resale. Company-level fields only.',
            '500.000'
        );
        $ms = (int) round((microtime(true) - $started) * 1000);
        CompanyDirectory::recordJob($db, 'export', 'ok', $result['count'], null, $ms, 'om_companies');
        echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }
    fwrite(STDERR, "Unknown command\n");
    exit(1);
} catch (Throwable $e) {
    $ms = (int) round((microtime(true) - $started) * 1000);
    try {
        CompanyDirectory::recordJob($db, $cmd, 'failed', 0, $e->getMessage(), $ms, null);
    } catch (Throwable $ignore) {
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
