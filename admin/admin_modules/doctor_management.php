<?php
$doctorTab = trim((string)($_GET['tab'] ?? 'schedule'));
$allowedDoctorTabs = ['schedule', 'leaves', 'deployment'];
if (!in_array($doctorTab, $allowedDoctorTabs, true)) {
    $doctorTab = 'schedule';
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h5 class="mb-0"><i class="bi bi-calendar2-check me-2"></i>Doctor Management</h5>
    <span class="text-muted small">Manage doctor schedule visibility, leave approvals, and worktype settings in one place.</span>
</div>

<nav class="module-section-nav" aria-label="Doctor management sections">
    <div class="module-section-nav__track">
        <ul class="nav nav-pills mb-0" role="tablist">
            <li class="nav-item" role="presentation">
                <a class="nav-link <?php echo $doctorTab === 'schedule' ? 'active' : ''; ?>" href="admin.php?page=doctor_management&tab=schedule">Doctor Schedule</a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?php echo $doctorTab === 'leaves' ? 'active' : ''; ?>" href="admin.php?page=doctor_management&tab=leaves">Doctor Leaves</a>
            </li>
            <li class="nav-item" role="presentation">
                <a class="nav-link <?php echo $doctorTab === 'deployment' ? 'active' : ''; ?>" href="admin.php?page=doctor_management&tab=deployment">Doctor Worktype</a>
            </li>
        </ul>
    </div>
</nav>

<?php
if ($doctorTab === 'schedule') {
    include 'doctor_schedule_admin.php';
} elseif ($doctorTab === 'leaves') {
    $doctorManagementMode = true;
    include 'doctor_leaves.php';
} else {
    include 'doctor_deployment.php';
}
?>
