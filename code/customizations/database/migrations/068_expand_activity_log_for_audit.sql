-- W8: Structured, append-only audit events with actor snapshots and searchable indexes.

DROP PROCEDURE IF EXISTS add_activity_log_column;
DELIMITER //
CREATE PROCEDURE add_activity_log_column(IN column_name_value VARCHAR(64), IN column_definition TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'activity_log'
          AND column_name = column_name_value
    ) THEN
        SET @alter_sql = CONCAT(
            'ALTER TABLE activity_log ADD COLUMN `',
            REPLACE(column_name_value, '`', '``'),
            '` ', column_definition
        );
        PREPARE alter_statement FROM @alter_sql;
        EXECUTE alter_statement;
        DEALLOCATE PREPARE alter_statement;
    END IF;
END//
DELIMITER ;

CALL add_activity_log_column('actor_id', 'INT NULL AFTER user_id');
CALL add_activity_log_column('actor_role', 'VARCHAR(32) NULL AFTER actor_id');
CALL add_activity_log_column('actor_branch_id', 'INT NULL AFTER actor_role');
CALL add_activity_log_column('module', 'VARCHAR(64) NULL AFTER action');
CALL add_activity_log_column('entity_type', 'VARCHAR(64) NULL AFTER module');
CALL add_activity_log_column('entity_id', 'VARCHAR(64) NULL AFTER entity_type');
CALL add_activity_log_column('entity_branch_id', 'INT NULL AFTER entity_id');
CALL add_activity_log_column('outcome', 'VARCHAR(16) NOT NULL DEFAULT ''success'' AFTER entity_branch_id');
CALL add_activity_log_column('reason', 'TEXT NULL AFTER outcome');
CALL add_activity_log_column('before_json', 'JSON NULL AFTER description');
CALL add_activity_log_column('after_json', 'JSON NULL AFTER before_json');
CALL add_activity_log_column('self_approved', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER after_json');
CALL add_activity_log_column('request_id', 'VARCHAR(64) NULL AFTER user_agent');

DROP PROCEDURE IF EXISTS add_activity_log_column;

UPDATE activity_log a
LEFT JOIN users u ON u.id = a.user_id
SET a.actor_id = COALESCE(a.actor_id, a.user_id),
    a.actor_role = COALESCE(a.actor_role, u.role, 'system'),
    a.actor_branch_id = COALESCE(a.actor_branch_id, u.branch_id),
    a.module = COALESCE(a.module, 'legacy'),
    a.outcome = COALESCE(NULLIF(a.outcome, ''), 'success')
WHERE a.actor_id IS NULL
   OR a.actor_role IS NULL
   OR a.module IS NULL;

DROP PROCEDURE IF EXISTS add_activity_log_index;
DELIMITER //
CREATE PROCEDURE add_activity_log_index(IN index_name_value VARCHAR(64), IN index_columns TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'activity_log'
          AND index_name = index_name_value
    ) THEN
        SET @index_sql = CONCAT(
            'CREATE INDEX `', REPLACE(index_name_value, '`', '``'),
            '` ON activity_log (', index_columns, ')'
        );
        PREPARE index_statement FROM @index_sql;
        EXECUTE index_statement;
        DEALLOCATE PREPARE index_statement;
    END IF;
END//
DELIMITER ;

CALL add_activity_log_index('idx_audit_created_id', 'created_at, id');
CALL add_activity_log_index('idx_audit_role_created', 'actor_role, created_at');
CALL add_activity_log_index('idx_audit_branch_created', 'actor_branch_id, created_at');
CALL add_activity_log_index('idx_audit_action_created', 'action, created_at');
CALL add_activity_log_index('idx_audit_entity_created', 'entity_type, entity_id, created_at');
CALL add_activity_log_index('idx_audit_actor_created', 'actor_id, created_at');

DROP PROCEDURE IF EXISTS add_activity_log_index;
