-- ============================================================
-- Migration: 003_add_item_conditions.sql
-- Purpose: เพิ่มระบบระบุสภาพสินค้า (ดี/พอใช้/ชำรุด)
-- Date: 2569-05-18
-- Author: Dr.SoloDev
-- ============================================================

USE pos_system;

-- ------------------------------------------------------------
-- ตาราง: item_conditions (สภาพสินค้า)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS item_conditions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE COMMENT 'รหัส เช่น GOOD, FAIR, BROKEN',
    name VARCHAR(50) NOT NULL COMMENT 'ชื่อสภาพ ภาษาไทย',
    description TEXT COMMENT 'คำอธิบาย',
    price_multiplier DECIMAL(4, 2) DEFAULT 1.00 COMMENT 'ตัวคูณราคา (ดี=1.0, พอใช้=0.7, ชำรุด=0.4)',
    sort_order INT DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ตารางสภาพสินค้า';

-- ค่าเริ่มต้น 3 สภาพ
INSERT INTO item_conditions (code, name, description, price_multiplier, sort_order) VALUES
('GOOD',   'ดี',     'สภาพดี ใช้งานได้ปกติ ไม่มีตำหนิ',  1.00, 1),
('FAIR',   'พอใช้',   'มีตำหนิเล็กน้อย ใช้งานได้',         0.70, 2),
('BROKEN', 'ชำรุด',  'มีตำหนิมาก หรือต้องซ่อม',           0.40, 3);

-- เพิ่ม condition_id ในตาราง products
ALTER TABLE products
    ADD COLUMN condition_id INT NULL AFTER branch_id,
    ADD CONSTRAINT fk_products_condition FOREIGN KEY (condition_id) REFERENCES item_conditions(id) ON DELETE SET NULL,
    ADD INDEX idx_products_condition (condition_id);
