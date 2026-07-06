-- Migration 021: Add cost_method column to branches (idempotent guard)
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='branches' AND COLUMN_NAME='cost_method'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE branches ADD COLUMN cost_method ENUM(''fifo'',''weighted'') NOT NULL DEFAULT ''fifo'' COMMENT ''FIFO = เข้าก่อนตัดก่อน, Weighted = ถัวเฉลี่ย''',
  'SELECT ''021 already applied'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
