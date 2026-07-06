-- =============================================================================
-- Migration: 009_add_sale_lots.sql
-- ระบบขายสินค้ามือสอง - โมดูลล็อตการขาย (Sales Lot)
-- วันที่สร้าง: 2026-05-27
-- คำอธิบาย: สร้างตาราง sale_lots และ sale_lot_items สำหรับบันทึกการขายสินค้า
--           เป็นล็อต โดยอ้างอิงหมวดหมู่สินค้าและคำนวณต้นทุนแบบ FIFO
-- =============================================================================

USE pos_system;

-- -----------------------------------------------------------------------------
-- ตาราง: sale_lots
-- คำอธิบาย: เก็บข้อมูลหัวล็อตการขาย (ใบขาย SO)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sale_lots (
    id            INT            NOT NULL AUTO_INCREMENT                   COMMENT 'รหัสล็อตการขาย (Primary Key)',
    reference_no  VARCHAR(30)    NOT NULL                                  COMMENT 'เลขที่ใบขาย รูปแบบ SO-YYYYMMDD-001',
    branch_id     INT            NOT NULL                                  COMMENT 'รหัสสาขาที่ทำการขาย (FK → branches.id)',
    buyer_name    VARCHAR(200)   DEFAULT NULL                              COMMENT 'ชื่อผู้ซื้อ (ไม่บังคับ)',
    sale_date     DATE           NOT NULL                                  COMMENT 'วันที่ขาย',
    total_amount  DECIMAL(12,2)  NOT NULL DEFAULT 0                       COMMENT 'ยอดขายรวม (บาท)',
    total_cost    DECIMAL(12,2)  NOT NULL DEFAULT 0                       COMMENT 'ต้นทุนรวม FIFO (บาท)',
    profit        DECIMAL(12,2)  GENERATED ALWAYS AS (total_amount - total_cost) STORED
                                                                          COMMENT 'กำไรขั้นต้น = ยอดขาย - ต้นทุน (คำนวณอัตโนมัติ)',
    status        ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft'
                                                                          COMMENT 'สถานะ: draft=ร่าง, confirmed=ยืนยัน, cancelled=ยกเลิก',
    notes         TEXT           DEFAULT NULL                              COMMENT 'หมายเหตุเพิ่มเติม',
    created_by    INT            DEFAULT NULL                              COMMENT 'รหัสผู้ใช้ที่สร้างรายการ (FK → users.id)',
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP        COMMENT 'วันเวลาที่สร้างรายการ',
    updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                          ON UPDATE CURRENT_TIMESTAMP     COMMENT 'วันเวลาที่แก้ไขล่าสุด',

    PRIMARY KEY (id),
    UNIQUE KEY uq_sale_lots_reference_no (reference_no),
    KEY idx_sale_lots_branch_id (branch_id),
    KEY idx_sale_lots_sale_date (sale_date),

    CONSTRAINT fk_sale_lots_branch_id
        FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ล็อตการขายสินค้ามือสอง (ใบขาย SO)';

-- -----------------------------------------------------------------------------
-- ตาราง: sale_lot_items
-- คำอธิบาย: เก็บข้อมูลรายการสินค้าในแต่ละล็อตการขาย แยกตามหมวดหมู่
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sale_lot_items (
    id           INT            NOT NULL AUTO_INCREMENT                    COMMENT 'รหัสรายการสินค้า (Primary Key)',
    sale_lot_id  INT            NOT NULL                                   COMMENT 'รหัสล็อตการขาย (FK → sale_lots.id)',
    category_id  INT            NOT NULL                                   COMMENT 'รหัสหมวดหมู่สินค้า (FK → categories.id)',
    quantity_kg  DECIMAL(10,3)  NOT NULL                                   COMMENT 'น้ำหนักสินค้า (กิโลกรัม)',
    unit_price   DECIMAL(10,2)  NOT NULL                                   COMMENT 'ราคาขายต่อกิโลกรัม (บาท)',
    subtotal     DECIMAL(12,2)  GENERATED ALWAYS AS (quantity_kg * unit_price) STORED
                                                                           COMMENT 'ยอดรวมรายการ = น้ำหนัก × ราคา (คำนวณอัตโนมัติ)',
    fifo_cost    DECIMAL(12,2)  NOT NULL DEFAULT 0                        COMMENT 'ต้นทุน FIFO ของรายการนี้ (บาท)',
    created_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP         COMMENT 'วันเวลาที่สร้างรายการ',

    PRIMARY KEY (id),
    KEY idx_sale_lot_items_sale_lot_id (sale_lot_id),
    KEY idx_sale_lot_items_category_id (category_id),

    CONSTRAINT fk_sale_lot_items_sale_lot_id
        FOREIGN KEY (sale_lot_id) REFERENCES sale_lots (id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_lot_items_category_id
        FOREIGN KEY (category_id) REFERENCES categories (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='รายการสินค้าในล็อตการขาย แยกตามหมวดหมู่';

-- =============================================================================
-- สิ้นสุด Migration: 009_add_sale_lots.sql
-- =============================================================================
