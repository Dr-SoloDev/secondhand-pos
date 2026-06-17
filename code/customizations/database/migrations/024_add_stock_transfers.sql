-- Migration 024: สร้างตาราง stock_transfers (โอนสต็อกระหว่างสาขา)
-- ต้องมาก่อน 025_add_transfer_logistics_fields

CREATE TABLE IF NOT EXISTS stock_transfers (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    reference_no  VARCHAR(30) NOT NULL UNIQUE,
    from_branch_id INT NOT NULL,
    to_branch_id   INT NOT NULL,
    category_id    INT NOT NULL,
    weight_kg      DECIMAL(12,3) NOT NULL,
    note           VARCHAR(255) DEFAULT NULL,
    status         ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    created_by     INT DEFAULT NULL,
    confirmed_by   INT DEFAULT NULL,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    confirmed_at   DATETIME DEFAULT NULL,
    CONSTRAINT fk_st_from FOREIGN KEY (from_branch_id) REFERENCES branches(id),
    CONSTRAINT fk_st_to   FOREIGN KEY (to_branch_id)   REFERENCES branches(id),
    CONSTRAINT fk_st_cat  FOREIGN KEY (category_id)    REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
