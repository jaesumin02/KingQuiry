<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/login_human_check.php';
require_once 'includes/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

apply_secure_headers();
$loginHumanChallenge = issue_login_human_challenge();

if (isset($_SESSION['user'])) {
    $role = normalized_user_role($_SESSION['user']);
    $destination = $role === 'Admin' ? 'admin_dashboard.php' : ($role === 'Sales Agent' ? 'agent_dashboard.php' : 'index.php');
    header('Location: ' . $destination);
    exit();
}

$error = kq_process_user_login($conn);
include 'includes/header.php';
?>

<div class="kq-auth-page">
    <aside class="kq-auth-panel card" aria-label="Admin login panel">
        <div class="kq-brand" aria-label="4 Kings Realty Services logo">
            <img class="kq-official-logo kq-logo-auth" src="assets/images/kings-land-properties-logo.png" alt="4 Kings Realty Services">
            <div class="kq-brand-sub">Admin portal</div>
        </div>

        <h2>Admin Sign In</h2>
        <p class="subcopy">Private access for system administrators.</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" class="kq-auth-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="kq-form-group">
                <label for="login-identifier">Email address or username</label>
                <input type="text" id="login-identifier" name="identifier" required maxlength="100" placeholder="Enter your authorized email" autocomplete="off">
            </div>

            <div class="kq-form-group">
                <label for="login-password">Password</label>
                <div class="kq-password-wrap">
                    <input type="password" id="login-password" name="password" required maxlength="255" placeholder="Enter your password" autocomplete="off">
                    <button type="button" class="kq-toggle-password" id="toggle-password" data-toggle-password="login-password" aria-label="Show password" aria-pressed="false">Show</button>
                </div>
            </div>

            <?php include 'includes/login_human_check_field.php'; ?>

            <div class="kq-form-options">
                <a class="kq-link" href="#contactUsModal" data-bs-toggle="modal" data-bs-target="#contactUsModal">Need help signing in? Contact the administrator</a>
            </div>

            <button type="submit" class="kq-auth-submit">Access admin portal</button>
        </form>
    </aside>
</div>

<?php include 'includes/footer.php'; ?>
