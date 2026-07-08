-- 048: Add created_at index on sale_lots for dashboard ORDER BY performance
-- Without this index, the dashboard query does full table scan + filesort

ALTER TABLE `sale_lots`
    ADD INDEX `idx_sale_lots_created_at` (`created_at`);
