-- Migration 052: add vehicle_type and vehicle_plate to purchase_orders
-- Purpose: บันทึกประเภทยานพาหนะและทะเบียนต่อ-บิล (ไม่ใช่ต่อ-ผู้ขาย)

SET @dbname = DATABASE();

SET @v1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='purchase_orders' AND COLUMN_NAME='vehicle_type');
SET @s1 = IF(@v1=0,
    'ALTER TABLE purchase_orders ADD COLUMN vehicle_type VARCHAR(30) DEFAULT NULL COMMENT ''ประเภทยานพาหนะต่อบิล'' AFTER notes',
    'SELECT 1');
PREPARE stmt FROM @s1; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @v2 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='purchase_orders' AND COLUMN_NAME='vehicle_plate');
SET @s2 = IF(@v2=0,
    'ALTER TABLE purchase_orders ADD COLUMN vehicle_plate VARCHAR(20) DEFAULT NULL COMMENT ''ทะเบียนรถต่อบิล'' AFTER vehicle_type',
    'SELECT 1');
PREPARE stmt FROM @s2; EXECUTE stmt; DEALLOCATE PREPARE stmt;
