<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/login_human_check.php';
require_once __DIR__ . '/includes/rate_limit.php';

kq_start_session();
apply_secure_headers();
$loginHumanChallenge = issue_login_human_challenge();

if (isset($_SESSION['user'])) {
    header('Location: ' . kq_dashboard_destination($_SESSION['user']));
    exit();
}

$loginNotice = '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && kq_clear_pending_client_two_factor($conn)) {
    $loginNotice = 'Client accounts do not use an email verification code at sign-in. Please enter your password again.';
}

$error = kq_process_user_login($conn);
$twoFactorNotice = is_string($_SESSION['pending_2fa_notice'] ?? null)
    ? (string) $_SESSION['pending_2fa_notice']
    : '';
unset($_SESSION['pending_2fa_notice']);
$showForgotPasswordHint = $error === 'Invalid email or password. Please try again.';
include __DIR__ . '/includes/header.php';
?>

<div class="kq-auth-page">
    <aside class="kq-auth-panel card" aria-label="Sign-in panel">
        <div class="kq-brand" aria-label="4 Kings Realty Services logo">
            <img class="kq-official-logo kq-logo-auth" src="assets/images/kings-land-properties-logo.png" alt="4 Kings Realty Services">
            <div class="kq-brand-sub">KingQuiry account</div>
        </div>

        <h2>Sign in</h2>
        <p class="subcopy">Sign in to continue to your KingQuiry account.</p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($loginNotice !== ''): ?>
            <div class="alert alert-info" role="status"><?= htmlspecialchars($loginNotice, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($twoFactorNotice !== ''): ?>
            <div class="alert alert-success" role="status"><?= htmlspecialchars($twoFactorNotice, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if (!empty($_GET['password_reset'])): ?>
            <div class="alert alert-success" role="status">Your password has been reset. You can now sign in.</div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['pending_2fa_user_id'])): ?>
            <?php include __DIR__ . '/includes/login_2fa_form.php'; ?>
        <?php else: ?>
            <form method="POST" autocomplete="off" class="kq-auth-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="kq-form-group">
                    <label for="login-identifier">Email address or username</label>
                    <input type="text" id="login-identifier" name="identifier" required maxlength="100" placeholder="Enter your email or username" autocomplete="username">
                </div>

                <div class="kq-form-group">
                    <label for="login-password">Password</label>
                    <div class="kq-password-wrap">
                        <input type="password" id="login-password" name="password" required maxlength="255" placeholder="Enter your password" autocomplete="current-password">
                        <button type="button" class="kq-toggle-password" id="toggle-password" data-toggle-password="login-password" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                </div>

                <?php include __DIR__ . '/includes/login_human_check_field.php'; ?>

                <div class="kq-auth-help-links" aria-label="Account assistance">
                    <a
                        class="kq-auth-help-link<?= $showForgotPasswordHint ? ' is-recommended' : '' ?>"
                        href="forgot_password.php"
                    >
                        <svg class="kq-auth-help-icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="8" cy="8" r="5"/><path d="m11.5 11.5 9 9M17 17l2-2M14 14l2-2"/></svg>
                        <span><?= $showForgotPasswordHint ? 'Forgot your password? Reset it here.' : 'Forgot password?' ?></span>
                    </a>
                    <button class="kq-auth-help-link" type="button" data-bs-toggle="modal" data-bs-target="#contactUsModal">
                        <svg class="kq-auth-help-icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/></svg><span>Need help signing in? Contact support</span>
                    </button>
                </div>

                <button type="submit" class="kq-auth-submit">Sign in</button>
            </form>
        <?php endif; ?>

        <?php if (empty($_SESSION['pending_2fa_user_id'])): ?>
            <div class="kq-auth-register">
                New to KingQuiry? <a href="register.php">Create an account</a>
            </div>
        <?php endif; ?>
    </aside>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
