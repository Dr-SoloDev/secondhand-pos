-- Migration 034: เพิ่ม catalog_id + item_name ใน sale_lot_items

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_lot_items' AND COLUMN_NAME = 'catalog_id'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sale_lot_items
    ADD COLUMN catalog_id INT DEFAULT NULL AFTER sale_lot_id,
    ADD COLUMN item_name VARCHAR(255) DEFAULT NULL AFTER catalog_id,
    MODIFY COLUMN category_id INT DEFAULT NULL',
  'SELECT ''034 already applied'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
