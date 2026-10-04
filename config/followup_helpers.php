<?php

include_once __DIR__ . '/reminders.php';
include_once dirname(__DIR__) . '/assets/api/appointments/availability_guard.php';
include_once __DIR__ . '/booking_helpers.php';

if (!function_exists('workflow_create_followup_appointment')) {
    /**
     * Create a follow-up appointment + schedule when secretary sets a follow-up date.
     * Returns new appointment ID or null when date is invalid.
     */
    function workflow_create_followup_appointment(mysqli $conn, int $sourceAppointmentId, int $patientId, int $doctorId, string $followDateYmd): ?int
    {
        if ($sourceAppointmentId <= 0 || $patientId <= 0 || $doctorId <= 0) {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $followDateYmd)) {
            return null;
        }

        [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $doctorId, $followDateYmd);
        if (!$bookable) {
            throw new Exception('Follow-up cannot be scheduled: ' . $bookableMsg);
        }

        appt_ensure_standard_timeslots_for_doctor_date($conn, $doctorId, $followDateYmd);

        // Standard slots are generated in APPT_BASE_SLOT_MINUTES (10-minute) increments —
        // a follow-up/adjustment visit books exactly one of those, not a fixed 20-minute
        // block (which never exists, since nothing generates 20-minute slots). This was
        // silently failing every single follow-up booking before this fix.
        $slotSel = $conn->prepare(
            "SELECT timeslot_id, start_time, end_time
             FROM timeslot
             WHERE doctor_id = ? AND slot_date = ? AND status = 'available'
               AND TIMESTAMPDIFF(MINUTE, start_time, end_time) = " . (int) APPT_BASE_SLOT_MINUTES . "
             ORDER BY start_time ASC
             LIMIT 1"
        );
        if (!$slotSel) {
            throw new Exception('Unable to load follow-up timeslots.');
        }
        $slotSel->bind_param('is', $doctorId, $followDateYmd);
        $slotSel->execute();
        $slotRow = $slotSel->get_result()->fetch_assoc();
        $slotSel->close();

        if (!$slotRow) {
            throw new Exception('No available follow-up slot on ' . $followDateYmd . '.');
        }

        $timeslotId = (int)$slotRow['timeslot_id'];
        $bookStartTime = (string)$slotRow['start_time'];
        $bookEndTime = (string)$slotRow['end_time'];

        $sourceRef = appointment_assign_reference($conn, $sourceAppointmentId);
        $sourceLabel = $sourceRef !== '' ? 'visit Ref ' . $sourceRef : 'a previous visit';
        $followupReason = 'Follow-up from ' . $sourceLabel;
        $followupStatus = 'confirmed';
        $followupNotes = 'Follow-up scheduled by secretary for ' . $sourceLabel;

        $insAppt = $conn->prepare(
            'INSERT INTO appointments
             (patient_id, appointment_date, reason, is_online_appointment, notes, status, user_id, is_emergency, timeslot_id, rescheduled_from)
             VALUES (?, ?, ?, 0, ?, ?, ?, 0, ?, ?)'
        );
        if (!$insAppt) {
            throw new Exception('Failed to prepare follow-up appointment.');
        }
        $insAppt->bind_param(
            'issssiii',
            $patientId,
            $followDateYmd,
            $followupReason,
            $followupNotes,
            $followupStatus,
            $doctorId,
            $timeslotId,
            $sourceAppointmentId
        );
        if (!$insAppt->execute()) {
            $insAppt->close();
            throw new Exception('Failed to create follow-up appointment.');
        }
        $followupAppointmentId = (int)$conn->insert_id;
        $insAppt->close();
        appointment_assign_reference($conn, $followupAppointmentId);

        $updSlot = $conn->prepare("UPDATE timeslot SET status = 'booked' WHERE timeslot_id = ? AND status = 'available'");
        if ($updSlot) {
            $updSlot->bind_param('i', $timeslotId);
            $updSlot->execute();
            $updSlot->close();
        }

        $bookStart = $followDateYmd . ' ' . $bookStartTime;
        $bookEnd = $followDateYmd . ' ' . $bookEndTime;

        $insSchedule = $conn->prepare(
            'INSERT INTO patient_schedules (appointment_id, user_id, scheduled_start, scheduled_end, status)
             VALUES (?, ?, ?, ?, "scheduled")'
        );
        if (!$insSchedule) {
            throw new Exception('Failed to prepare follow-up schedule.');
        }
        $insSchedule->bind_param('iiss', $followupAppointmentId, $doctorId, $bookStart, $bookEnd);
        if (!$insSchedule->execute()) {
            $insSchedule->close();
            throw new Exception('Failed to create follow-up schedule.');
        }
        $scheduleId = (int)$conn->insert_id;
        $insSchedule->close();

        reminder_queue_default_pack($conn, $scheduleId, $bookStart, $followupAppointmentId);

        return $followupAppointmentId;
    }
}

if (!function_exists('workflow_create_recurring_followup_appointments')) {
    /**
     * Auto-book a whole series of monthly follow-up appointments up front (e.g. 12 monthly
     * braces-adjustment visits), instead of relying on the secretary/doctor to manually set
     * a single "Follow-up Date" again at the end of every single visit. This is what actually
     * guarantees a clinical record (and odontogram snapshot) gets a chance to be logged each
     * month for a long-running case — a missed manual re-entry previously meant that month
     * had no appointment at all to attach anything to.
     *
     * One bad month (e.g. no open slot, doctor on leave) must not cancel the rest of the
     * series — each visit is booked independently and failures are collected, not thrown.
     *
     * @return array{created: int[], failed: array<array{visit:int, date:string, error:string}>}
     */
    function workflow_create_recurring_followup_appointments(
        mysqli $conn,
        int $sourceAppointmentId,
        int $patientId,
        int $doctorId,
        string $firstVisitDateYmd,
        int $intervalMonths,
        int $totalVisits
    ): array {
        $created = [];
        $failed = [];

        $intervalMonths = max(1, $intervalMonths);
        $totalVisits = max(1, min(60, $totalVisits)); // sanity cap — 60 monthly visits is 5 years

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstVisitDateYmd)) {
            return ['created' => $created, 'failed' => $failed];
        }

        for ($visit = 1; $visit <= $totalVisits; $visit++) {
            $monthsOut = ($visit - 1) * $intervalMonths;
            $visitDate = $monthsOut > 0
                ? date('Y-m-d', strtotime($firstVisitDateYmd . ' +' . $monthsOut . ' months'))
                : $firstVisitDateYmd;

            // The clinic is closed Sundays (see appt_doctor_has_schedule_on_date), which a
            // fixed "+1 month" projection will land on every few visits by pure chance of the
            // calendar. Bumping to the next day keeps the visit close to its intended monthly
            // date instead of dropping that month's adjustment entirely — a missed visit is
            // worse for a running treatment plan (and its clinical record trail) than a
            // same-week shift.
            if ((int)date('w', strtotime($visitDate)) === 0) {
                $visitDate = date('Y-m-d', strtotime($visitDate . ' +1 day'));
            }

            try {
                $apptId = workflow_create_followup_appointment($conn, $sourceAppointmentId, $patientId, $doctorId, $visitDate);
                if ($apptId !== null) {
                    $created[] = $apptId;
                } else {
                    $failed[] = ['visit' => $visit, 'date' => $visitDate, 'error' => 'Invalid date or parameters.'];
                }
            } catch (Exception $e) {
                $failed[] = ['visit' => $visit, 'date' => $visitDate, 'error' => $e->getMessage()];
                error_log("workflow_create_recurring_followup_appointments: visit {$visit} on {$visitDate} failed: " . $e->getMessage());
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }
}
