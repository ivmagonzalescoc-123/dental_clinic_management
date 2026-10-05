<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../config/security.php';

security_start_session();

// Holds license numbers and signature images, so staff only.
if (!in_array($_SESSION['role'] ?? '', ['doctor', 'secretary', 'admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$doctor_id = (int)($_GET['doctor_id'] ?? 0);

if ($doctor_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid doctor ID']);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM doctor_prescription_profiles WHERE user_id = ? LIMIT 1");
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    exit;
}
$stmt->bind_param('i', $doctor_id);
$stmt->execute();
$res = $stmt->get_result();
$profile = $res ? $res->fetch_assoc() : null;
$stmt->close();

if ($profile) {
    // Parse licenses JSON for backward compatibility
    $licenses = !empty($profile['licenses']) ? json_decode($profile['licenses'], true) : [];
    echo json_encode(['success' => true, 'profile' => $profile, 'licenses' => $licenses]);
} else {
    // Return default info from users table
    $stmt = $conn->prepare("SELECT first_name, last_name FROM users WHERE user_id = ? AND role_id = 2 LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $doctor_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $doc = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        
        if ($doc) {
            echo json_encode([
                'success' => true, 
                'profile' => [
                    'full_name' => 'Dr. ' . $doc['first_name'] . ' ' . $doc['last_name'],
                    'professional_title' => 'DMD',
                    'licenses' => [],
                    'signature_image' => '',
                    'signature_drawn' => '',
                    'clinic_name' => 'Azucena\'s Dental Clinic',
                    'clinic_address' => 'Metz Arcade, Cagayan de Oro City, Philippines',
                    'clinic_phone' => '0917 984 3031',
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Doctor not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
    }
}