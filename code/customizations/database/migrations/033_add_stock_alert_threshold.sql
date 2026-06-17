-- Migration 033: เพิ่ม alert_threshold ใน categories (แจ้งเตือนสต็อกต่ำ)

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'alert_threshold'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE categories ADD COLUMN alert_threshold DECIMAL(12,3) DEFAULT NULL',
  'SELECT ''033 already applied'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
