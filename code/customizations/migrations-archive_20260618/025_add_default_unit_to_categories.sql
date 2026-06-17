-- Migration 025: เพิ่ม default_unit ให้หมวดหมู่
-- แต่ละหมวดหมู่มีหน่วยเริ่มต้นต่างกัน (กก., ลัง, ชิ้น)

-- เพิ่ม column default_unit
ALTER TABLE categories
ADD COLUMN default_unit VARCHAR(20) DEFAULT 'กก.' AFTER name;

-- ตั้งค่าหน่วยเริ่มต้นสำหรับหมวดหมู่พิเศษ
UPDATE categories SET default_unit = 'ลัง' WHERE name = 'ขวดใส่ลัง';

-- แสดงผลลัพธ์
SELECT id, name, default_unit FROM categories ORDER BY id;
