-- Migration 032: เพิ่ม requires_precious_receipt ใน categories (G2 — ใบรับซื้อโลหะมีค่า)

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'requires_precious_receipt'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE categories ADD COLUMN requires_precious_receipt TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''032 already applied'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE categories SET requires_precious_receipt = 1
WHERE (name LIKE '%ทองแดง%' OR name = 'โลหะมีค่า') AND @col_exists = 0;
