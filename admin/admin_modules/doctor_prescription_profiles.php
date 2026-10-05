<?php
include_once '../config/database.php';
include_once '../config/security.php';
include_once '../config/capabilities.php';
security_start_session();
$csrf_token = security_generate_csrf_token();

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS doctor_prescription_profiles (
    profile_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    full_name VARCHAR(255) NOT NULL,
    professional_title VARCHAR(50) DEFAULT 'DMD',
    licenses JSON DEFAULT NULL,
    signature_image VARCHAR(500) DEFAULT NULL,
    signature_drawn LONGTEXT DEFAULT NULL,
    clinic_name VARCHAR(255) DEFAULT 'Azucena\'s Dental Clinic',
    clinic_address VARCHAR(500) DEFAULT 'Metz Arcade, Cagayan de Oro City, Philippines',
    clinic_phone VARCHAR(50) DEFAULT '0917 984 3031',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_profile (user_id),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Handle form submission
$action = $_POST['action'] ?? '';
if ($action === 'save_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    capabilities_require_web('manage_users', 'admin.php?page=doctor_management&tab=prescription_profiles');
    
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Invalid security token. Please refresh and try again.';
        $_SESSION['message_type'] = 'danger';
    } else {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $professional_title = trim((string)($_POST['professional_title'] ?? 'DMD'));
        
        // Build licenses array from dynamic fields
        $licenses = [];
        $i = 1;
        while (isset($_POST['license_type_' . $i])) {
            $type = trim((string)($_POST['license_type_' . $i] ?? ''));
            $number = trim((string)($_POST['license_number_' . $i] ?? ''));
            if ($type !== '' || $number !== '') {
                $licenses[] = ['type' => $type, 'number' => $number];
            }
            $i++;
        }
        $licensesJson = !empty($licenses) ? json_encode($licenses) : null;
        
        $signature_drawn = trim((string)($_POST['signature_drawn'] ?? ''));
        $clinic_name = trim((string)($_POST['clinic_name'] ?? 'Azucena\'s Dental Clinic'));
        $clinic_address = trim((string)($_POST['clinic_address'] ?? 'Metz Arcade, Cagayan de Oro City, Philippines'));
        $clinic_phone = trim((string)($_POST['clinic_phone'] ?? '0917 984 3031'));
        
        // Handle signature image upload
        $signature_image = '';
        if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../assets/uploads/doctor_signatures/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            // Must be a real raster image; the extension comes from its detected type, never
            // from the filename. SVG is not accepted since it can carry script.
            $sigTypes = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
            $sigTmp = (string)$_FILES['signature_image']['tmp_name'];
            $sigInfo = is_uploaded_file($sigTmp) ? @getimagesize($sigTmp) : false;
            $ext = $sigInfo ? ($sigTypes[(int)$sigInfo[2]] ?? '') : '';
            if ($ext !== '' && (int)$_FILES['signature_image']['size'] <= 2 * 1024 * 1024) {
                $filename = 'signature_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                $dest = $upload_dir . $filename;
                if (move_uploaded_file($_FILES['signature_image']['tmp_name'], $dest)) {
                    $signature_image = 'assets/uploads/doctor_signatures/' . $filename;
                }
            }
        }
        
        if ($user_id > 0 && $full_name !== '') {
            $stmt = $conn->prepare("INSERT INTO doctor_prescription_profiles (user_id, full_name, professional_title, licenses, signature_image, signature_drawn, clinic_name, clinic_address, clinic_phone)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    full_name = VALUES(full_name),
                    professional_title = VALUES(professional_title),
                    licenses = VALUES(licenses),
                    signature_image = VALUES(signature_image),
                    signature_drawn = VALUES(signature_drawn),
                    clinic_name = VALUES(clinic_name),
                    clinic_address = VALUES(clinic_address),
                    clinic_phone = VALUES(clinic_phone),
                    updated_at = CURRENT_TIMESTAMP");
            $stmt->bind_param('issssssss', $user_id, $full_name, $professional_title, $licensesJson, $signature_image, $signature_drawn, $clinic_name, $clinic_address, $clinic_phone);
            if ($stmt->execute()) {
                $_SESSION['message'] = 'Prescription profile saved successfully!';
                $_SESSION['message_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Error saving profile: ' . $stmt->error;
                $_SESSION['message_type'] = 'danger';
            }
            $stmt->close();
        } else {
            $_SESSION['message'] = 'Please select a doctor and enter their name.';
            $_SESSION['message_type'] = 'warning';
        }
    }
}

// Fetch all doctors (role_id = 2)
$doctors = [];
$doc_stmt = $conn->prepare('SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone_number, 
    dpp.profile_id, dpp.full_name, dpp.professional_title, dpp.licenses,
    dpp.signature_image, dpp.signature_drawn, dpp.clinic_name, dpp.clinic_address, dpp.clinic_phone
    FROM users u
    LEFT JOIN doctor_prescription_profiles dpp ON dpp.user_id = u.user_id
    WHERE u.role_id = 2 AND u.is_active = 1
    ORDER BY u.last_name, u.first_name');
if ($doc_stmt) {
    $doc_stmt->execute();
    $doc_res = $doc_stmt->get_result();
    while ($row = $doc_res->fetch_assoc()) {
        $doctors[] = $row;
    }
    $doc_stmt->close();
}
?>

<div class="card border-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><i class="bi bi-file-medical"></i> Doctor Prescription Profiles</h5>
    </div>
    <div class="card-body">
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
                <?php echo $_SESSION['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
        <?php endif; ?>
        
        <div class="table-responsive">
            <table class="table table-hover datatable" data-skip-table-search="1">
                <thead class="table-light">
                    <tr>
                        <th>Doctor</th>
                        <th>Professional Title</th>
                        <th>License(s)</th>
                        <th>Signature</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($doctors)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No active doctors found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($doctors as $doc): ?>
                            <?php
                            $hasProfile = !empty($doc['profile_id']);
                            $licenseDisplay = '';
                            $licenses = !empty($doc['licenses']) ? json_decode($doc['licenses'], true) : [];
                            if (!empty($licenses)) {
                                foreach ($licenses as $lic) {
                                    $licenseDisplay .= ($licenseDisplay ? '<br>' : '') . htmlspecialchars($lic['type'] ?? '') . ': ' . htmlspecialchars($lic['number'] ?? '');
                                }
                            }
                            $hasSignature = !empty($doc['signature_image']) || !empty($doc['signature_drawn']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></strong>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($doc['email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($doc['professional_title'] ?? 'DMD'); ?></td>
                                <td><?php echo $licenseDisplay ?: '<span class="text-muted">Not set</span>'; ?></td>
                                <td>
                                    <?php if ($hasSignature): ?>
                                        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Set</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="bi bi-x-circle"></i> Not set</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addProfileModal"
                                        onclick='editProfile(<?php echo htmlspecialchars(json_encode($doc), ENT_QUOTES, 'UTF-8'); ?>)'>
                                        <i class="bi bi-pencil"></i> Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Profile Modal -->
<div class="modal fade" id="addProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="max-height: 90vh;">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="profileModalTitle"><i class="bi bi-file-medical"></i> Add Prescription Profile</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="profileForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="save_profile">
                <input type="hidden" name="user_id" id="profile_user_id">
                <!-- Clinic details are fixed clinic-wide info, not part of a doctor's own
                     prescription credentials, so they're not editable per profile here. -->
                <input type="hidden" name="clinic_name" value="Azucena's Dental Clinic">
                <input type="hidden" name="clinic_phone" value="0917 984 3031">
                <input type="hidden" name="clinic_address" value="Metz Arcade, Cagayan de Oro City, Philippines">
                <div class="modal-body" style="overflow-y: auto; max-height: calc(90vh - 120px);">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Doctor</label>
                            <input type="text" class="form-control" id="profile_doctor_display" readonly style="background:#f8f9fa;">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name (as it should appear on prescription) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="full_name" id="profile_full_name" required placeholder="e.g., Dr. Juan Dela Cruz, DMD">
                            <small class="text-muted" id="profile_full_name_lock_hint" style="display:none;">Name is already set for this profile and can no longer be edited here. Only Professional Title & Licenses (and below) can be updated.</small>
                        </div>
                    </div>

                    <hr class="my-3">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-award"></i> Professional Title & Licenses</h6>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Professional Title</label>
                            <select class="form-select" name="professional_title" id="profile_professional_title">
                                <option value="DMD">DMD (Doctor of Dental Medicine)</option>
                                <option value="DDS">DDS (Doctor of Dental Surgery)</option>
                                <option value="MD">MD (Doctor of Medicine)</option>
                                <option value="DMD, MD">DMD, MD</option>
                                <option value="Custom">Custom...</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="customTitleField" style="display:none;">
                            <label class="form-label">Custom Title</label>
                            <input type="text" class="form-control" name="professional_title_custom" id="profile_professional_title_custom" placeholder="Enter custom title">
                        </div>
                    </div>
                    
                    <div class="row g-3 mt-2" id="licenseFields">
                        <div class="col-md-4">
                            <label class="form-label">License Type 1</label>
                            <input type="text" class="form-control" name="license_type_1" id="profile_license_type_1" value="PRC" placeholder="e.g., PRC">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">License Number 1</label>
                            <input type="text" class="form-control" name="license_number_1" id="profile_license_number_1" placeholder="License number">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="addLicenseField()">+ Add Another</button>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-pen"></i> Signature</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Draw Signature</label>
                            <div class="border rounded p-2" style="background:#f8f9fa; position:relative;" id="signatureCanvasContainer">
                                <canvas id="signatureCanvas" style="width:100%; touch-action:none; cursor:crosshair; background:white; display:block;"></canvas>
                                <div id="signaturePlaceholder" class="position-absolute top-50 start-50 translate-middle text-muted" style="pointer-events:none;">
                                    <i class="bi bi-pencil fs-1"></i><br>Draw signature here
                                </div>
                            </div>
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="clearSignature()">Clear</button>
                            </div>
                            <input type="hidden" name="signature_drawn" id="profile_signature_drawn">
                            <small class="text-muted">Draw your signature using mouse or touch. It will be saved with the profile.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Or Upload Signature Image</label>
                            <input type="file" class="form-control" name="signature_image" accept="image/png,image/jpeg,image/gif,image/webp">
                            <div id="signaturePreview" class="mt-2" style="display:none;">
                                <img id="previewImg" src="" alt="Signature preview" style="max-height:120px; border:1px solid #dee2e6; border-radius:4px;">
                            </div>
                            <small class="text-muted">Supported formats: PNG, JPG, GIF, WEBP (max 2MB). Recommended: transparent background PNG.</small>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Signature canvas handling
var signatureCanvas = null;
var signatureCtx = null;
var isDrawing = false;
var lastX = 0;
var lastY = 0;
var strokePoints = []; // Store points for smooth rendering

function initSignatureCanvas() {
    signatureCanvas = document.getElementById('signatureCanvas');
    if (!signatureCanvas) return;
    signatureCtx = signatureCanvas.getContext('2d');
    
    // Set canvas actual size first
    resizeCanvasToContainer();
    
    signatureCanvas.addEventListener('mousedown', startDrawing);
    signatureCanvas.addEventListener('mousemove', draw);
    signatureCanvas.addEventListener('mouseup', stopDrawing);
    signatureCanvas.addEventListener('mouseout', stopDrawing);
    
    signatureCanvas.addEventListener('touchstart', handleTouch);
    signatureCanvas.addEventListener('touchmove', handleTouch);
    signatureCanvas.addEventListener('touchend', stopDrawing);
}

function getCanvasCoordinates(e) {
    const rect = signatureCanvas.getBoundingClientRect();
    const clientX = e.clientX || (e.touches && e.touches[0].clientX);
    const clientY = e.clientY || (e.touches && e.touches[0].clientY);
    return {
        x: clientX - rect.left,
        y: clientY - rect.top
    };
}

function startDrawing(e) {
    isDrawing = true;
    const coords = getCanvasCoordinates(e);
    lastX = coords.x;
    lastY = coords.y;
    strokePoints = [{ x: coords.x, y: coords.y }]; // Start new stroke
    document.getElementById('signaturePlaceholder').style.display = 'none';
}

function draw(e) {
    if (!isDrawing) return;
    e.preventDefault();
    const coords = getCanvasCoordinates(e);
    strokePoints.push({ x: coords.x, y: coords.y });
    
    // Draw smooth curve using quadratic interpolation
    if (strokePoints.length >= 3) {
        signatureCtx.beginPath();
        signatureCtx.moveTo(strokePoints[0].x, strokePoints[0].y);
        
        for (let i = 1; i < strokePoints.length - 2; i++) {
            const xc = (strokePoints[i].x + strokePoints[i + 1].x) / 2;
            const yc = (strokePoints[i].y + strokePoints[i + 1].y) / 2;
            signatureCtx.quadraticCurveTo(strokePoints[i].x, strokePoints[i].y, xc, yc);
        }
        
        // Last segment
        const last = strokePoints.length - 1;
        signatureCtx.quadraticCurveTo(
            strokePoints[last - 1].x, strokePoints[last - 1].y,
            strokePoints[last].x, strokePoints[last].y
        );
        
        signatureCtx.stroke();
    } else if (strokePoints.length === 2) {
        // First segment - simple line
        signatureCtx.beginPath();
        signatureCtx.moveTo(strokePoints[0].x, strokePoints[0].y);
        signatureCtx.lineTo(strokePoints[1].x, strokePoints[1].y);
        signatureCtx.stroke();
    }
    
    lastX = coords.x;
    lastY = coords.y;
}

function stopDrawing() {
    if (isDrawing && signatureCanvas) {
        // Persist the drawn bitmap into the hidden field now — this is what
        // actually gets submitted; without it, a freshly drawn signature is
        // never saved.
        document.getElementById('profile_signature_drawn').value = signatureCanvas.toDataURL('image/png');
    }
    isDrawing = false;
    strokePoints = [];
}

function handleTouch(e) {
    if (e.touches.length === 1) {
        e.preventDefault();
        const touch = e.touches[0];
        const mouseEvent = new MouseEvent(e.type === 'touchstart' ? 'mousedown' : 'mousemove', {
            clientX: touch.clientX,
            clientY: touch.clientY
        });
        signatureCanvas.dispatchEvent(mouseEvent);
    }
}

function clearSignature() {
    if (signatureCtx && signatureCanvas) {
        signatureCtx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
        document.getElementById('signaturePlaceholder').style.display = 'flex';
        document.getElementById('profile_signature_drawn').value = '';
    }
}

// Responsive canvas sizing
function resizeCanvasToContainer() {
    if (!signatureCanvas || !signatureCtx) return;
    const container = document.getElementById('signatureCanvasContainer');
    const rect = container.getBoundingClientRect();
    
    // Determine canvas dimensions based on viewport
    const isMobile = window.innerWidth < 768;
    let canvasWidth, canvasHeight;
    
    if (isMobile) {
        // Mobile: landscape orientation (wider than tall)
        // Use container width, make height ~60% of width for landscape feel
        canvasWidth = rect.width;
        canvasHeight = Math.max(180, rect.width * 0.6);
    } else {
        // Desktop: larger canvas for precision
        canvasWidth = rect.width;
        canvasHeight = 200; // Fixed taller height for desktop
    }
    
    // Save current drawing
    const imageData = signatureCanvas.width > 0 && signatureCanvas.height > 0 
        ? signatureCtx.getImageData(0, 0, signatureCanvas.width, signatureCanvas.height)
        : null;
    
    // Set canvas actual size
    signatureCanvas.width = canvasWidth;
    signatureCanvas.height = canvasHeight;
    signatureCanvas.style.height = canvasHeight + 'px';
    
    // Restore drawing if exists
    if (imageData) {
        // Scale the image data to new dimensions
        const tempCanvas = document.createElement('canvas');
        tempCanvas.width = signatureCanvas.width;
        tempCanvas.height = signatureCanvas.height;
        const tempCtx = tempCanvas.getContext('2d');
        tempCtx.drawImage(signatureCanvas, 0, 0, signatureCanvas.width, signatureCanvas.height);
        signatureCtx.drawImage(tempCanvas, 0, 0);
    }
    
    // Re-apply drawing styles - optimized for signature
    signatureCtx.strokeStyle = '#000';
    signatureCtx.lineWidth = Math.max(1.5, canvasWidth / 400); // Thinner, more natural line
    signatureCtx.lineCap = 'round';
    signatureCtx.lineJoin = 'round';
    signatureCtx.miterLimit = 2;
    signatureCtx.shadowColor = 'rgba(0,0,0,0.1)';
    signatureCtx.shadowBlur = 0;
    signatureCtx.shadowOffsetX = 0;
    signatureCtx.shadowOffsetY = 0;
}

// Handle window resize
var resizeTimeout;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(resizeCanvasToContainer, 100);
});

// Profile modal handling
// The canvas can only be sized correctly once the modal is actually visible
// (a hidden modal reports 0 width), so any signature to preload is stashed
// here by editProfile() and drawn once 'shown.bs.modal' fires — drawing it
// immediately on click (before the modal is shown) silently no-ops.
var pendingSignatureDrawn = null;

document.getElementById('addProfileModal')?.addEventListener('shown.bs.modal', function() {
    initSignatureCanvas();
    if (signatureCtx && signatureCanvas) {
        signatureCtx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
    }
    if (pendingSignatureDrawn) {
        const img = new Image();
        img.onload = function() {
            if (signatureCtx && signatureCanvas) {
                signatureCtx.clearRect(0, 0, signatureCanvas.width, signatureCanvas.height);
                signatureCtx.drawImage(img, 0, 0, signatureCanvas.width, signatureCanvas.height);
                document.getElementById('signaturePlaceholder').style.display = 'none';
            }
        };
        img.src = pendingSignatureDrawn;
    } else {
        document.getElementById('signaturePlaceholder').style.display = 'flex';
    }
    pendingSignatureDrawn = null;
});

function editProfile(doc) {
    // Reset any leftover state from a previous time this modal was opened
    // (extra license rows, custom-title lock, signature) before loading doc.
    const licenseFieldsContainer = document.getElementById('licenseFields');
    while (licenseFieldsContainer.children.length > 3) {
        licenseFieldsContainer.lastChild.remove();
    }
    licenseCount = 1;
    document.getElementById('profile_professional_title').disabled = false;
    const oldHiddenTitle = document.getElementById('professional_title_final');
    if (oldHiddenTitle) oldHiddenTitle.remove();

    document.getElementById('profileModalTitle').innerHTML = '<i class="bi bi-file-medical"></i> '
        + (doc.full_name ? 'Edit' : 'Set Up') + ' Prescription Profile';
    document.getElementById('profile_user_id').value = doc.user_id;
    document.getElementById('profile_doctor_display').value = 'Dr. ' + doc.first_name + ' ' + doc.last_name;
    const nameField = document.getElementById('profile_full_name');
    nameField.value = doc.full_name || 'Dr. ' + doc.first_name + ' ' + doc.last_name;

    // Once a profile already has a name, lock it — editing here should only
    // touch Professional Title & Licenses (and the sections below it).
    const nameAlreadySet = !!(doc.full_name && doc.full_name.trim() !== '');
    nameField.readOnly = nameAlreadySet;
    nameField.classList.toggle('bg-light', nameAlreadySet);
    nameField.title = nameAlreadySet ? 'Name is locked once set.' : '';
    document.getElementById('profile_full_name_lock_hint').style.display = nameAlreadySet ? '' : 'none';

    // Handle professional title - check if it's a custom value
    const predefinedTitles = ['DMD', 'DDS', 'MD', 'DMD, MD'];
    const profTitle = doc.professional_title || 'DMD';
    if (predefinedTitles.includes(profTitle)) {
        document.getElementById('profile_professional_title').value = profTitle;
        document.getElementById('customTitleField').style.display = 'none';
        document.getElementById('profile_professional_title_custom').required = false;
        document.getElementById('profile_professional_title_custom').value = '';
    } else {
        document.getElementById('profile_professional_title').value = 'Custom';
        document.getElementById('customTitleField').style.display = '';
        document.getElementById('profile_professional_title_custom').required = true;
        document.getElementById('profile_professional_title_custom').value = profTitle;
    }
    
    // Set licenses from JSON
    const licenses = doc.licenses ? JSON.parse(doc.licenses) : [];
    if (licenses.length > 0) {
        licenses.forEach((lic, idx) => {
            if (idx === 0) {
                document.getElementById('profile_license_type_1').value = lic.type || '';
                document.getElementById('profile_license_number_1').value = lic.number || '';
            } else {
                addLicenseField(lic.type || '', lic.number || '');
            }
        });
    } else {
        document.getElementById('profile_license_type_1').value = 'PRC';
        document.getElementById('profile_license_number_1').value = '';
    }
    
    // Set signature — actual drawing is deferred to 'shown.bs.modal' since the
    // canvas can't be sized correctly while the modal is still hidden.
    document.getElementById('profile_signature_drawn').value = doc.signature_drawn || '';
    pendingSignatureDrawn = doc.signature_drawn || null;
    if (doc.signature_image) {
        document.getElementById('previewImg').src = '../' + doc.signature_image;
        document.getElementById('signaturePreview').style.display = 'block';
    } else {
        document.getElementById('signaturePreview').style.display = 'none';
    }
}

var licenseCount = 1;
function addLicenseField(type = '', number = '') {
    licenseCount++;
    const container = document.getElementById('licenseFields');
    const div = document.createElement('div');
    div.className = 'row g-3 license-row';
    div.innerHTML = `
        <div class="col-md-4">
            <label class="form-label">License Type ${licenseCount}</label>
            <input type="text" class="form-control" name="license_type_${licenseCount}" value="${type}" placeholder="e.g., PTR">
        </div>
        <div class="col-md-4">
            <label class="form-label">License Number ${licenseCount}</label>
            <input type="text" class="form-control" name="license_number_${licenseCount}" value="${number}" placeholder="License number">
        </div>
        <div class="col-md-4">
            <label class="form-label">&nbsp;</label>
            <button type="button" class="btn btn-outline-danger btn-sm w-100" onclick="this.closest('.license-row').remove(); licenseCount--;">Remove</button>
        </div>
    `;
    container.appendChild(div);
}

// Handle custom professional title field
document.getElementById('profile_professional_title')?.addEventListener('change', function() {
    const customField = document.getElementById('customTitleField');
    if (this.value === 'Custom') {
        customField.style.display = '';
        document.getElementById('profile_professional_title_custom').required = true;
    } else {
        customField.style.display = 'none';
        document.getElementById('profile_professional_title_custom').required = false;
        document.getElementById('profile_professional_title_custom').value = '';
    }
});

// Handle form submission - handle custom professional title
document.getElementById('profileForm')?.addEventListener('submit', function(e) {
    // Handle custom professional title
    const profTitleSelect = document.getElementById('profile_professional_title');
    const customTitleInput = document.getElementById('profile_professional_title_custom');
    if (profTitleSelect && profTitleSelect.value === 'Custom' && customTitleInput.value.trim()) {
        // Create a hidden input with the custom value
        let hiddenInput = document.getElementById('professional_title_final');
        if (!hiddenInput) {
            hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'professional_title';
            hiddenInput.id = 'professional_title_final';
            this.appendChild(hiddenInput);
        }
        hiddenInput.value = customTitleInput.value.trim();
        // Disable the select so it doesn't override
        profTitleSelect.disabled = true;
    }
});
</script>