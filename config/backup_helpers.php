<?php
/**
 * Database backup: dumps every table to backups/<file>.sql and records it in the `backup`
 * table. Shared by the scheduled job (scripts/cron_backup.php) and the admin's
 * "Create Backup Now" button (assets/api/admin/create_backup.php).
 */

if (!function_exists('db_backup_run')) {
    /**
     * @param int|null $userId The admin who asked for it, or null for the scheduled job.
     * @return array{success:bool, message:string, file?:string, path?:string, size_kb?:float, rows?:int, tables?:int}
     */
    function db_backup_run(mysqli $conn, ?int $userId = null): array
    {
        @set_time_limit(300);

        $backupDir = dirname(__DIR__) . '/backups';
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true)) {
            return ['success' => false, 'message' => 'Cannot create backups directory'];
        }

        $fileName = 'capstone_dental_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $filePath = $backupDir . '/' . $fileName;

        $handle = @fopen($filePath, 'w');
        if (!$handle) {
            return ['success' => false, 'message' => 'Cannot create backup file. Check that the backups folder is writable.'];
        }

        fwrite($handle, "-- Capstone Dental Clinic Database Backup\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database: " . DB_NAME . "\n\n");
        fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
        fwrite($handle, "SET time_zone = \"+00:00\";\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tablesRes = $conn->query('SHOW TABLES');
        if (!$tablesRes) {
            fclose($handle);
            @unlink($filePath);
            return ['success' => false, 'message' => 'Failed to list tables'];
        }

        $tableNames = [];
        while ($row = $tablesRes->fetch_row()) {
            $tableNames[] = $row[0];
        }

        $rowCount = 0;
        foreach ($tableNames as $table) {
            $tableEsc = $conn->real_escape_string($table);

            $createRes = $conn->query("SHOW CREATE TABLE `{$tableEsc}`");
            if ($createRes && ($createRow = $createRes->fetch_row())) {
                fwrite($handle, "DROP TABLE IF EXISTS `{$tableEsc}`;\n");
                fwrite($handle, $createRow[1] . ";\n\n");
            }

            $dataRes = $conn->query("SELECT * FROM `{$tableEsc}`");
            if (!$dataRes || $dataRes->num_rows === 0) continue;

            $columns = [];
            foreach ($dataRes->fetch_fields() as $field) {
                $columns[] = $field->name;
            }

            while ($row = $dataRes->fetch_assoc()) {
                $values = [];
                foreach ($columns as $col) {
                    $val = $row[$col];
                    if ($val === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = "'" . $conn->real_escape_string($val) . "'";
                    }
                }
                fwrite($handle, "INSERT INTO `{$tableEsc}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n");
                $rowCount++;
            }
            $dataRes->free();
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fwrite($handle, "-- End of backup\n");
        fclose($handle);

        $fileSizeKB = round(filesize($filePath) / 1024, 2);
        $relativePath = 'backups/' . $fileName;

        // The history list is read from this table, so a backup that isn't recorded here
        // would never show up — treat a failed insert as a failed backup.
        $stmt = $conn->prepare('INSERT INTO backup (user_id, backup_date, file_name, file_path, file_size) VALUES (?, NOW(), ?, ?, ?)');
        $recorded = false;
        if ($stmt) {
            $stmt->bind_param('issd', $userId, $fileName, $relativePath, $fileSizeKB);
            $recorded = $stmt->execute();
            $stmt->close();
        }
        if (!$recorded) {
            @unlink($filePath);
            return ['success' => false, 'message' => 'The backup file was written but could not be recorded in the backup list.'];
        }

        $retentionYears = 10;
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionYears} years"));
        $oldBackups = $conn->query("SELECT file_path FROM backup WHERE backup_date < '$cutoff'");
        if ($oldBackups) {
            while ($oldRow = $oldBackups->fetch_assoc()) {
                $oldFile = dirname(__DIR__) . '/' . $oldRow['file_path'];
                if (is_file($oldFile)) @unlink($oldFile);
            }
            $conn->query("DELETE FROM backup WHERE backup_date < '$cutoff'");
        }

        if (function_exists('log_system_event')) {
            log_system_event(
                $conn,
                $userId === null ? 'cron.backup.create' : 'backup.create',
                'backup',
                null,
                ($userId === null ? 'Scheduled' : 'Manual') . " backup: $fileName ($fileSizeKB KB, $rowCount rows, " . count($tableNames) . ' tables)',
                $userId
            );
        }

        return [
            'success' => true,
            'message' => 'Backup completed',
            'file' => $fileName,
            'path' => $filePath,
            'size_kb' => $fileSizeKB,
            'rows' => $rowCount,
            'tables' => count($tableNames),
        ];
    }
}
