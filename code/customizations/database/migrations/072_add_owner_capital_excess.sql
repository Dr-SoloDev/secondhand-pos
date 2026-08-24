-- Migration 072: owner_capital excess tracking — เติมทุนใหม่แล้วแยก ส่วนเกิน เป็นรายรับเพิ่มทุน
-- Additive only — ไม่ลบ/ไม่แก้ ENUM เดิม

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_deposit_requests' AND COLUMN_NAME = 'excess_amount'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE cash_deposit_requests ADD COLUMN excess_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER amount',
  'SELECT ''072 cash_deposit_requests.excess_amount already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_movements' AND COLUMN_NAME = 'excess_amount'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE cash_movements ADD COLUMN excess_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER amount',
  'SELECT ''072 cash_movements.excess_amount already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ขยาย movement_type รองรับ owner_capital_excess / owner_capital_base
SET @is_varchar = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_movements' AND COLUMN_NAME = 'movement_type' AND DATA_TYPE = 'varchar'
);
SET @sql = IF(@is_varchar = 0,
  'ALTER TABLE cash_movements MODIFY COLUMN movement_type VARCHAR(50) NOT NULL',
  'SELECT ''072 movement_type already varchar'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
