-- Migration 053: Card Location Section
-- Adds Google Maps location fields to employee_card_sections.
-- Idempotent: safe to re-run.

-- Each ALTER is wrapped by the PHP runner; these are the raw statements
-- for reference / direct MySQL execution.

ALTER TABLE `employee_card_sections`
  ADD COLUMN `location_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `offers_enabled`;

ALTER TABLE `employee_card_sections`
  ADD COLUMN `location_address` VARCHAR(512) DEFAULT NULL AFTER `location_enabled`;

ALTER TABLE `employee_card_sections`
  ADD COLUMN `location_lat` DECIMAL(10,7) DEFAULT NULL AFTER `location_address`;

ALTER TABLE `employee_card_sections`
  ADD COLUMN `location_lng` DECIMAL(10,7) DEFAULT NULL AFTER `location_lat`;

ALTER TABLE `employee_card_sections`
  ADD COLUMN `location_label` VARCHAR(120) DEFAULT NULL AFTER `location_lng`;
