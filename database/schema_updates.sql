-- Additional Schema Updates for Enhanced Admin Panel
-- Run this after initial schema.sql

-- Users table for unified authentication
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(36) PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'company',
    company_id VARCHAR(36) NULL,
    name VARCHAR(255) NOT NULL,
    status VARCHAR(50) DEFAULT 'active',
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_company_id (company_id),
    INDEX idx_status (status),
    CHECK (role IN ('super_admin', 'admin', 'company', 'employee'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Company themes/settings
CREATE TABLE IF NOT EXISTS company_themes (
    id VARCHAR(36) PRIMARY KEY,
    company_id VARCHAR(36) NOT NULL,
    primary_color VARCHAR(7) DEFAULT '#d4af37',
    secondary_color VARCHAR(7) DEFAULT '#0f3460',
    logo_path VARCHAR(500) NULL,
    favicon_path VARCHAR(500) NULL,
    custom_css TEXT NULL,
    header_text VARCHAR(255) NULL,
    footer_text VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    UNIQUE KEY unique_company_theme (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Departments table
CREATE TABLE IF NOT EXISTS departments (
    id VARCHAR(36) PRIMARY KEY,
    company_id VARCHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    template_id VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE SET NULL,
    INDEX idx_company_id (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add department_id to employees table
ALTER TABLE employees 
ADD COLUMN department_id VARCHAR(36) NULL AFTER company_id,
ADD FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
ADD INDEX idx_department_id (department_id);

-- Shareable design links
CREATE TABLE IF NOT EXISTS design_links (
    id VARCHAR(36) PRIMARY KEY,
    company_id VARCHAR(36) NOT NULL,
    template_id VARCHAR(100) NOT NULL,
    share_token VARCHAR(64) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NULL,
    expires_at TIMESTAMP NULL,
    access_count INT DEFAULT 0,
    max_access INT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_by VARCHAR(36) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_share_token (share_token),
    INDEX idx_company_id (company_id),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Print orders/jobs
CREATE TABLE IF NOT EXISTS print_orders (
    id VARCHAR(36) PRIMARY KEY,
    company_id VARCHAR(36) NOT NULL,
    order_number VARCHAR(50) UNIQUE NOT NULL,
    employee_ids JSON NOT NULL,
    template_id VARCHAR(100) NOT NULL,
    quantity INT DEFAULT 1,
    status VARCHAR(50) DEFAULT 'pending',
    print_provider VARCHAR(255) NULL,
    print_provider_order_id VARCHAR(255) NULL,
    total_cost DECIMAL(10, 2) NULL,
    notes TEXT NULL,
    created_by VARCHAR(36) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_company_id (company_id),
    INDEX idx_order_number (order_number),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add theme_id to templates for company-specific themes
ALTER TABLE templates
ADD COLUMN theme_id VARCHAR(36) NULL AFTER company_id,
ADD COLUMN department_id VARCHAR(36) NULL AFTER theme_id,
ADD COLUMN is_shared BOOLEAN DEFAULT FALSE,
ADD FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;

-- Create default super admin user (password: admin123 - CHANGE THIS!)
-- Password hash for 'admin123'
INSERT INTO users (id, email, password_hash, role, name, status) 
VALUES (
    UUID(),
    'admin@bhd.om',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'super_admin',
    'Super Admin',
    'active'
) ON DUPLICATE KEY UPDATE email=email;
