<?php

// Patient clinic review / landing-page testimonials.
// Rules (per product decision): a patient may leave ONE optional review of the clinic
// as a whole (not per appointment), and only after they have at least one completed
// appointment. Admin curates which submissions are public — the landing page shows
// between FEEDBACK_MIN_DISPLAY and FEEDBACK_MAX_FEATURED of them, and stays hidden
// entirely until enough are featured.

if (!defined('FEEDBACK_MAX_FEATURED')) {
    define('FEEDBACK_MAX_FEATURED', 4);
}
if (!defined('FEEDBACK_MIN_DISPLAY')) {
    define('FEEDBACK_MIN_DISPLAY', 2);
}

if (!function_exists('feedback_ensure_table')) {
    function feedback_ensure_table($conn)
    {
        $conn->query("CREATE TABLE IF NOT EXISTS patient_feedback (
            feedback_id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            appointment_id INT NULL,
            doctor_id INT NULL,
            rating TINYINT NOT NULL,
            comment TEXT NULL,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_patient_feedback (patient_id),
            KEY idx_feedback_featured (is_featured)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

// True once the patient has at least one completed appointment — the point at which
// they're allowed to review the clinic.
if (!function_exists('feedback_patient_is_eligible')) {
    function feedback_patient_is_eligible($conn, $patientId)
    {
        $stmt = $conn->prepare("SELECT 1 FROM appointments WHERE patient_id = ? AND status = 'completed' LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $patientId = (int)$patientId;
        $stmt->bind_param('i', $patientId);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }
}

if (!function_exists('feedback_patient_has_reviewed')) {
    function feedback_patient_has_reviewed($conn, $patientId)
    {
        feedback_ensure_table($conn);
        $stmt = $conn->prepare('SELECT 1 FROM patient_feedback WHERE patient_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $patientId = (int)$patientId;
        $stmt->bind_param('i', $patientId);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }
}

// Featured testimonials for the public landing page. Returns [] unless at least
// FEEDBACK_MIN_DISPLAY reviews are featured, and never more than FEEDBACK_MAX_FEATURED.
if (!function_exists('feedback_get_landing_testimonials')) {
    function feedback_get_landing_testimonials($conn)
    {
        feedback_ensure_table($conn);

        $testimonials = [];
        $sql = "SELECT f.feedback_id, f.rating, f.comment, f.updated_at, f.created_at,
                       p.first_name, p.last_name, p.created_at AS patient_since
                FROM patient_feedback f
                INNER JOIN patients p ON p.patient_id = f.patient_id
                WHERE f.is_featured = 1
                    AND f.comment IS NOT NULL AND TRIM(f.comment) <> ''
                ORDER BY COALESCE(f.updated_at, f.created_at) DESC
                LIMIT " . (int)FEEDBACK_MAX_FEATURED;
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $testimonials[] = $row;
            }
        }

        if (count($testimonials) < FEEDBACK_MIN_DISPLAY) {
            return [];
        }

        return $testimonials;
    }
}

if (!function_exists('feedback_count_featured')) {
    function feedback_count_featured($conn)
    {
        feedback_ensure_table($conn);
        $res = $conn->query("SELECT COUNT(*) AS c FROM patient_feedback WHERE is_featured = 1");
        $row = $res ? $res->fetch_assoc() : null;
        return (int)($row['c'] ?? 0);
    }
}
