-- W9: Staged seller ID-card encryption. The PHP migration tool performs backfill/finalize.

DROP PROCEDURE IF EXISTS add_seller_security_column;
DELIMITER //
CREATE PROCEDURE add_seller_security_column(IN column_name_value VARCHAR(64), IN column_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'sellers'
          AND column_name = column_name_value
    ) THEN
        SET @alter_sql = CONCAT(
            'ALTER TABLE sellers ADD COLUMN `', REPLACE(column_name_value, '`', '``'),
            '` ', column_definition
        );
        PREPARE alter_statement FROM @alter_sql;
        EXECUTE alter_statement;
        DEALLOCATE PREPARE alter_statement;
    END IF;
END//
DELIMITER ;

CALL add_seller_security_column('id_card_encrypted', 'VARCHAR(255) NULL AFTER id_card');
CALL add_seller_security_column('id_card_search_hash', 'CHAR(64) NULL AFTER id_card_encrypted');
CALL add_seller_security_column('id_card_key_version', 'SMALLINT UNSIGNED NULL AFTER id_card_search_hash');

DROP PROCEDURE IF EXISTS add_seller_security_column;

SET @seller_hash_index_exists = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sellers'
      AND index_name = 'uq_sellers_id_card_search_hash'
);
SET @seller_hash_index_sql = IF(
    @seller_hash_index_exists = 0,
    'CREATE UNIQUE INDEX uq_sellers_id_card_search_hash ON sellers (id_card_search_hash)',
    'SELECT 1'
);
PREPARE seller_hash_index_statement FROM @seller_hash_index_sql;
EXECUTE seller_hash_index_statement;
DEALLOCATE PREPARE seller_hash_index_statement;
