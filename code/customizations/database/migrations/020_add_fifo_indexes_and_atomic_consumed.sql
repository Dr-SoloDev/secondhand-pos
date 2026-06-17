-- ============================================================
-- Migration: 020_add_fifo_indexes_and_atomic_consumed.sql
-- Purpose: Add indexes for FIFO cost queries (idempotent guards)
-- ============================================================
USE pos_system;

-- consumed_qty index
SET @idx1 = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_items' AND INDEX_NAME='idx_poi_consumed_qty');
SET @s1 = IF(@idx1=0,
  'ALTER TABLE purchase_order_items ADD INDEX idx_poi_consumed_qty (consumed_qty)',
  'SELECT ''020 idx_poi_consumed_qty exists'' AS info');
PREPARE stmt FROM @s1; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- composite FIFO index
SET @idx2 = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_items' AND INDEX_NAME='idx_poi_category_consumed');
SET @s2 = IF(@idx2=0,
  'ALTER TABLE purchase_order_items ADD INDEX idx_poi_category_consumed (category_id, consumed_qty)',
  'SELECT ''020 idx_poi_category_consumed exists'' AS info');
PREPARE stmt FROM @s2; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- branch_id index on purchase_orders
SET @idx3 = (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_orders' AND INDEX_NAME='idx_po_branch_id');
SET @s3 = IF(@idx3=0,
  'ALTER TABLE purchase_orders ADD INDEX idx_po_branch_id (branch_id)',
  'SELECT ''020 idx_po_branch_id exists'' AS info');
PREPARE stmt FROM @s3; EXECUTE stmt; DEALLOCATE PREPARE stmt;
