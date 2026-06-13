<?php
/**
 * Renders a password field with visibility toggle on the right.
 *
 * Expected variables:
 * - string $password_field_id
 * - string $password_field_label
 * - string $password_field_attrs  optional extra input attributes (e.g. required minlength="8")
 */
$password_field_id = (string)($password_field_id ?? '');
$password_field_label = (string)($password_field_label ?? 'Password');
$password_field_attrs = (string)($password_field_attrs ?? '');
$password_field_after = (string)($password_field_after ?? '');
?>
<div class="mb-3">
    <label class="form-label" for="<?php echo htmlspecialchars($password_field_id, ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo htmlspecialchars($password_field_label, ENT_QUOTES, 'UTF-8'); ?>
    </label>
    <div class="password-toggle-wrap password-toggle-wrap--bootstrap">
        <input
            type="password"
            class="form-control password-toggle-input--bootstrap"
            id="<?php echo htmlspecialchars($password_field_id, ENT_QUOTES, 'UTF-8'); ?>"
            <?php echo $password_field_attrs; ?>
        >
        <button
            type="button"
            class="password-toggle-btn password-toggle-btn--auth"
            data-password-toggle-btn="1"
            data-password-target="<?php echo htmlspecialchars($password_field_id, ENT_QUOTES, 'UTF-8'); ?>"
            aria-label="Show password"
            aria-pressed="false"
        >
            <svg class="password-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/>
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
        </button>
    </div>
    <?php if (!empty($password_field_after)) { echo $password_field_after; } ?>
</div>
