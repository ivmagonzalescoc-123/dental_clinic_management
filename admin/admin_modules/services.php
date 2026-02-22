<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

// Detect whether services has soft-archive support
$hasIsActive = false;
$colResult = $conn->query("SHOW COLUMNS FROM services LIKE 'is_active'");
if ($colResult && $colResult->num_rows > 0) {
    $hasIsActive = true;
}

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

        // If we don't have a valid logged-in user id, store added_by as NULL to avoid FK issues.
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

    header('Location: admin.php?page=services');
    exit;
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
            // Fallback: hard delete
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

    header('Location: admin.php?page=services');
    exit;
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

    header('Location: admin.php?page=services');
    exit;
}

// Fetch services list
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
?>

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($_SESSION['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">Services</h2>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addServiceModal">
            <i class="bi bi-plus-circle"></i> Add Service
        </button>
    </div>
    <div class="card-body">
        <?php if (!$hasIsActive): ?>
            <div class="alert alert-warning">
                Archive uses delete because the current <code>services</code> table has no <code>is_active</code> field.
                If you want true archive, add <code>is_active</code> to the table.
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover datatable align-middle">
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
                            <td style="max-width: 420px;">
                                <span class="text-muted">
                                    <?php echo htmlspecialchars($svc['description'] ?? ''); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($svc['created_at'] ?? ''); ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#editServiceModal"
                                        onclick='openEditServiceModal(<?php echo htmlspecialchars(json_encode($svc), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <form method="POST" action="admin.php?page=services&action=archive" onsubmit="return confirm('Archive this service?');" style="display:inline-block;">
                                    <input type="hidden" name="service_id" value="<?php echo htmlspecialchars($svc['service_id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-warning">
                                        <i class="bi bi-archive"></i> <?php echo $hasIsActive ? 'Archive' : 'Delete'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Service Modal -->
<div class="modal fade" id="addServiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Service</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="admin.php?page=services&action=add">
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
            <form method="POST" action="admin.php?page=services&action=edit">
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
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function openEditServiceModal(service) {
        if (!service) return;
        document.getElementById('editServiceId').value = service.service_id || '';
        document.getElementById('editServiceName').value = service.service_name || '';
        document.getElementById('editInitialPrice').value = service.initial_price || 0;
        document.getElementById('editDescription').value = service.description || '';
    }
</script>
