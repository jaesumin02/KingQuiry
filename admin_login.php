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

$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!verify_login_human_challenge($_POST)) {
        $error = 'Complete the human check before signing in.';
    }
    $postedIdentifier = $_POST['identifier'] ?? $_POST['email'] ?? '';
    $identifier = is_string($postedIdentifier) ? trim(substr($postedIdentifier, 0, 100)) : '';
    $password = $_POST['password'] ?? '';
    $loginIpAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';

    if ($error === '') {
        $retryAfter = kq_login_rate_limit($conn, $identifier, $loginIpAddress);
        if ($retryAfter !== null) {
            $error = 'Too many sign-in attempts. Please wait ' . (int) ceil($retryAfter / 60) . ' minute(s) and try again.';
        }
    }

    if ($error === '' && $identifier !== '' && is_string($password) && $password !== '') {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR SUBSTRING_INDEX(email, '@', 1) = ? LIMIT 2");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $normalizedRole = normalized_user_role($user);
            if (password_verify($password, $user['passwordHash'])) {
                if (($user['accountStatus'] ?? 'Active') === 'Inactive') {
                    $error = 'This account is inactive. Please contact the administrator.';
                } else {
                    kq_clear_login_rate_limits($conn, $identifier, $loginIpAddress);
                    kq_regenerate_session_after_login($user);
                    $destination = $normalizedRole === 'Admin'
                        ? 'admin_dashboard.php'
                        : ($normalizedRole === 'Sales Agent' ? 'agent_dashboard.php' : 'index.php');
                    header('Location: ' . $destination);
                    exit();
                }
            }
        }
    }

    if ($error === '') {
        $error = 'Invalid email or password for the admin portal.';
    }
}
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
