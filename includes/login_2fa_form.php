<form method="POST" autocomplete="off" class="kq-auth-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="verify_2fa" value="1">
    <p class="text-muted">Enter the 6-digit code sent to your account email. The code expires in 10 minutes.</p>
    <div class="kq-form-group">
        <label for="verification-code">Email verification code</label>
        <input type="text" id="verification-code" name="verification_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
    </div>
    <button type="submit" class="kq-auth-submit">Verify and sign in</button>
</form>
<form method="POST" class="mt-2 text-center">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit" name="cancel_2fa" value="1" class="btn btn-link btn-sm">Cancel sign in</button>
</form>
