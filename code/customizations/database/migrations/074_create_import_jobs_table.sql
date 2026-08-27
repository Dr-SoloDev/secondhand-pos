-- 074: สร้างตาราง import_jobs สำหรับบันทึกประวัติการนำเข้า Excel
-- สร้างโดย: Phase 2 Excel Import System

CREATE TABLE IF NOT EXISTS import_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    user_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    file_hash VARCHAR(64) NULL COMMENT 'SHA-256 hash ของไฟล์สำหรับตรวจ duplicate',
    import_type ENUM('purchase_items', 'sale_lots', 'sellers') NOT NULL DEFAULT 'purchase_items',
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    total_rows INT NOT NULL DEFAULT 0,
    processed_rows INT NOT NULL DEFAULT 0,
    success_rows INT NOT NULL DEFAULT 0,
    failed_rows INT NOT NULL DEFAULT 0,
    error_log JSON NULL COMMENT 'บันทึกข้อผิดพลาดรายแถว',
    imported_at TIMESTAMP NULL COMMENT 'เวลาที่เริ่มประมวลผล',
    completed_at TIMESTAMP NULL COMMENT 'เวลาที่เสร็จสมบูรณ์',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_import_jobs_branch (branch_id),
    INDEX idx_import_jobs_user (user_id),
    INDEX idx_import_jobs_status (status),
    INDEX idx_import_jobs_file_hash (file_hash),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประวัติการนำเข้าข้อมูลจาก Excel';
