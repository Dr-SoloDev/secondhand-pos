-- ============================================================
-- Migration: 012_add_purchase_item_catalog.sql
-- Purpose: ตาราง master ของรายการสินค้าที่รับซื้อ (code + name + default price)
--          ใช้สำหรับ autocomplete ในหน้า PO — บังคับเลือกจาก catalog
-- Date: 2569-05-27
-- ============================================================
USE pos_system;

CREATE TABLE IF NOT EXISTS purchase_item_catalog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE COMMENT 'รหัสสินค้า เช่น WM01, IRON',
    name VARCHAR(100) NOT NULL COMMENT 'ชื่อสินค้า เช่น เครื่องซักผ้า',
    category_id INT NULL COMMENT 'หมวดสินค้า (FK -> categories)',
    default_unit VARCHAR(20) DEFAULT 'ชิ้น' COMMENT 'หน่วยเริ่มต้น',
    default_price DECIMAL(10,2) DEFAULT 0 COMMENT 'ราคาตั้งต้น (ก่อนคูณสภาพ)',
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_name (name),
    INDEX idx_active (is_active),
    CONSTRAINT fk_catalog_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Master รายการสินค้าที่รับซื้อ';

-- ------------------------------------------------------------
-- ปรับชื่อหมวด: เศษเหล็ก → โลหะ (ตามที่ลูกค้ากำหนด)
-- ------------------------------------------------------------
UPDATE categories SET name='โลหะ' WHERE id=15;

-- ------------------------------------------------------------
-- Seed รายการสินค้าตามที่ลูกค้ากำหนด — แอดมินจะ CRUD เพิ่ม/แก้ในหน้า admin ได้
-- ราคา default = 0 (admin จะกรอกราคาตอนรับซื้อจริง)
-- ------------------------------------------------------------
INSERT INTO purchase_item_catalog (code, name, category_id, default_unit, default_price) VALUES
-- หมวดโลหะ
('M01', 'เหล็กรวมหรือเหล็กบาง',     15, 'กก.', 0),
('M02', 'เหล็กหนา',                  15, 'กก.', 0),
('M03', 'เหล็กเกิน 300 กก.',         15, 'กก.', 0),
('M04', 'ผ้าเบรก/โช๊คอัพ/แผ่นครัช',  15, 'กก.', 0),
('M05', 'กระป๋องกาแฟ',               15, 'กก.', 0),
('M06', 'สังกะสี',                   15, 'กก.', 0),
-- หมวดกระดาษ
('P01', 'กระดาษลัง',                 20, 'กก.', 0),
('P02', 'กระดาษขาว-ดำ',              20, 'กก.', 0),
('P03', 'กระดาษเศษ',                 20, 'กก.', 0),
('P04', 'กระดาษหนังสือพิมพ์',        20, 'กก.', 0),
('P05', 'ลังเปล่า',                  20, 'กก.', 0);
