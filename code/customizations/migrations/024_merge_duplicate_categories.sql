-- Migration 024: Merge duplicate categories into global categories
-- แต่ละหมวดหมู่ซ้ำ 4 สาขา → เหลือแค่ 1 record

-- Step 1: สร้าง mapping table (old_id → canonical_id)
CREATE TEMPORARY TABLE category_mapping AS
SELECT c.id as old_id, m.min_id as new_id
FROM categories c
INNER JOIN (
  SELECT MIN(id) as min_id, name
  FROM categories
  GROUP BY name
) m ON c.name = m.name;

-- Step 2: Update FK references ให้ชี้ไปที่ canonical_id
UPDATE sale_lot_items sli
INNER JOIN category_mapping cm ON sli.category_id = cm.old_id
SET sli.category_id = cm.new_id
WHERE sli.category_id IS NOT NULL;

-- ทำซ้ำสำหรับ tables อื่นที่มี category_id FK (ถ้ามี)
UPDATE purchase_order_items poi
INNER JOIN category_mapping cm ON poi.category_id = cm.old_id
SET poi.category_id = cm.new_id
WHERE poi.category_id IS NOT NULL;

UPDATE purchase_item_catalog pic
INNER JOIN category_mapping cm ON pic.category_id = cm.old_id
SET pic.category_id = cm.new_id
WHERE pic.category_id IS NOT NULL;

-- Step 3: ลบรายการซ้ำ — เก็บแค่ id ที่น้อยที่สุดของแต่ละชื่อ
DELETE FROM categories
WHERE id NOT IN (
  SELECT min_id FROM (
    SELECT MIN(id) as min_id
    FROM categories
    GROUP BY name
  ) AS keep_ids
);

-- Step 4: ลบ branch_id column (ไม่ใช้แล้ว)
ALTER TABLE categories DROP COLUMN branch_id;

-- Step 5: เพิ่ม UNIQUE constraint บน name
ALTER TABLE categories ADD UNIQUE KEY unique_category_name (name);

-- แสดงผลลัพธ์
SELECT COUNT(*) as total_categories FROM categories WHERE status = 'active';
