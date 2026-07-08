-- ============================================================
-- Migration: 006_seed_demo_data.sql
-- Purpose: ข้อมูลตัวอย่างสำหรับ Demo ลูกค้า — ร้านรับซื้อของเก่าสุรินทร์ 4 สาขา
-- Date: 2569-05-22
-- ============================================================
USE pos_system;

-- ------------------------------------------------------------
-- อัปเดตข้อมูลสาขา (placeholder จนกว่าจะลงสำรวจจริง — คงชื่อ "สาขา 1-4" ไว้)
-- ------------------------------------------------------------
UPDATE branches SET address = 'อ.เมืองสุรินทร์ จ.สุรินทร์', phone = '044-xxx-xxx', manager_name = 'คุณสมชาย' WHERE code = 'BR01';
UPDATE branches SET address = 'อ.ปราสาท จ.สุรินทร์',      phone = '044-xxx-xxx', manager_name = 'คุณวิทยา'  WHERE code = 'BR02';
UPDATE branches SET address = 'อ.ศีขรภูมิ จ.สุรินทร์',     phone = '044-xxx-xxx', manager_name = 'คุณอำนาจ' WHERE code = 'BR03';
UPDATE branches SET address = 'อ.สังขะ จ.สุรินทร์',        phone = '044-xxx-xxx', manager_name = 'คุณประยุทธ' WHERE code = 'BR04';

-- ------------------------------------------------------------
-- ผู้ขายตัวอย่าง 8 ราย
-- ------------------------------------------------------------
INSERT INTO sellers (id_card, full_name, phone, address, notes, is_blacklisted) VALUES
('1320500123456', 'นายสมหวัง ใจดี',     '0812345678', 'ต.ในเมือง อ.เมืองสุรินทร์',          'ลูกค้าประจำ มาบ่อย',                 0),
('1320500234567', 'นางสาวมาลี รักษ์ดี',  '0823456789', 'ต.นอกเมือง อ.เมืองสุรินทร์',         'รับซื้อเหล็กเป็นหลัก',                 0),
('1320500345678', 'นายประจักษ์ ทองดี',  '0834567890', 'ต.กังแอน อ.ปราสาท',                 'นำของอิเล็กทรอนิกส์มาขาย',            0),
('1320500456789', 'นางบุญมี สุขใจ',     '0845678901', 'ต.ระแงง อ.ศีขรภูมิ',                 NULL,                                  0),
('1320500567890', 'นายอนุชา พงษ์ไพร',   '0856789012', 'ต.สังขะ อ.สังขะ',                   'ของเก่าโบราณ',                         0),
('1320500678901', 'นายวิชัย เก่งกาจ',   '0867890123', 'ต.ในเมือง อ.เมืองสุรินทร์',          NULL,                                  0),
('1320500789012', 'นางสุพร ค้าขาย',    '0878901234', 'ต.เฉนียง อ.เมืองสุรินทร์',           'รับเป็นรถ',                            0),
('1320500890123', 'นายต้องห้าม สมมติ',  '0889012345', 'ไม่ทราบที่อยู่',                       'เคยเอาของไม่ตรงปก หลีกเลี่ยง',         1);

-- ------------------------------------------------------------
-- หมวดหมู่เพิ่มเติมสำหรับร้านรับซื้อของเก่า
-- (เผื่อ 005_seed_categories.sql ยังไม่ครอบคลุม — ใช้ INSERT IGNORE)
-- ------------------------------------------------------------
INSERT IGNORE INTO categories (name, description, status) VALUES
('เหล็ก',           'เหล็กเส้น เหล็กแผ่น เศษเหล็ก',     'active'),
('ทองแดง',          'สายไฟ ทองแดงเส้น',                 'active'),
('อะลูมิเนียม',     'กระป๋อง ขอบหน้าต่าง',                'active'),
('เครื่องใช้ไฟฟ้า',  'พัดลม ทีวี ตู้เย็น',                  'active'),
('อิเล็กทรอนิกส์',   'มือถือ คอมพิวเตอร์ แท็บเล็ต',        'active'),
('กระดาษ',          'กระดาษหนังสือพิมพ์ กล่องลัง',          'active'),
('พลาสติก',         'ขวด PET ถัง',                       'active'),
('ของเก่าโบราณ',    'พระเครื่อง เครื่องทองเหลือง',          'active');

-- ------------------------------------------------------------
-- ใบรับซื้อตัวอย่าง 5 ใบ — สลับสาขา/ผู้ขาย/วันที่
-- ใช้ user_id = 1 (admin) จาก base seed
-- ------------------------------------------------------------

-- ใบที่ 1: วันนี้ — สาขาเมืองสุรินทร์ — สมหวัง — เหล็ก + ทองแดง
INSERT INTO purchase_orders (reference_no, branch_id, seller_id, user_id, total_items, total_amount, payment_method, status, notes, created_at)
VALUES ('PO20260523-001', 1, 1, 1, 25, 1850.00, 'cash', 'completed', 'รับซื้อเศษเหล็กและทองแดงจากบ้านเก่า', NOW());
SET @po1 = LAST_INSERT_ID();
INSERT INTO purchase_order_items (purchase_order_id, item_name, category_id, condition_id, quantity, unit, unit_price, total_price, notes) VALUES
(@po1, 'เหล็กเส้นเก่า',  (SELECT id FROM categories WHERE name='เหล็ก' LIMIT 1),    1, 20.000, 'กก.',  15.00, 300.00,   'เหล็กก่อสร้างเก่า'),
(@po1, 'สายไฟทองแดง',   (SELECT id FROM categories WHERE name='ทองแดง' LIMIT 1),  1,  5.000, 'กก.', 310.00, 1550.00,  'ทองแดงล้วน ไม่มีฉนวนปน');

-- ใบที่ 2: วันนี้ — สาขาปราสาท — ประจักษ์ — เครื่องใช้ไฟฟ้า
INSERT INTO purchase_orders (reference_no, branch_id, seller_id, user_id, total_items, total_amount, payment_method, status, notes, created_at)
VALUES ('PO20260523-002', 2, 3, 1, 3, 850.00, 'cash', 'completed', 'พัดลมเก่า + ทีวี CRT', NOW());
SET @po2 = LAST_INSERT_ID();
INSERT INTO purchase_order_items (purchase_order_id, item_name, category_id, condition_id, quantity, unit, unit_price, total_price, notes) VALUES
(@po2, 'พัดลมตั้งพื้น Hatari', (SELECT id FROM categories WHERE name='เครื่องใช้ไฟฟ้า' LIMIT 1), 2, 2, 'ชิ้น', 150.00, 300.00, 'ใช้งานได้ ใบพัดเก่า'),
(@po2, 'ทีวี CRT 21 นิ้ว',     (SELECT id FROM categories WHERE name='เครื่องใช้ไฟฟ้า' LIMIT 1), 3, 1, 'ชิ้น', 550.00, 550.00, 'จอแตก ขายเป็นซาก');

-- ใบที่ 3: เมื่อวาน — สาขาศีขรภูมิ — บุญมี — กระดาษ
INSERT INTO purchase_orders (reference_no, branch_id, seller_id, user_id, total_items, total_amount, payment_method, status, notes, created_at)
VALUES ('PO20260522-001', 3, 4, 1, 50, 250.00, 'cash', 'completed', NULL, DATE_SUB(NOW(), INTERVAL 1 DAY));
SET @po3 = LAST_INSERT_ID();
INSERT INTO purchase_order_items (purchase_order_id, item_name, category_id, condition_id, quantity, unit, unit_price, total_price) VALUES
(@po3, 'กระดาษหนังสือพิมพ์', (SELECT id FROM categories WHERE name='กระดาษ' LIMIT 1), 1, 50.000, 'กก.', 5.00, 250.00);

-- ใบที่ 4: เมื่อวาน — สาขาสังขะ — อนุชา — ของเก่าโบราณ
INSERT INTO purchase_orders (reference_no, branch_id, seller_id, user_id, total_items, total_amount, payment_method, status, notes, created_at)
VALUES ('PO20260522-002', 4, 5, 1, 4, 3200.00, 'bank_transfer', 'completed', 'พระเครื่องและเครื่องทองเหลือง — ตรวจสภาพแล้ว', DATE_SUB(NOW(), INTERVAL 1 DAY));
SET @po4 = LAST_INSERT_ID();
INSERT INTO purchase_order_items (purchase_order_id, item_name, category_id, condition_id, quantity, unit, unit_price, total_price, notes) VALUES
(@po4, 'พระเครื่องโบราณ',     (SELECT id FROM categories WHERE name='ของเก่าโบราณ' LIMIT 1), 1, 2, 'ชิ้น', 1200.00, 2400.00, 'รอผู้เชี่ยวชาญดูอีกที'),
(@po4, 'กระทะทองเหลือง',     (SELECT id FROM categories WHERE name='ของเก่าโบราณ' LIMIT 1), 2, 2, 'ชิ้น',  400.00,  800.00, NULL);

-- ใบที่ 5: 3 วันก่อน — สาขาเมืองสุรินทร์ — มาลี — เหล็ก/อะลูมิเนียม
INSERT INTO purchase_orders (reference_no, branch_id, seller_id, user_id, total_items, total_amount, payment_method, status, notes, created_at)
VALUES ('PO20260520-001', 1, 2, 1, 35, 1100.00, 'cash', 'completed', NULL, DATE_SUB(NOW(), INTERVAL 3 DAY));
SET @po5 = LAST_INSERT_ID();
INSERT INTO purchase_order_items (purchase_order_id, item_name, category_id, condition_id, quantity, unit, unit_price, total_price) VALUES
(@po5, 'เหล็กแผ่นเก่า',       (SELECT id FROM categories WHERE name='เหล็ก' LIMIT 1),       2, 25.000, 'กก.', 12.00, 300.00),
(@po5, 'กระป๋องอะลูมิเนียม',  (SELECT id FROM categories WHERE name='อะลูมิเนียม' LIMIT 1), 1, 10.000, 'กก.', 80.00, 800.00);

-- ------------------------------------------------------------
-- อัปเดตยอดสะสมของผู้ขาย
-- ------------------------------------------------------------
UPDATE sellers s
SET total_transactions = (SELECT COUNT(*) FROM purchase_orders WHERE seller_id = s.id AND status = 'completed'),
    total_amount       = (SELECT COALESCE(SUM(total_amount),0) FROM purchase_orders WHERE seller_id = s.id AND status = 'completed'),
    last_transaction_at = (SELECT MAX(created_at) FROM purchase_orders WHERE seller_id = s.id AND status = 'completed');
