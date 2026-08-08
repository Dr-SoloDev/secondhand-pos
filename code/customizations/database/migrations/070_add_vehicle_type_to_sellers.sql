-- ============================================================
-- Migration: 070_add_vehicle_type_to_sellers.sql
-- Purpose: เพิ่ม column vehicle_type ในตาราง sellers
--          (Seller model / SellersController / sellers.js ใช้งาน
--           แต่ migration 011 เพิ่มแค่ vehicle_plate — schema drift
--           ทำให้ sellers API 500 ทุก endpoint)
-- Date: 2569-08-08
-- ============================================================

SET @dbname = DATABASE();

SET @exists = (
  SELECT COUNT(*)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'sellers'
    AND COLUMN_NAME = 'vehicle_type'
);

SET @sql = IF(@exists = 0,
  'ALTER TABLE sellers
     ADD COLUMN vehicle_type VARCHAR(30) DEFAULT NULL COMMENT ''ประเภทยานพาหนะผู้ขาย'' AFTER vehicle_plate',
  'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
