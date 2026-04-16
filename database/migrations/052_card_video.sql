-- Migration 052: Embedded Video section for public cards
-- Adds video_enabled / video_url / video_title columns to employee_card_sections.

ALTER TABLE employee_card_sections
    ADD COLUMN video_enabled TINYINT(1) DEFAULT 0,
    ADD COLUMN video_url VARCHAR(1024) NULL,
    ADD COLUMN video_title VARCHAR(200) NULL;
