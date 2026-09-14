<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/feedback_helpers.php';

feedback_ensure_table($conn);

if (!function_exists('reviews_redirect')) {
    function reviews_redirect()
    {
        header('Location: admin.php?page=reviews');
        exit;
    }
}

$action = $_GET['action'] ?? '';

if ($action === 'feature' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);
    $currentCount = feedback_count_featured($conn);

    if ($feedbackId <= 0) {
        $_SESSION['message'] = 'Invalid review.';
        $_SESSION['message_type'] = 'warning';
    } elseif ($currentCount >= FEEDBACK_MAX_FEATURED) {
        $_SESSION['message'] = 'Maximum of ' . FEEDBACK_MAX_FEATURED . ' featured reviews reached. Unfeature one first.';
        $_SESSION['message_type'] = 'warning';
    } else {
        $stmt = $conn->prepare('UPDATE patient_feedback SET is_featured = 1 WHERE feedback_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $feedbackId);
            if ($stmt->execute()) {
                $_SESSION['message'] = 'Review is now featured on the landing page.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Error featuring review: ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        }
    }
    reviews_redirect();
}

if ($action === 'unfeature' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);
    if ($feedbackId <= 0) {
        $_SESSION['message'] = 'Invalid review.';
        $_SESSION['message_type'] = 'warning';
    } else {
        $stmt = $conn->prepare('UPDATE patient_feedback SET is_featured = 0 WHERE feedback_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $feedbackId);
            if ($stmt->execute()) {
                $_SESSION['message'] = 'Review removed from the landing page.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Error updating review: ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        }
    }
    reviews_redirect();
}

if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);
    if ($feedbackId <= 0) {
        $_SESSION['message'] = 'Invalid review.';
        $_SESSION['message_type'] = 'warning';
    } else {
        $stmt = $conn->prepare('DELETE FROM patient_feedback WHERE feedback_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $feedbackId);
            if ($stmt->execute()) {
                $_SESSION['message'] = 'Review deleted.';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Error deleting review: ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        }
    }
    reviews_redirect();
}

$filter = $_GET['filter'] ?? 'all';
if (!in_array($filter, ['all', 'featured', 'unfeatured'], true)) {
    $filter = 'all';
}

$where = '';
if ($filter === 'featured') {
    $where = 'WHERE f.is_featured = 1';
} elseif ($filter === 'unfeatured') {
    $where = 'WHERE f.is_featured = 0';
}

$reviews = [];
$sql = "SELECT f.feedback_id, f.rating, f.comment, f.is_featured, f.created_at,
               f.appointment_id, a.appointment_date,
               p.first_name AS patient_first_name, p.last_name AS patient_last_name,
               u.first_name AS doctor_first_name, u.last_name AS doctor_last_name
        FROM patient_feedback f
        INNER JOIN patients p ON p.patient_id = f.patient_id
        LEFT JOIN appointments a ON a.appointment_id = f.appointment_id
        LEFT JOIN users u ON u.user_id = f.doctor_id
        $where
        ORDER BY f.created_at DESC";
$res = $conn->query($sql);
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $reviews[] = $row;
    }
}

$featuredCount = feedback_count_featured($conn);
?>
<div class="module-page-header mb-3">
    <h4 class="mb-0">
        <span class="module-page-header__crumb">Patient Reviews</span>
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

<div class="alert <?php echo $featuredCount >= FEEDBACK_MIN_DISPLAY ? 'alert-success' : 'alert-info'; ?> d-flex align-items-center gap-2">
    <i class="bi bi-info-circle"></i>
    <div>
        <strong><?php echo $featuredCount; ?> / <?php echo FEEDBACK_MAX_FEATURED; ?></strong> reviews featured.
        The landing page testimonials section stays hidden until at least <?php echo FEEDBACK_MIN_DISPLAY; ?> reviews are featured, and shows at most <?php echo FEEDBACK_MAX_FEATURED; ?>.
    </div>
</div>

<nav class="module-section-nav" aria-label="Review filters">
    <div class="module-section-nav__track">
        <ul class="nav nav-tabs mb-0">
            <li class="nav-item">
                <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="admin.php?page=reviews&filter=all">All</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $filter === 'featured' ? 'active' : ''; ?>" href="admin.php?page=reviews&filter=featured">Featured</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $filter === 'unfeatured' ? 'active' : ''; ?>" href="admin.php?page=reviews&filter=unfeatured">Not Featured</a>
            </li>
        </ul>
    </div>
</nav>

<div class="table-responsive mt-3">
    <table class="table table-hover datatable align-middle" data-skip-table-search="1">
        <thead>
            <tr>
                <th>Patient</th>
                <th>Rating</th>
                <th>Comment</th>
                <th>Last Visit</th>
                <th>Doctor</th>
                <th>Submitted</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($reviews)): ?>
                <tr>
                    <td colspan="8" class="text-center py-4 text-muted">No reviews yet.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($reviews as $r): ?>
                    <?php
                    $patientName = trim((string)$r['patient_first_name'] . ' ' . (string)$r['patient_last_name']);
                    $doctorName = trim((string)($r['doctor_first_name'] ?? '') . ' ' . (string)($r['doctor_last_name'] ?? ''));
                    $rating = (int)$r['rating'];
                    ?>
                    <tr>
                        <td class="fw-semibold"><?php echo htmlspecialchars($patientName); ?></td>
                        <td>
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                                <i class="bi <?php echo $s <= $rating ? 'bi-star-fill text-warning' : 'bi-star text-muted'; ?>"></i>
                            <?php endfor; ?>
                        </td>
                        <td style="max-width: 320px;"><span class="text-muted"><?php echo htmlspecialchars((string)($r['comment'] ?? '')); ?></span></td>
                        <td><?php echo !empty($r['appointment_date']) ? htmlspecialchars(date('M d, Y', strtotime((string)$r['appointment_date']))) : 'N/A'; ?></td>
                        <td><?php echo $doctorName !== '' ? htmlspecialchars($doctorName) : 'N/A'; ?></td>
                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime((string)$r['created_at']))); ?></td>
                        <td>
                            <?php if ((int)$r['is_featured'] === 1): ?>
                                <span class="badge bg-success">Featured</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Not Featured</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$r['is_featured'] === 1): ?>
                                <form method="POST" action="admin.php?page=reviews&action=unfeature" style="display:inline-block;">
                                    <input type="hidden" name="feedback_id" value="<?php echo (int)$r['feedback_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-eye-slash"></i> Unfeature
                                    </button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="admin.php?page=reviews&action=feature" style="display:inline-block;">
                                    <input type="hidden" name="feedback_id" value="<?php echo (int)$r['feedback_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-success" <?php echo $featuredCount >= FEEDBACK_MAX_FEATURED ? 'disabled title="Maximum featured reviews reached"' : ''; ?>>
                                        <i class="bi bi-star"></i> Feature
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="admin.php?page=reviews&action=delete" data-confirm="Delete this review permanently?" data-confirm-title="Delete Review" data-confirm-variant="danger" data-confirm-ok="Delete" style="display:inline-block;">
                                <input type="hidden" name="feedback_id" value="<?php echo (int)$r['feedback_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
