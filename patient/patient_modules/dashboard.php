<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/dashboard_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/feedback_helpers.php';

billing_ensure_tables($conn);
feedback_ensure_table($conn);

$patientId = (int)($_SESSION['patient_id'] ?? 0);
if ($patientId <= 0 && !empty($_SESSION['user_id'])) {
    $stmt = $conn->prepare('SELECT patient_id FROM patients WHERE user_id = ? LIMIT 1');
    if ($stmt) {
        $uid = (int)$_SESSION['user_id'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $r = $stmt->get_result();
        $row = $r ? $r->fetch_assoc() : null;
        $patientId = (int)($row['patient_id'] ?? 0);
        $stmt->close();
    }
}

$showReviewPrompt = $patientId > 0
    && feedback_patient_is_eligible($conn, $patientId)
    && !feedback_patient_has_reviewed($conn, $patientId);

$today = date('Y-m-d');
$upcoming = 0;
$past = 0;
$outstandingBalance = 0.0;
$unpaidBills = 0;
$nextAppt = null;
$upcomingList = [];

if ($patientId > 0) {
    $activeStatuses = "'scheduled','confirmed','arrived','checked_in','in_progress','rescheduled'";
    $upcoming = dashboard_scalar_int(
        $conn,
        "SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND appointment_date >= ? AND status IN ($activeStatuses)",
        'is',
        [$patientId, $today]
    );
    $past = dashboard_scalar_int(
        $conn,
        "SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status IN ('completed','records_complete')",
        'i',
        [$patientId]
    );

    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.appointment_date, a.status, t.start_time
        FROM appointments a
        LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
        WHERE a.patient_id = ? AND a.appointment_date >= ? AND a.status IN ($activeStatuses)
        ORDER BY a.appointment_date ASC, COALESCE(t.start_time, '23:59:59') ASC
        LIMIT 5
    ");
    if ($stmt) {
        $stmt->bind_param('is', $patientId, $today);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $upcomingList[] = $row;
            }
            $nextAppt = $upcomingList[0] ?? null;
        }
        $stmt->close();
    }

    $balStmt = $conn->prepare("
        SELECT COALESCE(SUM(b.balance), 0) AS total_balance, COUNT(*) AS open_bills
        FROM billing b
        INNER JOIN appointments a ON a.appointment_id = b.appointment_id
        WHERE a.patient_id = ? AND b.balance > 0
    ");
    if ($balStmt) {
        $balStmt->bind_param('i', $patientId);
        $balStmt->execute();
        $balRes = $balStmt->get_result();
        if ($balRes && ($balRow = $balRes->fetch_assoc())) {
            $outstandingBalance = (float)($balRow['total_balance'] ?? 0);
            $unpaidBills = (int)($balRow['open_bills'] ?? 0);
        }
        $balStmt->close();
    }
}

[, $seriesValues, $chartLabels] = dashboard_last7_series($conn);
if ($patientId > 0) {
    $last7Start = date('Y-m-d', strtotime('-6 days'));
    $seriesValues = array_fill(0, 7, 0);
    $chartLabels = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($last7Start . ' +' . $i . ' days'));
        $chartLabels[] = date('M d', strtotime($d));
    }
    $stmt = $conn->prepare('
        SELECT appointment_date AS dt, COUNT(*) AS c
        FROM appointments
        WHERE patient_id = ? AND appointment_date BETWEEN ? AND ?
        GROUP BY appointment_date
    ');
    if ($stmt) {
        $stmt->bind_param('iss', $patientId, $last7Start, $today);
        $stmt->execute();
        $res = $stmt->get_result();
        $map = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $map[$r['dt']] = (int)$r['c'];
            }
        }
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($last7Start . ' +' . $i . ' days'));
            if (isset($map[$d])) {
                $seriesValues[$i] = $map[$d];
            }
        }
        $stmt->close();
    }
}
?>

<div class="dental-dashboard-shell">
    <div class="dental-dashboard-hero">
        <div>
            <h4 class="mb-0">Patient Dashboard</h4>
        </div>
        <span class="dental-chip">Care Overview</span>
    </div>

    <?php if ($showReviewPrompt): ?>
    <div class="card dental-surface-card patient-review-prompt-card mb-3" id="patientReviewPromptCard">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="patient-review-prompt-icon"><i class="bi bi-star"></i></div>
                <div>
                    <h5 class="mb-1">How has your care been so far?</h5>
                    <div class="text-muted small">Optional — share a quick review of the clinic. It only takes a minute.</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#patientReviewModal">
                    <i class="bi bi-star"></i> Leave a Review
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <?php dashboard_render_kpi_card('Upcoming Appointments', (string)(int)$upcoming, 'bi-calendar-check'); ?>
        </div>
        <div class="col-md-6 col-xl-3">
            <?php dashboard_render_kpi_card('Visit History', (string)(int)$past, 'bi-journal-medical', 'teal'); ?>
        </div>
        <div class="col-md-6 col-xl-3">
            <?php dashboard_render_kpi_card('Outstanding Balance', '₱' . number_format($outstandingBalance, 2), 'bi-wallet2', 'amber'); ?>
        </div>
        <div class="col-md-6 col-xl-3">
            <?php dashboard_render_kpi_card('Next Appointment', $nextAppt ? date('M d, Y', strtotime($nextAppt['appointment_date'])) : 'None', 'bi-calendar-event', 'violet'); ?>
        </div>

        <?php if ($nextAppt): ?>
        <div class="col-12 col-lg-6">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-1">Next Appointment Progress</h5>
                    <div class="text-muted small mb-3">
                        <?php echo date('l, M d, Y', strtotime($nextAppt['appointment_date'])); ?>
                        <?php $nextTimeLabel = dashboard_format_time($nextAppt['start_time'] ?? null); ?>
                        <?php if ($nextTimeLabel !== ''): ?> &bull; <?php echo htmlspecialchars($nextTimeLabel); ?><?php endif; ?>
                    </div>
                    <?php
                        $nextStatus = (string)($nextAppt['status'] ?? 'scheduled');
                        $apptStep2 = in_array($nextStatus, ['confirmed', 'arrived', 'checked_in', 'in_progress', 'awaiting_secretary_records', 'records_complete', 'completed'], true);
                        $apptStep3 = in_array($nextStatus, ['arrived', 'checked_in', 'in_progress', 'awaiting_secretary_records', 'records_complete', 'completed'], true);
                        $apptSteps = [
                            ['label' => 'Booked', 'active' => true],
                            ['label' => 'Confirmed', 'active' => $apptStep2],
                            ['label' => 'Arrived / Processing', 'active' => $apptStep3],
                        ];
                    ?>
                    <div class="d-flex align-items-center" style="max-width:520px;">
                        <?php foreach ($apptSteps as $i => $step): ?>
                            <div class="d-flex flex-column align-items-center" style="flex:0 0 auto;">
                                <div style="width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.85rem;
                                    <?php echo $step['active'] ? 'background:#16a34a;color:#fff;border:2px solid #16a34a;' : 'background:#f1f5f9;color:#94a3b8;border:2px dashed #cbd5e1;'; ?>">
                                    <?php if ($step['active']): ?><i class="bi bi-check-lg"></i><?php else: ?><?php echo $i + 1; ?><?php endif; ?>
                                </div>
                                <div class="small mt-1 text-center" style="max-width:90px;<?php echo $step['active'] ? 'color:#16a34a;font-weight:600;' : 'color:#94a3b8;'; ?>"><?php echo htmlspecialchars($step['label']); ?></div>
                            </div>
                            <?php if ($i < count($apptSteps) - 1): ?>
                                <div style="flex:1 1 auto;height:3px;margin:0 6px;margin-bottom:20px;<?php echo $apptSteps[$i + 1]['active'] ? 'background:#16a34a;' : 'background:#e2e8f0;'; ?>"></div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-12 col-lg-6">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-0">Your Visits | Last 7 days</h5>
                    <div class="dental-chart-frame mt-3">
                        <div id="patientAppt7Chart" style="min-height:220px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-0">Upcoming Appointments</h5>
                    <?php if (empty($upcomingList)): ?>
                        <?php dashboard_render_empty_state('No upcoming appointments yet.', 'bi-calendar-plus', 'Book one now', 'patient.php?page=appointments'); ?>
                    <?php else: ?>
                        <ul class="list-group list-group-flush mt-3">
                            <?php foreach ($upcomingList as $appt): ?>
                                <?php $status = (string)($appt['status'] ?? 'scheduled'); ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <div class="fw-semibold"><?php echo date('M d, Y', strtotime($appt['appointment_date'])); ?></div>
                                        <div class="text-muted small">
                                            <?php
                                            $timeLabel = dashboard_format_time($appt['start_time'] ?? null);
                                            echo $timeLabel !== '' ? htmlspecialchars($timeLabel) : 'Time to be confirmed';
                                            ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-<?php echo dashboard_appt_status_badge_class($status); ?>">
                                        <?php echo htmlspecialchars(dashboard_format_status_label($status)); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php dashboard_render_appt_chart_script('patientAppt7Chart', $chartLabels, $seriesValues, '#0d9488', 'area'); ?>

<?php if ($showReviewPrompt): ?>
<style>
.patient-review-prompt-card {
    border-left: 4px solid #0d9488;
}
.patient-review-prompt-icon {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #ccfbf1;
    color: #0d9488;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}
.patient-review-stars {
    font-size: 1.8rem;
    display: inline-flex;
    gap: 6px;
    cursor: pointer;
}
.patient-review-star {
    color: #cbd5e1;
    transition: color 0.15s ease;
}
.patient-review-star.active {
    color: #f59e0b;
}</style>

<div class="modal fade" id="patientReviewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-star"></i> Review the Clinic</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Optional — this is a one-time review of your overall experience with us. Our team may feature it on our website if selected.</p>

                <div class="mb-3 text-center">
                    <label class="form-label d-block">Your Rating</label>
                    <div class="patient-review-stars" id="patientReviewStars">
                        <i class="bi bi-star patient-review-star" data-value="1"></i>
                        <i class="bi bi-star patient-review-star" data-value="2"></i>
                        <i class="bi bi-star patient-review-star" data-value="3"></i>
                        <i class="bi bi-star patient-review-star" data-value="4"></i>
                        <i class="bi bi-star patient-review-star" data-value="5"></i>
                    </div>
                    <input type="hidden" id="patientReviewRating" value="0">
                </div>

                <div class="mb-2">
                    <label class="form-label">Comments (optional)</label>
                    <textarea id="patientReviewComment" class="form-control" rows="3" maxlength="1000" placeholder="Tell us about your experience..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Not Now</button>
                <button type="button" class="btn btn-primary" id="submitPatientReviewBtn">Submit Review</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const dashboardCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    const promptCard = document.getElementById('patientReviewPromptCard');
    const reviewModalEl = document.getElementById('patientReviewModal');
    const ratingEl = document.getElementById('patientReviewRating');
    const commentEl = document.getElementById('patientReviewComment');
    const starsWrap = document.getElementById('patientReviewStars');
    const stars = starsWrap ? Array.from(starsWrap.querySelectorAll('.patient-review-star')) : [];
    const submitBtn = document.getElementById('submitPatientReviewBtn');
    let reviewModal = null;

    if (typeof bootstrap !== 'undefined' && reviewModalEl) {
        reviewModal = bootstrap.Modal.getOrCreateInstance(reviewModalEl);
    }

    function setRating(value) {
        if (ratingEl) ratingEl.value = value;
        stars.forEach(function(star) {
            const starValue = parseInt(star.getAttribute('data-value'), 10);
            star.classList.toggle('active', starValue <= value);
            star.classList.toggle('bi-star', starValue > value);
            star.classList.toggle('bi-star-fill', starValue <= value);
        });
    }

    stars.forEach(function(star) {
        star.addEventListener('click', function() {
            setRating(parseInt(star.getAttribute('data-value'), 10));
        });
    });

    function toast(message, type) {
        if (typeof window.showAppToast === 'function') {
            window.showAppToast(message, type);
        }
    }

    if (submitBtn) {
        submitBtn.addEventListener('click', async function() {
            const rating = ratingEl ? String(ratingEl.value || '0').trim() : '0';
            const comment = commentEl ? String(commentEl.value || '').trim() : '';

            if (parseInt(rating, 10) < 1) {
                toast('Please select a star rating before submitting.', 'warning');
                return;
            }

            submitBtn.disabled = true;
            const originalLabel = submitBtn.innerHTML;
            submitBtn.innerHTML = 'Submitting...';
            try {
                const fd = new FormData();
                fd.append('rating', rating);
                fd.append('comment', comment);
                fd.append('csrf_token', dashboardCsrfToken);

                const r = await fetch('../assets/api/appointments/submit_feedback.php', {
                    method: 'POST',
                    body: fd
                });
                const j = await r.json();

                if (!j.success) {
                    toast(j.message || 'Failed to submit review.', 'danger');
                    return;
                }

                if (reviewModal) reviewModal.hide();
                toast(j.message || 'Thank you for your review!', 'success');
                if (promptCard) promptCard.remove();
            } catch (err) {
                console.error(err);
                toast('Server error while submitting review.', 'danger');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalLabel;
            }
        });
    }
});
</script>
<?php endif; ?>
