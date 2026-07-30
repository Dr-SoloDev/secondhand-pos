-- Migration 056: เก็บรายการสินค้าในใบโอนสต็อก
-- ใบโอนใหม่ต้องอ้างอิงสินค้าจริง ไม่ใช่โอนรวมทั้งหมวดหมู่

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'item_name'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE stock_transfers
    ADD COLUMN item_name VARCHAR(200) DEFAULT NULL AFTER category_id',
  'SELECT ''056 already applied, skipping'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
