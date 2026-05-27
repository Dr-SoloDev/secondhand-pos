-- ============================================================
-- Migration: 014_add_missing_indexes.sql
-- Purpose: Add missing indexes on frequently queried columns
--          identified by Database Optimizer audit
-- Date: 2569-05-28
-- ============================================================
USE pos_system;

-- purchase_orders: status filtered in FIFO cost queries
ALTER TABLE purchase_orders ADD INDEX idx_po_status (status);

-- purchase_orders: user_id FK (JOIN in getById)
ALTER TABLE purchase_orders ADD INDEX idx_po_user (user_id);

-- purchase_orders: composite for branch + status + date queries
ALTER TABLE purchase_orders ADD INDEX idx_po_branch_status_date (branch_id, status, created_at);

-- purchase_order_items: category_id standalone (FIFO WHERE clause)
ALTER TABLE purchase_order_items ADD INDEX idx_poi_category (category_id);

-- sellers: full_name used in search (LIKE query)
ALTER TABLE sellers ADD INDEX idx_sellers_full_name (full_name);

-- sale_lots: status filtered in getAll()
ALTER TABLE sale_lots ADD INDEX idx_sale_lots_status (status);

-- sale_lots: created_by FK (JOIN in getById/getAll)
ALTER TABLE sale_lots ADD INDEX idx_sale_lots_created_by (created_by);

-- base tables: FK indexes missing from original schema
ALTER TABLE sale_items ADD INDEX idx_sale_items_sale (sale_id);
ALTER TABLE sale_items ADD INDEX idx_sale_items_product (product_id);
ALTER TABLE products ADD INDEX idx_products_category (category_id);
ALTER TABLE activity_log ADD INDEX idx_activity_log_user (user_id);
ALTER TABLE activity_log ADD INDEX idx_activity_log_created (created_at);
