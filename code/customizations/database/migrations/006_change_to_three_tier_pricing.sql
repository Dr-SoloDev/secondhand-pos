-- ============================================================
-- Migration: 006_change_to_three_tier_pricing.sql
-- Purpose: เปลี่ยนจากราคาเดียวเป็นราคา 3 บิล (บิล1 < บิล2 < บิล3)
-- Date: 2026-06-01
-- Author: Dr.SoloDev
-- ============================================================

USE pos_system;

-- ------------------------------------------------------------
-- 1. เพิ่มคอลัมน์ราคา 3 บิลในตาราง products (guard: ข้ามถ้ามีแล้ว)
-- ------------------------------------------------------------
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'price_tier1'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE products
    ADD COLUMN price_tier1 DECIMAL(10,2) NULL COMMENT ''ราคาบิล 1 (ต่ำสุด)'' AFTER price,
    ADD COLUMN price_tier2 DECIMAL(10,2) NULL COMMENT ''ราคาบิล 2 (กลาง)'' AFTER price_tier1,
    ADD COLUMN price_tier3 DECIMAL(10,2) NULL COMMENT ''ราคาบิล 3 (สูงสุด)'' AFTER price_tier2',
  'SELECT ''006 price_tier columns already exist'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. Copy ราคาเดิมไปยัง price_tier2 (ถ้าเพิ่ง add columns)
-- ------------------------------------------------------------
UPDATE products SET
  price_tier1 = price * 0.9,
  price_tier2 = price,
  price_tier3 = price * 1.1
WHERE price IS NOT NULL AND @col_exists = 0;

-- ------------------------------------------------------------
-- 3. ลบตาราง price_tiers (ถ้ามี)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS price_tiers;

-- ------------------------------------------------------------
-- 4. เพิ่มคอลัมน์ selected_tier ใน sale_items (ถ้าตารางมีอยู่)
--    หมายเหตุ: ชื่อตาราง sale_items (ไม่ใช่ sales_items)
--    custom code ไม่ได้ใช้ table นี้ — guard ป้องกัน fresh deploy พัง
-- ------------------------------------------------------------
SET @tbl_exists = (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items'
);
SET @col_exists = IF(@tbl_exists > 0, (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'selected_tier'
), 1);
SET @sql = IF(@tbl_exists > 0 AND @col_exists = 0,
  'ALTER TABLE sale_items ADD COLUMN selected_tier TINYINT DEFAULT 2 COMMENT ''บิลที่เลือก (1/2/3)''',
  'SELECT ''006 sale_items skip'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- หมายเหตุ:
-- - price_tier1 < price_tier2 < price_tier3
-- - ตอนเพิ่มสินค้าใหม่ ต้องกรอกราคาทั้ง 3 บิล
-- - ตอนขาย เลือกบิล → ราคาเปลี่ยนตามบิล
-- ------------------------------------------------------------
