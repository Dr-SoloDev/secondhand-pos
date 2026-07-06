-- Migration 040: Create employees table for staff management + social security
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    position VARCHAR(100) DEFAULT NULL,
    id_card VARCHAR(13) DEFAULT NULL,
    phone VARCHAR(20) DEFAULT NULL,
    address TEXT DEFAULT NULL,
    salary DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'เงินเดือน',
    daily_wage DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าแรงรายวัน',
    social_security_number VARCHAR(20) DEFAULT NULL COMMENT 'เลขประกันสังคม',
    social_security_rate DECIMAL(5,2) NOT NULL DEFAULT 5.00 COMMENT 'อัตราประกันสังคม %',
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_branch (branch_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
