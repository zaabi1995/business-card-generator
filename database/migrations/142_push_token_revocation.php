<?php
/**
 * Add a hash-only client capability for exact push-token revocation.
 *
 * Existing rows remain nullable so build 50 and authenticated unregister keep
 * working until a newer client registers a revocation secret.
 */
function migration_142_push_token_revocation(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];

    try {
        $hashColumn = $pdo->query(
            "SELECT 1
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'push_tokens'
               AND column_name = 'revocation_secret_hash'
             LIMIT 1"
        )->fetchColumn();

        $tokenColumn = $pdo->query(
            "SELECT character_set_name, collation_name, column_type, is_nullable
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'push_tokens'
               AND column_name = 'token'
             LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        $alterations = [];
        if (!$hashColumn) {
            $alterations[] = 'ADD COLUMN revocation_secret_hash CHAR(64)
                CHARACTER SET ascii COLLATE ascii_bin NULL AFTER platform';
        }
        if (
            !is_array($tokenColumn)
            || strtolower((string) ($tokenColumn['character_set_name'] ?? '')) !== 'utf8mb4'
            || strtolower((string) ($tokenColumn['collation_name'] ?? '')) !== 'utf8mb4_bin'
            || strtolower((string) ($tokenColumn['column_type'] ?? '')) !== 'varchar(255)'
            || strtoupper((string) ($tokenColumn['is_nullable'] ?? '')) !== 'NO'
        ) {
            $alterations[] = 'MODIFY COLUMN token VARCHAR(255)
                CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
        }
        if ($alterations) {
            $pdo->exec('ALTER TABLE push_tokens ' . implode(', ', $alterations));
        }

        $result['success'] = true;
        $result['messages'][] = 'Push tokens use exact matching and revocation hashes';
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }

    return $result;
}
