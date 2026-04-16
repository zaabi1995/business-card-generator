-- Migration 058: Card Product Catalog (mini product list section on public cards)
-- Safe to run multiple times against a fresh DB.

CREATE TABLE IF NOT EXISTS employee_card_products (
    id VARCHAR(36) PRIMARY KEY,
    employee_id VARCHAR(36) NOT NULL,
    company_id VARCHAR(36) NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(10,3) NOT NULL DEFAULT 0,
    currency VARCHAR(3) NOT NULL DEFAULT 'OMR',
    image_path VARCHAR(512) DEFAULT NULL,
    whatsapp_message VARCHAR(500) DEFAULT NULL,
    position INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id)  REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_employee_pos_enabled (employee_id, position, enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE employee_card_sections
    ADD COLUMN products_enabled TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE card_events MODIFY COLUMN event_type
    ENUM('view','click_phone','click_mobile','click_whatsapp','click_email',
         'click_website','click_map','click_social','save_contact','wallet_add',
         'qr_scan','offer_redeem','short_link_click','product_order_click') NOT NULL;
