-- 050: Repair Thai text that was stored after UTF-8 bytes were decoded as latin1/cp1252.
-- The WHERE clause targets Thai mojibake markers only, so normal UTF-8 Thai is left unchanged.

DROP PROCEDURE IF EXISTS repair_mojibake_text;

DELIMITER $$
CREATE PROCEDURE repair_mojibake_text()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE table_name_value VARCHAR(255);
  DECLARE column_name_value VARCHAR(255);

  DECLARE text_columns CURSOR FOR
    SELECT TABLE_NAME, COLUMN_NAME
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND DATA_TYPE IN ('char', 'varchar', 'text', 'mediumtext', 'longtext')
    ORDER BY TABLE_NAME, ORDINAL_POSITION;

  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  OPEN text_columns;

  read_loop: LOOP
    FETCH text_columns INTO table_name_value, column_name_value;
    IF done THEN
      LEAVE read_loop;
    END IF;

    SET @table_name = CONCAT('`', REPLACE(table_name_value, '`', '``'), '`');
    SET @column_name = CONCAT('`', REPLACE(column_name_value, '`', '``'), '`');
    SET @sql = CONCAT(
      'UPDATE ', @table_name,
      ' SET ', @column_name,
      ' = CONVERT(CAST(CONVERT(', @column_name, ' USING latin1) AS BINARY) USING utf8mb4)',
      ' WHERE ', @column_name, ' IS NOT NULL',
      ' AND (', @column_name, ' LIKE ''%à¸%''',
      ' OR ', @column_name, ' LIKE ''%à¹%''',
      ' OR ', @column_name, ' LIKE ''%àº%'')'
    );

    PREPARE repair_stmt FROM @sql;
    EXECUTE repair_stmt;
    DEALLOCATE PREPARE repair_stmt;
  END LOOP;

  CLOSE text_columns;
END$$
DELIMITER ;

CALL repair_mojibake_text();

DROP PROCEDURE IF EXISTS repair_mojibake_text;
