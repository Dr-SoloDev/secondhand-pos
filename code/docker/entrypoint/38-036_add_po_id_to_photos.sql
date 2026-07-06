-- Migration 036: เพิ่ม purchase_order_id ใน purchase_order_photos + ทำให้ item_id เป็น nullable
-- WF-01: Photo upload ต้องการ PO-level FK (ไม่ผูกติด item เสมอไป)

-- เพิ่ม purchase_order_id (ถ้ายังไม่มี)
SET @col_po = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_photos' AND COLUMN_NAME='purchase_order_id'
);
SET @sql1 = IF(@col_po = 0,
  'ALTER TABLE purchase_order_photos ADD COLUMN purchase_order_id INT NOT NULL AFTER id,
   ADD CONSTRAINT fk_pop_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE',
  'SELECT ''036 purchase_order_id exists'' AS info'
);
PREPARE stmt1 FROM @sql1; EXECUTE stmt1; DEALLOCATE PREPARE stmt1;

-- เปลี่ยน purchase_order_item_id เป็น nullable (รูประดับ PO ไม่ต้องผูก item)
SET @col_item_null = (
  SELECT IS_NULLABLE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='purchase_order_photos' AND COLUMN_NAME='purchase_order_item_id'
);
SET @sql2 = IF(@col_item_null = 'NO',
  'ALTER TABLE purchase_order_photos MODIFY COLUMN purchase_order_item_id INT NULL',
  'SELECT ''036 item_id already nullable'' AS info'
);
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;
