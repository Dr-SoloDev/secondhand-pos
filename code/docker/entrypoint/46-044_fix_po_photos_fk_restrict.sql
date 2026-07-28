-- Migration 044: Change purchase_order_photos FK from CASCADE to RESTRICT
-- Legal requirement: PO evidence photos must not be destroyed when a PO is cancelled/deleted
-- Instead, soft-delete POs; photos remain as evidence.

-- Step 1: Drop existing CASCADE constraint
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'purchase_order_photos'
    AND CONSTRAINT_NAME = 'fk_pop_po'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql_drop = IF(@fk_exists > 0,
  'ALTER TABLE purchase_order_photos DROP FOREIGN KEY fk_pop_po',
  'SELECT 1'
);
PREPARE stmt FROM @sql_drop; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Step 2: Re-add with RESTRICT
SET @fk_exists2 = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'purchase_order_photos'
    AND CONSTRAINT_NAME = 'fk_pop_po'
);
SET @sql_add = IF(@fk_exists2 = 0,
  'ALTER TABLE purchase_order_photos ADD CONSTRAINT fk_pop_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE RESTRICT',
  'SELECT 1'
);
PREPARE stmt FROM @sql_add; EXECUTE stmt; DEALLOCATE PREPARE stmt;
