-- Migration 028: เพิ่ม default_unit ให้หมวดหมู่

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'default_unit'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE categories ADD COLUMN default_unit VARCHAR(20) DEFAULT ''กก.'' AFTER name',
  'SELECT ''028 already applied'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE categories SET default_unit = 'ลัง' WHERE name = 'ขวดใส่ลัง' AND @col_exists = 0;
