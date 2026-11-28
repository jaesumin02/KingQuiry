<?php
$login2faChannel = ($_SESSION['pending_2fa_channel'] ?? 'email') === 'sms' ? 'sms' : 'email';
$login2faPhoneMask = is_string($_SESSION['pending_2fa_phone_mask'] ?? null)
    ? (string) $_SESSION['pending_2fa_phone_mask']
    : '';
$login2faSmsAvailable = !empty($_SESSION['pending_2fa_sms_available']) && $login2faPhoneMask !== '';
?>
<form method="POST" autocomplete="off" class="kq-auth-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="verify_2fa" value="1">
    <p class="text-muted mb-3">Enter the 6-digit code sent to your registered <?= $login2faChannel === 'sms' ? 'phone' : 'email' ?>. It expires in 10 minutes.</p>
    <?php if (!empty($_SESSION['pending_2fa_email_delivery_failed'])): ?>
        <div class="alert alert-warning py-2" role="status">
            Email delivery failed. Try SMS below or contact the administrator.
        </div>
    <?php endif; ?>
    <?php if (
        $login2faChannel === 'email'
        && empty($_SESSION['pending_2fa_email_delivery_failed'])
        &&
        strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development')) !== 'production'
        && !filter_var(getenv('KINGQUIRY_DEV_MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN)
    ): ?>
        <p class="small text-muted mb-3" role="status">Demo mode: check <code>logs/email_notifications.log</code> for the code; it is not emailed.</p>
    <?php endif; ?>
    <div class="kq-form-group">
        <label for="verification-code"><?= $login2faChannel === 'sms' ? 'SMS verification code' : 'Email verification code' ?></label>
        <input type="text" id="verification-code" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
    </div>
    <button type="submit" class="kq-auth-submit">Verify and sign in</button>
</form>
<?php if ($login2faSmsAvailable): ?>
    <form method="POST" class="mt-3 text-center">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit" name="send_2fa_sms" value="1" class="btn btn-outline-primary w-100">
            <?= $login2faChannel === 'sms' ? 'Resend code by SMS' : 'Try another way: send code to phone' ?>
        </button>
        <p class="text-muted small mt-2 mb-0">Verified phone ending in <?= htmlspecialchars(substr($login2faPhoneMask, -4), ENT_QUOTES, 'UTF-8') ?>.</p>
    </form>
<?php else: ?>
    <p class="small text-muted mt-3 mb-2" role="note">SMS is unavailable. A verified phone number and configured SMS service are required.</p>
<?php endif; ?>
<form method="POST" class="mt-2 text-center">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" name="cancel_2fa" value="1" class="btn btn-link btn-sm">Back to sign in</button>
</form>
