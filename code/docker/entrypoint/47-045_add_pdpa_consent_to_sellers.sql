-- Migration 045: Track when seller gave PDPA consent
-- Required before collecting id_card_photo and personal data

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'sellers'
    AND COLUMN_NAME = 'pdpa_consented_at'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE sellers ADD COLUMN pdpa_consented_at DATETIME NULL DEFAULT NULL AFTER blacklisted_by',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
