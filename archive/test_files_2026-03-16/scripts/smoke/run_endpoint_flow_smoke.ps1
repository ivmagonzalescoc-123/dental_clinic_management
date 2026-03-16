Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$workspace = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location -Path $workspace

$mysqlExe = 'C:\xampp\mysql\bin\mysql.exe'
$phpExe = 'C:\xampp\php\php.exe'

if (!(Test-Path $mysqlExe)) { throw "mysql.exe not found at $mysqlExe" }
if (!(Test-Path $phpExe)) { throw "php.exe not found at $phpExe" }

function Invoke-MySqlScalar {
    param([Parameter(Mandatory = $true)][string]$Sql)
    $raw = & $mysqlExe -u root -pCapstone_123 -D capstone_dental -N -B -e $Sql 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "MySQL scalar query failed: $Sql`n$($raw -join "`n")"
    }
    $lines = @($raw | ForEach-Object { $_.ToString().Trim() } | Where-Object { $_ -ne '' })
    if ($lines.Count -eq 0) { return '' }
    return $lines[$lines.Count - 1]
}

function Invoke-MySqlExec {
    param([Parameter(Mandatory = $true)][string]$Sql)
    $raw = & $mysqlExe -u root -pCapstone_123 -D capstone_dental -e $Sql 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "MySQL exec failed: $Sql`n$($raw -join "`n")"
    }
}

function Invoke-Endpoint {
    param(
        [Parameter(Mandatory = $true)][string]$RelativePath,
        [Parameter(Mandatory = $true)][string]$Role,
        [Parameter(Mandatory = $true)][int]$UserId,
        [Parameter(Mandatory = $true)][hashtable]$PostData
    )

    $postLines = New-Object System.Collections.Generic.List[string]
    foreach ($k in $PostData.Keys) {
        $v = $PostData[$k]
        if ($null -eq $v) {
            $postLines.Add("`$_POST['$k'] = null;")
        } elseif ($v -is [int] -or $v -is [long] -or $v -is [double]) {
            $postLines.Add("`$_POST['$k'] = $v;")
        } else {
            $safe = $v.ToString().Replace("\\", "\\\\").Replace("'", "\\'")
            $postLines.Add("`$_POST['$k'] = '$safe';")
        }
    }

    $endpointPath = (Join-Path $workspace $RelativePath).Replace('\\', '/')
    $phpScript = @"
<?php
session_id('smoke-' . bin2hex(random_bytes(8)));
session_start();
`$_SESSION['role'] = '$Role';
`$_SESSION['user_id'] = $UserId;
`$_SERVER['REQUEST_METHOD'] = 'POST';
"@
    $phpScript += ($postLines -join "`r`n") + "`r`n"
    $phpScript += "include '$endpointPath';`r`n"

    $tmpFile = Join-Path $env:TEMP ("smoke_" + [Guid]::NewGuid().ToString('N') + '.php')
    try {
        Set-Content -Path $tmpFile -Value $phpScript -Encoding ASCII
        $raw = & $phpExe $tmpFile 2>&1
        if ($LASTEXITCODE -ne 0) {
            throw "Endpoint execution failed ($RelativePath):`n$($raw -join "`n")"
        }
        return ($raw -join "`n").Trim()
    }
    finally {
        if (Test-Path $tmpFile) {
            Remove-Item -Path $tmpFile -Force -ErrorAction SilentlyContinue
        }
    }
}

$timeslotId = 0
$appointmentId = 0

try {
    $doctorId = [int](Invoke-MySqlScalar "SELECT u.user_id FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'doctor' ORDER BY u.user_id ASC LIMIT 1")
    $staffId  = [int](Invoke-MySqlScalar "SELECT u.user_id FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name IN ('secretary','admin') ORDER BY CASE WHEN r.role_name='secretary' THEN 0 ELSE 1 END, u.user_id ASC LIMIT 1")
    $patientId = [int](Invoke-MySqlScalar "SELECT patient_id FROM patients ORDER BY patient_id ASC LIMIT 1")
    $serviceId = [int](Invoke-MySqlScalar "SELECT service_id FROM services ORDER BY service_id ASC LIMIT 1")

    if ($doctorId -le 0 -or $staffId -le 0 -or $patientId -le 0 -or $serviceId -le 0) {
        throw "Missing required seed data (doctor/staff/patient/service)."
    }

    $slotDate = ''
    $slotStart = ''
    $slotEnd = ''

    for ($d = 40; $d -le 55 -and $slotStart -eq ''; $d++) {
        $candidateDate = (Get-Date).AddDays($d).ToString('yyyy-MM-dd')
        for ($h = 9; $h -le 16 -and $slotStart -eq ''; $h++) {
            foreach ($m in @(0, 30)) {
                $start = ('{0:D2}:{1:D2}:00' -f $h, $m)
                $exists = [int](Invoke-MySqlScalar "SELECT COUNT(*) FROM timeslot WHERE doctor_id = $doctorId AND slot_date = '$candidateDate' AND start_time = '$start'")
                if ($exists -eq 0) {
                    $slotDate = $candidateDate
                    $slotStart = $start
                    $slotEnd = ([DateTime]::ParseExact($start, 'HH:mm:ss', $null)).AddMinutes(30).ToString('HH:mm:ss')
                    break
                }
            }
        }
    }

    if ($slotStart -eq '') {
        throw 'Unable to find a free test timeslot.'
    }

    $timeslotId = [int](Invoke-MySqlScalar "INSERT INTO timeslot (doctor_id, slot_date, start_time, end_time, status, appointment_id) VALUES ($doctorId, '$slotDate', '$slotStart', '$slotEnd', 'available', NULL); SELECT LAST_INSERT_ID();")
    if ($timeslotId -le 0) {
        throw 'Failed creating test timeslot.'
    }

    $appointmentId = [int](Invoke-MySqlScalar "INSERT INTO appointments (appointment_date, timeslot_id, service_id, reason, is_online_appointment, notes, status, user_id, patient_id, is_emergency) VALUES ('$slotDate', NULL, $serviceId, 'Smoke endpoint flow', 0, 'smoke', 'scheduled', $doctorId, $patientId, 0); SELECT LAST_INSERT_ID();")
    if ($appointmentId -le 0) {
        throw 'Failed creating test appointment.'
    }

    Write-Output "[SETUP] doctor_id=$doctorId staff_id=$staffId patient_id=$patientId service_id=$serviceId timeslot_id=$timeslotId appointment_id=$appointmentId"

    $confirmOut = Invoke-Endpoint -RelativePath 'assets/api/appointments/confirm_appointment.php' -Role 'secretary' -UserId $staffId -PostData @{ appointment_id = $appointmentId; timeslot_id = $timeslotId; action = 'accept' }
    Write-Output "[STEP1 confirm_appointment] $confirmOut"

    $status = Invoke-MySqlScalar "SELECT status FROM appointments WHERE appointment_id = $appointmentId"
    $linkedTimeslot = [int](Invoke-MySqlScalar "SELECT COALESCE(timeslot_id, 0) FROM appointments WHERE appointment_id = $appointmentId")
    $schedCount = [int](Invoke-MySqlScalar "SELECT COUNT(*) FROM patient_schedules WHERE appointment_id = $appointmentId")
    $remCount = [int](Invoke-MySqlScalar "SELECT COUNT(*) FROM schedule_reminders r INNER JOIN patient_schedules ps ON ps.schedule_id = r.patient_schedule_id WHERE ps.appointment_id = $appointmentId")
    if ($status -ne 'confirmed' -or $linkedTimeslot -ne $timeslotId -or $schedCount -lt 1 -or $remCount -lt 1) {
        throw "Step 1 validation failed (status=$status, linkedTimeslot=$linkedTimeslot, schedules=$schedCount, reminders=$remCount)."
    }

    $arrivedOut = Invoke-Endpoint -RelativePath 'assets/api/arrivals/mark_arrived.php' -Role 'secretary' -UserId $staffId -PostData @{ appointment_id = $appointmentId }
    Write-Output "[STEP2 mark_arrived] $arrivedOut"

    $status = Invoke-MySqlScalar "SELECT status FROM appointments WHERE appointment_id = $appointmentId"
    if ($status -ne 'checked_in') {
        throw "Step 2 validation failed (status=$status)."
    }

    $startOut = Invoke-Endpoint -RelativePath 'assets/api/arrivals/start_session.php' -Role 'doctor' -UserId $doctorId -PostData @{ appointment_id = $appointmentId }
    Write-Output "[STEP3 start_session] $startOut"

    $status = Invoke-MySqlScalar "SELECT status FROM appointments WHERE appointment_id = $appointmentId"
    if ($status -ne 'in_progress') {
        throw "Step 3 validation failed (status=$status)."
    }

    $completeOut = Invoke-Endpoint -RelativePath 'assets/api/ehr/complete_visit.php' -Role 'doctor' -UserId $doctorId -PostData @{ appointment_id = $appointmentId; service_id = $serviceId; notes = 'smoke complete' }
    Write-Output "[STEP4 complete_visit] $completeOut"

    $status = Invoke-MySqlScalar "SELECT status FROM appointments WHERE appointment_id = $appointmentId"
    $billingCount = [int](Invoke-MySqlScalar "SELECT COUNT(*) FROM billing WHERE appointment_id = $appointmentId")
    if ($status -ne 'completed' -or $billingCount -lt 1) {
        throw "Step 4 validation failed (status=$status, billingCount=$billingCount)."
    }

    Write-Output '[PASS] Smoke endpoint flow succeeded in sequence: confirm -> check-in -> start session -> complete visit.'
}
finally {
    if ($appointmentId -gt 0) {
        try { Invoke-MySqlExec "DELETE r FROM schedule_reminders r INNER JOIN patient_schedules ps ON ps.schedule_id = r.patient_schedule_id WHERE ps.appointment_id = $appointmentId" } catch {}
        try { Invoke-MySqlExec "DELETE FROM patient_schedules WHERE appointment_id = $appointmentId" } catch {}
        try { Invoke-MySqlExec "DELETE FROM billing WHERE appointment_id = $appointmentId" } catch {}
        try { Invoke-MySqlExec "UPDATE timeslot SET appointment_id = NULL, status = 'available' WHERE timeslot_id = $timeslotId" } catch {}
        try { Invoke-MySqlExec "DELETE FROM appointments WHERE appointment_id = $appointmentId" } catch {}
    }
    if ($timeslotId -gt 0) {
        try { Invoke-MySqlExec "DELETE FROM timeslot WHERE timeslot_id = $timeslotId" } catch {}
    }
    Write-Output '[CLEANUP] Disposable smoke data removed.'
}
