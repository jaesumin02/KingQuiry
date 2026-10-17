<?php
include 'config/db.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (isset($_SESSION['user'])) {
    $role = normalized_user_role($_SESSION['user']);
    $destination = $role === 'Admin' ? 'admin_dashboard.php' : ($role === 'Sales Agent' ? 'agent_dashboard.php' : 'client_dashboard.php');
    header('Location: ' . $destination);
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
    }
    $postedIdentifier = $_POST['identifier'] ?? $_POST['email'] ?? '';
    $identifier = is_string($postedIdentifier) ? trim(substr($postedIdentifier, 0, 100)) : '';
    $password = $_POST['password'] ?? '';

    if ($error === '' && $identifier !== '' && is_string($password) && $password !== '') {
        $stmt = $conn->prepare("SELECT userID, fullname, email, passwordHash, role FROM users WHERE email = ? OR SUBSTRING_INDEX(email, '@', 1) = ? LIMIT 2");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['passwordHash']) && in_array(normalized_user_role($user), ['Admin', 'Sales Agent', 'Client'], true)) {
                session_regenerate_id(true);
                if ($user['role'] === 'Agent') {
                    $user['role'] = 'Sales Agent';
                }
                $_SESSION['user'] = $user;
                $role = normalized_user_role($user);
                $destination = $role === 'Admin' ? 'admin_dashboard.php' : ($role === 'Sales Agent' ? 'agent_dashboard.php' : 'client_dashboard.php');
                header('Location: ' . $destination);
                exit();
            }
        }
    }

    if ($error === '') {
        $error = 'Invalid username/email or password. Please try again.';
    }
}
include 'includes/header.php';
?>

<div class="row justify-content-center align-items-center py-4 py-lg-5">
    <div class="col-md-7 col-lg-5 col-xl-4">
        <div class="card p-4 p-lg-5">
            <div class="page-eyebrow text-center mb-2">Welcome back</div>
            <h1 class="page-title h2 text-center mb-2">Sign in to KingQuiry</h1>
            <p class="text-muted text-center mb-4">Manage your property search and requests.</p>
            
            <?php if(!empty($error)): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="login-identifier">Email address or username</label>
                    <input type="text" id="login-identifier" name="identifier" class="form-control" required maxlength="100" placeholder="name@example.com" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="login-password">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="login-password" class="form-control" required maxlength="255" placeholder="Enter your password" autocomplete="off">
                        <button type="button" class="btn btn-outline-secondary" id="toggle-password" aria-label="Show password" aria-pressed="false">
                            <svg id="show-password-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12s3.5-6 9.75-6 9.75 6 9.75 6-3.5 6-9.75 6-9.75-6-9.75-6Z"/>
                                <circle cx="12" cy="12" r="2.75"/>
                            </svg>
                            <svg id="hide-password-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true" hidden>
                                <path stroke-linecap="round" stroke-linejoin="round" d="m3 3 18 18M10.6 6.2A10.7 10.7 0 0 1 12 6c6.25 0 9.75 6 9.75 6a16.3 16.3 0 0 1-3.1 3.65M6.2 6.2C3.65 7.85 2.25 12 2.25 12s3.5 6 9.75 6c1.05 0 2-.17 2.85-.46"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.88 9.88a3 3 0 0 0 4.24 4.24"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2">Sign in</button>
            </form>
            
            <p class="text-center mt-4 mb-0 small">New to KingQuiry? <a href="register.php" class="fw-semibold">Create an account</a></p>
        </div>
    </div>
</div>

<script>
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });

    const passwordInput = document.getElementById('login-password');
    const passwordToggle = document.getElementById('toggle-password');
    const showPasswordIcon = document.getElementById('show-password-icon');
    const hidePasswordIcon = document.getElementById('hide-password-icon');

    passwordToggle.addEventListener('click', () => {
        const isPasswordHidden = passwordInput.type === 'password';
        passwordInput.type = isPasswordHidden ? 'text' : 'password';
        passwordToggle.setAttribute('aria-label', isPasswordHidden ? 'Hide password' : 'Show password');
        passwordToggle.setAttribute('aria-pressed', String(isPasswordHidden));
        showPasswordIcon.hidden = isPasswordHidden;
        hidePasswordIcon.hidden = !isPasswordHidden;
    });
</script>

<?php include 'includes/footer.php'; ?>