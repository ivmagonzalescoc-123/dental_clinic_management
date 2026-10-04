<?php

include_once __DIR__ . '/reminders.php';
include_once __DIR__ . '/email_templates.php';
include_once __DIR__ . '/booking_helpers.php';

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
        $body = $patientName . ' booked an appointment on ' . $appointmentDate . '.';
        $link = 'secretary.php?page=appointments&view=all';

        if ($status === 'scheduled') {
            wf_notify_secretaries($conn, $title, $body, $link);
        }

        wf_notify_user($conn, $doctorId, 'New appointment scheduled', $patientName . ' has an appointment on ' . $appointmentDate . '.', 'doctor.php?page=appointments');
        wf_notify_patient($conn, $patientId, 'Appointment submitted', 'Your appointment on ' . $appointmentDate . ' is awaiting secretary approval.', 'patient.php?page=appointments');
    }
}

if (!function_exists('wf_notify_new_patient_booking')) {
    function wf_notify_new_patient_booking($conn, $bookingId, $patientName, $preferredDate)
    {
        $title = 'New patient booking';
        $body = $patientName . ' requested a new-patient booking for ' . $preferredDate . '.';
        $link = 'secretary.php?page=appointments&section=new_customers';
        wf_notify_secretaries($conn, $title, $body, $link);
    }
}

if (!function_exists('wf_notify_appointment_approved')) {
    function wf_notify_appointment_approved($conn, $appointmentId, $patientId, $doctorId, $appointmentDate)
    {
        $ref = wf_format_patient_appointment($conn, $patientId, $appointmentId);
        $title = 'Appointment confirmed';
        $bodyPatient = 'Your appointment on ' . $appointmentDate . ' has been approved.';
        $bodyStaff = $ref . ' on ' . $appointmentDate . ' has been approved.';
        wf_notify_patient($conn, $patientId, $title, $bodyPatient, 'patient.php?page=appointments');
        wf_notify_user($conn, $doctorId, $title, $bodyStaff, 'doctor.php?page=appointments');
    }
}

if (!function_exists('wf_notify_appointment_cancelled')) {
    function wf_notify_appointment_cancelled($conn, $appointmentId, $patientId, $doctorId, $appointmentDate, $reason = '')
    {
        $ref = wf_format_patient_appointment($conn, $patientId, $appointmentId);
        $reasonSuffix = trim((string)$reason) !== '' ? (' Reason: ' . trim((string)$reason)) : '';
        $title = 'Appointment cancelled';
        $bodyPatient = 'Your appointment on ' . $appointmentDate . ' has been cancelled.' . $reasonSuffix;
        $bodyStaff = $ref . ' on ' . $appointmentDate . ' has been cancelled.' . $reasonSuffix;

        wf_notify_patient($conn, $patientId, $title, $bodyPatient, 'patient.php?page=appointments');
        if ($doctorId) {
            wf_notify_user($conn, $doctorId, $title, $bodyStaff, 'doctor.php?page=appointments');
        }

        if (function_exists('reminder_send_email') && function_exists('email_appointment_cancelled_html')) {
            $patientName = 'Patient';
            $patientEmail = '';
            $stmt = $conn->prepare('SELECT first_name, last_name, email FROM patients WHERE patient_id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $patientId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $patientName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: 'Patient';
                    $patientEmail = trim((string)($row['email'] ?? ''));
                }
            }
            if ($patientEmail !== '') {
                $plainText = 'Your appointment on ' . $appointmentDate . ' has been cancelled.' . $reasonSuffix;
                $html = email_appointment_cancelled_html($patientName, $appointmentDate, (string)$reason);
                reminder_send_email($patientEmail, 'Appointment Cancelled', $plainText, $html);
            }
        }
    }
}

if (!function_exists('wf_notify_doctor_leave_conflicts')) {
    function wf_notify_doctor_leave_conflicts($conn, $doctorId, $doctorName, $leaveStart, $leaveEnd, array $conflicts)
    {
        if (empty($conflicts)) {
            return;
        }

        $count = count($conflicts);
        $title = 'Doctor leave conflicts with existing appointments';
        $body = 'Dr. ' . $doctorName . ' was approved for leave (' . $leaveStart . ' to ' . $leaveEnd . '), which conflicts with '
            . $count . ' existing appointment' . ($count === 1 ? '' : 's') . '. Please reschedule or reassign affected patients.';
        wf_notify_secretaries($conn, $title, $body, 'secretary.php?page=appointments&view=needs_reassignment');

        foreach ($conflicts as $c) {
            $appointmentId = (int)($c['appointment_id'] ?? 0);
            $patientId = (int)($c['patient_id'] ?? 0);
            $appointmentDate = (string)($c['appointment_date'] ?? '');
            if ($patientId <= 0) {
                continue;
            }

            $ref = wf_format_patient_appointment($conn, $patientId, $appointmentId);
            wf_notify_patient(
                $conn,
                $patientId,
                'Your appointment needs rescheduling',
                'Dr. ' . $doctorName . ' is unavailable on ' . $appointmentDate . '. Our staff will contact you to reschedule.',
                'patient.php?page=appointments'
            );

            if (function_exists('reminder_send_email') && function_exists('email_appointment_leave_conflict_html')) {
                $patientName = 'Patient';
                $patientEmail = '';
                $pStmt = $conn->prepare('SELECT first_name, last_name, email FROM patients WHERE patient_id = ? LIMIT 1');
                if ($pStmt) {
                    $pStmt->bind_param('i', $patientId);
                    $pStmt->execute();
                    $pRow = $pStmt->get_result()->fetch_assoc();
                    $pStmt->close();
                    if ($pRow) {
                        $patientName = trim(($pRow['first_name'] ?? '') . ' ' . ($pRow['last_name'] ?? '')) ?: 'Patient';
                        $patientEmail = trim((string)($pRow['email'] ?? ''));
                    }
                }
                if ($patientEmail !== '') {
                    $plainText = 'Dr. ' . $doctorName . ' is unavailable on ' . $appointmentDate . '. Our staff will contact you to reschedule.';
                    $html = email_appointment_leave_conflict_html($patientName, $appointmentDate, $doctorName);
                    reminder_send_email($patientEmail, 'Your Appointment Needs Rescheduling', $plainText, $html);
                }
            }

            if (function_exists('log_system_event')) {
                log_system_event(
                    $conn,
                    'appointment.leave_conflict',
                    'appointments',
                    $appointmentId,
                    $ref . ' conflicts with approved doctor leave (' . $leaveStart . ' to ' . $leaveEnd . ').',
                    null
                );
            }
        }
    }
}

if (!function_exists('wf_notify_reschedule_requested')) {
    function wf_notify_reschedule_requested($conn, $appointmentId, $patientId, $doctorId, $requestedDate)
    {
        $patientName = wf_lookup_patient_name($conn, $patientId);
        $ref = wf_format_patient_appointment($conn, $patientId, $appointmentId);
        $title = 'Reschedule request';
        $body = $patientName . ' requested to reschedule to ' . $requestedDate . '.';
        $link = 'secretary.php?page=appointments&view=reschedule';
        wf_notify_secretaries($conn, $title, $body, $link);
        wf_notify_user($conn, $doctorId, $title, $body, 'doctor.php?page=appointments');
        wf_notify_patient($conn, $patientId, 'Reschedule submitted', 'Your reschedule request is pending secretary approval.', 'patient.php?page=appointments');
    }
}

if (!function_exists('wf_notify_reschedule_decision')) {
    function wf_notify_reschedule_decision($conn, $appointmentId, $patientId, $doctorId, $approved, $newDate = null)
    {
        $ref = wf_format_patient_appointment($conn, $patientId, $appointmentId);
        if ($approved) {
            $title = 'Reschedule approved';
            $bodyPatient = 'Your appointment has been rescheduled to ' . ($newDate ?: 'the new date') . '.';
            $bodyStaff = $ref . ' has been rescheduled to ' . ($newDate ?: 'the new date') . '.';
        } else {
            $title = 'Reschedule rejected';
            $bodyPatient = 'Your reschedule request was not approved.';
            $bodyStaff = $ref . ' — reschedule request was not approved.';
        }
        wf_notify_patient($conn, $patientId, $title, $bodyPatient, 'patient.php?page=appointments');
        wf_notify_user($conn, $doctorId, $title, $bodyStaff, 'doctor.php?page=appointments');
    }
}

if (!function_exists('wf_notify_patient_arrived')) {
    function wf_notify_patient_arrived($conn, $appointmentId, $patientId, $doctorId)
    {
        $patientName = wf_lookup_patient_name($conn, $patientId);
        wf_notify_user($conn, $doctorId, 'Patient arrived', $patientName . ' has checked in and is ready to be seen.', 'doctor.php?page=arrivals');
        wf_notify_patient($conn, $patientId, 'Checked in', 'You have been marked as arrived. Please wait for the doctor.', 'patient.php?page=appointments');
    }
}

if (!function_exists('wf_notify_doctor_finished')) {
    function wf_notify_doctor_finished($conn, $appointmentId, $patientId)
    {
        $ref = wf_format_patient_appointment($conn, $patientId, $appointmentId);
        $title = 'Records needed';
        $body = $ref . ' is ready for clinical record submission.';
        wf_notify_secretaries($conn, $title, $body, 'secretary.php?page=ehr_queue');
    }
}

if (!function_exists('wf_notify_record_submitted')) {
    function wf_notify_record_submitted($conn, $appointmentId, $patientId, $doctorId, $billingId, $totalAmount)
    {
        $ref = wf_format_patient_appointment($conn, $patientId, $appointmentId);
        $title = 'Clinical record complete';
        $body = $ref . ' — record submitted. Bill #' . $billingId . ' — ₱' . number_format($totalAmount, 2) . '.';
        wf_notify_patient($conn, $patientId, 'Bill ready', 'Your bill of ₱' . number_format($totalAmount, 2) . ' is ready. View it in Transactions.', 'patient.php?page=transactions');
        wf_notify_user($conn, $doctorId, $title, $body, 'doctor.php?page=records');
        wf_notify_secretaries($conn, 'Billing generated', $body, 'secretary.php?page=billing');
    }
}

if (!function_exists('wf_notify_feedback_request')) {
    function wf_notify_feedback_request($conn, $patientId)
    {
        include_once __DIR__ . '/feedback_helpers.php';
        if (feedback_patient_has_reviewed($conn, $patientId)) {
            return;
        }
        wf_notify_patient(
            $conn,
            $patientId,
            'How was your visit?',
            'Sharing a review of the clinic is optional and takes less than a minute.',
            'patient.php?page=dashboard'
        );
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

if (!function_exists('wf_format_patient_appointment')) {
    function wf_format_patient_appointment($conn, $patientId, $appointmentId)
    {
        $name = wf_lookup_patient_name($conn, $patientId);
        // People see the appointment's reference number, never the internal ID.
        $ref = (int)$appointmentId > 0 ? appointment_assign_reference($conn, (int)$appointmentId) : '';
        return $ref !== '' ? ($name . ' (Ref ' . $ref . ')') : $name;
    }
}
