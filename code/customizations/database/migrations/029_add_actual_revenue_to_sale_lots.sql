-- Migration 029: เพิ่ม actual_revenue ใน sale_lots

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_lots' AND COLUMN_NAME = 'actual_revenue'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sale_lots
    ADD COLUMN actual_revenue      DECIMAL(12,2) DEFAULT NULL AFTER total_amount,
    ADD COLUMN actual_revenue_note VARCHAR(500)  DEFAULT NULL AFTER actual_revenue,
    ADD COLUMN actual_revenue_date DATE          DEFAULT NULL AFTER actual_revenue_note',
  'SELECT ''029 already applied'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
