-- Migration 030: สร้างตาราง business_expenses (ค่าใช้จ่ายธุรกิจ)
-- (IF NOT EXISTS → idempotent)

CREATE TABLE IF NOT EXISTS business_expenses (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    branch_id   INT NOT NULL,
    expense_date DATE NOT NULL,
    category    VARCHAR(50) NOT NULL DEFAULT 'อื่นๆ',
    amount      DECIMAL(12,2) NOT NULL,
    note        VARCHAR(255) DEFAULT NULL,
    created_by  INT DEFAULT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bexp_branch FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
