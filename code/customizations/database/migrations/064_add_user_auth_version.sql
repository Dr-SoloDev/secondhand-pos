-- Migration 064: invalidate all existing JWTs after password changes

SET @auth_version_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'auth_version'
);
SET @sql = IF(@auth_version_exists = 0,
  'ALTER TABLE users ADD COLUMN auth_version INT NOT NULL DEFAULT 1 AFTER status',
  'SELECT ''064 users.auth_version already exists'' AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
