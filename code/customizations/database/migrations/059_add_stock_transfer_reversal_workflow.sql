-- Migration 059: stock-transfer provenance and controlled reversal workflow

SET @po_source_type_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'source_type'
);
SET @sql = IF(@po_source_type_exists = 0,
  'ALTER TABLE purchase_orders
     ADD COLUMN source_type ENUM(''manual'',''stock_transfer'') NOT NULL DEFAULT ''manual'' AFTER status,
     ADD COLUMN source_id INT NULL AFTER source_type',
  'SELECT ''059 purchase source columns already exist'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE purchase_orders po
JOIN stock_transfers st ON po.notes LIKE CONCAT('%(ST: ', st.reference_no, ')%')
SET po.source_type = 'stock_transfer', po.source_id = st.id
WHERE po.source_id IS NULL;

SET @po_source_idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND INDEX_NAME = 'uq_po_transfer_source'
);
SET @sql = IF(@po_source_idx_exists = 0,
  'ALTER TABLE purchase_orders ADD UNIQUE INDEX uq_po_transfer_source (source_type, source_id)',
  'SELECT ''059 purchase source index already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @transfer_type_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transfers' AND COLUMN_NAME = 'transfer_type'
);
SET @sql = IF(@transfer_type_exists = 0,
  'ALTER TABLE stock_transfers
     ADD COLUMN transfer_type ENUM(''normal'',''reversal'') NOT NULL DEFAULT ''normal'' AFTER reference_no,
     ADD COLUMN reverses_transfer_id INT NULL AFTER transfer_type,
     ADD COLUMN reversal_reason VARCHAR(500) NULL AFTER reverses_transfer_id,
     ADD COLUMN approval_status ENUM(''not_required'',''pending'',''approved'',''rejected'') NOT NULL DEFAULT ''not_required'' AFTER status,
     ADD COLUMN approved_by INT NULL AFTER approval_status,
     ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
     ADD COLUMN review_note VARCHAR(500) NULL AFTER approved_at',
  'SELECT ''059 transfer workflow columns already exist'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @source_item_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transfer_items' AND COLUMN_NAME = 'source_transfer_item_id'
);
SET @sql = IF(@source_item_exists = 0,
  'ALTER TABLE stock_transfer_items
     ADD COLUMN source_transfer_item_id INT NULL AFTER stock_transfer_id,
     ADD INDEX idx_sti_source_item (source_transfer_item_id)',
  'SELECT ''059 source transfer item already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @reversal_idx_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_transfers' AND INDEX_NAME = 'idx_st_reversal'
);
SET @sql = IF(@reversal_idx_exists = 0,
  'ALTER TABLE stock_transfers ADD INDEX idx_st_reversal (reverses_transfer_id, approval_status)',
  'SELECT ''059 reversal index already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

