-- Migration 023: Populate category_id in purchase_item_catalog
-- จาก purchase_order_items ที่ใช้บ่อยสุด (mode)

UPDATE purchase_item_catalog pic
SET category_id = (
  SELECT category_id
  FROM purchase_order_items poi
  WHERE poi.catalog_item_id = pic.id
    AND poi.category_id IS NOT NULL
  GROUP BY category_id
  ORDER BY COUNT(*) DESC
  LIMIT 1
)
WHERE pic.category_id IS NULL
  AND EXISTS (
    SELECT 1 FROM purchase_order_items poi2
    WHERE poi2.catalog_item_id = pic.id AND poi2.category_id IS NOT NULL
  );

-- สำหรับรายการที่ไม่เคยมี PO → ใช้หมวดหมู่ตามชื่อที่ใกล้เคียง (ถ้ามี)
-- เช่น "เหล็ก" → หา category ชื่อ "เหล็ก" ของสาขา 1
UPDATE purchase_item_catalog pic
SET category_id = (
  SELECT c.id
  FROM categories c
  WHERE c.name = pic.name
    AND c.branch_id = 1
    AND c.status = 'active'
  LIMIT 1
)
WHERE pic.category_id IS NULL;
