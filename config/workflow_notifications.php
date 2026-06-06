<?php

include_once __DIR__ . '/reminders.php';

if (!function_exists('wf_get_secretary_user_ids')) {
    function wf_get_secretary_user_ids($conn)
    {
        $ids = [];
        $stmt = $conn->prepare("SELECT u.user_id FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'secretary' AND u.is_active = 1");
        if ($stmt) {
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $ids[] = (int)$row['user_id'];
            }
            $stmt->close();
        }
        return $ids;
    }
}

if (!function_exists('wf_notify_user')) {
    function wf_notify_user($conn, $userId, $title, $body, $link = null)
    {
        return reminder_insert_inapp_notification($conn, (int)$userId, $title, $body, $link);
    }
}

if (!function_exists('wf_notify_patient')) {
    function wf_notify_patient($conn, $patientId, $title, $body, $link = null)
    {
        return reminder_insert_patient_inapp_notification($conn, (int)$patientId, $title, $body, $link);
    }
}

if (!function_exists('wf_notify_secretaries')) {
    function wf_notify_secretaries($conn, $title, $body, $link = null)
    {
        foreach (wf_get_secretary_user_ids($conn) as $uid) {
            wf_notify_user($conn, $uid, $title, $body, $link);
        }
    }
}

if (!function_exists('wf_notify_appointment_created')) {
    function wf_notify_appointment_created($conn, $appointmentId, $patientId, $doctorId, $appointmentDate, $status)
    {
        $patientName = wf_lookup_patient_name($conn, $patientId);
        $title = 'New appointment request';
        $body = $patientName . ' booked an appointment on ' . $appointmentDate . ' (#' . $appointmentId . ').';
        $link = 'secretary.php?page=appointments&view=scheduled';

        if ($status === 'scheduled') {
            wf_notify_secretaries($conn, $title, $body, $link);
        }

        wf_notify_user($conn, $doctorId, 'New appointment scheduled', $patientName . ' has an appointment on ' . $appointmentDate . '.', 'doctor.php?page=appointments');
        wf_notify_patient($conn, $patientId, 'Appointment submitted', 'Your appointment on ' . $appointmentDate . ' is awaiting secretary approval.', 'patient.php?page=appointments');
    }
}

if (!function_exists('wf_notify_appointment_approved')) {
    function wf_notify_appointment_approved($conn, $appointmentId, $patientId, $doctorId, $appointmentDate)
    {
        $title = 'Appointment confirmed';
        $body = 'Appointment #' . $appointmentId . ' on ' . $appointmentDate . ' has been approved.';
        wf_notify_patient($conn, $patientId, $title, $body, 'patient.php?page=appointments');
        wf_notify_user($conn, $doctorId, $title, $body, 'doctor.php?page=appointments');
    }
}

if (!function_exists('wf_notify_reschedule_requested')) {
    function wf_notify_reschedule_requested($conn, $appointmentId, $patientId, $doctorId, $requestedDate)
    {
        $patientName = wf_lookup_patient_name($conn, $patientId);
        $title = 'Reschedule request';
        $body = $patientName . ' requested to reschedule appointment #' . $appointmentId . ' to ' . $requestedDate . '.';
        $link = 'secretary.php?page=appointments&view=scheduled';
        wf_notify_secretaries($conn, $title, $body, $link);
        wf_notify_user($conn, $doctorId, $title, $body, 'doctor.php?page=appointments');
        wf_notify_patient($conn, $patientId, 'Reschedule submitted', 'Your reschedule request is pending secretary approval.', 'patient.php?page=appointments');
    }
}

if (!function_exists('wf_notify_reschedule_decision')) {
    function wf_notify_reschedule_decision($conn, $appointmentId, $patientId, $doctorId, $approved, $newDate = null)
    {
        if ($approved) {
            $title = 'Reschedule approved';
            $body = 'Appointment #' . $appointmentId . ' has been rescheduled to ' . ($newDate ?: 'the new date') . '.';
        } else {
            $title = 'Reschedule rejected';
            $body = 'Your reschedule request for appointment #' . $appointmentId . ' was not approved.';
        }
        wf_notify_patient($conn, $patientId, $title, $body, 'patient.php?page=appointments');
        wf_notify_user($conn, $doctorId, $title, $body, 'doctor.php?page=appointments');
    }
}

if (!function_exists('wf_notify_patient_arrived')) {
    function wf_notify_patient_arrived($conn, $appointmentId, $patientId, $doctorId)
    {
        $patientName = wf_lookup_patient_name($conn, $patientId);
        wf_notify_user($conn, $doctorId, 'Patient arrived', $patientName . ' has checked in for appointment #' . $appointmentId . '.', 'doctor.php?page=arrivals');
        wf_notify_patient($conn, $patientId, 'Checked in', 'You have been marked as arrived. Please wait for the doctor.', 'patient.php?page=appointments');
    }
}

if (!function_exists('wf_notify_doctor_finished')) {
    function wf_notify_doctor_finished($conn, $appointmentId, $patientId)
    {
        $patientName = wf_lookup_patient_name($conn, $patientId);
        $title = 'Records needed';
        $body = $patientName . ' — appointment #' . $appointmentId . ' is ready for clinical record submission.';
        wf_notify_secretaries($conn, $title, $body, 'secretary.php?page=appointments&view=records_queue');
    }
}

if (!function_exists('wf_notify_record_submitted')) {
    function wf_notify_record_submitted($conn, $appointmentId, $patientId, $doctorId, $billingId, $totalAmount)
    {
        $title = 'Clinical record complete';
        $body = 'Appointment #' . $appointmentId . ' record submitted. Bill #' . $billingId . ' — ₱' . number_format($totalAmount, 2) . '.';
        wf_notify_patient($conn, $patientId, 'Bill ready', 'Your bill of ₱' . number_format($totalAmount, 2) . ' is ready. View it in Transactions.', 'patient.php?page=transactions');
        wf_notify_user($conn, $doctorId, $title, $body, 'doctor.php?page=records');
        wf_notify_secretaries($conn, 'Billing generated', $body, 'secretary.php?page=billing');
    }
}

if (!function_exists('wf_lookup_patient_name')) {
    function wf_lookup_patient_name($conn, $patientId)
    {
        $name = 'Patient';
        $stmt = $conn->prepare('SELECT first_name, last_name FROM patients WHERE patient_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $patientId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            }
        }
        return $name !== '' ? $name : 'Patient';
    }
}
