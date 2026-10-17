<?php
include 'config/db.php';
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_SESSION['user'])) {
    header("Location: index.php");
    exit();
}

$error = '';
$success = isset($_GET['registered']) ? 'Your account has been created. You can now sign in.' : '';
$fullname = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $fullname = is_string($_POST['fullname'] ?? null) ? trim($_POST['fullname']) : '';
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $rawPassword = $_POST['password'] ?? '';

    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
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
            $stmt = $conn->prepare("INSERT INTO users (fullname, email, passwordHash, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('ssss', $fullname, $email, $passwordHash, $role);
            try {
                $stmt->execute();
                header('Location: register.php?registered=1');
                exit();
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() === 1062) {
                    $error = 'This email address is already registered.';
                } else {
                    throw $exception;
                }
            }
        }
    }
}
include 'includes/header.php';
?>

<div class="row justify-content-center py-4 py-lg-5">
    <div class="col-md-7 col-lg-5 col-xl-4">
        <div class="card p-4 p-lg-5">
            <div class="page-eyebrow text-center mb-2">Start exploring</div>
            <h1 class="page-title h2 text-center mb-2">Create your account</h1>
            <p class="text-muted text-center mb-4">Save time when requesting property viewings.</p>
            
            <?php if($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if($success): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="register-fullname">Full name</label>
                    <input type="text" id="register-fullname" name="fullname" class="form-control" required maxlength="100" value="<?= htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8') ?>" placeholder="Your full name" autocomplete="name">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="register-email">Email address</label>
                    <input type="email" id="register-email" name="email" class="form-control" required maxlength="100" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="name@example.com" autocomplete="email">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="register-password">Password</label>
                    <input type="password" id="register-password" name="password" class="form-control" required minlength="8" maxlength="72" placeholder="At least 8 characters" autocomplete="new-password">
                    <div class="form-text">Use at least 8 characters.</div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2">Create account</button>
            </form>
            
            <p class="text-center mt-3 small">Already have an account? <a href="login.php">Login here</a></p>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>