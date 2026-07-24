<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/ScanAccountDeletionCleanup.php';

$localLock = fopen(
    sys_get_temp_dir() . '/cardify-scan-account-cleanup.lock',
    'c'
);
if ($localLock === false) {
    fwrite(STDERR, "Unable to open account cleanup lock\n");
    exit(1);
}
if (!flock($localLock, LOCK_EX | LOCK_NB)) {
    fclose($localLock);
    exit(0);
}

$databaseLockAcquired = false;
$pdo = null;
$exitCode = 0;
try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable');
    }
    $lockName = 'cardify_scan_account_cleanup_v1';
    $acquire = $pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
    $acquire->execute(['lock_name' => $lockName]);
    $databaseLockAcquired = (int) $acquire->fetchColumn() === 1;
    if ($databaseLockAcquired) {
        $cleanup = ScanAccountDeletionCleanup::processBacklog($db, 20);
        if (($cleanup['quarantined'] ?? 0) > 0) {
            error_log(
                '[scan/account-cleanup] unresolved quarantined paths: '
                . (int) $cleanup['quarantined']
            );
        }
        if (($cleanup['waiting_reference'] ?? 0) > 0) {
            error_log(
                '[scan/account-cleanup] paths waiting for references: '
                . (int) $cleanup['waiting_reference']
            );
        }
        $purge = ScanAccountDeletionCleanup::purgeExpiredTombstones(
            $db,
            30,
            100
        );
        echo json_encode(
            [
                'timestamp' => gmdate('c'),
                'cleanup' => $cleanup,
                'purge' => $purge,
            ],
            JSON_UNESCAPED_SLASHES
        ) . PHP_EOL;
    }
} catch (Throwable $e) {
    $exitCode = 1;
    error_log(
        '[scan/account-cleanup] worker failed: '
        . $e->getMessage()
    );
    fwrite(STDERR, "Account cleanup worker failed\n");
} finally {
    if ($databaseLockAcquired && $pdo instanceof PDO) {
        try {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute([
                'lock_name' => 'cardify_scan_account_cleanup_v1',
            ]);
        } catch (Throwable $releaseError) {
            $exitCode = 1;
            error_log(
                '[scan/account-cleanup] lock release failed: '
                . $releaseError->getMessage()
            );
        }
    }
    flock($localLock, LOCK_UN);
    fclose($localLock);
}

exit($exitCode);
