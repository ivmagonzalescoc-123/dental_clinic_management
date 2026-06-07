<?php
/**
 * Appointment Services Management API
 * 
 * Handles:
 * - Getting services available for appointment
 * - Setting/updating selected services for appointment
 * - Auto-calculating required timeslots based on service duration
 * - Doctor can modify services (add/remove)
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', dirname(__FILE__) . '/service-error.log');

session_start();
header('Content-Type: application/json');

try {
    include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
    include dirname(dirname(dirname(__DIR__))) . '/config/security.php';
    
    if (!$conn) throw new Exception('Database connection failed');

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // GET: Fetch available services
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'services') {
        $appointment_id = $_GET['appointment_id'] ?? 0;
        
        // Get all active services
        $services_sql = "SELECT 
            s.service_id, 
            s.service_name, 
            s.initial_price, 
            s.requires_schedule,
            s.default_duration_minutes,
            COALESCE(ts.duration_minutes, s.default_duration_minutes, 30) AS service_duration_minutes,
            s.is_active
        FROM services s
        LEFT JOIN (
            SELECT t1.service_id, t1.duration_minutes
            FROM timeslot_service t1
            INNER JOIN (
                SELECT service_id, MAX(id) AS max_id
                FROM timeslot_service
                GROUP BY service_id
            ) t2 ON t1.id = t2.max_id
        ) ts ON ts.service_id = s.service_id
        WHERE s.is_active = 1 
        ORDER BY s.service_name ASC";
        
        $services = [];
        $result = $conn->query($services_sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $services[] = $row;
            }
        }
        
        // Get selected services if appointment_id provided
        $selected_services = [];
        if ($appointment_id > 0) {
            $selected_sql = "SELECT 
                ss.selected_service_id,
                ss.service_id,
                s.service_name,
                s.initial_price,
                s.default_duration_minutes,
                COALESCE(ts.duration_minutes, s.default_duration_minutes, 30) AS service_duration_minutes
            FROM selected_services ss
            JOIN services s ON ss.service_id = s.service_id
            LEFT JOIN (
                SELECT t1.service_id, t1.duration_minutes
                FROM timeslot_service t1
                INNER JOIN (
                    SELECT service_id, MAX(id) AS max_id
                    FROM timeslot_service
                    GROUP BY service_id
                ) t2 ON t1.id = t2.max_id
            ) ts ON ts.service_id = s.service_id
            WHERE ss.appointment_id = ?
            ORDER BY ss.selected_service_id ASC";
            
            $stmt = $conn->prepare($selected_sql);
            if ($stmt) {
                $stmt->bind_param('i', $appointment_id);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $selected_services[] = $row;
                }
                $stmt->close();
            }
        }
        
        echo json_encode([
            'success' => true,
            'services' => $services,
            'selected_services' => $selected_services,
            'total_duration_minutes' => array_reduce($selected_services, function($carry, $item) {
                return $carry + (int)($item['service_duration_minutes'] ?? 0);
            }, 0)
        ]);
        exit;
    }
    
    // POST: Update selected services for appointment
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update-services') {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!security_validate_csrf_token($csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
        
        $appointment_id = $_POST['appointment_id'] ?? 0;
        $service_ids = $_POST['service_ids'] ?? [];
        
        if ($appointment_id <= 0) {
            throw new Exception('Invalid appointment ID');
        }
        
        if (!is_array($service_ids)) {
            $service_ids = [];
        }
        
        // Verify appointment exists and user is authorized
        $appt_sql = "SELECT a.appointment_id, a.patient_id, a.user_id FROM appointments a WHERE a.appointment_id = ?";
        $appt_stmt = $conn->prepare($appt_sql);
        if (!$appt_stmt) throw new Exception('Database error: ' . $conn->error);
        
        $appt_stmt->bind_param('i', $appointment_id);
        $appt_stmt->execute();
        $appt_res = $appt_stmt->get_result();
        $appointment = $appt_res->fetch_assoc();
        $appt_stmt->close();
        
        if (!$appointment) {
            throw new Exception('Appointment not found');
        }
        
        // Check authorization
        $user_id = $_SESSION['user_id'] ?? 0;
        $patient_id = $_SESSION['patient_id'] ?? 0;
        $role = $_SESSION['role'] ?? '';
        
        $is_patient = ($patient_id > 0 && $patient_id == $appointment['patient_id']);
        $is_doctor = ($user_id > 0 && $user_id == $appointment['user_id'] && $role === 'doctor');
        $is_admin = ($role === 'admin');
        
        if (!($is_patient || $is_doctor || $is_admin)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        // Clear existing selected services
        $delete_sql = "DELETE FROM selected_services WHERE appointment_id = ?";
        $del_stmt = $conn->prepare($delete_sql);
        if (!$del_stmt) throw new Exception('Database error: ' . $conn->error);
        $del_stmt->bind_param('i', $appointment_id);
        $del_stmt->execute();
        $del_stmt->close();
        
        // Insert new selected services
        $total_duration = 0;
        foreach ($service_ids as $service_id) {
            $service_id = (int)$service_id;
            if ($service_id > 0) {
                // Validate service exists and resolve duration (timeslot_service overrides default)
                $srv_check = $conn->prepare(
                    "SELECT COALESCE(ts.duration_minutes, s.default_duration_minutes, 30) AS service_duration_minutes
                     FROM services s
                     LEFT JOIN (
                         SELECT t1.service_id, t1.duration_minutes
                         FROM timeslot_service t1
                         INNER JOIN (
                             SELECT service_id, MAX(id) AS max_id
                             FROM timeslot_service
                             GROUP BY service_id
                         ) t2 ON t1.id = t2.max_id
                     ) ts ON ts.service_id = s.service_id
                     WHERE s.service_id = ? AND s.is_active = 1"
                );
                if (!$srv_check) throw new Exception('Database error: ' . $conn->error);
                
                $srv_check->bind_param('i', $service_id);
                $srv_check->execute();
                $srv_res = $srv_check->get_result();
                $service = $srv_res->fetch_assoc();
                $srv_check->close();
                
                if ($service) {
                    $total_duration += (int)($service['service_duration_minutes'] ?? 0);
                    
                    $insert_sql = "INSERT INTO selected_services (appointment_id, service_id) VALUES (?, ?)";
                    $ins_stmt = $conn->prepare($insert_sql);
                    if (!$ins_stmt) throw new Exception('Database error: ' . $conn->error);
                    
                    $ins_stmt->bind_param('ii', $appointment_id, $service_id);
                    $ins_stmt->execute();
                    $ins_stmt->close();
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Services updated successfully',
            'appointment_id' => $appointment_id,
            'total_duration_minutes' => $total_duration
        ]);
        exit;
    }
    
    // Fallback for invalid actions
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>
