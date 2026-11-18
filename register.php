<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$error = '';
$isRegistered = isset($_GET['registered']);
$fullname = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = is_string($_POST['fullname'] ?? null) ? trim($_POST['fullname']) : '';
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $rawPassword = $_POST['password'] ?? '';

    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (($retryAfter = kq_registration_rate_limit(
        $conn,
        $email,
        is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : ''
    )) !== null) {
        $error = 'Too many registration attempts. Please wait ' . (int) ceil($retryAfter / 60) . ' minute(s) and try again.';
    } elseif (strlen($fullname) < 2 || strlen($fullname) > 100 || !preg_match("/^[\p{L}\p{M} .'-]+$/u", $fullname)) {
        $error = 'Enter a valid name using letters and standard punctuation.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $error = 'Enter a valid email address.';
    } elseif (!is_string($rawPassword) || strlen($rawPassword) < 8 || strlen($rawPassword) > 72) {
        $error = 'Use a password between 8 and 72 characters.';
    } else {
        $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            $error = 'We could not create your account right now. Please try again.';
        } else {
            $role = 'Client';
            try {
                $stmt = $conn->prepare("INSERT INTO users (fullname, email, passwordHash, role) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $fullname, $email, $passwordHash, $role);
                $stmt->execute();
                header('Location: register.php?registered=1');
                exit();
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() === 1062) {
                    $error = 'This email address is already registered.';
                } else {
                    error_log('KingQuiry account registration failed: ' . $exception->getMessage());
                    $error = 'We could not save your account. Confirm MySQL is running and the KingQuiry database is set up, then try again.';
                }
            }
        }
    }
}
include 'includes/header.php';
?>

<div class="kq-auth-page">
    <aside class="kq-auth-panel card" aria-label="Registration panel">
        <div class="kq-brand" aria-label="4 Kings Realty Services logo">
            <img class="kq-official-logo kq-logo-auth" src="assets/images/kings-land-properties-logo.png" alt="4 Kings Realty Services">
            <div class="kq-brand-sub">Realty services</div>
        </div>

        <?php if ($isRegistered): ?>
            <div class="text-center py-2" aria-live="polite">
                <div class="mb-3">
                    <span class="d-inline-flex align-items-center justify-content-center rounded-circle" style="width: 72px; height: 72px; background: rgba(22, 101, 52, 0.12); color: #166534;">
                        <svg width="38" height="38" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </span>
                </div>

                <h2>Account created successfully</h2>
                <p class="subcopy mb-4">Your account is ready. Please proceed to the login screen to sign in with your credentials.</p>

                <a href="client_login.php" class="kq-auth-submit" role="button">
                    Back to Login Screen
                </a>
            </div>
        <?php else: ?>
            <h2>Sign Up</h2>
            <p class="subcopy">Create your account to continue.</p>

            <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <form method="POST" autocomplete="off" class="kq-auth-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

                <div class="kq-form-group">
                    <label for="register-fullname">Full name</label>
                    <input type="text" id="register-fullname" name="fullname" required maxlength="100" value="<?= htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8') ?>" placeholder="Your full name" autocomplete="name">
                </div>

                <div class="kq-form-group">
                    <label for="register-email">Email address</label>
                    <input type="email" id="register-email" name="email" required maxlength="100" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="name@example.com" autocomplete="email">
                </div>

                <div class="kq-form-group">
                    <label for="register-password">Password</label>
                    <div class="kq-password-wrap">
                        <input type="password" id="register-pass" name="password" required minlength="8" maxlength="72" placeholder="At least 8 characters" autocomplete="new-password">
                        <button type="button" class="kq-toggle-password" data-toggle-password="register-pass" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                    <div class="form-text mt-2">Use at least 8 characters.</div>
                </div>

                <button type="submit" class="kq-auth-submit">Create account</button>
            </form>

            <div class="kq-auth-register">
                Already have an account? <a href="client_login.php">Sign in here</a>
            </div>
        <?php endif; ?>
    </aside>
</div>

<div class="kq-page-note">Find your place. <strong>Make your next move.</strong></div>

<script>
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            window.location.reload();
        }
    });

</script>

<?php include 'includes/footer.php'; ?>