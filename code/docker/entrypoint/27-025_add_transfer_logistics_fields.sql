-- Migration 025: เพิ่มข้อมูลโลจิสติกส์ใน stock_transfers
-- ต้องมาหลัง 024_add_stock_transfers

-- Guard: ข้ามถ้า column มีอยู่แล้ว (รันซ้ำได้)
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_transfers'
    AND COLUMN_NAME = 'transporter_name'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE stock_transfers
    ADD COLUMN transporter_name   VARCHAR(100) DEFAULT NULL AFTER note,
    ADD COLUMN vehicle_plate      VARCHAR(20)  DEFAULT NULL AFTER transporter_name,
    ADD COLUMN received_weight_kg DECIMAL(12,3) DEFAULT NULL AFTER vehicle_plate,
    ADD COLUMN receive_note       VARCHAR(500) DEFAULT NULL AFTER received_weight_kg',
  'SELECT ''025 already applied, skipping'' AS info'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
