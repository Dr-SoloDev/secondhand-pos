-- Migration 053: add tier_level to sellers
-- Purpose: กำหนดระดับราคาพิเศษ (บิล 2/3) ให้ผู้ขายเฉพาะราย
-- Default 1 = ราคาทั่วไป (บิล 1), 2 = บิล 2, 3 = บิล 3

SET @dbname = DATABASE();

SET @v1 = (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='sellers' AND COLUMN_NAME='tier_level');
SET @s1 = IF(@v1=0,
    'ALTER TABLE sellers ADD COLUMN tier_level INT DEFAULT 1 COMMENT ''ระดับราคาพิเศษ (1=ทั่วไป, 2=บิล2, 3=บิล3)'' AFTER notes',
    'SELECT 1');
PREPARE stmt FROM @s1; EXECUTE stmt; DEALLOCATE PREPARE stmt;
