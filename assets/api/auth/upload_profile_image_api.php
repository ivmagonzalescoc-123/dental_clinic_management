<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';
include '../../../config/capabilities.php';
include '../../../config/system_logs.php';
include_once '../../../config/profile_helpers.php';

security_start_session();
security_ensure_auth_columns($conn);
capabilities_require_json('profile.update_own');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

if (!profile_role_allows_custom_photo()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator accounts use the default clinic profile image and cannot upload a custom photo.']);
    exit;
}

if (!isset($_FILES['profile_image']) || !is_array($_FILES['profile_image'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No image file uploaded.']);
    exit;
}

$file = $_FILES['profile_image'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No image file uploaded.']);
    exit;
}

if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File upload failed. Please try again.']);
    exit;
}

$maxBytes = profile_photo_max_bytes();
if ((int)($file['size'] ?? 0) > $maxBytes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Image must be 2MB or smaller.']);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid upload payload.']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = $finfo ? (string)finfo_file($finfo, $tmpPath) : '';
if ($finfo) {
    finfo_close($finfo);
}

$allowed = profile_photo_allowed_mime_types();

if (!isset($allowed[$mimeType])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, or WEBP images are allowed.']);
    exit;
}

$role = strtolower((string)($_SESSION['role'] ?? ''));
$isPatient = isset($_SESSION['patient_id']) || $role === 'patient';
$table = $isPatient ? 'patients' : 'users';
$idColumn = $isPatient ? 'patient_id' : 'user_id';
$entityId = $isPatient ? (int)($_SESSION['patient_id'] ?? 0) : (int)($_SESSION['user_id'] ?? 0);

if ($entityId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
    exit;
}

$findSql = "SELECT profile_image FROM {$table} WHERE {$idColumn} = ? LIMIT 1";
$findStmt = $conn->prepare($findSql);
if (!$findStmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load current profile image.']);
    exit;
}
$findStmt->bind_param('i', $entityId);
$findStmt->execute();
$currentRow = $findStmt->get_result()->fetch_assoc();
$findStmt->close();

$uploadDir = dirname(dirname(__DIR__)) . '/uploads/profile_images/';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create upload directory.']);
    exit;
}

$prefix = $isPatient ? 'patient' : 'user';
$uniqueName = 'profile_' . $prefix . '_' . $entityId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mimeType];
$destPath = $uploadDir . $uniqueName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save profile image.']);
    exit;
}

$dbPath = 'assets/uploads/profile_images/' . $uniqueName;
$updSql = "UPDATE {$table} SET profile_image = ? WHERE {$idColumn} = ? LIMIT 1";
$updStmt = $conn->prepare($updSql);
if (!$updStmt) {
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update profile image.']);
    exit;
}
$updStmt->bind_param('si', $dbPath, $entityId);
$ok = $updStmt->execute();
$updStmt->close();

if (!$ok) {
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update profile image.']);
    exit;
}

profile_delete_stored_image($currentRow['profile_image'] ?? null);
$_SESSION['profile_image'] = $dbPath;

log_system_event(
    $conn,
    'profile.image_upload',
    $table,
    $entityId,
    'Profile image updated',
    $isPatient ? null : $entityId
);

echo json_encode([
    'success' => true,
    'message' => 'Profile photo updated successfully.',
    'profile_image' => $dbPath,
    'profile_image_url' => profile_session_image_url($conn, '..') ?: profile_resolve_image_url($dbPath, '..'),
]);
