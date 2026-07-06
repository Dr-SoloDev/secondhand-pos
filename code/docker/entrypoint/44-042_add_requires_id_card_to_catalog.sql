-- Migration 042: เพิ่ม requires_id_card ใน purchase_item_catalog (G3 — สินค้าเสี่ยงสูงต้องแสดงบัตร)

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'purchase_item_catalog'
    AND COLUMN_NAME = 'requires_id_card'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE purchase_item_catalog ADD COLUMN requires_id_card TINYINT(1) NOT NULL DEFAULT 0 AFTER notes',
  'SELECT ''042 already applied'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
