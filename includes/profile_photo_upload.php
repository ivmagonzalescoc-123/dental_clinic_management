<?php
/** @var mysqli $conn */
/** @var string $profile_reload_url */
/** @var string $csrf_token */

include_once dirname(__DIR__) . '/config/profile_helpers.php';

$profile_can_upload_photo = profile_role_allows_custom_photo();
$profile_avatar_markup = profile_render_avatar_markup($conn, '..', [
    'class' => 'profile-photo-preview rounded-circle',
    'alt' => 'Profile photo',
]);
?>
<div class="profile-photo-panel mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="profile-photo-preview-wrap">
            <?php echo $profile_avatar_markup; ?>
        </div>
        <div>
            <?php if ($profile_can_upload_photo): ?>
                <h6 class="mb-2">Profile Photo</h6>
                <form id="profilePhotoForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input
                        type="file"
                        class="form-control form-control-sm profile-photo-input"
                        id="profilePhotoInput"
                        name="profile_image"
                        accept="image/jpeg,image/png,image/webp"
                    >
                    <p class="text-muted small mb-2 profile-photo-upload-guide"><?php echo htmlspecialchars(profile_photo_upload_guide_text(), ENT_QUOTES, 'UTF-8'); ?></p>
                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2" id="profilePhotoSaveBtn">
                        Upload Photo
                    </button>
                </form>
                <div id="profilePhotoAlert" class="small mt-2"></div>
            <?php else: ?>
                <h6 class="mb-2">Profile Photo</h6>
                <p class="text-muted small mb-0">Administrator accounts use the default clinic profile image and cannot upload a custom photo.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if ($profile_can_upload_photo): ?>
<script>
function initProfilePhotoUpload() {
    const form = document.getElementById('profilePhotoForm');
    const input = document.getElementById('profilePhotoInput');
    const previewWrap = document.querySelector('.profile-photo-preview-wrap');
    const alertBox = document.getElementById('profilePhotoAlert');
    const saveBtn = document.getElementById('profilePhotoSaveBtn');
    if (!form || !input || !previewWrap || !saveBtn) return;
    if (form.dataset.initialized) return;
    form.dataset.initialized = '1';

    function showPhotoAlert(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(String(message || ''), type || 'info');
        } else if (alertBox) {
            alertBox.innerHTML = '<div class="alert alert-' + (type || 'info') + ' alert-dismissible fade show">' + String(message || '') + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
        }
    }

    const maxBytes = <?php echo (int)profile_photo_max_bytes(); ?>;
    const allowedTypes = <?php echo json_encode(array_keys(profile_photo_allowed_mime_types())); ?>;

    function validateProfilePhotoFile(file) {
        if (!file) {
            return 'Choose an image first.';
        }
        if (!allowedTypes.includes(file.type)) {
            return 'Only JPG, PNG, or WEBP images are allowed.';
        }
        if (file.size > maxBytes) {
            return 'Image must be 2MB or smaller.';
        }
        return '';
    }

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        if (!file) return;

        const validationError = validateProfilePhotoFile(file);
        if (validationError) {
            input.value = '';
            showPhotoAlert(validationError, 'danger');
            return;
        }

        if (alertBox) {
            alertBox.innerHTML = '';
        }
        const reader = new FileReader();
        reader.onload = function (e) {
            let preview = previewWrap.querySelector('img');
            if (!preview) {
                previewWrap.innerHTML = '';
                preview = document.createElement('img');
                preview.className = 'profile-photo-preview rounded-circle';
                preview.alt = 'Profile photo';
                previewWrap.appendChild(preview);
            }
            preview.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const file = input.files && input.files[0];
        const validationError = validateProfilePhotoFile(file);
        if (validationError) {
            showPhotoAlert(validationError, 'danger');
            return;
        }

        saveBtn.disabled = true;
        saveBtn.textContent = 'Uploading...';

        const fd = new FormData(form);
        try {
            const resp = await fetch('../assets/api/auth/upload_profile_image_api.php', {
                method: 'POST',
                body: fd
            });
            const data = await resp.json();
            if (!resp.ok || !data.success) {
                showPhotoAlert(data.message || 'Unable to upload profile photo.', 'danger');
            } else {
                showPhotoAlert('Profile photo updated. Refreshing...', 'success');
                document.querySelectorAll('.header-profile-avatar').forEach(function (node) {
                    if (node.tagName === 'IMG') {
                        node.src = data.profile_image_url + '?t=' + Date.now();
                    }
                });
            }
        } catch (err) {
            showPhotoAlert('Network error while uploading photo.', 'danger');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Upload Photo';
        }
    });
}
document.addEventListener('DOMContentLoaded', initProfilePhotoUpload);
window.addEventListener('page-loaded', function () {
    if (!document.getElementById('profilePhotoForm') || document.getElementById('profilePhotoForm').dataset.initialized) return;
    initProfilePhotoUpload();
});
</script>
<?php endif; ?>
