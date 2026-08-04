-- Migration 065: record the exact purchase batches consumed by each Sale Lot item

CREATE TABLE IF NOT EXISTS sale_lot_stock_allocations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    sale_lot_id INT NOT NULL,
    sale_lot_item_id INT NOT NULL,
    purchase_order_item_id INT NOT NULL,
    quantity_kg DECIMAL(12,3) NOT NULL,
    unit_cost DECIMAL(14,6) NOT NULL,
    allocated_cost DECIMAL(14,4) NOT NULL,
    cost_method ENUM('fifo','weighted') NOT NULL,
    restored_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sale_lot_item_purchase_item (sale_lot_item_id, purchase_order_item_id),
    KEY idx_sale_lot_alloc_lot (sale_lot_id),
    KEY idx_sale_lot_alloc_purchase_item (purchase_order_item_id),
    CONSTRAINT fk_sale_lot_alloc_lot
      FOREIGN KEY (sale_lot_id) REFERENCES sale_lots(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_lot_alloc_item
      FOREIGN KEY (sale_lot_item_id) REFERENCES sale_lot_items(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_lot_alloc_purchase_item
      FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
