-- Migration 057: normalized line items for stock transfers
-- Keeps stock_transfers header columns for backward compatibility.

CREATE TABLE IF NOT EXISTS stock_transfer_items (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    stock_transfer_id  INT NOT NULL,
    line_no            INT NOT NULL DEFAULT 1,
    category_id        INT NOT NULL,
    item_name          VARCHAR(200) DEFAULT NULL,
    weight_kg          DECIMAL(12,3) NOT NULL,
    received_weight_kg DECIMAL(12,3) DEFAULT NULL,
    receive_note       VARCHAR(500) DEFAULT NULL,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_transfer_line (stock_transfer_id, line_no),
    KEY idx_transfer_id (stock_transfer_id),
    KEY idx_category_item (category_id, item_name),
    CONSTRAINT fk_sti_transfer FOREIGN KEY (stock_transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sti_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO stock_transfer_items
    (stock_transfer_id, line_no, category_id, item_name, weight_kg, received_weight_kg, receive_note, created_at)
SELECT
    st.id,
    1,
    st.category_id,
    st.item_name,
    st.weight_kg,
    CASE
        WHEN st.status = 'confirmed' THEN COALESCE(st.received_weight_kg, st.weight_kg)
        ELSE NULL
    END,
    CASE
        WHEN st.status = 'confirmed' THEN st.receive_note
        ELSE NULL
    END,
    st.created_at
FROM stock_transfers st
WHERE NOT EXISTS (
    SELECT 1
    FROM stock_transfer_items sti
    WHERE sti.stock_transfer_id = st.id
      AND sti.line_no = 1
);
