-- Migration 039: Add transport_cost to sale_lots for tracking delivery expenses
SELECT @col_exists := COUNT(*)
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_lots' AND COLUMN_NAME = 'transport_cost';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE sale_lots ADD COLUMN transport_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER total_cost',
    'SELECT 1');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
