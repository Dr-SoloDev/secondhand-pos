-- ============================================================
-- Migration: 011_add_seller_vehicle_plate.sql
-- Purpose: เพิ่มฟิลด์ทะเบียนรถในตาราง sellers
--          (address มีอยู่แล้วจาก migration 002 — เพิ่มเฉพาะ vehicle_plate)
-- Date: 2569-05-27
-- ============================================================
USE pos_system;

ALTER TABLE sellers
    ADD COLUMN vehicle_plate VARCHAR(20) NULL COMMENT 'ทะเบียนรถผู้ขาย' AFTER address,
    ADD INDEX idx_vehicle_plate (vehicle_plate);
