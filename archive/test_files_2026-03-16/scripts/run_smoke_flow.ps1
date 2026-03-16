$ErrorActionPreference = 'Stop'
Set-Location -Path (Split-Path -Parent $PSScriptRoot)

$mysql = 'C:\xampp\mysql\bin\mysql.exe'
$php = 'C:\xampp\php\php.exe'

if (!(Test-Path $mysql)) { throw "MySQL client not found at $mysql" }
if (!(Test-Path $php)) { throw "PHP not found at $php" }

function SqlScalar([string]$query) {
    $out = & $mysql -u root -pCapstone_123 -D capstone_dental -N -s -e $query
    if ($LASTEXITCODE -ne 0) { throw "SQL failed: $query" }
    return ($out | Select-Object -First 1).Trim()
}

function SqlExec([string]$query) {
    & $mysql -u root -pCapstone_123 -D capstone_dental -e $query | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "SQL failed: $query" }
}

function Invoke-Endpoint([string]$endpoint, [string]$role, [int]$userId, [hashtable]$payload) {
    $json = $payload | ConvertTo-Json -Compress -Depth 8
    $jsonBytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    $jsonB64 = [System.Convert]::ToBase64String($jsonBytes)
    $raw = & $php scripts/smoke_invoke_endpoint.php $endpoint $role $userId $jsonB64
    $text = ($raw -join "`n").Trim()
    if ([string]::IsNullOrWhiteSpace($text)) {
        throw "Endpoint returned empty output: $endpoint"
    }
    try {
        return $text | ConvertFrom-Json
    } catch {
        throw "Non-JSON endpoint output for ${endpoint}: $text"
    }
}

$appointmentId = 0
$timeslotId = 0
$doctorId = 0
$slotDate = ''
$createdTimeslot = $false

try {
    $adminId = [int](SqlScalar "SELECT u.user_id FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'admin' ORDER BY u.user_id ASC LIMIT 1;")
    if ($adminId -le 0) {
        $adminId = [int](SqlScalar "SELECT u.user_id FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'secretary' ORDER BY u.user_id ASC LIMIT 1;")
    }
    if ($adminId -le 0) { throw "No admin/secretary user available for arrivals/confirm tests." }

    $serviceId = [int](SqlScalar "SELECT service_id FROM services WHERE is_active = 1 ORDER BY service_id ASC LIMIT 1;")
    if ($serviceId -le 0) { throw "No active service found." }

    $patientId = [int](SqlScalar "SELECT patient_id FROM patients ORDER BY patient_id ASC LIMIT 1;")
    if ($patientId -le 0) { throw "No patient found." }

    # Prefer a regular doctor to satisfy availability guard on any non-Sunday date.
    $doctorId = [int](SqlScalar "SELECT u.user_id FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'doctor' AND COALESCE(u.work_type, 'regular') = 'regular' ORDER BY u.user_id ASC LIMIT 1;")

    if ($doctorId -gt 0) {
        $slotDate = SqlScalar @"
SELECT DATE_FORMAT(d.dt, '%Y-%m-%d')
FROM (
    SELECT DATE_ADD(CURDATE(), INTERVAL 1 DAY) AS dt
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 2 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 4 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 5 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 6 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 8 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 9 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 10 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 11 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 12 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 13 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 14 DAY)
) d
WHERE DAYOFWEEK(d.dt) <> 1
  AND NOT EXISTS (
      SELECT 1
      FROM doctor_leaves l
      WHERE l.user_id = $doctorId
        AND d.dt BETWEEN l.leave_start_date AND l.leave_end_date
        AND l.status = 'approved'
  )
ORDER BY d.dt ASC
LIMIT 1;
"@
    }

    # Fallback: part-time doctor with explicit weekday availability.
    if ($doctorId -le 0 -or [string]::IsNullOrWhiteSpace($slotDate)) {
        $availRow = SqlScalar "SELECT CONCAT(da.user_id, '|', da.weekday) FROM doctor_availability da INNER JOIN users u ON u.user_id = da.user_id INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'doctor' AND da.weekday BETWEEN 1 AND 6 ORDER BY da.user_id ASC LIMIT 1;"
        if ([string]::IsNullOrWhiteSpace($availRow)) {
            throw "No doctor with usable availability found."
        }

        $aparts = $availRow.Split('|')
        if ($aparts.Count -lt 2) { throw "Unexpected availability row format: $availRow" }

        $doctorId = [int]$aparts[0]
        $weekday = [int]$aparts[1]
        $weekdayIndex = ($weekday + 6) % 7

        $slotDate = SqlScalar @"
SELECT DATE_FORMAT(d.dt, '%Y-%m-%d')
FROM (
    SELECT DATE_ADD(CURDATE(), INTERVAL 1 DAY) AS dt
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 2 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 4 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 5 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 6 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 8 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 9 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 10 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 11 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 12 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 13 DAY)
    UNION ALL SELECT DATE_ADD(CURDATE(), INTERVAL 14 DAY)
) d
WHERE WEEKDAY(d.dt) = $weekdayIndex
  AND DAYOFWEEK(d.dt) <> 1
  AND NOT EXISTS (
      SELECT 1
      FROM doctor_leaves l
      WHERE l.user_id = $doctorId
        AND d.dt BETWEEN l.leave_start_date AND l.leave_end_date
        AND l.status = 'approved'
  )
ORDER BY d.dt ASC
LIMIT 1;
"@
    }

    if ($doctorId -le 0 -or [string]::IsNullOrWhiteSpace($slotDate)) {
        throw "Unable to determine a bookable doctor/date for smoke test."
    }

    # Reuse existing open slot first; create a disposable one only if needed.
    $timeslotId = [int](SqlScalar "SELECT timeslot_id FROM timeslot WHERE doctor_id = $doctorId AND slot_date = '$slotDate' AND status = 'available' ORDER BY start_time ASC LIMIT 1;")
    if ($timeslotId -le 0) {
        $timeslotId = [int](SqlScalar "INSERT INTO timeslot (doctor_id, slot_date, start_time, end_time, status, appointment_id, created_at) VALUES ($doctorId, '$slotDate', '09:00:00', '09:30:00', 'available', NULL, NOW()); SELECT LAST_INSERT_ID();")
        if ($timeslotId -le 0) { throw "Failed to prepare smoke timeslot." }
        $createdTimeslot = $true
    }

    $appointmentId = [int](SqlScalar "INSERT INTO appointments (appointment_date, timeslot_id, service_id, reason, is_online_appointment, notes, status, created_at, user_id, patient_id, is_emergency) VALUES ('$slotDate', NULL, $serviceId, 'SMOKE FLOW TEST', 0, 'smoke-sequence', 'scheduled', NOW(), $doctorId, $patientId, 0); SELECT LAST_INSERT_ID();")
    if ($appointmentId -le 0) { throw "Failed to create smoke appointment." }

    $confirm = Invoke-Endpoint 'assets/api/appointments/confirm_appointment.php' 'admin' $adminId @{
        appointment_id = $appointmentId
        action = 'accept'
        timeslot_id = $timeslotId
    }
    if (-not $confirm.success) { throw "Confirm failed: $($confirm.message)" }

    $postConfirm = SqlScalar "SELECT CONCAT(status,'|',IFNULL(timeslot_id,0),'|',(SELECT COUNT(*) FROM patient_schedules WHERE appointment_id = $appointmentId)) FROM appointments WHERE appointment_id = $appointmentId LIMIT 1;"
    $postConfirmParts = $postConfirm.Split('|')
    if ($postConfirmParts[0] -ne 'confirmed') { throw "Unexpected status after confirm: $($postConfirmParts[0])" }
    if ([int]$postConfirmParts[1] -ne $timeslotId) { throw "timeslot_id not linked after confirm." }
    if ([int]$postConfirmParts[2] -lt 1) { throw "No patient_schedules row after confirm." }

    $arrived = Invoke-Endpoint 'assets/api/arrivals/mark_arrived.php' 'admin' $adminId @{
        appointment_id = $appointmentId
    }
    if (-not $arrived.success) { throw "Mark arrived failed: $($arrived.message)" }

    $statusAfterArrival = SqlScalar "SELECT status FROM appointments WHERE appointment_id = $appointmentId LIMIT 1;"
    if ($statusAfterArrival -ne 'checked_in') { throw "Unexpected status after arrival: $statusAfterArrival" }

    $sessionStart = Invoke-Endpoint 'assets/api/arrivals/start_session.php' 'doctor' $doctorId @{
        appointment_id = $appointmentId
    }
    if (-not $sessionStart.success) { throw "Start session failed: $($sessionStart.message)" }

    $statusAfterStart = SqlScalar "SELECT status FROM appointments WHERE appointment_id = $appointmentId LIMIT 1;"
    if ($statusAfterStart -ne 'in_progress') { throw "Unexpected status after start_session: $statusAfterStart" }

    $complete = Invoke-Endpoint 'assets/api/ehr/complete_visit.php' 'doctor' $doctorId @{
        appointment_id = $appointmentId
        service_id = $serviceId
        notes = 'smoke-complete'
    }
    if (-not $complete.success) { throw "Complete visit failed: $($complete.message)" }

    $finalRow = SqlScalar "SELECT CONCAT((SELECT status FROM appointments WHERE appointment_id = $appointmentId LIMIT 1),'|',(SELECT COUNT(*) FROM billing WHERE appointment_id = $appointmentId))"
    $finalParts = $finalRow.Split('|')
    if ($finalParts[0] -ne 'completed') { throw "Unexpected final status: $($finalParts[0])" }
    if ([int]$finalParts[1] -lt 1) { throw "Billing row not created." }

    Write-Output ("SMOKE_OK appointment_id={0} timeslot_id={1} doctor_id={2} admin_id={3}" -f $appointmentId, $timeslotId, $doctorId, $adminId)
}
finally {
    if ($appointmentId -gt 0 -or $timeslotId -gt 0) {
        try {
            if ($timeslotId -gt 0) {
                SqlExec "UPDATE timeslot SET status = 'available', appointment_id = NULL WHERE timeslot_id = $timeslotId;"
            }
            if ($appointmentId -gt 0) {
                SqlExec "DELETE FROM billing WHERE appointment_id = $appointmentId;"
                SqlExec "DELETE FROM clinical_records WHERE appointment_id = $appointmentId;"
                SqlExec "DELETE FROM patient_schedules WHERE appointment_id = $appointmentId;"
                SqlExec "DELETE FROM appointments WHERE appointment_id = $appointmentId;"
            }
            if ($createdTimeslot -and $timeslotId -gt 0) {
                SqlExec "DELETE FROM timeslot WHERE timeslot_id = $timeslotId AND appointment_id IS NULL AND status = 'available';"
            }
            Write-Output "SMOKE_CLEANUP_OK"
        } catch {
            Write-Output ("SMOKE_CLEANUP_WARNING " + $_.Exception.Message)
        }
    }
}
