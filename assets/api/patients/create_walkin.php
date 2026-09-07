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
    security_ensure_auth_columns($conn);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrfToken)) {
        throw new Exception('Please refresh the page and try again.');
    }

    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (empty($first_name) || empty($last_name)) {
        throw new Exception('First name and last name are required');
    }

    if ($email === '') {
        throw new Exception('Email is required to create a patient portal account.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address.');
    }

    // Check by email or phone to avoid duplicate patient records.
    $identifier_sql = 'SELECT patient_id FROM patients WHERE (email = ? AND email != "") OR (phone_number = ? AND phone_number != "") LIMIT 1';
    $identifier_stmt = $conn->prepare($identifier_sql);
    if ($identifier_stmt) {
        $identifier_stmt->bind_param('ss', $email, $phone_number);
        $identifier_stmt->execute();
        $res = $identifier_stmt->get_result();
        if ($res && ($existing = $res->fetch_assoc())) {
            echo json_encode([
                'success' => true,
                'message' => 'Patient already exists',
                'patient_id' => (int)$existing['patient_id'],
                'already_exists' => true,
            ]);
            exit;
        }
        $identifier_stmt->close();
    }

    $added_by = $_SESSION['user_id'] ?? null;

    $generated_password = security_generate_default_password();
    $password_hash = security_hash_password($generated_password);
    $must_change_password = 1;

    $sql = "INSERT INTO patients (first_name, middle_name, last_name, password_hash, date_of_birth, gender, phone_number, email, address, added_by, must_change_password, password_updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    if (!$stmt->bind_param('sssssssssii', $first_name, $middle_name, $last_name, $password_hash, $date_of_birth, $gender, $phone_number, $email, $address, $added_by, $must_change_password)) {
        throw new Exception('Bind failed: ' . $stmt->error);
    }

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
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

    $patientName = trim($first_name . ' ' . ($middle_name !== '' ? $middle_name . ' ' : '') . $last_name);
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
