<div class="kq-form-group kq-human-check">
    <div class="kq-human-check-control">
        <input class="kq-human-check-input" type="checkbox" id="login-human-check" name="human_check" value="1" required>
        <label for="login-human-check">I'm not a robot</label>
        <span class="kq-human-check-status" id="login-human-check-status" role="status" aria-live="polite">Click to verify</span>
    </div>
    <input type="hidden" name="human_token" value="<?= htmlspecialchars($loginHumanChallenge['token'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="human_verified" value="0">
    <div aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden;">
        <label for="login-website">Leave this field empty</label>
        <input type="text" id="login-website" name="website" tabindex="-1" autocomplete="off">
    </div>
    <div class="form-text">Complete this check before signing in.</div>
</div>
