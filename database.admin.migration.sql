-- Apply after database.admin.sql. This migration is additive and safe to rerun.
-- It preserves all existing public reports, locations, votes, and uploaded files.

DROP PROCEDURE IF EXISTS madok_admin_add_column_if_missing;
DELIMITER //
CREATE PROCEDURE madok_admin_add_column_if_missing(
    IN table_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64),
    IN column_definition_value VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND COLUMN_NAME = column_name_value
    ) THEN
        SET @madok_admin_ddl = CONCAT(
            'ALTER TABLE `', REPLACE(table_name_value, '`', '``'), '` ADD COLUMN `',
            REPLACE(column_name_value, '`', '``'), '` ', column_definition_value
        );
        PREPARE madok_admin_stmt FROM @madok_admin_ddl;
        EXECUTE madok_admin_stmt;
        DEALLOCATE PREPARE madok_admin_stmt;
    END IF;
END//
DELIMITER ;

CALL madok_admin_add_column_if_missing('reports', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL madok_admin_add_column_if_missing('reports', 'deleted_by', 'BIGINT UNSIGNED NULL DEFAULT NULL');
CALL madok_admin_add_column_if_missing('reports', 'delete_reason', 'VARCHAR(500) NULL DEFAULT NULL');
CALL madok_admin_add_column_if_missing('locations', 'deleted_at', 'DATETIME NULL DEFAULT NULL');
CALL madok_admin_add_column_if_missing('locations', 'deleted_by', 'BIGINT UNSIGNED NULL DEFAULT NULL');
CALL madok_admin_add_column_if_missing('locations', 'delete_reason', 'VARCHAR(500) NULL DEFAULT NULL');

DROP PROCEDURE madok_admin_add_column_if_missing;

-- Keep delete attribution valid without preventing report/location retention
-- when an administrator account is removed.
SET @madok_admin_fk_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_reports_deleted_by'
    ),
    'ALTER TABLE reports ADD CONSTRAINT fk_reports_deleted_by FOREIGN KEY (deleted_by) REFERENCES admin_users(id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE madok_admin_fk_stmt FROM @madok_admin_fk_sql;
EXECUTE madok_admin_fk_stmt;
DEALLOCATE PREPARE madok_admin_fk_stmt;

SET @madok_admin_fk_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND CONSTRAINT_NAME = 'fk_locations_deleted_by'
    ),
    'ALTER TABLE locations ADD CONSTRAINT fk_locations_deleted_by FOREIGN KEY (deleted_by) REFERENCES admin_users(id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE madok_admin_fk_stmt FROM @madok_admin_fk_sql;
EXECUTE madok_admin_fk_stmt;
DEALLOCATE PREPARE madok_admin_fk_stmt;

SET @madok_admin_idx_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reports' AND INDEX_NAME = 'idx_reports_deleted_at'
    ),
    'CREATE INDEX idx_reports_deleted_at ON reports (deleted_at)',
    'SELECT 1'
);
PREPARE madok_admin_idx_stmt FROM @madok_admin_idx_sql;
EXECUTE madok_admin_idx_stmt;
DEALLOCATE PREPARE madok_admin_idx_stmt;

SET @madok_admin_idx_sql = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'locations' AND INDEX_NAME = 'idx_locations_deleted_at'
    ),
    'CREATE INDEX idx_locations_deleted_at ON locations (deleted_at)',
    'SELECT 1'
);
PREPARE madok_admin_idx_stmt FROM @madok_admin_idx_sql;
EXECUTE madok_admin_idx_stmt;
DEALLOCATE PREPARE madok_admin_idx_stmt;
