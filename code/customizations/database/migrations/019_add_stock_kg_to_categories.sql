-- Migration 019: Add stock_kg to categories for real-time inventory tracking
ALTER TABLE categories
    ADD COLUMN stock_kg DECIMAL(12,3) NOT NULL DEFAULT 0.000
    AFTER status,
    ADD INDEX idx_categories_stock (stock_kg);

-- Initialize stock_kg from existing purchase_order_items (completed POs only, unconsumed)
-- This ensures existing data gets proper stock values
UPDATE categories c
SET c.stock_kg = (
    SELECT COALESCE(SUM(poi.quantity - poi.consumed_qty), 0)
    FROM purchase_order_items poi
    INNER JOIN purchase_orders po ON poi.purchase_order_id = po.id
    WHERE poi.category_id = c.id
      AND po.status = 'completed'
)
WHERE EXISTS (
    SELECT 1 FROM purchase_order_items poi2
    INNER JOIN purchase_orders po2 ON poi2.purchase_order_id = po2.id
    WHERE poi2.category_id = c.id AND po2.status = 'completed'
);
