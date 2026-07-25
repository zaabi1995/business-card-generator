<?php
/**
 * Business-activity category for a scanned card.
 *
 * The app categorises on device (lib/cardCategory.ts) so a card is never
 * uncategorised, even offline. The server may then REFINE a weak guess with a
 * model (see includes/ScanCategorizer.php). category_source records which of the
 * two decided, so a server refine can be re-run or audited without guessing, and
 * a human correction is never overwritten by a machine.
 */
function migration_146_scan_business_category(PDO $pdo): array
{
    $result = ['success' => false, 'errors' => [], 'messages' => []];
    try {
        $columns = $pdo->query(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'scans'
               AND column_name IN (
                   'category',
                   'category_source',
                   'category_confidence',
                   'category_at'
               )"
        )->fetchAll(PDO::FETCH_COLUMN);
        $columnSet = array_fill_keys($columns, true);
        $alterations = [];

        if (!isset($columnSet['category'])) {
            $alterations[] = "ADD COLUMN category VARCHAR(32) NULL AFTER tags";
        }
        if (!isset($columnSet['category_source'])) {
            // device | server | user  - user always wins.
            $alterations[] = "ADD COLUMN category_source VARCHAR(16) NULL AFTER category";
        }
        if (!isset($columnSet['category_confidence'])) {
            $alterations[] = "ADD COLUMN category_confidence DECIMAL(4,3) NULL AFTER category_source";
        }
        if (!isset($columnSet['category_at'])) {
            $alterations[] = "ADD COLUMN category_at DATETIME NULL AFTER category_confidence";
        }

        if ($alterations) {
            $pdo->exec('ALTER TABLE scans ' . implode(', ', $alterations));
        }

        $indexes = $pdo->query(
            "SELECT index_name
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = 'scans'
               AND index_name = 'idx_scans_category'"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (!$indexes) {
            // The admin view filters and groups by category across every tenant.
            $pdo->exec('ALTER TABLE scans ADD INDEX idx_scans_category (category)');
        }

        $result['success'] = true;
        $result['messages'][] = 'scans carries a business category with its source and confidence';
    } catch (Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }
    return $result;
}
