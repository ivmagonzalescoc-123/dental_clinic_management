-- One-time migration: finalize work_type and remove legacy doctor_work_type column.
-- Safe for repeated execution.

SET @has_legacy_col := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'doctor_work_type'
);

-- If legacy column exists, make sure any legacy part-time doctor is preserved in work_type.
SET @promote_sql := IF(
    @has_legacy_col > 0,
    'UPDATE users SET work_type = ''part_time'' WHERE role_id = 2 AND doctor_work_type = ''part_time'' AND work_type <> ''part_time''',
    'SELECT 1'
);
PREPARE stmt_promote FROM @promote_sql;
EXECUTE stmt_promote;
DEALLOCATE PREPARE stmt_promote;

-- Drop legacy column when present.
SET @drop_sql := IF(
    @has_legacy_col > 0,
    'ALTER TABLE users DROP COLUMN doctor_work_type',
    'SELECT 1'
);
PREPARE stmt_drop FROM @drop_sql;
EXECUTE stmt_drop;
DEALLOCATE PREPARE stmt_drop;

-- Optional verification output
SELECT user_id, first_name, last_name, role_id, work_type
FROM users
WHERE role_id = 2
ORDER BY user_id;
