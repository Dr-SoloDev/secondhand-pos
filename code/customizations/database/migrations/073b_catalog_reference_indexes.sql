-- Migration 073b: guarded indexes and foreign keys after catalog reference backfill.

SET @index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='branch_stock' AND INDEX_NAME='idx_branch_stock_catalog'
);
SET @sql = IF(@index_exists = 0,
  'ALTER TABLE branch_stock ADD INDEX idx_branch_stock_catalog (branch_id,catalog_id)',
  'SELECT ''073b idx_branch_stock_catalog already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branch_stock'
    AND CONSTRAINT_NAME='fk_branch_stock_catalog' AND REFERENCED_TABLE_NAME='purchase_item_catalog'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE branch_stock ADD CONSTRAINT fk_branch_stock_catalog FOREIGN KEY (catalog_id) REFERENCES purchase_item_catalog(id)',
  'SELECT ''073b fk_branch_stock_catalog already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='purchase_order_items' AND INDEX_NAME='idx_poi_catalog_available'
);
SET @sql = IF(@index_exists = 0,
  'ALTER TABLE purchase_order_items ADD INDEX idx_poi_catalog_available (catalog_id,net_quantity,consumed_qty)',
  'SELECT ''073b idx_poi_catalog_available already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_items'
    AND CONSTRAINT_NAME='fk_purchase_order_items_catalog' AND REFERENCED_TABLE_NAME='purchase_item_catalog'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE purchase_order_items ADD CONSTRAINT fk_purchase_order_items_catalog FOREIGN KEY (catalog_id) REFERENCES purchase_item_catalog(id)',
  'SELECT ''073b fk_purchase_order_items_catalog already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='stock_transfer_items' AND INDEX_NAME='idx_sti_catalog'
);
SET @sql = IF(@index_exists = 0,
  'ALTER TABLE stock_transfer_items ADD INDEX idx_sti_catalog (catalog_id)',
  'SELECT ''073b idx_sti_catalog already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stock_transfer_items'
    AND CONSTRAINT_NAME='fk_stock_transfer_items_catalog' AND REFERENCED_TABLE_NAME='purchase_item_catalog'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE stock_transfer_items ADD CONSTRAINT fk_stock_transfer_items_catalog FOREIGN KEY (catalog_id) REFERENCES purchase_item_catalog(id)',
  'SELECT ''073b fk_stock_transfer_items_catalog already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
