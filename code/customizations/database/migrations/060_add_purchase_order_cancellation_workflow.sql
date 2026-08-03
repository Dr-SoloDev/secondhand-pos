-- Migration 060: controlled purchase-order cancellation requests

CREATE TABLE IF NOT EXISTS purchase_order_cancellation_requests (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id    INT NOT NULL,
    reason               VARCHAR(500) NOT NULL,
    status               ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by         INT NOT NULL,
    requested_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by          INT NULL,
    reviewed_at          DATETIME NULL,
    review_note          VARCHAR(500) NULL,
    pending_purchase_order_id INT
        GENERATED ALWAYS AS (CASE WHEN status = 'pending' THEN purchase_order_id ELSE NULL END) STORED,

    UNIQUE KEY uq_po_cancel_pending (pending_purchase_order_id),
    KEY idx_po_cancel_po (purchase_order_id, id),
    KEY idx_po_cancel_status (status, requested_at),
    KEY idx_po_cancel_requester (requested_by),
    KEY idx_po_cancel_reviewer (reviewed_by),
    CONSTRAINT fk_po_cancel_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
    CONSTRAINT fk_po_cancel_requester FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_po_cancel_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
