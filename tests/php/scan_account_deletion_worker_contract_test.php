<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;

function deletionWorkerCheck(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? 'PASS' : 'FAIL') . " $label\n";
    if (!$condition) {
        $failures++;
    }
}

function deletionWorkerSource(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    return $source;
}

$endpoint = deletionWorkerSource(
    $root . '/api/scan/delete-account.php'
);
$cleanup = deletionWorkerSource(
    $root . '/includes/ScanAccountDeletionCleanup.php'
);
$migration = deletionWorkerSource(
    $root . '/database/migrations/145_scan_account_deletion_cleanup.php'
);
$renderer = deletionWorkerSource(
    $root . '/includes/CardRenderer.php'
);
$worker = deletionWorkerSource(
    $root . '/scripts/process-scan-account-deletions.php'
);
$schedule = deletionWorkerSource(
    $root . '/ops/cardify-scan-account-cleanup.cron'
);
$deploy = deletionWorkerSource(
    $root . '/ops/deploy-cardify.sh'
);
$runbook = deletionWorkerSource(
    $root . '/ops/runbook.md'
);

deletionWorkerCheck(
    'deletion tombstones store only a one-way token hash',
    strpos($migration, 'confirmation_token_hash CHAR(64)') !== false
        && strpos($migration, 'COLLATE ascii_bin') !== false
        && strpos($migration, 'uniq_scan_account_delete_token_hash')
            !== false
        && strpos($endpoint, 'confirmation_token_hash') !== false
        && strpos($endpoint, 'presentedTokenHash') !== false
        && strpos(
            $endpoint,
            'ScanAuth::presentedBearerTokenHash()'
        ) !== false
);
deletionWorkerCheck(
    'supplied operation replay requires both operation and token hashes',
    preg_match(
        '/operation_id = :operation_id.*'
            . 'confirmation_token_hash = :confirmation_token_hash/s',
        $endpoint
    ) === 1
);
deletionWorkerCheck(
    'legacy omitted-operation replay uses only the token hash',
    strpos($endpoint, '$operationIdProvided') !== false
        && preg_match(
            '/if \\(\\$operationIdProvided\\).*'
                . 'confirmation_token_hash = :confirmation_token_hash.*'
                . 'else.*confirmation_token_hash = :confirmation_token_hash/s',
            $endpoint
        ) === 1
);
deletionWorkerCheck(
    'preauthentication lookup is rate limited before tombstone queries',
    preg_match(
        '/RateLimiter::check\\(.*'
            . 'scan_delete_account_confirmation.*'
            . 'scan_account_delete_operations/s',
        $endpoint
    ) === 1
);
deletionWorkerCheck(
    'unsafe paths become hash-only quarantine evidence',
    strpos($cleanup, "'last_error' => 'unsafe_scan_path'") !== false
        && strpos($cleanup, "'relative_path' => ''") !== false
        && strpos($cleanup, "'status' => 'quarantined'") !== false
        && strpos($cleanup, "'blocked'") === false
);
deletionWorkerCheck(
    'shared scan reference probes use indexed paths',
    strpos($migration, 'idx_scans_image_path (image_path)') !== false
        && strpos(
            $migration,
            'idx_scans_image_path_back (image_path_back)'
        ) !== false
        && strpos(
            $cleanup,
            'SELECT id FROM scans WHERE image_path = :front_path'
        ) !== false
        && strpos(
            $cleanup,
            'SELECT id FROM scans WHERE image_path_back = :back_path'
        ) !== false
        && strpos($cleanup, "status = 'waiting_reference'") !== false
        && strpos(
            $cleanup,
            "last_error = 'scan_path_still_referenced'"
        ) !== false
        && strpos(
            $cleanup,
            "'pending', 'failed', 'waiting_reference'"
        ) !== false
);
deletionWorkerCheck(
    'card design deletion queues durable postcommit invalidation',
    strpos(
        $migration,
        'scan_account_delete_render_invalidations'
    ) !== false
        && preg_match(
            '/queueRenderInvalidation\\(.*'
                . 'DELETE FROM card_designs WHERE employee_id/s',
            $endpoint
        ) === 1
        && strpos(
            $cleanup,
            'CardRenderer::invalidateForEmployee'
        ) !== false
        && strpos($renderer, 'UPDATE generated_cards') !== false
        && strpos($renderer, 'front_file_path = NULL') !== false
);
deletionWorkerCheck(
    'operation completion includes render invalidation backlog',
    strpos(
        $cleanup,
        'FROM scan_account_delete_render_invalidations'
    ) !== false
        && strpos($cleanup, 'processRenderQueueRow') !== false
        && strpos($cleanup, 'render_operation_id') !== false
);
deletionWorkerCheck(
    'cleanup worker is CLI-only and overlap-locked',
    strpos($worker, "PHP_SAPI !== 'cli'") !== false
        && strpos($worker, 'flock') !== false
        && strpos($worker, 'GET_LOCK') !== false
        && strpos($worker, 'RELEASE_LOCK') !== false
        && strpos($worker, 'processBacklog') !== false
        && strpos($worker, 'purgeExpiredTombstones') !== false
        && strpos($worker, 'unresolved quarantined paths') !== false
        && strpos($worker, 'paths waiting for references') !== false
);
deletionWorkerCheck(
    'completed replay identity has bounded 30-day retention',
    strpos($cleanup, 'purgeExpiredTombstones') !== false
        && strpos($cleanup, 'deleted_account_id = NULL') !== false
        && strpos($cleanup, 'deleted_employee_ids = NULL') !== false
        && strpos($cleanup, 'confirmation_token_hash = NULL') !== false
        && strpos($worker, '30,') !== false
        && strpos(
            $migration,
            'idx_scan_account_delete_retention'
        ) !== false
        && strpos($migration, '(status, updated_at)') !== false
);
deletionWorkerCheck(
    'cleanup worker has a committed five-minute schedule',
    strpos($schedule, '*/5 * * * * www ') !== false
        && strpos($schedule, 'TMPDIR=/tmp') !== false
        && strpos(
            $schedule,
            '/scripts/process-scan-account-deletions.php'
        ) !== false
        && strpos($schedule, '/usr/bin/logger') !== false
);
deletionWorkerCheck(
    'root deployment installs and verifies the cleanup schedule',
    strpos(
        $deploy,
        '/etc/cron.d/cardify-scan-account-cleanup'
    ) !== false
        && strpos($deploy, 'install -o root -g root -m 0644') !== false
        && strpos($deploy, 'cmp -s') !== false
        && strpos($deploy, 'runuser -u www') !== false
        && strpos($deploy, 'env TMPDIR=/tmp') !== false
        && strpos($deploy, '/www/server/php/83/bin/php') !== false
        && strpos($deploy, '/usr/bin/flock') !== false
        && strpos($deploy, '/usr/bin/logger') !== false
        && strpos(
            $deploy,
            'restore_scan_account_cleanup_cron'
        ) !== false
        && strpos(
            $deploy,
            'Account cleanup schedule installation failed. Rolling back.'
        ) !== false
        && substr_count(
            $deploy,
            'install_scan_account_cleanup_cron'
        ) >= 3
        && strpos(
            $runbook,
            '/etc/cron.d/cardify-scan-account-cleanup'
        ) !== false
        && strpos($runbook, 'runuser -u www') !== false
);

echo $failures === 0 ? "ALL PASS\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
