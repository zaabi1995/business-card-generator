<?php
/**
 * Migration 179: email_logs.actor_id holds a print shop operator ("pso:" + a
 * 36-character id = 40 characters), which overflowed VARCHAR(36), so those
 * emails were sent but never logged (5 Oct 2026).
 */
require_once __DIR__ . '/../../config.php';

try {
    Database::getInstance()->exec('ALTER TABLE email_logs MODIFY actor_id VARCHAR(64) NULL');
    echo "Migration 179: email_logs.actor_id is VARCHAR(64)\n";
} catch (Exception $e) {
    echo "Migration 179 failed: " . $e->getMessage() . "\n";
    exit(1);
}
