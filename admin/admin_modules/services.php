<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

if (!function_exists('services_redirect')) {
    function services_redirect($tab = 'services')
    {
        $safeTab = in_array($tab, ['services', 'archived', 'timeslot', 'consultation'], true) ? $tab : 'services';
        header('Location: admin.php?page=services&tab=' . rawurlencode($safeTab));
        exit;
    }
}

$tab = $_GET['tab'] ?? 'services';
if (!in_array($tab, ['services', 'archived', 'timeslot', 'consultation'], true)) {
    $tab = 'services';
}

// Detect whether services has soft-archive support
$hasIsActive = false;
$colResult = $conn->query("SHOW COLUMNS FROM services LIKE 'is_active'");
if ($colResult && $colResult->num_rows > 0) {
    $hasIsActive = true;
}

$hasDefaultDuration = false;
$durCol = $conn->query("SHOW COLUMNS FROM services LIKE 'default_duration_minutes'");
if ($durCol && $durCol->num_rows > 0) {
    $hasDefaultDuration = true;
}

// Ensure timeslot_service exists for smart scheduling duration lookup.
$conn->query(
    "CREATE TABLE IF NOT EXISTS timeslot_service (
        id INT(11) NOT NULL AUTO_INCREMENT,
        service_id INT(11) NOT NULL,
        duration_minutes INT(11) NOT NULL,
        buffer_minutes INT(11) DEFAULT 5,
        slots_per_day_limit INT(11) DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_timeslot_service_service (service_id),
        CONSTRAINT fk_timeslot_service_service FOREIGN KEY (service_id) REFERENCES services(service_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$action = $_GET['action'] ?? '';

// Handle ADD service
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceName = trim((string)($_POST['service_name'] ?? ''));
    $initialPrice = (string)($_POST['initial_price'] ?? '0');
    $description = trim((string)($_POST['description'] ?? ''));
    $addedBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    if ($serviceName === '') {
        $_SESSION['message'] = 'Service name is required.';
        $_SESSION['message_type'] = 'warning';
    } else {
        $price = is_numeric($initialPrice) ? (float)$initialPrice : 0.0;

        if ($addedBy === null) {
            $sql = $hasIsActive
                ? "INSERT INTO services (service_name, initial_price, description, added_by, is_active) VALUES (?, ?, ?, NULL, 1)"
                : "INSERT INTO services (service_name, initial_price, description, added_by) VALUES (?, ?, ?, NULL)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('sds', $serviceName, $price, $description);
            }
        } else {
            $sql = $hasIsActive
                ? "INSERT INTO services (service_name, initial_price, description, added_by, is_active) VALUES (?, ?, ?, ?, 1)"
                : "INSERT INTO services (service_name, initial_price, description, added_by) VALUES (?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('sdsi', $serviceName, $price, $description, $addedBy);
            }
        }

        if ($stmt) {
            if ($stmt->execute()) {
                $_SESSION['message'] = 'Service added successfully.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Error adding service: ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        } else {
            $_SESSION['message'] = 'Error preparing add service query.';
            $_SESSION['message_type'] = 'danger';
        }
    }

    services_redirect('services');
}

// Handle ARCHIVE (soft) or DELETE (fallback)
if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = (int)($_POST['service_id'] ?? 0);
    if ($serviceId <= 0) {
        $_SESSION['message'] = 'Invalid service.';
        $_SESSION['message_type'] = 'warning';
    } else {
        if ($hasIsActive) {
            $stmt = $conn->prepare("UPDATE services SET is_active = 0 WHERE service_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $serviceId);
                if ($stmt->execute()) {
                    $_SESSION['message'] = 'Service archived successfully.';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Error archiving service: ' . $stmt->error;
                    $_SESSION['message_type'] = 'danger';
                }
                $stmt->close();
            } else {
                $_SESSION['message'] = 'Error preparing archive query.';
                $_SESSION['message_type'] = 'danger';
            }
        } else {
            $stmt = $conn->prepare("DELETE FROM services WHERE service_id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $serviceId);
                if ($stmt->execute()) {
                    $_SESSION['message'] = 'Service deleted (archive not supported by current DB schema).';
                    $_SESSION['message_type'] = 'warning';
                } else {
                    $_SESSION['message'] = 'Error deleting service: ' . $stmt->error;
                    $_SESSION['message_type'] = 'danger';
                }
                $stmt->close();
            } else {
                $_SESSION['message'] = 'Error preparing delete query.';
                $_SESSION['message_type'] = 'danger';
            }
        }
    }

    services_redirect('services');
}

// Handle RESTORE (un-archive) service
if ($action === 'restore' && $_SERVER['REQUEST_METHOD'] === 'POST' && $hasIsActive) {
    $serviceId = (int)($_POST['service_id'] ?? 0);
    if ($serviceId <= 0) {
        $_SESSION['message'] = 'Invalid service.';
        $_SESSION['message_type'] = 'warning';
    } else {
        $stmt = $conn->prepare("UPDATE services SET is_active = 1 WHERE service_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $serviceId);
            if ($stmt->execute()) {
                $_SESSION['message'] = 'Service restored successfully.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Error restoring service: ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        } else {
            $_SESSION['message'] = 'Error preparing restore query.';
            $_SESSION['message_type'] = 'danger';
        }
    }

    services_redirect('archived');
}

// Handle EDIT service
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $serviceName = trim((string)($_POST['service_name'] ?? ''));
    $initialPrice = (string)($_POST['initial_price'] ?? '0');
    $description = trim((string)($_POST['description'] ?? ''));

    if ($serviceId <= 0) {
        $_SESSION['message'] = 'Invalid service.';
        $_SESSION['message_type'] = 'warning';
    } elseif ($serviceName === '') {
        $_SESSION['message'] = 'Service name is required.';
        $_SESSION['message_type'] = 'warning';
    } else {
        $price = is_numeric($initialPrice) ? (float)$initialPrice : 0.0;

        $stmt = $conn->prepare("UPDATE services SET service_name = ?, initial_price = ?, description = ? WHERE service_id = ?");
        if ($stmt) {
            $stmt->bind_param('sdsi', $serviceName, $price, $description, $serviceId);
            if ($stmt->execute()) {
                $_SESSION['message'] = 'Service updated successfully.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Error updating service: ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        } else {
            $_SESSION['message'] = 'Error preparing update query.';
            $_SESSION['message_type'] = 'danger';
        }
    }

    services_redirect('services');
}

// Handle ADD/UPDATE service time profile
if ($action === 'save_time' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $duration = (int)($_POST['duration_minutes'] ?? 0);
    $buffer = (int)($_POST['buffer_minutes'] ?? 0);
    $limitRaw = trim((string)($_POST['slots_per_day_limit'] ?? ''));
    $slotsLimit = ($limitRaw === '') ? null : (int)$limitRaw;

    if ($serviceId <= 0) {
        $_SESSION['message'] = 'Please select a service.';
        $_SESSION['message_type'] = 'warning';
    } elseif ($duration < 20) {
        $_SESSION['message'] = 'Duration must be at least 20 minutes.';
        $_SESSION['message_type'] = 'warning';
    } elseif ($buffer < 0) {
        $_SESSION['message'] = 'Buffer minutes cannot be negative.';
        $_SESSION['message_type'] = 'warning';
    } else {
        $find = $conn->prepare('SELECT id FROM timeslot_service WHERE service_id = ? ORDER BY id DESC LIMIT 1');
        $existingId = 0;
        if ($find) {
            $find->bind_param('i', $serviceId);
            $find->execute();
            $row = $find->get_result()->fetch_assoc();
            $existingId = (int)($row['id'] ?? 0);
            $find->close();
        }

        if ($existingId > 0) {
            $upd = $conn->prepare('UPDATE timeslot_service SET duration_minutes = ?, buffer_minutes = ?, slots_per_day_limit = ? WHERE id = ?');
            if ($upd) {
                $upd->bind_param('iiii', $duration, $buffer, $slotsLimit, $existingId);
                if ($upd->execute()) {
                    $_SESSION['message'] = 'Service time updated.';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Error updating service time: ' . $upd->error;
                    $_SESSION['message_type'] = 'danger';
                }
                $upd->close();
            } else {
                $_SESSION['message'] = 'Error preparing service time update.';
                $_SESSION['message_type'] = 'danger';
            }
        } else {
            $ins = $conn->prepare('INSERT INTO timeslot_service (service_id, duration_minutes, buffer_minutes, slots_per_day_limit) VALUES (?, ?, ?, ?)');
            if ($ins) {
                $ins->bind_param('iiii', $serviceId, $duration, $buffer, $slotsLimit);
                if ($ins->execute()) {
                    $_SESSION['message'] = 'Service time added.';
                    $_SESSION['message_type'] = 'success';
                } else {
                    $_SESSION['message'] = 'Error adding service time: ' . $ins->error;
                    $_SESSION['message_type'] = 'danger';
                }
                $ins->close();
            } else {
                $_SESSION['message'] = 'Error preparing service time insert.';
                $_SESSION['message_type'] = 'danger';
            }
        }
    }

    services_redirect('timeslot');
}

// Handle REMOVE service time profile
if ($action === 'delete_time' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $serviceTimeId = (int)($_POST['service_time_id'] ?? 0);
    if ($serviceTimeId <= 0) {
        $_SESSION['message'] = 'Invalid service time profile.';
        $_SESSION['message_type'] = 'warning';
    } else {
        $del = $conn->prepare('DELETE FROM timeslot_service WHERE id = ?');
        if ($del) {
            $del->bind_param('i', $serviceTimeId);
            if ($del->execute()) {
                $_SESSION['message'] = 'Service time removed.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Error removing service time: ' . $del->error;
                $_SESSION['message_type'] = 'danger';
            }
            $del->close();
        } else {
            $_SESSION['message'] = 'Error preparing service time delete.';
            $_SESSION['message_type'] = 'danger';
        }
    }

    services_redirect('timeslot');
}

// Read current consultation fee
$consultationFee = '500.00';
$consultationDesc = '';
$cfeeRes = $conn->query("SELECT setting_value, description FROM clinic_settings WHERE setting_key = 'consultation_fee' LIMIT 1");
if ($cfeeRes && $cfeeRow = $cfeeRes->fetch_assoc()) {
    $consultationFee = $cfeeRow['setting_value'] ?? '500.00';
    $consultationDesc = $cfeeRow['description'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_consultation_fee') {
    $fee = (float)($_POST['consultation_fee'] ?? 0);
    $fee = max(0, round($fee, 2));
    $desc = trim((string)($_POST['setting_description'] ?? ''));
    if ($desc === '') $desc = 'Default consultation fee for consultation-only appointments';

    $check = $conn->query("SELECT setting_key FROM clinic_settings WHERE setting_key = 'consultation_fee'");
    if ($check && $check->num_rows > 0) {
        $stmt = $conn->prepare('UPDATE clinic_settings SET setting_value = ?, description = ? WHERE setting_key = ?');
        $feeStr = number_format($fee, 2, '.', '');
        $settingKey = 'consultation_fee';
        $stmt->bind_param('sss', $feeStr, $desc, $settingKey);
    } else {
        $stmt = $conn->prepare('INSERT INTO clinic_settings (setting_key, setting_value, description) VALUES (?, ?, ?)');
        $feeStr = number_format($fee, 2, '.', '');
        $key = 'consultation_fee';
        $stmt->bind_param('sss', $key, $feeStr, $desc);
    }
    $stmt->execute();
    $stmt->close();

    $_SESSION['message'] = 'Consultation fee updated to ₱' . number_format($fee, 2) . '.';
    $_SESSION['message_type'] = 'success';
    services_redirect('consultation');
}

// Fetch archived services list
$archivedServices = [];
if ($hasIsActive) {
    $archResult = $conn->query("SELECT * FROM services WHERE is_active = 0 ORDER BY service_name ASC");
    if ($archResult) {
        while ($row = $archResult->fetch_assoc()) {
            $archivedServices[] = $row;
        }
    }
}

// Fetch active services list
$services = [];
if ($hasIsActive) {
    $result = $conn->query("SELECT * FROM services WHERE is_active = 1 ORDER BY service_name ASC");
} else {
    $result = $conn->query("SELECT * FROM services ORDER BY service_name ASC");
}
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
    }
}

$serviceTimeRows = [];
$baseDurationExpr = $hasDefaultDuration ? 's.default_duration_minutes' : '30';
$sqlServiceTimes = "
    SELECT
        s.service_id,
        s.service_name,
        s.initial_price,
        ts.id AS service_time_id,
        ts.duration_minutes,
        ts.buffer_minutes,
        ts.slots_per_day_limit,
        COALESCE(ts.duration_minutes, {$baseDurationExpr}, 30) AS effective_duration
    FROM services s
    LEFT JOIN (
        SELECT t1.*
        FROM timeslot_service t1
        INNER JOIN (
            SELECT service_id, MAX(id) AS max_id
            FROM timeslot_service
            GROUP BY service_id
        ) t2 ON t1.id = t2.max_id
    ) ts ON ts.service_id = s.service_id
";
if ($hasIsActive) {
    $sqlServiceTimes .= " WHERE s.is_active = 1";
}
$sqlServiceTimes .= " ORDER BY s.service_name ASC";

$tsRes = $conn->query($sqlServiceTimes);
if ($tsRes) {
    while ($row = $tsRes->fetch_assoc()) {
        $serviceTimeRows[] = $row;
    }
}

?>
<div class="module-page-header mb-3">
    <h4 class="mb-0">
        <span class="module-page-header__crumb">Services</span>
        <?php if ($tab === 'archived'): ?>
            <span class="module-page-header__sep">&gt;</span> Archived
        <?php elseif ($tab === 'timeslot'): ?>
            <span class="module-page-header__sep">&gt;</span> Service Time
        <?php elseif ($tab === 'consultation'): ?>
            <span class="module-page-header__sep">&gt;</span> Consultation Fee
        <?php endif; ?>
    </h4>
</div>
<?php if (isset($_SESSION['message'])):
    $flashMessage = $_SESSION['message'];
    $flashType = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['message'], $_SESSION['message_type']);
?>
    <script>
        (function () {
            var msg = <?php echo json_encode($flashMessage); ?>;
            var type = <?php echo json_encode($flashType); ?>;
            function fireToast() {
                if (typeof window.showAppToast === 'function') {
                    window.showAppToast(msg, type);
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fireToast);
            } else {
                fireToast();
            }
        })();
    </script>
<?php endif; ?>

<nav class="module-section-nav" aria-label="Services sections">
    <div class="module-section-nav__track">
        <ul class="nav nav-tabs mb-0">
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'services' ? 'active' : ''; ?>" href="admin.php?page=services&tab=services">Services</a>
            </li>
            <?php if ($hasIsActive): ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'archived' ? 'active' : ''; ?>" href="admin.php?page=services&tab=archived">Archived</a>
            </li>
            <?php endif; ?>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'timeslot' ? 'active' : ''; ?>" href="admin.php?page=services&tab=timeslot">Service Time</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $tab === 'consultation' ? 'active' : ''; ?>" href="admin.php?page=services&tab=consultation">Consultation Fee</a>
            </li>
        </ul>
    </div>
</nav>

<?php if ($tab === 'services'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="card-title mb-0">Services</h2>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addServiceModal">
        <i class="bi bi-plus-circle"></i> Add Service
    </button>
</div>
<?php if (!$hasIsActive): ?>
    <div class="alert alert-warning">
        Archive uses delete because the current services table has no is_active field.
    </div>
<?php endif; ?>

<div class="table-responsive">
            <table class="table table-hover datatable align-middle" data-skip-table-search="1">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Initial Price</th>
                        <th>Description</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services as $svc): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars($svc['service_name']); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)$svc['initial_price'], 2)); ?></td>
                            <td style="max-width: 420px;"><span class="text-muted"><?php echo htmlspecialchars($svc['description'] ?? ''); ?></span></td>
                            <td><?php echo htmlspecialchars($svc['created_at'] ?? ''); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editServiceModal"
                                        onclick='openEditServiceModal(<?php echo htmlspecialchars(json_encode($svc), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="admin.php?page=services&tab=services&action=archive" data-confirm="Archive this service?" data-confirm-title="Archive Service" data-confirm-variant="danger" data-confirm-ok="Archive" style="display:inline-block;">
                                    <input type="hidden" name="service_id" value="<?php echo htmlspecialchars($svc['service_id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i> <?php echo $hasIsActive ? '' : 'Delete'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php elseif ($tab === 'archived'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="card-title mb-0">Archived Services</h2>
</div>
<?php if (empty($archivedServices)): ?>
    <p class="text-muted">No archived services.</p>
<?php else: ?>
<div class="table-responsive">
            <table class="table table-hover datatable align-middle" data-skip-table-search="1">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Initial Price</th>
                        <th>Description</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($archivedServices as $svc): ?>
                        <tr>
                            <td class="fw-semibold text-muted"><?php echo htmlspecialchars($svc['service_name']); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)$svc['initial_price'], 2)); ?></td>
                            <td style="max-width: 420px;"><span class="text-muted"><?php echo htmlspecialchars($svc['description'] ?? ''); ?></span></td>
                            <td><?php echo htmlspecialchars($svc['created_at'] ?? ''); ?></td>
                            <td>
                                <form method="POST" action="admin.php?page=services&tab=archived&action=restore" data-confirm="Restore this service? It will become selectable again." data-confirm-title="Restore Service" data-confirm-variant="success" data-confirm-ok="Restore" style="display:inline-block;">
                                    <input type="hidden" name="service_id" value="<?php echo htmlspecialchars($svc['service_id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-arrow-counterclockwise"></i> Restore
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
<?php elseif ($tab === 'timeslot'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="card-title mb-0">Service Averate Time</h2>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#serviceTimeModal" onclick="openServiceTimeModal(null)">
        <i class="bi bi-plus-circle"></i> Add Service Time
    </button>
</div>
<div class="table-responsive">
            <table class="table table-hover datatable align-middle" data-skip-table-search="1">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Price</th>
                        <th>Smart Duration</th>
                        <th>Slots Used</th>
                        <th>Buffer</th>
                        <th>Daily Limit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($serviceTimeRows as $row): ?>
                        <?php
                            $effectiveDuration = (int)$row['effective_duration'];
                            $slotsUsed = (int)ceil(max(20, $effectiveDuration) / 20);
                        ?>
                        <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars($row['service_name']); ?></td>
                            <td><?php echo htmlspecialchars(number_format((float)$row['initial_price'], 2)); ?></td>
                            <td><?php echo htmlspecialchars((string)$effectiveDuration); ?> min</td>
                            <td><span class="badge bg-primary"><?php echo htmlspecialchars((string)$slotsUsed); ?> x 20m</span></td>
                            <td><?php echo $row['buffer_minutes'] !== null ? htmlspecialchars((string)((int)$row['buffer_minutes'])) . ' min' : '<span class="text-muted">-</span>'; ?></td>
                            <td><?php echo $row['slots_per_day_limit'] !== null ? htmlspecialchars((string)((int)$row['slots_per_day_limit'])) : '<span class="text-muted">No limit</span>'; ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#serviceTimeModal"
                                    onclick='openServiceTimeModal(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if (!empty($row['service_time_id'])): ?>
                                    <form method="POST" action="admin.php?page=services&tab=timeslot&action=delete_time" style="display:inline-block;" data-confirm="Remove service time profile?" data-confirm-title="Remove Time Profile" data-confirm-variant="danger" data-confirm-ok="Remove">
                                        <input type="hidden" name="service_time_id" value="<?php echo htmlspecialchars($row['service_time_id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>

<!-- Add Service Modal -->
<div class="modal fade" id="addServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="admin.php?page=services&tab=services&action=add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Service Name</label>
                        <input type="text" name="service_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Initial Price</label>
                        <input type="number" name="initial_price" class="form-control" min="0" step="0.01" value="0">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Optional"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Service Modal -->
<div class="modal fade" id="editServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="admin.php?page=services&tab=services&action=edit">
                <div class="modal-body">
                    <input type="hidden" name="service_id" id="editServiceId">
                    <div class="mb-3">
                        <label class="form-label">Service Name</label>
                        <input type="text" name="service_name" id="editServiceName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Initial Price</label>
                        <input type="number" name="initial_price" id="editInitialPrice" class="form-control" min="0" step="0.01">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Description</label>
                        <textarea name="description" id="editDescription" class="form-control" rows="3" placeholder="Optional"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Service Time Modal -->
<div class="modal fade" id="serviceTimeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Service Time Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="admin.php?page=services&tab=timeslot&action=save_time">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Service</label>
                        <select class="form-select" name="service_id" id="timeServiceId" required>
                            <option value="">-- Select Service --</option>
                            <?php foreach ($services as $svc): ?>
                                <option value="<?php echo htmlspecialchars($svc['service_id']); ?>"><?php echo htmlspecialchars($svc['service_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Duration Minutes</label>
                        <input type="number" class="form-control" id="timeDuration" name="duration_minutes" min="20" step="5" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Buffer Minutes</label>
                        <input type="number" class="form-control" id="timeBuffer" name="buffer_minutes" min="0" step="1" value="5">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Slots Per Day Limit</label>
                        <input type="number" class="form-control" id="timeLimit" name="slots_per_day_limit" min="1" step="1" placeholder="Leave blank for no limit">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Save Profile</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($tab === 'consultation'): ?>
<h2 class="card-title mb-3">Consultation Fee Configuration</h2>
<form method="POST" action="admin.php?page=services&tab=consultation">
    <input type="hidden" name="action" value="update_consultation_fee">
    <div class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Consultation Fee (₱)</label>
            <input type="number" name="consultation_fee" class="form-control" step="0.01" min="0" value="<?php echo htmlspecialchars($consultationFee); ?>" required>
        </div>
        <div class="col-md-5">
            <label class="form-label fw-semibold">Description</label>
            <input type="text" name="setting_description" class="form-control readonly-muted-field" value="Default consultation fee for consultation-only appointments" readonly>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-circle me-1"></i>Update</button>
        </div>
    </div>
</form>
<?php endif; ?>

<script>
    function openEditServiceModal(service) {
        if (!service) return;
        document.getElementById('editServiceId').value = service.service_id || '';
        document.getElementById('editServiceName').value = service.service_name || '';
        document.getElementById('editInitialPrice').value = service.initial_price || 0;
        document.getElementById('editDescription').value = service.description || '';
    }

    function openServiceTimeModal(row) {
        const serviceSel = document.getElementById('timeServiceId');
        const durationEl = document.getElementById('timeDuration');
        const bufferEl = document.getElementById('timeBuffer');
        const limitEl = document.getElementById('timeLimit');
        if (!serviceSel || !durationEl || !bufferEl || !limitEl) return;

        if (!row) {
            serviceSel.value = '';
            durationEl.value = 30;
            bufferEl.value = 5;
            limitEl.value = '';
            return;
        }

        serviceSel.value = row.service_id || '';
        durationEl.value = row.duration_minutes || row.effective_duration || 30;
        bufferEl.value = (row.buffer_minutes !== null && row.buffer_minutes !== undefined) ? row.buffer_minutes : 5;
        limitEl.value = (row.slots_per_day_limit !== null && row.slots_per_day_limit !== undefined) ? row.slots_per_day_limit : '';
    }
</script>
