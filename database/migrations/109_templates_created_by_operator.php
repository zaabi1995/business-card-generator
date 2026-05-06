<?php
/**
 * Migration 109: templates.created_by_operator_id
 *
 * Attribution column for templates imported by a print-shop operator
 * (BHD as internal provider) acting on behalf of a tenant. NULL when
 * the template came from the tenant's own admin / onboarding flow.
 *
 * Used by printshop/import_pdf_for_tenant.php to record which operator
 * uploaded the source PDF for which client.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $hasCol = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema = DATABASE() AND table_name = 'templates'
           AND column_name = 'created_by_operator_id'"
    )->fetchColumn();

    if ($hasCol === 0) {
        $pdo->exec(
            "ALTER TABLE templates
             ADD COLUMN created_by_operator_id VARCHAR(36) NULL
             COMMENT 'When set, template was imported via print-shop on behalf of tenant'"
        );
        $pdo->exec("CREATE INDEX idx_tmpl_creator_op ON templates (created_by_operator_id)");
        echo "[109] Added templates.created_by_operator_id\n";
    } else {
        echo "[109] created_by_operator_id already exists\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[109] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
