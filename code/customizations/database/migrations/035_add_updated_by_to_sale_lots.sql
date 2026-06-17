-- Migration 035: เพิ่ม updated_by ใน sale_lots

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_lots' AND COLUMN_NAME = 'updated_by'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sale_lots ADD COLUMN updated_by INT NULL AFTER created_by',
  'SELECT ''035 col already exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK constraint (แยกออกมา เพราะถ้า PREPARE + FK มักมีปัญหา)
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_lots' AND CONSTRAINT_NAME = 'fk_sale_lots_updated_by'
);
SET @fk_sql = IF(@fk_exists = 0,
  'ALTER TABLE sale_lots ADD CONSTRAINT fk_sale_lots_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL',
  'SELECT ''035 FK already exists'' AS info'
);
PREPARE stmt2 FROM @fk_sql; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
