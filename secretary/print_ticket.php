<?php
include dirname(__DIR__) . '/config/database.php';

$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
$queue_number = isset($_GET['queue_number']) ? (int)$_GET['queue_number'] : 0;

if (!$appointment_id) {
    echo "Invalid appointment.";
    exit;
}

// Fetch appointment, patient and doctor info
$stmt = $conn->prepare("SELECT a.appointment_id, a.appointment_date, a.reason, a.status, p.first_name AS p_first, p.last_name AS p_last, p.phone_number AS p_phone, u.first_name AS d_first, u.last_name AS d_last FROM appointments a LEFT JOIN patients p ON a.patient_id = p.patient_id LEFT JOIN users u ON a.user_id = u.user_id WHERE a.appointment_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = $res ? $res->fetch_assoc() : null;
    $stmt->close();
} else {
    $data = null;
}

if (!$data) {
    echo "Appointment not found.";
    exit;
}

$clinicName = "Azucena's Dental Clinic";
$patientName = htmlspecialchars($data['p_first'] . ' ' . $data['p_last']);
$doctorName = htmlspecialchars(($data['d_first'] ?? '') . ' ' . ($data['d_last'] ?? ''));
$apptDate = htmlspecialchars($data['appointment_date']);
$reason = htmlspecialchars($data['reason']);
$queue_number = (int)$queue_number;

?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Queue Ticket - <?php echo $clinicName; ?></title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; padding: 20px; }
        .ticket { width: 320px; border: 1px dashed #333; padding: 16px; text-align: center; }
        .clinic { font-size: 18px; font-weight: bold; margin-bottom: 8px; }
        .queue { font-size: 48px; font-weight: 900; color: #c00; margin: 12px 0; }
        .meta { font-size: 12px; color: #333; margin-top: 8px; }
        @media print { .no-print { display:none; } }
        .btn { display:inline-block; padding:8px 12px; background:#007bff; color:#fff; text-decoration:none; border-radius:4px; margin-top:12px; }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="clinic"><?php echo $clinicName; ?></div>
        <div>Patient</div>
        <div><strong><?php echo $patientName; ?></strong></div>
        <div class="meta">Doctor: <?php echo $doctorName; ?></div>
        <div class="meta">Date: <?php echo $apptDate; ?></div>
        <div class="meta">Reason: <?php echo $reason; ?></div>

        <div class="queue"><?php echo $queue_number ? 'Q' . $queue_number : 'Q-'; ?></div>

        <div class="meta">Please wait for your number to be called.</div>

        <a class="btn no-print" href="#" onclick="window.print();return false;">Print</a>
        <a class="btn no-print" href="javascript:window.close();" style="background:#6c757d;margin-left:8px;">Close</a>
    </div>

    <script>
        // Auto-print when opened
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
