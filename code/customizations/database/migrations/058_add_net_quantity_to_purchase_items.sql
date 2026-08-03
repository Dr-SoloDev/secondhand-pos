-- Migration 058: canonical net quantity for purchase-order inventory
-- Business rule: deducted weight is discarded and never enters stock or cost.

SET @net_qty_exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'purchase_order_items'
    AND COLUMN_NAME = 'net_quantity'
);

SET @sql = IF(
  @net_qty_exists = 0,
  'ALTER TABLE purchase_order_items
     ADD COLUMN net_quantity DECIMAL(10,3)
       GENERATED ALWAYS AS (GREATEST(0, quantity - COALESCE(weight_deduction, 0))) STORED
       AFTER weight_deduction',
  'SELECT ''058 net_quantity already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @net_idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'purchase_order_items'
    AND INDEX_NAME = 'idx_poi_stock_available'
);

SET @sql = IF(
  @net_idx_exists = 0,
  'ALTER TABLE purchase_order_items
     ADD INDEX idx_poi_stock_available (category_id, item_name, net_quantity, consumed_qty)',
  'SELECT ''058 stock availability index already exists'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

