-- ============================================================
-- Migration 051: Create branch_stock table (per-branch per-item stock tracking)
-- Purpose: Source of Truth สำหรับสต็อก แยกตามสาขา + item_name
-- Depends on: 001_add_branches.sql, 019_add_stock_kg_to_categories.sql
-- Reference: ADD-001 Branch Stock Redesign
-- ============================================================

-- Step 1: Create branch_stock table
CREATE TABLE IF NOT EXISTS branch_stock (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    branch_id   INT NOT NULL,
    category_id INT NOT NULL,
    item_name   VARCHAR(200) NOT NULL,
    stock_kg    DECIMAL(12,3) NOT NULL DEFAULT 0.000,
    unit_price  DECIMAL(12,4) NOT NULL DEFAULT 0.0000 COMMENT 'weighted average cost per kg',
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_branch_category_item (branch_id, category_id, item_name),
    FOREIGN KEY (branch_id)   REFERENCES branches(id)   ON DELETE RESTRICT,
    FOREIGN KEY (category_id) REFERENCES categories(id)  ON DELETE RESTRICT,
    INDEX idx_branch_category (branch_id, category_id),
    INDEX idx_item_name (item_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-branch per-item stock tracking — Source of Truth for inventory';

-- Step 2: Populate from existing purchase_order_items
-- stock_kg    = SUM(quantity - weight_deduction - consumed_qty) per (branch, category, item_name)
-- unit_price  = weighted average cost of unconsumed stock
-- TRIM(item_name) ใช้ normalize ชื่อ item ป้องกัน mismatch จาก whitespace
INSERT INTO branch_stock (branch_id, category_id, item_name, stock_kg, unit_price)
SELECT
    po.branch_id,
    poi.category_id,
    TRIM(poi.item_name) AS item_name,
    ROUND(SUM(poi.quantity - poi.weight_deduction - poi.consumed_qty), 3) AS stock_kg,
    CASE
        WHEN SUM(poi.quantity - poi.weight_deduction - poi.consumed_qty) > 0
        THEN ROUND(
            SUM((poi.quantity - poi.weight_deduction - poi.consumed_qty) * poi.unit_price)
            / SUM(poi.quantity - poi.weight_deduction - poi.consumed_qty),
            4
        )
        ELSE 0
    END AS unit_price
FROM purchase_order_items poi
INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
WHERE po.status = 'completed'
  AND (poi.quantity - poi.weight_deduction - poi.consumed_qty) > 0
  AND poi.category_id IS NOT NULL
GROUP BY po.branch_id, poi.category_id, TRIM(poi.item_name);

-- Step 3: Verify migration
SELECT CONCAT('Migration 051 complete — inserted ', COUNT(*), ' rows into branch_stock') AS status
FROM branch_stock;
