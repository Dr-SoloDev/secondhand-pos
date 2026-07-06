-- Migration 027: Merge duplicate categories → global (1 record per name)
-- Guard: ข้ามถ้า branch_id column ถูกลบไปแล้ว (สัญญาณว่า migration นี้รันแล้ว)

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'categories'
    AND COLUMN_NAME = 'branch_id'
);

SET @sql = IF(@col_exists > 0,
  'SELECT ''running 027 merge'' AS info',
  'SELECT ''027 already applied, skipping'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ถ้า branch_id ยังมีอยู่ ให้รัน merge
DROP TEMPORARY TABLE IF EXISTS category_mapping;
CREATE TEMPORARY TABLE IF NOT EXISTS category_mapping AS
SELECT c.id as old_id, m.min_id as new_id
FROM categories c
INNER JOIN (SELECT MIN(id) as min_id, name FROM categories GROUP BY name) m ON c.name = m.name
WHERE @col_exists > 0;

UPDATE sale_lot_items sli
INNER JOIN category_mapping cm ON sli.category_id = cm.old_id
SET sli.category_id = cm.new_id WHERE @col_exists > 0;

UPDATE purchase_order_items poi
INNER JOIN category_mapping cm ON poi.category_id = cm.old_id
SET poi.category_id = cm.new_id WHERE @col_exists > 0;

UPDATE purchase_item_catalog pic
INNER JOIN category_mapping cm ON pic.category_id = cm.old_id
SET pic.category_id = cm.new_id WHERE @col_exists > 0;

-- ลบ duplicate rows
DELETE FROM categories WHERE @col_exists > 0 AND id NOT IN (
  SELECT min_id FROM (SELECT MIN(id) as min_id FROM categories GROUP BY name) AS keep_ids
);

-- ลบ FK constraint ก่อน (ถ้ามี) แล้วค่อย DROP branch_id
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='categories'
    AND CONSTRAINT_NAME='fk_categories_branch' AND CONSTRAINT_TYPE='FOREIGN KEY'
);
SET @ddl_fk = IF(@fk_exists > 0 AND @col_exists > 0,
  'ALTER TABLE categories DROP FOREIGN KEY fk_categories_branch',
  'SELECT ''no FK to drop'' AS info'
);
PREPARE stmt_fk FROM @ddl_fk; EXECUTE stmt_fk; DEALLOCATE PREPARE stmt_fk;

-- ลบ branch_id และเพิ่ม UNIQUE constraint (ถ้ายังมี branch_id)
SET @ddl_drop = IF(@col_exists > 0,
  'ALTER TABLE categories DROP COLUMN branch_id',
  'SELECT ''branch_id already gone'' AS info'
);
PREPARE stmt2 FROM @ddl_drop; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

-- เพิ่ม UNIQUE constraint ถ้ายังไม่มี
SET @idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND INDEX_NAME = 'unique_category_name'
);
SET @idx_sql = IF(@idx_exists = 0,
  'ALTER TABLE categories ADD UNIQUE KEY unique_category_name (name)',
  'SELECT ''unique key exists'' AS info'
);
PREPARE stmt3 FROM @idx_sql; EXECUTE stmt3; DEALLOCATE PREPARE stmt3;
