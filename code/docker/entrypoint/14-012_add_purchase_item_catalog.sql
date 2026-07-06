-- ============================================================
-- Migration: 012_add_purchase_item_catalog.sql
-- Purpose: ตาราง master ของรายการสินค้าที่รับซื้อ
-- Fix: ใช้ subquery lookup category_id ด้วยชื่อ (ไม่ hardcode id)
-- ============================================================
USE pos_system;

CREATE TABLE IF NOT EXISTS purchase_item_catalog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    category_id INT NULL,
    default_unit VARCHAR(20) DEFAULT 'ชิ้น',
    default_price DECIMAL(10,2) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_name (name),
    INDEX idx_active (is_active),
    CONSTRAINT fk_catalog_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- เปลี่ยนชื่อหมวด: เศษเหล็ก → โลหะ (ค้นหาด้วยชื่อ ไม่ใช่ id)
UPDATE categories SET name='โลหะ' WHERE name='เศษเหล็ก';

-- Seed รายการสินค้า (lookup category_id ด้วยชื่อ — idempotent ด้วย INSERT IGNORE)
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'M01', 'เหล็กรวมหรือเหล็กบาง', id, 'กก.', 0 FROM categories WHERE name='โลหะ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'M02', 'เหล็กหนา', id, 'กก.', 0 FROM categories WHERE name='โลหะ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'M03', 'เหล็กเกิน 300 กก.', id, 'กก.', 0 FROM categories WHERE name='โลหะ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'M04', 'ผ้าเบรก/โช๊คอัพ/แผ่นครัช', id, 'กก.', 0 FROM categories WHERE name='โลหะ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'M05', 'กระป๋องกาแฟ', id, 'กก.', 0 FROM categories WHERE name='โลหะ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'M06', 'สังกะสี', id, 'กก.', 0 FROM categories WHERE name='โลหะ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'P01', 'กระดาษลัง', id, 'กก.', 0 FROM categories WHERE name='กระดาษ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'P02', 'กระดาษขาว-ดำ', id, 'กก.', 0 FROM categories WHERE name='กระดาษ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'P03', 'กระดาษเศษ', id, 'กก.', 0 FROM categories WHERE name='กระดาษ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'P04', 'กระดาษหนังสือพิมพ์', id, 'กก.', 0 FROM categories WHERE name='กระดาษ' LIMIT 1;
INSERT IGNORE INTO purchase_item_catalog (code, name, category_id, default_unit, default_price)
SELECT 'P05', 'ลังเปล่า', id, 'กก.', 0 FROM categories WHERE name='กระดาษ' LIMIT 1;
