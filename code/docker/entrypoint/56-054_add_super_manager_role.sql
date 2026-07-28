-- 054: เพิ่ม role super_manager — ผู้จัดการที่ดูรายงานข้ามสาขาได้
SET @role_type = (
  SELECT COLUMN_TYPE
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'role'
);

SET @sql = IF(@role_type NOT LIKE '%super_manager%',
  'ALTER TABLE users MODIFY role ENUM(''admin'', ''manager'', ''cashier'', ''super_manager'') NOT NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
