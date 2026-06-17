-- Migration 026: Populate category_id ใน purchase_item_catalog
-- Guard: ถ้า catalog_item_id ไม่มีใน purchase_order_items → ข้ามส่วนนั้น (fresh install ไม่มี data)

SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_items' AND COLUMN_NAME='catalog_item_id'
);

-- ถ้า catalog_item_id มีอยู่ → populate จาก purchase history (dev/prod ที่ใช้งานมาแล้ว)
SET @sql = IF(@col_exists > 0,
  'UPDATE purchase_item_catalog pic
   SET category_id = (
     SELECT category_id FROM purchase_order_items poi
     WHERE poi.catalog_item_id = pic.id AND poi.category_id IS NOT NULL
     GROUP BY category_id ORDER BY COUNT(*) DESC LIMIT 1
   )
   WHERE pic.category_id IS NULL
     AND EXISTS (
       SELECT 1 FROM purchase_order_items poi2
       WHERE poi2.catalog_item_id = pic.id AND poi2.category_id IS NOT NULL
     )',
  'SELECT ''026 skip (no catalog_item_id column)'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Fallback: populate ด้วยชื่อ (ทำงานทั้ง fresh และ existing)
-- COLLATE ชัดเจนเพื่อแก้ collation mismatch ระหว่าง utf8mb4_unicode_ci และ utf8mb4_0900_ai_ci
UPDATE purchase_item_catalog pic
SET category_id = (
  SELECT c.id FROM categories c
  WHERE c.name COLLATE utf8mb4_unicode_ci = pic.name COLLATE utf8mb4_unicode_ci
    AND c.status = 'active'
  LIMIT 1
)
WHERE pic.category_id IS NULL;
