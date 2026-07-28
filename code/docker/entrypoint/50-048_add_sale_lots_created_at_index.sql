-- 048: Add created_at index on sale_lots for dashboard ORDER BY performance
-- Without this index, the dashboard query does full table scan + filesort

SET @index_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sale_lots'
    AND INDEX_NAME = 'idx_sale_lots_created_at'
);

SET @sql = IF(@index_exists = 0,
  'ALTER TABLE `sale_lots` ADD INDEX `idx_sale_lots_created_at` (`created_at`)',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
