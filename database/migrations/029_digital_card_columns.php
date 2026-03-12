<?php
/**
 * Migration 029: Add web-optimized card paths and theme mode to generated_cards
 */

return [
    'up' => "
        ALTER TABLE generated_cards
            ADD COLUMN IF NOT EXISTS front_web_path VARCHAR(500) DEFAULT NULL AFTER back_file_path,
            ADD COLUMN IF NOT EXISTS back_web_path VARCHAR(500) DEFAULT NULL AFTER front_web_path,
            ADD COLUMN IF NOT EXISTS theme_mode ENUM('light','dark') DEFAULT NULL AFTER back_web_path;
    ",
    'down' => "
        ALTER TABLE generated_cards
            DROP COLUMN IF EXISTS front_web_path,
            DROP COLUMN IF EXISTS back_web_path,
            DROP COLUMN IF EXISTS theme_mode;
    "
];
