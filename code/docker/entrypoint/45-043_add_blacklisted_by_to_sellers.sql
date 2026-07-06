-- Migration 043: Add blacklisted_by to sellers for ม.357 audit trail
-- Who blacklisted this seller and when can now be traced to a specific user

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sellers'
    AND COLUMN_NAME = 'blacklisted_by'
);

SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sellers ADD COLUMN blacklisted_by INT NULL DEFAULT NULL AFTER blacklisted_at',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
