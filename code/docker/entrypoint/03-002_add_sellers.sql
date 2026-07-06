-- ============================================================
-- Migration: 002_add_sellers.sql
-- Purpose: เพิ่มระบบผู้ขาย (Walk-in Sellers) สำหรับร้านรับซื้อของเก่า
-- Date: 2569-05-18
-- Author: Dr.SoloDev
-- ============================================================

USE pos_system;

-- ------------------------------------------------------------
-- ตาราง: sellers (ผู้ที่นำของมาขาย)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sellers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_card VARCHAR(13) UNIQUE COMMENT 'เลขบัตรประชาชน (สำคัญทางกฎหมาย)',
    full_name VARCHAR(100) NOT NULL COMMENT 'ชื่อ-นามสกุล',
    phone VARCHAR(20) COMMENT 'เบอร์โทรศัพท์',
    address TEXT COMMENT 'ที่อยู่',
    id_card_photo VARCHAR(255) COMMENT 'path รูปบัตรประชาชน',
    notes TEXT COMMENT 'หมายเหตุ (เช่น เคยขายของผิดกฎหมาย, blacklist)',
    is_blacklisted TINYINT(1) DEFAULT 0 COMMENT 'blacklist: ห้ามรับซื้อ',
    total_transactions INT DEFAULT 0 COMMENT 'จำนวนครั้งที่ขาย',
    total_amount DECIMAL(12, 2) DEFAULT 0 COMMENT 'ยอดรวมที่ขายมาแล้ว',
    last_transaction_at TIMESTAMP NULL COMMENT 'วันที่ขายล่าสุด',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_id_card (id_card),
    INDEX idx_phone (phone),
    INDEX idx_blacklist (is_blacklisted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ตารางผู้ขาย (คนเอาของมาขาย)';
