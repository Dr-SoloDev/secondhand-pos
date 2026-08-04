-- Migration 066: isolate login throttling by IP and username

SET @username_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'login_attempts'
    AND COLUMN_NAME = 'username'
);
SET @sql = IF(@username_exists = 0,
  'ALTER TABLE login_attempts ADD COLUMN username VARCHAR(50) NOT NULL DEFAULT '''' AFTER ip',
  'SELECT ''066 login_attempts.username already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @primary_columns = (
  SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'login_attempts'
    AND INDEX_NAME = 'PRIMARY'
);
SET @sql = CASE
  WHEN @primary_columns = 'ip,username' THEN
    'SELECT ''066 login_attempts primary key already scoped'' AS info'
  WHEN @primary_columns IS NULL THEN
    'ALTER TABLE login_attempts ADD PRIMARY KEY (ip, username)'
  ELSE
    'ALTER TABLE login_attempts DROP PRIMARY KEY, ADD PRIMARY KEY (ip, username)'
END;
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing IP-only counters cannot be assigned to a specific account.
DELETE FROM login_attempts WHERE username = '';
