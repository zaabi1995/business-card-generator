<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ScanAuth.php';

final class ScanAccountDeletionCleanup
{
    private const TERMINAL_STATUSES = ['completed', 'quarantined'];

    public static function generateOperationId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20);
    }

    public static function presentedBearerTokenHash(
        ?array $server = null,
        ?array $headers = null
    ): ?string {
        return ScanAuth::presentedBearerTokenHash($server, $headers);
    }

    public static function normalizeOwnedPath(
        ?string $relativePath,
        string $employeeId
    ): ?string {
        if ($relativePath === null) {
            return null;
        }
        $relativePath = trim($relativePath);
        $employeeId = trim($employeeId);
        if (
            $relativePath === ''
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $employeeId) !== 1
        ) {
            return null;
        }
        $pattern = '/^uploads\/scans\/'
            . preg_quote($employeeId, '/')
            . '\/[0-9a-f]{24}\.(?:jpg|png|webp)$/D';
        return preg_match($pattern, $relativePath) === 1
            ? $relativePath
            : null;
    }

    public static function queueOwnedPath(
        PDO $pdo,
        string $operationId,
        string $employeeId,
        ?string $relativePath
    ): void {
        if ($relativePath === null || trim($relativePath) === '') {
            return;
        }
        $originalPath = trim($relativePath);
        $normalizedPath = self::normalizeOwnedPath(
            $originalPath,
            $employeeId
        );
        if ($normalizedPath === null) {
            $quarantine = $pdo->prepare(
                'INSERT INTO scan_account_delete_files
                    (
                        operation_id,
                        relative_path,
                        path_hash,
                        status,
                        attempts,
                        last_error,
                        completed_at
                    )
                 VALUES
                    (
                        :operation_id,
                        :relative_path,
                        :path_hash,
                        :status,
                        :attempts,
                        :last_error,
                        NOW()
                    )
                 ON DUPLICATE KEY UPDATE operation_id = operation_id'
            );
            $quarantine->execute([
                'operation_id' => $operationId,
                'relative_path' => '',
                'path_hash' => hash('sha256', $originalPath),
                'status' => 'quarantined',
                'attempts' => 1,
                'last_error' => 'unsafe_scan_path',
            ]);
            return;
        }
        $stmt = $pdo->prepare(
            'INSERT INTO scan_account_delete_files
                (operation_id, relative_path, path_hash, status, last_error)
             VALUES
                (:operation_id, :relative_path, :path_hash, :status, :last_error)
             ON DUPLICATE KEY UPDATE operation_id = operation_id'
        );
        $stmt->execute([
            'operation_id' => $operationId,
            'relative_path' => $normalizedPath,
            'path_hash' => hash('sha256', $originalPath),
            'status' => 'pending',
            'last_error' => null,
        ]);
    }

    public static function queueRenderInvalidation(
        PDO $pdo,
        string $operationId,
        string $employeeId
    ): void {
        $operationId = trim($operationId);
        $employeeId = trim($employeeId);
        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-'
                    . '[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
                $operationId
            ) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,36}$/D', $employeeId) !== 1
        ) {
            throw new InvalidArgumentException(
                'invalid_render_invalidation_owner'
            );
        }
        $stmt = $pdo->prepare(
            'INSERT INTO scan_account_delete_render_invalidations
                (operation_id, employee_id, status, last_error)
             VALUES
                (:operation_id, :employee_id, :status, :last_error)
             ON DUPLICATE KEY UPDATE operation_id = operation_id'
        );
        $stmt->execute([
            'operation_id' => strtolower($operationId),
            'employee_id' => $employeeId,
            'status' => 'pending',
            'last_error' => null,
        ]);
    }

    public static function processOperation(
        Database $db,
        string $operationId,
        int $limit = 100
    ): bool {
        $pdo = $db->getConnection();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection is unavailable');
        }
        $limit = max(1, min(500, $limit));
        $stmt = $pdo->prepare(
            "SELECT id
             FROM scan_account_delete_files
             WHERE operation_id = :operation_id
               AND status IN ('pending', 'failed', 'waiting_reference')
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        $stmt->execute(['operation_id' => $operationId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            self::processQueueRow($pdo, (int) $id);
        }

        $renderStmt = $pdo->prepare(
            "SELECT id
             FROM scan_account_delete_render_invalidations
             WHERE operation_id = :operation_id
               AND status IN ('pending', 'failed')
             ORDER BY id ASC
             LIMIT {$limit}"
        );
        $renderStmt->execute(['operation_id' => $operationId]);
        $renderIds = $renderStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($renderIds as $renderId) {
            self::processRenderQueueRow($pdo, (int) $renderId);
        }

        $remaining = $pdo->prepare(
            "SELECT
                (
                    SELECT COUNT(*)
                    FROM scan_account_delete_files
                    WHERE operation_id = :file_operation_id
                      AND status NOT IN (
                          'completed',
                          'quarantined',
                          'waiting_reference'
                      )
                )
                +
                (
                    SELECT COUNT(*)
                    FROM scan_account_delete_render_invalidations
                    WHERE operation_id = :render_operation_id
                      AND status <> 'completed'
                )"
        );
        $remaining->execute([
            'file_operation_id' => $operationId,
            'render_operation_id' => $operationId,
        ]);
        return (int) $remaining->fetchColumn() === 0;
    }

    public static function processBacklog(
        Database $db,
        int $operationLimit = 3
    ): array {
        $pdo = $db->getConnection();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection is unavailable');
        }
        $operationLimit = max(1, min(20, $operationLimit));
        $rows = $pdo->query(
            "SELECT operation_id
             FROM (
                 SELECT operation_id, updated_at
                 FROM scan_account_delete_files
                 WHERE status IN (
                     'pending',
                     'failed',
                     'waiting_reference'
                 )
                 UNION ALL
                 SELECT operation_id, updated_at
                 FROM scan_account_delete_render_invalidations
                 WHERE status IN ('pending', 'failed')
             ) AS cleanup_jobs
             GROUP BY operation_id
             ORDER BY MIN(updated_at) ASC
             LIMIT {$operationLimit}"
        )->fetchAll(PDO::FETCH_COLUMN);
        $stats = [
            'operations' => count($rows),
            'completed' => 0,
            'pending' => 0,
        ];
        foreach ($rows as $operationId) {
            try {
                $complete = self::processOperation(
                    $db,
                    (string) $operationId,
                    50
                );
                $stats[$complete ? 'completed' : 'pending']++;
            } catch (Throwable $e) {
                $stats['pending']++;
                error_log(
                    '[scan/account-cleanup] backlog operation failed: '
                    . $e->getMessage()
                );
            }
        }
        $evidence = $pdo->query(
            "SELECT
                (
                    SELECT COUNT(*)
                    FROM scan_account_delete_files
                    WHERE status = 'waiting_reference'
                ) AS waiting_reference,
                (
                    SELECT COUNT(*)
                    FROM scan_account_delete_files
                    WHERE status = 'quarantined'
                ) AS quarantined"
        )->fetch(PDO::FETCH_ASSOC);
        $stats['waiting_reference'] = (int) (
            $evidence['waiting_reference'] ?? 0
        );
        $stats['quarantined'] = (int) ($evidence['quarantined'] ?? 0);
        return $stats;
    }

    public static function purgeExpiredTombstones(
        Database $db,
        int $retentionDays = 30,
        int $limit = 100
    ): array {
        $pdo = $db->getConnection();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Database connection is unavailable');
        }
        $retentionDays = max(1, min(365, $retentionDays));
        $limit = max(1, min(500, $limit));
        $operationIds = $pdo->query(
            "SELECT operation_id
             FROM scan_account_delete_operations
             WHERE status = 'completed'
               AND updated_at < DATE_SUB(
                   NOW(),
                   INTERVAL {$retentionDays} DAY
               )
             ORDER BY updated_at ASC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_COLUMN);
        $stats = [
            'selected' => count($operationIds),
            'deleted' => 0,
            'anonymized' => 0,
            'failed' => 0,
        ];
        foreach ($operationIds as $operationId) {
            $pdo->beginTransaction();
            try {
                $locked = $pdo->prepare(
                    "SELECT operation_id
                     FROM scan_account_delete_operations
                     WHERE operation_id = :operation_id
                       AND status = 'completed'
                       AND updated_at < DATE_SUB(
                           NOW(),
                           INTERVAL {$retentionDays} DAY
                       )
                     FOR UPDATE"
                );
                $locked->execute(['operation_id' => $operationId]);
                if (!$locked->fetchColumn()) {
                    $pdo->commit();
                    continue;
                }
                $pdo->prepare(
                    "DELETE FROM scan_account_delete_files
                     WHERE operation_id = :operation_id
                       AND status = 'completed'"
                )->execute(['operation_id' => $operationId]);
                $pdo->prepare(
                    "DELETE FROM scan_account_delete_render_invalidations
                     WHERE operation_id = :operation_id
                       AND status = 'completed'"
                )->execute(['operation_id' => $operationId]);
                $remaining = $pdo->prepare(
                    "SELECT
                        (
                            SELECT COUNT(*)
                            FROM scan_account_delete_files
                            WHERE operation_id = :file_operation_id
                              AND status NOT IN (
                                  'completed',
                                  'quarantined'
                              )
                        )
                        +
                        (
                            SELECT COUNT(*)
                            FROM scan_account_delete_render_invalidations
                            WHERE operation_id = :render_operation_id
                        )"
                );
                $remaining->execute([
                    'file_operation_id' => $operationId,
                    'render_operation_id' => $operationId,
                ]);
                if ((int) $remaining->fetchColumn() === 0) {
                    $deleted = $pdo->prepare(
                        'DELETE FROM scan_account_delete_operations
                         WHERE operation_id = :operation_id'
                    );
                    $deleted->execute(['operation_id' => $operationId]);
                    $stats['deleted'] += $deleted->rowCount();
                } else {
                    $anonymized = $pdo->prepare(
                        'UPDATE scan_account_delete_operations
                         SET confirmation_token_hash = NULL,
                             deleted_account_id = NULL,
                             deleted_employee_ids = NULL,
                             updated_at = updated_at
                         WHERE operation_id = :operation_id'
                    );
                    $anonymized->execute(['operation_id' => $operationId]);
                    $stats['anonymized'] += $anonymized->rowCount();
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $stats['failed']++;
                error_log(
                    '[scan/account-cleanup] tombstone purge: '
                    . $e->getMessage()
                );
            }
        }
        return $stats;
    }

    public static function deleteRelativePath(
        string $relativePath,
        string $baseDirectory
    ): array {
        if (
            preg_match(
                '/^uploads\/scans\/[A-Za-z0-9_-]{1,64}\/'
                    . '[0-9a-f]{24}\.(?:jpg|png|webp)$/D',
                $relativePath
            ) !== 1
        ) {
            return ['success' => false, 'error' => 'unsafe_scan_path'];
        }
        $baseReal = realpath($baseDirectory);
        if ($baseReal === false || !is_dir($baseReal)) {
            return ['success' => false, 'error' => 'base_directory_unavailable'];
        }
        $scanBase = $baseReal . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'scans';
        $scanBaseReal = realpath($scanBase);
        if ($scanBaseReal === false) {
            return [
                'success' => false,
                'error' => 'scan_directory_unavailable',
            ];
        }
        if (!hash_equals($scanBase, $scanBaseReal)) {
            return ['success' => false, 'error' => 'scan_directory_redirected'];
        }
        $absolutePath = $baseReal . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $parentReal = realpath(dirname($absolutePath));
        $pathParts = explode('/', $relativePath);
        $expectedParent = $scanBaseReal
            . DIRECTORY_SEPARATOR
            . $pathParts[2];
        $requiredPrefix = $scanBaseReal . DIRECTORY_SEPARATOR;
        if ($parentReal === false) {
            $parentPath = dirname($absolutePath);
            if (file_exists($parentPath) || is_link($parentPath)) {
                return [
                    'success' => false,
                    'error' => 'scan_owner_directory_unavailable',
                ];
            }
            return ['success' => true, 'error' => null];
        }
        if (
            !hash_equals($expectedParent, $parentReal)
        ) {
            return ['success' => false, 'error' => 'scan_directory_redirected'];
        }
        if (is_link($absolutePath)) {
            return self::unlinkPath($absolutePath);
        }
        if (!file_exists($absolutePath)) {
            return ['success' => true, 'error' => null];
        }
        $absoluteReal = realpath($absolutePath);
        if (
            $absoluteReal === false
            || strpos($absoluteReal, $requiredPrefix) !== 0
            || !is_file($absoluteReal)
        ) {
            return ['success' => false, 'error' => 'unsafe_scan_target'];
        }
        return self::unlinkPath($absoluteReal);
    }

    private static function processQueueRow(PDO $pdo, int $id): void
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT id, relative_path, status
                 FROM scan_account_delete_files
                 WHERE id = :id
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($row)
                || in_array(
                    (string) ($row['status'] ?? ''),
                    self::TERMINAL_STATUSES,
                    true
                )
            ) {
                $pdo->commit();
                return;
            }
            $relativePath = (string) ($row['relative_path'] ?? '');
            $reference = $pdo->prepare(
                'SELECT id FROM scans WHERE image_path = :front_path
                 UNION ALL
                 SELECT id FROM scans WHERE image_path_back = :back_path
                 LIMIT 1'
            );
            $reference->execute([
                'front_path' => $relativePath,
                'back_path' => $relativePath,
            ]);
            if ($reference->fetchColumn()) {
                $waiting = $pdo->prepare(
                    "UPDATE scan_account_delete_files
                     SET status = 'waiting_reference',
                         attempts = attempts + 1,
                         last_error = 'scan_path_still_referenced',
                         completed_at = NULL
                     WHERE id = :id"
                );
                $waiting->execute(['id' => $id]);
                $pdo->commit();
                return;
            }
            $root = defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__);
            $result = self::deleteRelativePath($relativePath, $root);
            if (!empty($result['success'])) {
                self::markTerminal($pdo, $id, 'completed');
            } else {
                $failure = $pdo->prepare(
                    "UPDATE scan_account_delete_files
                     SET status = 'failed',
                         attempts = attempts + 1,
                         last_error = :last_error
                     WHERE id = :id"
                );
                $failure->execute([
                    'id' => $id,
                    'last_error' => substr(
                        (string) ($result['error'] ?? 'unlink_failed'),
                        0,
                        255
                    ),
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log(
                '[scan/account-cleanup] queue row '
                . $id
                . ': '
                . $e->getMessage()
            );
        }
    }

    private static function processRenderQueueRow(PDO $pdo, int $id): void
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "SELECT id, employee_id, status
                 FROM scan_account_delete_render_invalidations
                 WHERE id = :id
                 FOR UPDATE"
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->commit();
                return;
            }
            $status = (string) ($row['status'] ?? '');
            if ($status === 'completed') {
                $pdo->prepare(
                    'DELETE FROM scan_account_delete_render_invalidations
                     WHERE id = :id'
                )->execute(['id' => $id]);
                $pdo->commit();
                return;
            }
            $employeeId = trim((string) ($row['employee_id'] ?? ''));
            if ($employeeId === '') {
                throw new RuntimeException(
                    'render_invalidation_employee_unavailable'
                );
            }
            require_once __DIR__ . '/CardRenderer.php';
            CardRenderer::invalidateForEmployee(
                $employeeId,
                'scan_account_deleted'
            );
            $pdo->prepare(
                'DELETE FROM scan_account_delete_render_invalidations
                 WHERE id = :id'
            )->execute(['id' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                $failure = $pdo->prepare(
                    "UPDATE scan_account_delete_render_invalidations
                     SET status = 'failed',
                         attempts = attempts + 1,
                         last_error = :last_error
                     WHERE id = :id
                       AND status <> 'completed'"
                );
                $failure->execute([
                    'id' => $id,
                    'last_error' => substr($e->getMessage(), 0, 255),
                ]);
            } catch (Throwable $markError) {
                error_log(
                    '[scan/account-cleanup] render failure tracking: '
                    . $markError->getMessage()
                );
            }
            error_log(
                '[scan/account-cleanup] render row '
                . $id
                . ': '
                . $e->getMessage()
            );
        }
    }

    private static function markTerminal(
        PDO $pdo,
        int $id,
        string $status
    ): void {
        $stmt = $pdo->prepare(
            'UPDATE scan_account_delete_files
             SET status = :status,
                 attempts = attempts + 1,
                 relative_path = \'\',
                 last_error = NULL,
                 completed_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'status' => $status]);
    }

    private static function unlinkPath(string $absolutePath): array
    {
        $deleted = false;
        $message = 'unlink_failed';
        set_error_handler(
            static function (int $severity, string $error): bool {
                throw new ErrorException($error, 0, $severity);
            }
        );
        try {
            $deleted = unlink($absolutePath);
        } catch (Throwable $e) {
            $message = $e->getMessage();
        } finally {
            restore_error_handler();
        }
        if (
            $deleted
            && !file_exists($absolutePath)
            && !is_link($absolutePath)
        ) {
            return ['success' => true, 'error' => null];
        }
        return [
            'success' => false,
            'error' => substr($message, 0, 255),
        ];
    }
}
