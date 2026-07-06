-- ============================================================
-- Migration: 010_add_consumed_qty.sql
-- Purpose: เพิ่ม consumed_qty ใน purchase_order_items
--          สำหรับติดตามสต็อกที่ถูกตัดโดย Sale Lot (FIFO)
-- Date: 2026-05-27
-- Author: Dr.SoloDev
-- ============================================================

USE pos_system;

ALTER TABLE purchase_order_items
    ADD COLUMN consumed_qty DECIMAL(10,3) NOT NULL DEFAULT 0
        COMMENT 'น้ำหนักที่ถูกตัดสต็อกแล้ว (กก.) โดยระบบ Sale Lot — สต็อกคงเหลือ = quantity - consumed_qty'
        AFTER total_price;

-- Index ช่วย query FIFO available stock ให้เร็วขึ้น
ALTER TABLE purchase_order_items
    ADD INDEX idx_poi_category_consumed (category_id, consumed_qty);
