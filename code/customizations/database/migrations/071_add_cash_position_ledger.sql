-- Migration 071: additive cash-position metadata and per-branch cutover baseline.
-- Historical cash movements remain untouched; v2 activates only after a branch baseline exists.

CREATE TABLE IF NOT EXISTS cash_position_baselines (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    branch_id        INT NOT NULL,
    effective_date   DATE NOT NULL,
    drawer_balance   DECIMAL(14,2) NOT NULL DEFAULT 0,
    reserve_balance  DECIMAL(14,2) NOT NULL DEFAULT 0,
    note             VARCHAR(500) NOT NULL,
    created_by       INT NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_cash_position_baseline_branch (branch_id),
    KEY idx_cash_position_baseline_date (effective_date, branch_id),
    CONSTRAINT fk_cash_position_baseline_branch FOREIGN KEY (branch_id) REFERENCES branches(id),
    CONSTRAINT fk_cash_position_baseline_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_movements' AND COLUMN_NAME = 'source_location'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE cash_movements ADD COLUMN source_location ENUM(''drawer'',''business_reserve'',''external'') NULL AFTER direction',
  'SELECT ''071 cash_movements.source_location already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_deposit_requests' AND COLUMN_NAME = 'source_type'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE cash_deposit_requests ADD COLUMN source_type ENUM(''reserve_transfer'',''owner_capital'') NULL AFTER amount',
  'SELECT ''071 cash_deposit_requests.source_type already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_movements' AND COLUMN_NAME = 'destination_location'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE cash_movements ADD COLUMN destination_location ENUM(''drawer'',''business_reserve'',''external'') NULL AFTER source_location',
  'SELECT ''071 cash_movements.destination_location already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_movements' AND COLUMN_NAME = 'balance_effect'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE cash_movements ADD COLUMN balance_effect ENUM(''transfer'',''increase'',''decrease'') NULL AFTER destination_location',
  'SELECT ''071 cash_movements.balance_effect already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_movements' AND COLUMN_NAME = 'business_date'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE cash_movements ADD COLUMN business_date DATE NULL AFTER balance_effect',
  'SELECT ''071 cash_movements.business_date already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_movements' AND COLUMN_NAME = 'reverses_movement_id'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE cash_movements ADD COLUMN reverses_movement_id BIGINT NULL AFTER reference_id',
  'SELECT ''071 cash_movements.reverses_movement_id already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @index_exists = (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_movements' AND INDEX_NAME = 'idx_cash_movement_positions'
);
SET @sql = IF(@index_exists = 0,
  'ALTER TABLE cash_movements ADD INDEX idx_cash_movement_positions (branch_id, business_date, source_location, destination_location)',
  'SELECT ''071 idx_cash_movement_positions already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_sessions' AND COLUMN_NAME = 'cash_model_version'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE cash_sessions
     ADD COLUMN cash_model_version TINYINT NOT NULL DEFAULT 1 AFTER status,
     ADD COLUMN opening_transfer_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER opening_actual,
     ADD COLUMN opening_capital_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER opening_transfer_amount,
     ADD COLUMN closing_transfer_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER closing_actual',
  'SELECT ''071 cash session v2 columns already exist'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
