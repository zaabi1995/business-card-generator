<?php
/**
 * Migration 170: Oman directory provenance.
 *
 * Adds identity, score and provenance tables beside om_companies.
 * Does not rewrite names, websites or summaries. Does not invent CR numbers.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();

    $add = [
        'cr_number' => "VARCHAR(32) NULL",
        'legal_form' => "VARCHAR(80) NULL",
        'registry_status' => "VARCHAR(40) NULL",
        'normalized_name_en' => "VARCHAR(500) NULL",
        'normalized_name_ar' => "VARCHAR(500) NULL",
        'data_completeness' => "TINYINT UNSIGNED NOT NULL DEFAULT 0",
        'verification_confidence' => "DECIMAL(4,3) NOT NULL DEFAULT 0",
        'source_checked_at' => "TIMESTAMP NULL DEFAULT NULL",
        'profile_scored_at' => "TIMESTAMP NULL DEFAULT NULL",
        'website_http_status' => "SMALLINT NULL",
        'website_checked_at' => "TIMESTAMP NULL DEFAULT NULL",
    ];
    foreach ($add as $col => $def) {
        if (!$db->columnExists('om_companies', $col)) {
            $pdo->exec("ALTER TABLE om_companies ADD COLUMN $col $def");
            echo "[170] added om_companies.$col\n";
        }
    }
    $indexes = [
        'uniq_om_cr' => "CREATE UNIQUE INDEX uniq_om_cr ON om_companies (cr_number)",
        'idx_om_norm_en' => "CREATE INDEX idx_om_norm_en ON om_companies (normalized_name_en(191))",
    ];
    foreach ($indexes as $name => $sql) {
        $have = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'om_companies' AND index_name = " . $pdo->quote($name)
        )->fetchColumn();
        if ($have === 0) {
            $pdo->exec($sql);
            echo "[170] index $name\n";
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS om_company_facts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        value_text TEXT NOT NULL,
        source_name VARCHAR(190) NOT NULL,
        source_type ENUM('official_registry','government_directory','company_website','institutional','third_party','cardify_curated','inferred') NOT NULL,
        source_url VARCHAR(500) NULL,
        confidence DECIMAL(4,3) NOT NULL DEFAULT 0,
        verified_at TIMESTAMP NULL,
        source_checked_at TIMESTAMP NULL,
        UNIQUE KEY uniq_company_field (company_id, field_name),
        KEY idx_source_type (source_type),
        CONSTRAINT fk_om_fact_company FOREIGN KEY (company_id) REFERENCES om_companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS om_company_matches (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_a INT NOT NULL,
        company_b INT NOT NULL,
        score DECIMAL(4,3) NOT NULL,
        reason VARCHAR(64) NOT NULL,
        status ENUM('proposed','accepted','rejected','auto_merged') NOT NULL DEFAULT 'proposed',
        decided_by VARCHAR(80) NULL,
        decided_at TIMESTAMP NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_pair (company_a, company_b),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS om_company_merges (
        id INT AUTO_INCREMENT PRIMARY KEY,
        survivor_id INT NOT NULL,
        merged_id INT NOT NULL,
        snapshot_json LONGTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reversed_at TIMESTAMP NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS om_company_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_type VARCHAR(40) NOT NULL,
        status VARCHAR(20) NOT NULL,
        records_changed INT NOT NULL DEFAULT 0,
        error_text TEXT NULL,
        duration_ms INT NOT NULL DEFAULT 0,
        attempted_source VARCHAR(190) NULL,
        started_at TIMESTAMP NULL,
        finished_at TIMESTAMP NULL,
        KEY idx_job_started (started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS om_company_corrections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        actor VARCHAR(80) NOT NULL,
        note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_corr_field (field_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS om_directory_exports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer VARCHAR(120) NOT NULL,
        dataset_version VARCHAR(80) NOT NULL,
        record_count INT NOT NULL,
        fields_json TEXT NOT NULL,
        licence VARCHAR(190) NOT NULL,
        amount_omr DECIMAL(12,3) NULL,
        checksum CHAR(64) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS om_directory_sources (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(190) NOT NULL,
        source_type VARCHAR(40) NOT NULL,
        rank_order TINYINT NOT NULL,
        usable TINYINT(1) NOT NULL DEFAULT 0,
        reason VARCHAR(500) NOT NULL,
        UNIQUE KEY uniq_source_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $seed = [
        ['Oman Business Platform (business.gov.om)', 'official_registry', 1, 0, 'robots.txt Disallow: / for all agents. No public API found. Not crawled.'],
        ['MoCIIP / Tejarah public site', 'government_directory', 2, 0, 'Marketing site only. No company-registry export or documented public API found from the public pages.'],
        ['Cardify Oman Business Index', 'cardify_curated', 6, 1, 'Existing om_companies rows. Company-level fields already held. Not an official registry.'],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO om_directory_sources (name, source_type, rank_order, usable, reason) VALUES (?, ?, ?, ?, ?)");
    foreach ($seed as $s) {
        $stmt->execute($s);
    }
    echo "[170] directory provenance ready\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[170] FAILED: " . $e->getMessage() . "\n");
    exit(1);
}
