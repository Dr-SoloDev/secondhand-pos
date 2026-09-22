-- Migration 078: Scale readings audit log
-- เก็บทุกครั้งที่จับน้ำหนัก เพื่อตรวจสอบย้อนหลัง (กันฮั้ว) + ดูสัดส่วน scale vs manual

CREATE TABLE IF NOT EXISTS scale_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NULL COMMENT 'scale_devices.id — null ถ้า manual',
    branch_id INT NOT NULL,
    purchase_order_id INT NULL,
    purchase_order_item_id INT NULL,
    weight_kg DECIMAL(10,2) NOT NULL,
    raw_value VARCHAR(100) NULL COMMENT 'string ดิบจาก RS232 เช่น \"  12.34 kg\"',
    stable TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=นิ่งแล้ว, 0=ยังแกว่ง',
    weight_source ENUM('manual','scale','manual_override') NOT NULL DEFAULT 'scale',
    captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scale_readings_device (device_id),
    INDEX idx_scale_readings_branch (branch_id),
    INDEX idx_scale_readings_po (purchase_order_id),
    INDEX idx_scale_readings_created (captured_at),
    CONSTRAINT fk_scale_readings_device FOREIGN KEY (device_id) REFERENCES scale_devices(id) ON DELETE SET NULL,
    CONSTRAINT fk_scale_readings_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='audit ตาชั่งทุกครั้งที่จับน้ำหนัก';
