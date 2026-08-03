-- Migration 061: daily branch cash sessions, immutable cash ledger, and expense approvals

CREATE TABLE IF NOT EXISTS cash_sessions (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    branch_id             INT NOT NULL,
    business_date         DATE NOT NULL,
    status                ENUM('pending_open','open','pending_close','closed','rejected') NOT NULL,
    opening_expected      DECIMAL(14,2) NOT NULL DEFAULT 0,
    opening_actual        DECIMAL(14,2) NOT NULL DEFAULT 0,
    opening_variance      DECIMAL(14,2) GENERATED ALWAYS AS (opening_actual - opening_expected) STORED,
    opening_reason        VARCHAR(500) NULL,
    opening_requested_by  INT NOT NULL,
    opened_by             INT NULL,
    opened_at             DATETIME NULL,
    closing_expected      DECIMAL(14,2) NULL,
    closing_actual        DECIMAL(14,2) NULL,
    closing_variance      DECIMAL(14,2)
        GENERATED ALWAYS AS (CASE WHEN closing_actual IS NULL OR closing_expected IS NULL THEN NULL ELSE closing_actual - closing_expected END) STORED,
    closing_reason        VARCHAR(500) NULL,
    closing_requested_by  INT NULL,
    closed_by             INT NULL,
    closed_at             DATETIME NULL,
    last_reviewed_by      INT NULL,
    last_reviewed_at      DATETIME NULL,
    last_review_note      VARCHAR(500) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_cash_session_branch_date (branch_id, business_date),
    KEY idx_cash_session_status (status, business_date),
    CONSTRAINT fk_cash_session_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_cash_session_open_requester FOREIGN KEY (opening_requested_by) REFERENCES users(id),
    CONSTRAINT fk_cash_session_opened_by FOREIGN KEY (opened_by) REFERENCES users(id),
    CONSTRAINT fk_cash_session_close_requester FOREIGN KEY (closing_requested_by) REFERENCES users(id),
    CONSTRAINT fk_cash_session_closed_by FOREIGN KEY (closed_by) REFERENCES users(id),
    CONSTRAINT fk_cash_session_reviewer FOREIGN KEY (last_reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_session_events (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    cash_session_id INT NOT NULL,
    event_type      VARCHAR(40) NOT NULL,
    expected_amount DECIMAL(14,2) NULL,
    actual_amount   DECIMAL(14,2) NULL,
    variance_amount DECIMAL(14,2) NULL,
    reason          VARCHAR(500) NULL,
    actor_id        INT NOT NULL,
    reviewer_id     INT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_cash_event_session (cash_session_id, id),
    CONSTRAINT fk_cash_event_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id),
    CONSTRAINT fk_cash_event_actor FOREIGN KEY (actor_id) REFERENCES users(id),
    CONSTRAINT fk_cash_event_reviewer FOREIGN KEY (reviewer_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cash_movements (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,
    cash_session_id INT NOT NULL,
    branch_id       INT NOT NULL,
    direction       ENUM('in','out') NOT NULL,
    movement_type   VARCHAR(40) NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,
    reference_type  VARCHAR(40) NULL,
    reference_id    INT NULL,
    description     VARCHAR(500) NOT NULL,
    recorded_by     INT NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_cash_movement_reference (movement_type, reference_type, reference_id),
    KEY idx_cash_movement_session (cash_session_id, id),
    KEY idx_cash_movement_branch_date (branch_id, created_at),
    CONSTRAINT fk_cash_movement_session FOREIGN KEY (cash_session_id) REFERENCES cash_sessions(id),
    CONSTRAINT fk_cash_movement_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_cash_movement_user FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sale_payment_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_lots' AND COLUMN_NAME = 'revenue_payment_method'
);
SET @sql = IF(@sale_payment_exists = 0,
  'ALTER TABLE sale_lots ADD COLUMN revenue_payment_method ENUM(''cash'',''bank_transfer'') NULL AFTER actual_revenue_date',
  'SELECT ''061 sale lot payment method already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @expense_status_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_expenses' AND COLUMN_NAME = 'status'
);
SET @sql = IF(@expense_status_exists = 0,
  'ALTER TABLE business_expenses
     ADD COLUMN payment_method ENUM(''cash'',''bank_transfer'') NOT NULL DEFAULT ''cash'' AFTER amount,
     ADD COLUMN beneficiary_name VARCHAR(200) NULL AFTER payment_method,
     ADD COLUMN status ENUM(''pending'',''approved'',''rejected'',''cancelled'') NOT NULL DEFAULT ''pending'' AFTER note,
     ADD COLUMN requested_by INT NULL AFTER created_by,
     ADD COLUMN approved_by INT NULL AFTER requested_by,
     ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
     ADD COLUMN review_note VARCHAR(500) NULL AFTER approved_at',
  'SELECT ''061 expense approval columns already exist'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE business_expenses
SET requested_by = COALESCE(requested_by, created_by),
    status = 'approved',
    approved_at = COALESCE(approved_at, created_at)
WHERE requested_by IS NULL;

SET @expense_idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_expenses' AND INDEX_NAME = 'idx_bexp_approval'
);
SET @sql = IF(@expense_idx_exists = 0,
  'ALTER TABLE business_expenses ADD INDEX idx_bexp_approval (status, branch_id, expense_date)',
  'SELECT ''061 expense approval index already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
