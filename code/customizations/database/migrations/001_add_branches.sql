-- ============================================================
-- Migration: 001_add_branches.sql
-- Purpose: เพิ่มระบบ Multi-branch (4 สาขา)
-- Date: 2569-05-18
-- Author: Dr.SoloDev
-- ============================================================

USE pos_system;

-- ------------------------------------------------------------
-- ตาราง: branches (สาขา)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE COMMENT 'รหัสสาขา เช่น BR01',
    name VARCHAR(100) NOT NULL COMMENT 'ชื่อสาขา',
    address TEXT COMMENT 'ที่อยู่',
    phone VARCHAR(20) COMMENT 'เบอร์โทรสาขา',
    manager_name VARCHAR(100) COMMENT 'ชื่อผู้จัดการสาขา',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ตารางสาขา';

-- ------------------------------------------------------------
-- เพิ่มสาขาเริ่มต้น 4 สาขา (จะแก้ไขชื่อจริงหลังคุยลูกค้า)
-- ------------------------------------------------------------
INSERT INTO branches (code, name, address, phone) VALUES
('BR01', 'สาขา 1 (สำนักงานใหญ่)', 'จะระบุหลังสำรวจหน้างาน', ''),
('BR02', 'สาขา 2', 'จะระบุหลังสำรวจหน้างาน', ''),
('BR03', 'สาขา 3', 'จะระบุหลังสำรวจหน้างาน', ''),
('BR04', 'สาขา 4', 'จะระบุหลังสำรวจหน้างาน', '');

-- ------------------------------------------------------------
-- เพิ่ม branch_id ในตารางที่เกี่ยวข้อง
-- ------------------------------------------------------------

-- users: พนักงานสังกัดสาขาไหน (admin = NULL = ทุกสาขา)
ALTER TABLE users
    ADD COLUMN branch_id INT NULL AFTER role,
    ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    ADD INDEX idx_users_branch (branch_id);

-- products: สินค้าอยู่ที่สาขาไหน
ALTER TABLE products
    ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER category_id,
    ADD CONSTRAINT fk_products_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    ADD INDEX idx_products_branch (branch_id);

-- sales: บิลขายของสาขาไหน
ALTER TABLE sales
    ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER user_id,
    ADD CONSTRAINT fk_sales_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    ADD INDEX idx_sales_branch (branch_id);

-- inventory_transactions: การเคลื่อนไหวของสต็อกที่สาขา
ALTER TABLE inventory_transactions
    ADD COLUMN branch_id INT NOT NULL DEFAULT 1 AFTER user_id,
    ADD CONSTRAINT fk_inv_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    ADD INDEX idx_inv_branch (branch_id);
