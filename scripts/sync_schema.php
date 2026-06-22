<?php
/**
 * Align live capstone_dental schema with capstone_dental.sql expectations.
 * Safe to re-run (idempotent where possible).
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/billing_helpers.php';
require_once dirname(__DIR__) . '/config/patient_profiling_helpers.php';

function sync_log($msg)
{
    echo $msg . PHP_EOL;
}

$errors = [];

sync_log('=== Schema sync start ===');

if (!table_exists($conn, 'password_resets')) {
    sync_log('Creating password_resets table...');
    $sql = "CREATE TABLE `password_resets` (
      `reset_id` int(11) NOT NULL AUTO_INCREMENT,
      `user_type` enum('user','patient') NOT NULL,
      `user_id` int(11) NOT NULL,
      `email` varchar(150) NOT NULL,
      `token_hash` char(64) NOT NULL,
      `otp_hash` char(64) NOT NULL,
      `expires_at` datetime NOT NULL,
      `used_at` datetime DEFAULT NULL,
      `ip_address` varchar(45) DEFAULT NULL,
      `created_at` datetime NOT NULL,
      PRIMARY KEY (`reset_id`),
      KEY `idx_resets_email` (`email`),
      KEY `idx_resets_token` (`token_hash`),
      KEY `idx_resets_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    if (!$conn->query($sql)) {
        $errors[] = 'password_resets create failed: ' . $conn->error;
    } else {
        sync_log('  OK password_resets created');
    }
} else {
    sync_log('password_resets already exists');
}

$colRes = $conn->query("SHOW COLUMNS FROM tooth_status_type LIKE 'tooth_id'");
$hasLegacyLegend = $colRes && $colRes->num_rows > 0;
if ($hasLegacyLegend) {
    sync_log('Migrating tooth_status_type to chart legend schema...');
    $conn->query('SET FOREIGN_KEY_CHECKS = 0');
    @$conn->query('ALTER TABLE tooth_status_type DROP FOREIGN KEY fk_tst_tooth');
    if (!$conn->query('ALTER TABLE tooth_status_type DROP COLUMN tooth_id')) {
        $errors[] = 'tooth_status_type drop tooth_id failed: ' . $conn->error;
    }
    $labelCol = $conn->query("SHOW COLUMNS FROM tooth_status_type LIKE 'label'");
    if (!$labelCol || $labelCol->num_rows === 0) {
        $conn->query("ALTER TABLE tooth_status_type ADD COLUMN label varchar(120) NOT NULL DEFAULT '' AFTER status_type_id");
    }
    $descCol = $conn->query("SHOW COLUMNS FROM tooth_status_type LIKE 'description'");
    if (!$descCol || $descCol->num_rows === 0) {
        $conn->query("ALTER TABLE tooth_status_type ADD COLUMN description varchar(255) DEFAULT NULL AFTER code");
    }
    $conn->query('TRUNCATE TABLE tooth_status_type');
    $conn->query("INSERT INTO tooth_status_type (status_type_id, label, category, code, description) VALUES
(1, 'Dental Caries', 'decay', 'C', 'Dental Caries'),
(2, 'Caries — Vital Pulp Expose', 'decay', 'C1', 'Dental Caries with Vital Pulp Expose'),
(3, 'Caries — Non-vital Pulp Expose', 'decay', 'C2', 'Dental Caries with Non-vital Pulp Expose'),
(4, 'Indicated for Extraction', 'other', 'X', 'Indicated for Extraction'),
(5, 'Retained Root Fragment', 'missing', 'RF', 'Retained Root Fragment'),
(6, 'Amalgam Filling', 'restoration', 'Am', 'Amalgam Filling'),
(7, 'Silicate Filling', 'restoration', 'S', 'Silicate Filling'),
(8, 'Gold Crown', 'restoration', 'GC', 'Gold Crown'),
(9, 'Bridge Abutment', 'restoration', 'Ab1', 'Bridge Abutment'),
(10, 'Pontic', 'restoration', 'P', 'Pontic'),
(11, 'Gold Clasp', 'restoration', 'GCI', 'Gold Clasp'),
(12, 'Gold Inlays', 'restoration', 'GI', 'Gold Inlays'),
(13, 'Missing (Extraction)', 'missing', 'M', 'Missing due to Extraction'),
(14, 'Unerupted', 'other', 'Un', 'Unerupted')");
    @$conn->query('ALTER TABLE tooth_status_type ADD UNIQUE KEY uk_chart_legend_code (code)');
    $conn->query('SET FOREIGN_KEY_CHECKS = 1');
    sync_log('  OK tooth_status_type migrated');
} else {
    sync_log('tooth_status_type already on chart legend schema');
}

ensure_clinical_records_profiling_column($conn);
sync_log('clinical_records.profiling ensured');

$uqCheck = $conn->query("SHOW INDEX FROM tooth_status WHERE Key_name = 'uq_tooth_status_record_tooth'");
if ($uqCheck && $uqCheck->num_rows > 0) {
    sync_log('Fixing tooth_status unique key to include tooth_surface and status...');
    $conn->query('ALTER TABLE tooth_status DROP INDEX uq_tooth_status_record_tooth');
    $conn->query('ALTER TABLE tooth_status ADD UNIQUE KEY uq_tooth_status_record_tooth_surface (record_id, tooth_selected, tooth_surface, status)');
    sync_log('  OK tooth_status unique key updated');
} else {
    sync_log('tooth_status unique key already includes tooth_surface');
}

billing_ensure_tables($conn);
sync_log('billing tables/columns ensured');

sync_log('=== Schema sync complete ===');

if (!empty($errors)) {
    foreach ($errors as $err) {
        sync_log('ERROR: ' . $err);
    }
    exit(1);
}

exit(0);
