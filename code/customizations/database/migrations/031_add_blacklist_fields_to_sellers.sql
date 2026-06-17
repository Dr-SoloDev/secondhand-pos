-- Migration 031: เพิ่ม blacklist fields ใน sellers (G3 — Blacklist Alert)

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sellers' AND COLUMN_NAME = 'blacklist_reason'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sellers
    ADD COLUMN blacklist_reason VARCHAR(255) DEFAULT NULL AFTER is_blacklisted,
    ADD COLUMN blacklisted_at   DATETIME    DEFAULT NULL AFTER blacklist_reason',
  'SELECT ''031 already applied'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
