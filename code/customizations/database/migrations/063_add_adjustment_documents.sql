-- Migration 063: immutable admin adjustment documents for historical corrections

CREATE TABLE IF NOT EXISTS adjustment_documents (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    reference_no            VARCHAR(40) NULL,
    branch_id               INT NOT NULL,
    adjustment_type         ENUM('purchase_order_cancellation','sale_lot_revenue_correction','historical_expense') NOT NULL,
    effective_date          DATE NOT NULL,
    target_type             VARCHAR(40) NULL,
    target_id               INT NULL,
    amount_before           DECIMAL(14,2) NULL,
    amount_after            DECIMAL(14,2) NULL,
    payment_method_before   ENUM('cash','bank_transfer') NULL,
    payment_method_after    ENUM('cash','bank_transfer') NULL,
    reason                  VARCHAR(500) NOT NULL,
    details_json            JSON NULL,
    status                  ENUM('posted') NOT NULL DEFAULT 'posted',
    created_by              INT NOT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_adjustment_reference (reference_no),
    KEY idx_adjustment_branch_date (branch_id, effective_date),
    KEY idx_adjustment_target (target_type, target_id),
    CONSTRAINT fk_adjustment_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_adjustment_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @expense_adjustment_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'business_expenses' AND COLUMN_NAME = 'adjustment_document_id'
);
SET @sql = IF(@expense_adjustment_exists = 0,
  'ALTER TABLE business_expenses
     ADD COLUMN adjustment_document_id INT NULL AFTER review_note,
     ADD UNIQUE INDEX uq_bexp_adjustment_document (adjustment_document_id)',
  'SELECT ''063 business expense adjustment link already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
