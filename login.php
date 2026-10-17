<?php
include 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user'])) {
    if ($_SESSION['user']['role'] == 'Admin') {
        header("Location: admin_dashboard.php");
    } elseif ($_SESSION['user']['role'] == 'Sales Agent') {
        header("Location: agent_dashboard.php");
    } else {
        header("Location: client_dashboard.php");
    }
    exit();
}

include 'includes/header.php';
$error = ""; // Pormal na in-initialize na walang laman

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $postedIdentifier = $_POST['identifier'] ?? $_POST['email'] ?? '';
    $identifier = is_string($postedIdentifier) ? trim($postedIdentifier) : '';
    $password = $_POST['password'] ?? '';

    if ($identifier !== '' && is_string($password)) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR SUBSTRING_INDEX(email, '@', 1) = ?");
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['passwordHash'])) {
                $_SESSION['user'] = $user;
                if ($user['role'] == 'Admin') {
                    header("Location: admin_dashboard.php");
                } elseif ($user['role'] == 'Sales Agent') {
                    header("Location: agent_dashboard.php");
                } else {
                    header("Location: client_dashboard.php");
                }
                exit();
            }
        }
    }

    $error = "Invalid username/email or password. Please try again.";
}
?>

<style>
    .login-form .form-control,
    .login-form .form-control:-webkit-autofill,
    .login-form .form-control:-webkit-autofill:hover,
    .login-form .form-control:-webkit-autofill:focus {
        color: #111 !important;
        -webkit-text-fill-color: #111 !important;
        caret-color: #000 !important;
        background-color: #e2e5e9 !important;
        border-color: #495057 !important;
        -webkit-box-shadow: 0 0 0 1000px #e2e5e9 inset !important;
        box-shadow: 0 0 0 1000px #e2e5e9 inset !important;
    }

    .login-form .form-control:focus {
        background-color: #d1d5db !important;
        border-color: #212529 !important;
        outline: 2px solid #495057;
        outline-offset: 1px;
    }
</style>

<div class="row justify-content-center my-5">
    <div class="col-md-5">
        <div class="card shadow-sm border-0 p-4">
            <h3 class="fw-bold text-center mb-4">KingQuiry Login</h3>
            
            <!-- Lalabas lamang ang pulang kahon KUNG may laman na ang $error variable pagkatapos mag-submit -->
            <?php if(!empty($error)): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off" class="login-form">
                <div class="mb-3">
                    <label class="form-label">Username or Email Address</label>
                    <input type="text" name="identifier" class="form-control" required placeholder="Enter username or email address" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" name="password" id="login-password" class="form-control" required placeholder="Enter password" autocomplete="off">
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
                <button type="submit" class="btn btn-primary w-100">Login to System</button>
            </form>
            
            <p class="text-center mt-3 small">Don't have an account yet? <a href="register.php">Register here</a></p>
        </div>
    </div>
</div>

<script>
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