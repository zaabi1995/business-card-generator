<?php
/**
 * Migration 034: Configure WhatsApp (Dardasha) settings for order notifications
 * Enables WhatsApp notifications via Dardasha local API on port 3000
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();

    // Ensure system_settings table exists
    $db->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id VARCHAR(36) PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        description VARCHAR(500),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $settings = [
        'whatsapp_enabled'   => ['1',                                                          'Enable WhatsApp order notifications via Dardasha'],
        'whatsapp_api_url'   => ['http://127.0.0.1:3000/api/messages/api/send',               'Dardasha API endpoint (local port 3000)'],
        'whatsapp_api_token' => ['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1aWQiOiJzVHgwdGRxOXR2cmMyN3B6SDU3RmVoZkd0RjBscFNtcSIsInJvbGUiOiJ1c2VyIiwiaWF0IjoxNzcxOTcwNTMzLCJleHAiOjE3NzQ1NjI1MzN9.Xw6K_Lso75lA8p2yDhpH3EVZEkxkHQ8ziYS48dEMdR4', 'Anna line API token (Dardasha X-API-Key)'],
        'whatsapp_session_id'=> ['96898899100',                                                'Anna line phone number (from field in Dardasha)'],
    ];

    foreach ($settings as $key => [$value, $desc]) {
        $existing = $db->fetchOne("SELECT id FROM system_settings WHERE setting_key = :k", ['k' => $key]);
        if (!$existing) {
            $db->insert('system_settings', [
                'id'            => bin2hex(random_bytes(18)),
                'setting_key'   => $key,
                'setting_value' => $value,
                'description'   => $desc,
            ]);
            echo "Migration 034: inserted $key\n";
        } else {
            // Update URL and enabled flag but don't overwrite token if already set
            if ($key !== 'whatsapp_api_token') {
                $db->update('system_settings', ['setting_value' => $value], 'setting_key = :k', ['k' => $key]);
                echo "Migration 034: updated $key\n";
            } else {
                echo "Migration 034: skipped $key (already set)\n";
            }
        }
    }

    echo "Migration 034: WhatsApp settings configured\n";
} catch (Exception $e) {
    echo "Migration 034 failed: " . $e->getMessage() . "\n";
    exit(1);
}
