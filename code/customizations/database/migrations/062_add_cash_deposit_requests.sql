-- Migration 062: controlled manual cash deposits/top-ups

CREATE TABLE IF NOT EXISTS cash_deposit_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    branch_id       INT NOT NULL,
    cash_session_id INT NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,
    source_name     VARCHAR(200) NOT NULL,
    reason          VARCHAR(500) NOT NULL,
    status          ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    requested_by    INT NOT NULL,
    requested_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by     INT NULL,
    reviewed_at     DATETIME NULL,
    review_note     VARCHAR(500) NULL,

    KEY idx_cash_deposit_status (status, branch_id, requested_at),
    CONSTRAINT fk_cash_deposit_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_cash_deposit_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id),
    CONSTRAINT fk_cash_deposit_requester FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_cash_deposit_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
