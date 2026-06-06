<?php
/** @var string $profile_image_url */
/** @var string $profile_reload_url */
/** @var string $csrf_token */
?>
<div class="profile-photo-panel mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="profile-photo-preview-wrap">
            <img
                src="<?php echo htmlspecialchars($profile_image_url); ?>"
                alt="Profile photo"
                class="profile-photo-preview rounded-circle"
                id="profilePhotoPreview"
            >
        </div>
        <div>
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
                <button type="submit" class="btn btn-outline-primary btn-sm mt-2" id="profilePhotoSaveBtn">
                    Upload Photo
                </button>
            </form>
            <div id="profilePhotoAlert" class="small mt-2"></div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('profilePhotoForm');
    const input = document.getElementById('profilePhotoInput');
    const preview = document.getElementById('profilePhotoPreview');
    const alertBox = document.getElementById('profilePhotoAlert');
    const saveBtn = document.getElementById('profilePhotoSaveBtn');
    if (!form || !input || !preview || !saveBtn) return;

    function showPhotoAlert(message, type) {
        if (typeof window.showAppToast === 'function') {
            window.showAppToast(String(message || ''), type || 'info');
        }
        alertBox.innerHTML = '<span class="text-' + type + '">' + message + '</span>';
    }

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function (e) {
            preview.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const file = input.files && input.files[0];
        if (!file) {
            showPhotoAlert('Choose an image first.', 'danger');
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
                document.querySelectorAll('.header-profile-avatar').forEach(function (img) {
                    img.src = data.profile_image_url + '?t=' + Date.now();
                });
                setTimeout(function () {
                    window.location.href = <?php echo json_encode($profile_reload_url); ?>;
                }, 700);
            }
        } catch (err) {
            showPhotoAlert('Network error while uploading photo.', 'danger');
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Upload Photo';
        }
    });
});
</script>
