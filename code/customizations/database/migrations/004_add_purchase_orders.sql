-- ============================================================
-- Migration: 004_add_purchase_orders.sql
-- Purpose: เพิ่มระบบรับซื้อของเก่า (Purchase Orders)
-- Date: 2569-05-18
-- Author: Dr.SoloDev
-- ============================================================

USE pos_system;

-- ------------------------------------------------------------
-- ตาราง: purchase_orders (ใบรับซื้อ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_no VARCHAR(20) NOT NULL UNIQUE COMMENT 'เลขที่ใบรับซื้อ เช่น PO20260518-001',
    branch_id INT NOT NULL COMMENT 'รับซื้อที่สาขาไหน',
    seller_id INT NOT NULL COMMENT 'ใครเอาของมาขาย',
    user_id INT NOT NULL COMMENT 'พนักงานที่รับซื้อ',
    total_items INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0 COMMENT 'ยอดรวมที่จ่าย',
    payment_method ENUM('cash', 'bank_transfer') NOT NULL DEFAULT 'cash',
    payment_status ENUM('pending', 'paid', 'rejected') DEFAULT 'paid',
    status ENUM('draft', 'completed', 'cancelled') DEFAULT 'completed',
    notes TEXT COMMENT 'หมายเหตุการรับซื้อ',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (seller_id) REFERENCES sellers(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_po_branch (branch_id),
    INDEX idx_po_seller (seller_id),
    INDEX idx_po_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ใบรับซื้อของเก่า';

-- ------------------------------------------------------------
-- ตาราง: purchase_order_items (รายการของที่รับซื้อ)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    product_id INT NULL COMMENT 'NULL = ของที่ไม่เคยรับมาก่อน (จะสร้าง product ใหม่)',
    item_name VARCHAR(200) NOT NULL COMMENT 'ชื่อของ',
    category_id INT NULL,
    condition_id INT NOT NULL COMMENT 'สภาพของ',
    quantity DECIMAL(10, 3) NOT NULL DEFAULT 1 COMMENT 'จำนวน (ทศนิยม 3 ตำแหน่ง สำหรับชั่งน้ำหนัก)',
    unit VARCHAR(20) DEFAULT 'ชิ้น' COMMENT 'หน่วย: ชิ้น, กก., เมตร',
    unit_price DECIMAL(10, 2) NOT NULL COMMENT 'ราคาต่อหน่วย (ที่ต่อรอง)',
    total_price DECIMAL(12, 2) NOT NULL COMMENT 'ราคารวม',
    photo_path VARCHAR(255) COMMENT 'path รูปของที่รับซื้อ',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
    FOREIGN KEY (condition_id) REFERENCES item_conditions(id) ON DELETE RESTRICT,
    INDEX idx_poi_po (purchase_order_id),
    INDEX idx_poi_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='รายการของในใบรับซื้อ';

-- ------------------------------------------------------------
-- ตาราง: purchase_order_photos (รูปถ่ายของในใบรับซื้อ)
-- เผื่อ 1 รายการมีหลายรูป
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_order_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_item_id INT NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    is_primary TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id) ON DELETE CASCADE,
    INDEX idx_poi (purchase_order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='รูปถ่ายของในใบรับซื้อ';
