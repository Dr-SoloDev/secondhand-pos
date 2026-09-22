-- Migration 077: Scale devices per branch (Tiger TI-01 RS232)
-- รองรับเพิ่มสาขาไม่จำกัด, แต่ละสาขามีได้หลายเครื่อง (ขยายร้านใหม่)

CREATE TABLE IF NOT EXISTS scale_devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    code VARCHAR(20) NOT NULL COMMENT 'รหัสเครื่อง เช่น TIGER-01',
    name VARCHAR(100) NOT NULL DEFAULT 'Tiger TI-01' COMMENT 'ชื่อเครื่อง',
    model VARCHAR(50) NOT NULL DEFAULT 'Tiger TI-01',
    serial_no VARCHAR(50) NULL COMMENT 'S/N เช่น TI010965797',
    port VARCHAR(20) NULL COMMENT 'COM3 หรือ /dev/ttyUSB0 — auto-scan ถ้าว่าง',
    baud_rate INT NOT NULL DEFAULT 9600,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_scale_devices_code (code),
    INDEX idx_scale_devices_branch (branch_id),
    INDEX idx_scale_devices_status (status),
    CONSTRAINT fk_scale_devices_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ตาชั่งดิจิตอลต่อสาขา';
