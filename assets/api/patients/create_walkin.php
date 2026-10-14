<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();
header('Content-Type: application/json');
include dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
security_start_session();
check_session(['admin','secretary','doctor']);
try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/env.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/reminders.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/email_templates.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_profiling_helpers.php';
    include_once dirname(dirname(dirname(__DIR__))) . '/config/patient_input_helpers.php';
    security_ensure_auth_columns($conn);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrfToken)) {
        throw new Exception('Please refresh the page and try again.');
    }

    // The Add Patient form sends validate=strict. Converting a guest booking does not: the
    // secretary cannot correct what the guest typed there, so only the essentials are checked.
    $checked = patient_validate_details($_POST, ['strict' => ($_POST['validate'] ?? '') === 'strict']);
    if (!empty($checked['errors'])) {
        ob_end_clean();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => reset($checked['errors']), 'errors' => $checked['errors']]);
        exit;
    }
    $details = $checked['values'];
    $first_name = $details['first_name'];
    $middle_name = $details['middle_name'] !== '' ? $details['middle_name'] : 'N/A';
    $last_name = $details['last_name'];
    $date_of_birth = $details['date_of_birth']; // null when not given
    $gender = $details['gender'] !== '' ? $details['gender'] : 'N/A';
    $phone_number = $details['phone_number'];
    $email = $details['email'];
    $address = $details['address'];

    // Avoid duplicate records without merging different people. The email is the portal
    // login, so it belongs to one patient: the same email under the same name is that
    // patient again, under another name it is refused (it used to quietly return the other
    // patient, so a child's booking landed on the parent's record). A shared phone number
    // is common in a family, so a phone match counts only when the name matches too.
    $sameName = function (array $row) use ($first_name, $last_name): bool {
        return mb_strtolower(trim((string)$row['first_name'])) === mb_strtolower($first_name)
            && mb_strtolower(trim((string)$row['last_name'])) === mb_strtolower($last_name);
    };
    $alreadyExists = function (int $patientId) {
        ob_end_clean();
        echo json_encode([
            'success' => true,
            'message' => 'Patient already exists',
            'patient_id' => $patientId,
            'already_exists' => true,
        ]);
        exit;
    };

    $emailStmt = $conn->prepare("SELECT patient_id, first_name, last_name FROM patients WHERE email = ? AND email <> '' ORDER BY patient_id LIMIT 1");
    if ($emailStmt) {
        $emailStmt->bind_param('s', $email);
        $emailStmt->execute();
        $byEmail = $emailStmt->get_result()->fetch_assoc();
        $emailStmt->close();
        if ($byEmail) {
            if ($sameName($byEmail)) {
                $alreadyExists((int)$byEmail['patient_id']);
            }
            $owner = trim($byEmail['first_name'] . ' ' . $byEmail['last_name']);
            $inUse = 'This email is already used by ' . $owner . "'s account. Each patient needs their own email address.";
            ob_end_clean();
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $inUse, 'errors' => ['email' => $inUse], 'code' => 'email_in_use']);
            exit;
        }
    }

    if ($phone_number !== '') {
        $phoneStmt = $conn->prepare("SELECT patient_id, first_name, last_name FROM patients WHERE phone_number = ? AND phone_number <> ''");
        if ($phoneStmt) {
            $phoneStmt->bind_param('s', $phone_number);
            $phoneStmt->execute();
            $phoneRes = $phoneStmt->get_result();
            while ($row = $phoneRes->fetch_assoc()) {
                if ($sameName($row)) {
                    $phoneStmt->close();
                    $alreadyExists((int)$row['patient_id']);
                }
            }
            $phoneStmt->close();
        }
    }

    $added_by = $_SESSION['user_id'] ?? null;

    $generated_password = security_generate_default_password();
    $password_hash = security_hash_password($generated_password);
    $must_change_password = 1;

    $sql = "INSERT INTO patients (first_name, middle_name, last_name, password_hash, date_of_birth, gender, phone_number, email, address, added_by, must_change_password, password_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    // Database errors go to the log, not to the screen.
    if (!$stmt) {
        error_log('create_walkin: prepare failed: ' . $conn->error);
        throw new Exception('The patient could not be saved. Please try again.');
    }

    if (!$stmt->bind_param('sssssssssii', $first_name, $middle_name, $last_name, $password_hash, $date_of_birth, $gender, $phone_number, $email, $address, $added_by, $must_change_password)) {
        error_log('create_walkin: bind failed: ' . $stmt->error);
        throw new Exception('The patient could not be saved. Please try again.');
    }

    if (!$stmt->execute()) {
        error_log('create_walkin: insert failed: ' . $stmt->error);
        throw new Exception('The patient could not be saved. Please try again.');
    }

    $patient_id = (int)$conn->insert_id;
    $stmt->close();

    // Save background profiling (allergies, medical history, hypertensive/diabetic/etc.)
    // in the SAME request as patient creation, not a separate follow-up fetch — a later,
    // independent call is a silent single point of failure: if it throws or the network
    // hiccups, the patient account exists but the safety-relevant profiling data is lost
    // with no error visible anywhere (confirmed happening in practice).
    $profilingRaw = $_POST['profiling'] ?? '';
    if ($profilingRaw !== '') {
        $profilingData = json_decode($profilingRaw, true);
        if (is_array($profilingData)) {
            $profiling = filter_background_profiling_data($profilingData);
            if (!empty($profiling)) {
                $na = 'N/A';
                $p_race = $profiling['race'] ?: $na;
                $p_medical_history = $profiling['medical_history'] ?: $na;
                $p_medications = $profiling['medications'] ?: $na;
                $p_allergies = $profiling['allergies'] ?: $na;
                $p_smoking = $profiling['smoking'] ?: $na;
                $p_alcohol = $profiling['alcohol'] ?: $na;
                $p_hypertensive = (int)($profiling['hypertensive'] ?? 0);
                $p_diabetic = (int)($profiling['diabetic'] ?? 0);
                $p_asthmatic = (int)($profiling['asthmatic'] ?? 0);
                $p_heart_disease = (int)($profiling['heart_disease'] ?? 0);
                $p_hiv_positive = (int)($profiling['hiv_positive'] ?? 0);
                $p_bleeding_disorder = $profiling['bleeding_disorder'] ?: $na;
                // Male patients never see the pregnancy field in the form — don't backfill
                // it with a meaningless "N/A" default; leave it out of the record entirely.
                $p_pregnancy_status = ($gender === 'Male') ? null : ($profiling['pregnancy_status'] ?: $na);
                $p_previous_dental_treatment = $profiling['previous_dental_treatment'] ?: $na;
                $p_dental_anxiety = $profiling['dental_anxiety'] ?: $na;
                $p_background_notes = $profiling['background_notes'] ?: $na;

                $pfStmt = $conn->prepare('INSERT INTO patient_profiling (patient_id, race, medical_history, medications, allergies, smoking, alcohol, is_hypertensive, is_diabetic, is_asthmatic, has_heart_disease, is_hiv_positive, bleeding_disorder, pregnancy_status, previous_dental_treatment, dental_anxiety, background_notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                if ($pfStmt) {
                    $pfStmt->bind_param(
                        'issssssiiiiisssss',
                        $patient_id,
                        $p_race,
                        $p_medical_history,
                        $p_medications,
                        $p_allergies,
                        $p_smoking,
                        $p_alcohol,
                        $p_hypertensive,
                        $p_diabetic,
                        $p_asthmatic,
                        $p_heart_disease,
                        $p_hiv_positive,
                        $p_bleeding_disorder,
                        $p_pregnancy_status,
                        $p_previous_dental_treatment,
                        $p_dental_anxiety,
                        $p_background_notes
                    );
                    if (!$pfStmt->execute()) {
                        error_log('create_walkin: failed to save profiling for patient ' . $patient_id . ': ' . $pfStmt->error);
                    }
                    $pfStmt->close();
                }
            }
        }
    }

    $patientName = trim($first_name . ' ' . (($middle_name !== '' && strtoupper($middle_name) !== 'N/A') ? $middle_name . ' ' : '') . $last_name);
    $subject = 'Patient Account - ' . email_clinic_name();
    $message = 'Welcome ' . $patientName . '! Your account has been created. Email: ' . $email . ' | Temporary Password: ' . $generated_password . '. Please change your password after logging in.';
    $htmlMessage = email_patient_account_created_html($patientName, $email, $generated_password);

    $emailSent = reminder_send_email($email, $subject, $message, $htmlMessage);

    $emailMode = app_email_mode();
    if (!$emailSent && $emailMode === 'brevo' && function_exists('reminder_send_email_smtp')) {
        $smtpHost = trim((string)env('SMTP_HOST', ''));
        $smtpUser = trim((string)env('SMTP_USER', ''));
        $smtpPass = (string)env('SMTP_PASS', '');
        if ($smtpHost !== '' && $smtpUser !== '' && $smtpPass !== '') {
            $from = trim((string)env('BREVO_EMAIL_FROM', env('SMTP_FROM_EMAIL', '')));
            $fromName = trim((string)env('BREVO_EMAIL_FROM_NAME', env('SMTP_FROM_NAME', "Azucena's Dental Clinic")));
            $emailSent = reminder_send_email_smtp($email, $subject, $message, $from, $fromName, $htmlMessage);
        }
    }

    $response = [
        'success' => true,
        'message' => $emailSent
            ? 'Patient account created. Login credentials were sent to their email.'
            : 'Patient account created, but the welcome email could not be sent. Check email settings.',
        'patient_id' => $patient_id,
        'email_sent' => $emailSent,
    ];

    if (!$emailSent) {
        $response['email_warning'] = 'Welcome email failed to send. Verify Brevo/SMTP settings in .env.';
    }

    ob_end_clean();
    echo json_encode($response);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}
