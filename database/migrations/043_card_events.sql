-- Migration 043: card_events table for per-card analytics
-- Idempotent; direct mysql-runnable (runner exit-in-CLI bug workaround)

CREATE TABLE IF NOT EXISTS card_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(36) NOT NULL,
    company_id VARCHAR(36) NOT NULL,
    event_type ENUM('view','click_phone','click_mobile','click_whatsapp','click_email',
                    'click_website','click_map','click_social','save_contact','wallet_add','qr_scan') NOT NULL,
    cta_target VARCHAR(512) NULL,
    visitor_id VARCHAR(64) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_type ENUM('mobile','tablet','desktop','unknown') DEFAULT 'unknown',
    browser VARCHAR(32) NULL,
    os VARCHAR(32) NULL,
    country_code CHAR(2) NULL,
    country_name VARCHAR(64) NULL,
    referrer VARCHAR(1024) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_employee_created (employee_id, created_at),
    INDEX idx_company_created (company_id, created_at),
    INDEX idx_event_type (event_type),
    INDEX idx_visitor (visitor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
