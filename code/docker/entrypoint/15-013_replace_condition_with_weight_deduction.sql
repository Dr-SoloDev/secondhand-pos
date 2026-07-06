-- ============================================================
-- Migration: 013_replace_condition_with_weight_deduction.sql
-- Purpose: เปลี่ยนช่อง "สภาพ" (condition) → "หักน้ำหนัก" (weight_deduction)
--          เนื่องจากปัญหาจริงในธุรกิจ: ผู้ขายเอาของใส่กระสอบมาขาย —
--          กระสอบเปล่ามีน้ำหนัก ทำให้สต็อกบวมและขาดทุน
--          แอดมิน/แคชเชียร์กรอกน้ำหนักหักตามดุลพินิจ
-- Date: 2569-05-27
-- ============================================================
USE pos_system;

-- 1) condition_id เปลี่ยนเป็น nullable (ไม่ลบคอลัมน์ — เก็บไว้ดูประวัติ PO เก่าได้)
ALTER TABLE purchase_order_items
    MODIFY condition_id INT NULL COMMENT '(deprecated) เก็บไว้สำหรับ PO เก่า — ใหม่ใช้ weight_deduction';

-- 2) เพิ่ม weight_deduction
ALTER TABLE purchase_order_items
    ADD COLUMN weight_deduction DECIMAL(10,3) NOT NULL DEFAULT 0 COMMENT 'น้ำหนักหัก (กก.) — เช่น น้ำหนักกระสอบ' AFTER quantity;
