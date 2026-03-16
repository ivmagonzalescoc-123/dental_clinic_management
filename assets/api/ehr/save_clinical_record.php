<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once __DIR__ . '/../appointments/availability_guard.php';
header('Content-Type: application/json');

// Only doctors may save clinical records
check_session(['doctor']);
$doctor_id = $_SESSION['user_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

if (!function_exists('normalize_positive_int_list')) {
    function normalize_positive_int_list($values)
    {
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }
}

if (!function_exists('extract_service_ids_from_node')) {
    function extract_service_ids_from_node($node, &$bucket)
    {
        if (is_array($node)) {
            if (array_key_exists('service_id', $node)) {
                $sid = (int)$node['service_id'];
                if ($sid > 0) {
                    $bucket[$sid] = $sid;
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    extract_service_ids_from_node($child, $bucket);
                }
            }
        }
    }
}

$appointment_id = (int)($_POST['appointment_id'] ?? 0);
$schedule_id = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : null;
$recommended_services_raw = isset($_POST['recommended_services']) ? $_POST['recommended_services'] : '[]';
$recommended_services_decoded = json_decode($recommended_services_raw, true);
$recommended_services = normalize_positive_int_list(
    is_array($recommended_services_decoded) ? $recommended_services_decoded : []
);

$treatment_plan_raw = isset($_POST['treatment_plan']) ? trim((string)$_POST['treatment_plan']) : '';
$treatment_plan = null;
if ($treatment_plan_raw !== '') {
    $decodedTreatment = json_decode($treatment_plan_raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $treatment_plan = $decodedTreatment;
    } else {
        // Accept plain text treatment notes in addition to structured JSON payloads.
        $treatment_plan = $treatment_plan_raw;
    }
}

$selected_treatments_raw = isset($_POST['selected_treatments']) ? $_POST['selected_treatments'] : '[]';
$selected_treatments_decoded = json_decode($selected_treatments_raw, true);
$selected_treatment_ids = normalize_positive_int_list(
    is_array($selected_treatments_decoded) ? $selected_treatments_decoded : []
);

$materials_used = isset($_POST['materials_used']) ? json_decode($_POST['materials_used'], true) : null;
if (!is_array($materials_used)) {
    $materials_used = null;
}
$odontogram = isset($_POST['odontogram']) ? json_decode($_POST['odontogram'], true) : null;
$doctor_notes = $_POST['doctor_notes'] ?? '';
$follow_up_date = isset($_POST['follow_up_date']) ? trim($_POST['follow_up_date']) : null;
$submit_and_finalize = isset($_POST['finalize']) && $_POST['finalize'] == '1';

if ($appointment_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id']);
    exit;
}

try {
    $conn->begin_transaction();

    // Get appointment and patient
    $stmt = $conn->prepare('SELECT appointment_id, patient_id, user_id FROM appointments WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $appt = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$appt) throw new Exception('Appointment not found');
    $patient_id = (int)$appt['patient_id'];

    // Read profiling payload from POST (if any)
    $profiling_json = isset($_POST['profiling']) ? $_POST['profiling'] : null;

    // Ensure patients table has profiling_locked column
    $colResP = $conn->query("SHOW COLUMNS FROM patients LIKE 'profiling_locked'");
    if (!$colResP || $colResP->num_rows === 0) {
        $conn->query("ALTER TABLE patients ADD COLUMN profiling_locked TINYINT(1) DEFAULT 0");
    }

    // Ensure clinical_records has a `profiling` column to snapshot profiling at time of record
    $colResCR = $conn->query("SHOW COLUMNS FROM clinical_records LIKE 'profiling'");
    if (!$colResCR || $colResCR->num_rows === 0) {
        $conn->query("ALTER TABLE clinical_records ADD COLUMN profiling JSON DEFAULT NULL");
    }

    // Fetch current patient profiling & lock status
    $pstmt = $conn->prepare('SELECT profiling, profiling_locked FROM patients WHERE patient_id = ? LIMIT 1');
    $pstmt->bind_param('i', $patient_id);
    $pstmt->execute();
    $pres = $pstmt->get_result();
    $prow = $pres ? $pres->fetch_assoc() : null;
    $pstmt->close();
    $patient_has_profiling = ($prow && !empty($prow['profiling']));
    $patient_locked = ($prow && !empty($prow['profiling_locked']));

    // Upsert clinical_records
    $treatment_service_map = [];
    if (is_array($treatment_plan)) {
        extract_service_ids_from_node($treatment_plan, $treatment_service_map);
    }

    $service_ids = array_values(array_unique(array_merge($recommended_services, array_values($treatment_service_map))));

    $treatment_payload = $treatment_plan;
    if (!empty($selected_treatment_ids)) {
        if (is_array($treatment_payload)) {
            $treatment_payload['_selected_treatment_ids'] = $selected_treatment_ids;
        } elseif (is_string($treatment_payload) && trim($treatment_payload) !== '') {
            $treatment_payload = [
                'notes' => $treatment_payload,
                '_selected_treatment_ids' => $selected_treatment_ids,
            ];
        } else {
            $treatment_payload = ['_selected_treatment_ids' => $selected_treatment_ids];
        }
    }

    $json_recommended = !empty($recommended_services) ? json_encode($recommended_services, JSON_UNESCAPED_UNICODE) : null;
    $json_treatment = ($treatment_payload !== null) ? json_encode($treatment_payload, JSON_UNESCAPED_UNICODE) : null;
    $json_materials = $materials_used ? json_encode($materials_used, JSON_UNESCAPED_UNICODE) : null;
    $json_odontogram = $odontogram ? json_encode($odontogram, JSON_UNESCAPED_UNICODE) : null;
    $json_profiling = $profiling_json ? $profiling_json : null;
    // Handle uploaded images (optional)
    $uploaded_images = [];
    if (!empty($_FILES['images'])) {
        $files = $_FILES['images'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $baseDir = __DIR__ . '/../../uploads/clinical_images/' . $appointment_id . '/';
        if (!is_dir($baseDir)) @mkdir($baseDir, 0777, true);
        for ($i = 0; $i < $count; $i++) {
            $err = $files['error'][$i];
            if ($err !== UPLOAD_ERR_OK) continue;
            $tmp = $files['tmp_name'][$i];
            if (!is_uploaded_file($tmp)) continue;
            $info = @getimagesize($tmp);
            if (!$info) continue; // not an image
            $maxSize = 5 * 1024 * 1024; // 5MB
            if (filesize($tmp) > $maxSize) continue;
            $ext = image_type_to_extension($info[2]);
            $fname = time() . '_' . bin2hex(random_bytes(6)) . $ext;
            $dest = $baseDir . $fname;
            if (@move_uploaded_file($tmp, $dest)) {
                // web-accessible path
                $webPath = 'assets/uploads/clinical_images/' . $appointment_id . '/' . $fname;
                $uploaded_images[] = $webPath;
            }
        }
    }
    // Also accept tooth-specific images under 'tooth_images' (x-rays / tooth photos)
    if (!empty($_FILES['tooth_images'])) {
        $files = $_FILES['tooth_images'];
        $count = is_array($files['name']) ? count($files['name']) : 0;
        $baseDir = __DIR__ . '/../../uploads/clinical_images/' . $appointment_id . '/';
        if (!is_dir($baseDir)) @mkdir($baseDir, 0777, true);
        for ($i = 0; $i < $count; $i++) {
            $err = $files['error'][$i];
            if ($err !== UPLOAD_ERR_OK) continue;
            $tmp = $files['tmp_name'][$i];
            if (!is_uploaded_file($tmp)) continue;
            $info = @getimagesize($tmp);
            if (!$info) continue; // not an image
            $maxSize = 5 * 1024 * 1024; // 5MB
            if (filesize($tmp) > $maxSize) continue;
            $ext = image_type_to_extension($info[2]);
            $fname = time() . '_' . bin2hex(random_bytes(6)) . $ext;
            $dest = $baseDir . $fname;
            if (@move_uploaded_file($tmp, $dest)) {
                $webPath = 'assets/uploads/clinical_images/' . $appointment_id . '/' . $fname;
                $uploaded_images[] = $webPath;
            }
        }
    }
    $json_images = !empty($uploaded_images) ? json_encode(array_values($uploaded_images), JSON_UNESCAPED_UNICODE) : null;

    // Ensure clinical_records has an `images` column (add if missing)
    $colRes = $conn->query("SHOW COLUMNS FROM clinical_records LIKE 'images'");
    if (!$colRes || $colRes->num_rows === 0) {
        $conn->query("ALTER TABLE clinical_records ADD COLUMN images JSON DEFAULT NULL");
    }
    // Ensure clinical_records has a `tooth_image` column to store primary tooth/xray path
    $colRes2 = $conn->query("SHOW COLUMNS FROM clinical_records LIKE 'tooth_image'");
    if (!$colRes2 || $colRes2->num_rows === 0) {
        $conn->query("ALTER TABLE clinical_records ADD COLUMN tooth_image VARCHAR(255) DEFAULT NULL");
    }

    // Check if record exists
    $stmt = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($existing) {
        $stmt = $conn->prepare('UPDATE clinical_records SET doctor_id = ?, patient_id = ?, recommended_services = ?, treatment_plan = ?, materials_used = ?, odontogram = ?, images = ?, doctor_notes = ?, follow_up_date = ?, status = ?, profiling = ?, submitted_by = ?, submitted_at = IF(?=1,NOW(),submitted_at) WHERE appointment_id = ?');
        $status = $submit_and_finalize ? 'submitted' : 'in_progress';
        $submitted_flag = $submit_and_finalize ? 1 : 0;
        $stmt->bind_param('iisssssssssiii', $doctor_id, $patient_id, $json_recommended, $json_treatment, $json_materials, $json_odontogram, $json_images, $doctor_notes, $follow_up_date, $status, $json_profiling, $doctor_id, $submitted_flag, $appointment_id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare('INSERT INTO clinical_records (appointment_id, doctor_id, patient_id, recommended_services, treatment_plan, materials_used, odontogram, images, doctor_notes, follow_up_date, status, profiling, submitted_by, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?=1,NOW(),NULL))');
        $status = $submit_and_finalize ? 'submitted' : 'in_progress';
        $submitted_flag = $submit_and_finalize ? 1 : 0;
        $stmt->bind_param('iiisssssssssii', $appointment_id, $doctor_id, $patient_id, $json_recommended, $json_treatment, $json_materials, $json_odontogram, $json_images, $doctor_notes, $follow_up_date, $status, $json_profiling, $doctor_id, $submitted_flag);
        $stmt->execute();
        $stmt->close();
    }

    // If patient-level profiling provided and patient has no profiling yet, save it to patients (silent auto-lock)
    if ($profiling_json !== null && !$patient_has_profiling) {
        $up = $conn->prepare('UPDATE patients SET profiling = ?, profiling_locked = 1 WHERE patient_id = ?');
        if ($up) {
            $up->bind_param('si', $profiling_json, $patient_id);
            $up->execute();
            $up->close();
        }
    }

    // If there are uploaded images, set the primary tooth_image to the first uploaded path
    if (!empty($uploaded_images)) {
        $first = $uploaded_images[0];
        $ust_img = $conn->prepare('UPDATE clinical_records SET tooth_image = ? WHERE appointment_id = ?');
        if ($ust_img) {
            $ust_img->bind_param('si', $first, $appointment_id);
            $ust_img->execute();
            $ust_img->close();
        }
    }

    // Keep appointment-level service links in selected_services (appointments.service_id is removed).
    $existingServiceLinkIds = [];
    $findLinks = $conn->prepare('SELECT selected_service_id FROM selected_services WHERE appointment_id = ?');
    if ($findLinks) {
        $findLinks->bind_param('i', $appointment_id);
        $findLinks->execute();
        $linkRes = $findLinks->get_result();
        while ($linkRes && ($linkRow = $linkRes->fetch_assoc())) {
            $existingServiceLinkIds[] = (int)$linkRow['selected_service_id'];
        }
        $findLinks->close();
    }

    if (!empty($existingServiceLinkIds)) {
        $ph = implode(',', array_fill(0, count($existingServiceLinkIds), '?'));
        $types = str_repeat('i', count($existingServiceLinkIds));
        $delTreat = $conn->prepare("DELETE FROM selected_treatments WHERE selected_service_id IN ($ph)");
        if ($delTreat) {
            $delTreat->bind_param($types, ...$existingServiceLinkIds);
            $delTreat->execute();
            $delTreat->close();
        }
    }

    $delServices = $conn->prepare('DELETE FROM selected_services WHERE appointment_id = ?');
    if ($delServices) {
        $delServices->bind_param('i', $appointment_id);
        $delServices->execute();
        $delServices->close();
    }

    $selectedServiceLinkIds = [];
    if (!empty($service_ids)) {
        $serviceNote = 'From clinical record';
        $insService = $conn->prepare('INSERT INTO selected_services (appointment_id, service_id, notes) VALUES (?, ?, ?)');
        if ($insService) {
            foreach ($service_ids as $sid) {
                $insService->bind_param('iis', $appointment_id, $sid, $serviceNote);
                if (!$insService->execute()) {
                    $insService->close();
                    throw new Exception('Failed to save selected services: ' . $conn->error);
                }
                $selectedServiceLinkIds[] = (int)$conn->insert_id;
            }
            $insService->close();
        }
    }

    // selected_treatments requires selected_service_id linkage; save when at least one service exists.
    if (!empty($selected_treatment_ids) && !empty($selectedServiceLinkIds)) {
        $defaultSelectedServiceId = (int)$selectedServiceLinkIds[0];
        $insTreatment = $conn->prepare(
            "INSERT INTO selected_treatments (treatment_id, selected_service_id, status, notes)
             VALUES (?, ?, 'pending', NULL)"
        );
        if ($insTreatment) {
            foreach ($selected_treatment_ids as $tid) {
                $insTreatment->bind_param('ii', $tid, $defaultSelectedServiceId);
                if (!$insTreatment->execute()) {
                    $insTreatment->close();
                    throw new Exception('Failed to save selected treatments: ' . $conn->error);
                }
            }
            $insTreatment->close();
        }
    }

    // --- Smart-scheduling hook: detect services/treatment items that require scheduling
    $will_need_scheduling = false;
    $created_schedule_id = null;
    $created_followup_appointment_id = null;

    $total_needed_minutes = 0;
    if (!empty($service_ids)) {
        foreach ($service_ids as $sid) {
            $sstmt = $conn->prepare(
                'SELECT s.requires_schedule, COALESCE(ts.duration_minutes, s.default_duration_minutes, 30) AS duration_minutes
                 FROM services s
                 LEFT JOIN timeslot_service ts ON ts.service_id = s.service_id
                 WHERE s.service_id = ?
                 LIMIT 1'
            );
            $sstmt->bind_param('i', $sid);
            $sstmt->execute();
            $sres = $sstmt->get_result();
            $srow = $sres ? $sres->fetch_assoc() : null;
            $sstmt->close();
            if ($srow) {
                if ((int)$srow['requires_schedule'] === 1) {
                    $will_need_scheduling = true;
                    $total_needed_minutes += max(20, (int)$srow['duration_minutes']);
                }
            }
        }
    }

    // If scheduling needed and a valid follow-up datetime was provided, auto-book a schedule now
    $has_valid_followup = false;
    if ($follow_up_date) {
        // Accept either date-only (YYYY-MM-DD) or datetime (YYYY-MM-DD HH:MM or with seconds)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $follow_up_date) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $follow_up_date)) {
            $has_valid_followup = true;
        }
    }

    if ($submit_and_finalize && $will_need_scheduling && $has_valid_followup) {
        $neededMinutes = max(20, (int)$total_needed_minutes);

        // Normalize follow-up preference: date-only starts at 08:00; datetime rounds up to next 20-minute boundary.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $follow_up_date)) {
            $followDate = $follow_up_date;
            $preferredStart = '08:00:00';
        } else {
            $tmpDt = new DateTime($follow_up_date);
            $followDate = $tmpDt->format('Y-m-d');

            $hour = (int)$tmpDt->format('H');
            $min = (int)$tmpDt->format('i');
            $nextMin = ((int)ceil($min / 20)) * 20;
            if ($nextMin >= 60) {
                $hour += 1;
                $nextMin = 0;
            }
            if ($hour > 23) {
                $hour = 23;
                $nextMin = 40;
            }
            $preferredStart = sprintf('%02d:%02d:00', $hour, $nextMin);
        }

        [$bookable, $bookableMsg] = appt_assert_doctor_bookable($conn, $doctor_id, $followDate);
        if (!$bookable) {
            throw new Exception('Follow-up cannot be scheduled: ' . $bookableMsg);
        }

        appt_ensure_standard_timeslots_for_doctor_date($conn, $doctor_id, $followDate);

        $slotSel = $conn->prepare(
            "SELECT timeslot_id, start_time, end_time
             FROM timeslot
             WHERE doctor_id = ? AND slot_date = ? AND status = 'available'
               AND TIMESTAMPDIFF(MINUTE, start_time, end_time) = 20
             ORDER BY start_time ASC"
        );
        if (!$slotSel) throw new Exception('Prepare failed (timeslot select): ' . $conn->error);
        $slotSel->bind_param('is', $doctor_id, $followDate);
        $slotSel->execute();
        $slotRes = $slotSel->get_result();
        $slots = [];
        while ($sr = $slotRes->fetch_assoc()) {
            $startSec = strtotime($followDate . ' ' . $sr['start_time']);
            $endSec = strtotime($followDate . ' ' . $sr['end_time']);
            $dur = ($startSec && $endSec && $endSec > $startSec) ? (int)(($endSec - $startSec) / 60) : 0;
            if ($dur <= 0) continue;
            $slots[] = [
                'timeslot_id' => (int)$sr['timeslot_id'],
                'start_time' => $sr['start_time'],
                'end_time' => $sr['end_time'],
                'duration' => $dur,
            ];
        }
        $slotSel->close();

        $reservedIds = [];
        $bookStartTime = null;
        $bookEndTime = null;

        $count = count($slots);
        for ($i = 0; $i < $count; $i++) {
            if (strcmp($slots[$i]['start_time'], $preferredStart) < 0) {
                continue;
            }

            $candidateIds = [(int)$slots[$i]['timeslot_id']];
            $accumulated = (int)$slots[$i]['duration'];
            $candidateStart = $slots[$i]['start_time'];
            $candidateEnd = $slots[$i]['end_time'];

            for ($j = $i + 1; $j < $count && $accumulated < $neededMinutes; $j++) {
                if ($slots[$j]['start_time'] !== $candidateEnd) {
                    break;
                }
                $candidateIds[] = (int)$slots[$j]['timeslot_id'];
                $accumulated += (int)$slots[$j]['duration'];
                $candidateEnd = $slots[$j]['end_time'];
            }

            if ($accumulated < $neededMinutes) {
                continue;
            }

            $candidateStartDT = $followDate . ' ' . $candidateStart;
            $candidateEndDT = $followDate . ' ' . $candidateEnd;

            // Guard against overlap with existing scheduled/in-progress appointments for this doctor.
                        $ov = $conn->prepare(
                                "SELECT schedule_id
                                 FROM patient_schedules
                                 WHERE user_id = ?
                                     AND status IN ('scheduled','in_progress')
                                     AND scheduled_start < ?
                                     AND scheduled_end > ?
                                 LIMIT 1"
                        );
            if (!$ov) throw new Exception('Prepare failed (overlap check): ' . $conn->error);
            $ov->bind_param('iss', $doctor_id, $candidateEndDT, $candidateStartDT);
            $ov->execute();
            $ovRow = $ov->get_result()->fetch_assoc();
            $ov->close();

            if ($ovRow) {
                continue;
            }

            $reservedIds = $candidateIds;
            $bookStartTime = $candidateStart;
            $bookEndTime = $candidateEnd;
            break;
        }

        if (empty($reservedIds) || !$bookStartTime || !$bookEndTime) {
            throw new Exception('No contiguous available follow-up slots found for the selected date and required duration (' . $neededMinutes . ' mins).');
        }

        // Create a real follow-up appointment so it enters secretary/doctor workflow normally.
        $followupReason = 'Follow-up from appointment #' . $appointment_id;
        $followupStatus = 'confirmed';
        $insAppt = $conn->prepare(
            'INSERT INTO appointments
             (patient_id, appointment_date, reason, is_online_appointment, notes, status, user_id, is_emergency, timeslot_id, rescheduled_from)
               VALUES (?, ?, ?, 0, ?, ?, ?, 0, NULL, ?)'
        );
        if (!$insAppt) throw new Exception('Prepare failed (follow-up appointment): ' . $conn->error);
        $followupDateOnly = $followDate;
        $followupNotes = 'Auto follow-up scheduled from clinical record of appointment #' . $appointment_id;
        $rescheduledFrom = $appointment_id;
        $insAppt->bind_param(
            'issssii',
            $patient_id,
            $followupDateOnly,
            $followupReason,
            $followupNotes,
            $followupStatus,
            $doctor_id,
            $rescheduledFrom
        );
        if (!$insAppt->execute()) throw new Exception('Failed to create follow-up appointment: ' . $insAppt->error);
        $created_followup_appointment_id = (int)$conn->insert_id;
        $insAppt->close();

        if (!empty($service_ids)) {
            $followServiceNote = 'Auto follow-up services copied from appointment #' . $appointment_id;
            $insFollowSvc = $conn->prepare(
                'INSERT INTO selected_services (appointment_id, service_id, notes) VALUES (?, ?, ?)'
            );
            if ($insFollowSvc) {
                foreach ($service_ids as $sid) {
                    $insFollowSvc->bind_param('iis', $created_followup_appointment_id, $sid, $followServiceNote);
                    if (!$insFollowSvc->execute()) {
                        $insFollowSvc->close();
                        throw new Exception('Failed to save follow-up services: ' . $conn->error);
                    }
                }
                $insFollowSvc->close();
            }
        }

        // Reserve selected timeslots so secretary/patient cannot book them.
        $ph = implode(',', array_fill(0, count($reservedIds), '?'));
        $types = str_repeat('i', count($reservedIds));
        $params = $reservedIds;
        $updSql = "UPDATE timeslot SET status = 'booked' WHERE timeslot_id IN ($ph) AND status = 'available'";
        $upd = $conn->prepare($updSql);
        if (!$upd) throw new Exception('Prepare failed (timeslot reserve): ' . $conn->error);
        $upd->bind_param($types, ...$params);
        $upd->execute();
        $affected = (int)$upd->affected_rows;
        $upd->close();

        if ($affected !== count($reservedIds)) {
            throw new Exception('Some follow-up slots were just taken. Please retry scheduling.');
        }

        // Link primary timeslot to follow-up appointment.
        $firstTimeslotId = (int)$reservedIds[0];
        $upFollow = $conn->prepare('UPDATE appointments SET timeslot_id = ?, appointment_date = ? WHERE appointment_id = ?');
        if ($upFollow) {
            $upFollow->bind_param('isi', $firstTimeslotId, $followDate, $created_followup_appointment_id);
            $upFollow->execute();
            $upFollow->close();
        }

        $book_start = $followDate . ' ' . $bookStartTime;
        $book_end = $followDate . ' ' . $bookEndTime;

        $ins_sql = 'INSERT INTO patient_schedules (appointment_id, user_id, scheduled_start, scheduled_end, status) VALUES (?, ?, ?, ?, "scheduled")';
        $ins = $conn->prepare($ins_sql);
        if (!$ins) throw new Exception('Prepare failed (schedule insert): ' . $conn->error);
        $ins->bind_param('iiss', $created_followup_appointment_id, $doctor_id, $book_start, $book_end);
        if (!$ins->execute()) throw new Exception('Failed to create schedule: ' . $ins->error);
        $created_schedule_id = (int)$conn->insert_id;
        $ins->close();

        $remind_dt = (new DateTime($book_start))->modify('-24 hours')->format('Y-m-d H:i:s');
        $r_sql = 'INSERT INTO schedule_reminders (patient_schedule_id, remind_at, channel, payload) VALUES (?, ?, "in_app", ?)';
        $payload = json_encode([
            'appointment_id' => $created_followup_appointment_id,
            'schedule_id' => $created_schedule_id,
            'patient_schedule_id' => $created_schedule_id,
        ]);
        $r_stmt = $conn->prepare($r_sql);
        if ($r_stmt) {
            $r_stmt->bind_param('iss', $created_schedule_id, $remind_dt, $payload);
            $r_stmt->execute();
            $r_stmt->close();
        }
    }

    // If finalized/submitted, update appointment status and queue, and create billing
    if ($submit_and_finalize) {
        $status = 'completed';
        $ust = $conn->prepare("UPDATE appointments SET status = ?, notes = CONCAT(IFNULL(notes,''), ?) WHERE appointment_id = ?");
        $note_append = "\n[Clinical record submitted by doctor {$doctor_id}]";
        $ust->bind_param('ssi', $status, $note_append, $appointment_id);
        $ust->execute();
        $ust->close();

        // Calculate billing: sum of services + materials total + optional doctor fee
        $total = 0.0;
        // Services from recommended services + structured treatment plan service references.
        if (!empty($service_ids)) {
            foreach ($service_ids as $sid) {
                $sstmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ?');
                $sstmt->bind_param('i', $sid);
                $sstmt->execute();
                $res = $sstmt->get_result();
                if ($res && ($r = $res->fetch_assoc())) {
                    $total += (float)$r['initial_price'];
                }
                $sstmt->close();
            }
        }
        // Materials
        if ($materials_used && is_array($materials_used)) {
            foreach ($materials_used as $m) {
                $qty = isset($m['qty']) ? (float)$m['qty'] : 0;
                $price = isset($m['price']) ? (float)$m['price'] : 0;
                $total += $qty * $price;
            }
        }
        // Doctor fee (optional POST param)
        $doctor_fee = isset($_POST['doctor_fee']) ? (float)$_POST['doctor_fee'] : 0.0;
        if ($doctor_fee > 0) $total += $doctor_fee;

        // Insert or update billing record. If a billing exists for this appointment, update it instead of inserting.
        $bcheck = $conn->prepare('SELECT billing_id FROM billing WHERE appointment_id = ? LIMIT 1');
        if ($bcheck) {
            $bcheck->bind_param('i', $appointment_id);
            $bcheck->execute();
            $bres = $bcheck->get_result();
            $brow = $bres ? $bres->fetch_assoc() : null;
            $bcheck->close();
            if ($brow && !empty($brow['billing_id'])) {
                $bid = (int)$brow['billing_id'];
                $bust = $conn->prepare('UPDATE billing SET total_amount = ?, created_at = NOW() WHERE billing_id = ?');
                if ($bust) {
                    $bust->bind_param('di', $total, $bid);
                    $bust->execute();
                    $bust->close();
                }
            } else {
                $bstmt = $conn->prepare('INSERT INTO billing (appointment_id, user_id, total_amount, created_at) VALUES (?, ?, ?, NOW())');
                if (!$bstmt) throw new Exception('Prepare failed (billing insert): ' . $conn->error);
                $bstmt->bind_param('iid', $appointment_id, $patient_id, $total);
                $bstmt->execute();
                $bstmt->close();
            }
        } else {
            // fallback: try to insert
            $bstmt = $conn->prepare('INSERT INTO billing (appointment_id, user_id, total_amount, created_at) VALUES (?, ?, ?, NOW())');
            if ($bstmt) {
                $bstmt->bind_param('iid', $appointment_id, $patient_id, $total);
                $bstmt->execute();
                $bstmt->close();
            }
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Clinical record saved',
        'finalized' => $submit_and_finalize,
        'billing_amount' => isset($total) ? $total : null,
        'schedule_id' => $created_schedule_id,
        'followup_appointment_id' => $created_followup_appointment_id,
        // Do not prompt suggested scheduling after profiling submission
        'needs_scheduling' => false
    ]);
} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    // Log error for debugging
    $logDir = __DIR__ . '/../../../../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . '/save_clinical_record_error.log';
    $logEntry = date('Y-m-d H:i:s') . " - " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);

    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
