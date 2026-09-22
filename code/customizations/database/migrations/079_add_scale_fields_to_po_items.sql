-- Migration 079: Add scale provenance to purchase_order_items
-- น้ำหนัก (quantity) = auto จากตาชั่งเมื่อต่ออยู่, หักน้ำหนัก (weight_deduction) = manual เสมอ

ALTER TABLE purchase_order_items
  ADD COLUMN weight_source ENUM('manual','scale','manual_override') NOT NULL DEFAULT 'manual'
    COMMENT 'manual=คีย์มือ(แท็บเล็ต/ไม่มีตาชั่ง), scale=ตาชั่ง Tiger TI-01, manual_override=ผู้จัดการอนุมัติคีย์มือตอนมีตาชั่ง' AFTER weight_deduction,
  ADD COLUMN scale_device_id INT NULL
    COMMENT 'scale_devices.id — null ถ้า manual' AFTER weight_source,
  ADD COLUMN scale_raw_kg DECIMAL(10,2) NULL
    COMMENT 'ค่าน้ำหนักดิบจากตาชั่งก่อนคำนวณ' AFTER scale_device_id,
  ADD COLUMN scale_stable TINYINT(1) NULL
    COMMENT '1=นิ่งแล้วตอนจับ, 0=ยังแกว่ง' AFTER scale_raw_kg,
  ADD COLUMN captured_at DATETIME NULL
    COMMENT 'เวลาที่กดจับน้ำหนัก' AFTER scale_stable,
  ADD COLUMN override_reason VARCHAR(500) NULL
    COMMENT 'เหตุผลเมื่อ manual_override' AFTER captured_at,
  ADD INDEX idx_poi_weight_source (weight_source),
  ADD INDEX idx_poi_scale_device (scale_device_id);

-- FK แบบ SET NULL — ลบเครื่องชั่งไม่ลบประวัติ PO
-- ใช้ IF NOT EXISTS แบบ MariaDB ไม่รองรับ ADD CONSTRAINT IF NOT EXISTS จึงเช็คก่อน
-- MySQL 8 จะ ignore ถ้ามีแล้ว via information_schema check ใน run-migrations.sh

-- scale_readings already FK to scale_devices, here we add optional FK
-- (executed via raw query, ignore error if exists)
