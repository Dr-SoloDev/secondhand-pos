-- ============================================================
-- Migration: 020_add_fifo_indexes_and_atomic_consumed.sql
-- Purpose: Add indexes for FIFO cost queries + 
--          atomic consumed_qty update to prevent race conditions
-- Date: 2569-06-01
-- ============================================================
USE pos_system;

-- purchase_order_items: consumed_qty used in WHERE (quantity - consumed_qty) > 0
ALTER TABLE purchase_order_items ADD INDEX idx_poi_consumed_qty (consumed_qty);

-- purchase_order_items: composite for FIFO query (category_id + consumed_qty)
ALTER TABLE purchase_order_items ADD INDEX idx_poi_category_consumed (category_id, consumed_qty);

-- purchase_orders: standalone branch_id for JOINs
ALTER TABLE purchase_orders ADD INDEX idx_po_branch_id (branch_id);
