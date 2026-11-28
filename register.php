<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate_limit.php';

kq_start_session();
if (isset($_SESSION['user'])) {
    header('Location: ' . kq_dashboard_destination($_SESSION['user']));
    exit();
}

$error = '';
$message = is_string($_SESSION['kq_registration_flash_success'] ?? null)
    ? (string) $_SESSION['kq_registration_flash_success']
    : '';
unset($_SESSION['kq_registration_flash_success']);
$isRegistered = isset($_GET['registered']);
$fullname = '';
$email = '';
$phoneValue = '';
$step = 'details';
$pendingRegistration = is_array($_SESSION['kq_pending_registration'] ?? null)
    ? $_SESSION['kq_pending_registration']
    : null;
$clearPendingRegistration = static function (): void {
    unset($_SESSION['kq_pending_registration']);
};

if (
    $pendingRegistration !== null
    && (!is_int($pendingRegistration['expires_at'] ?? null) || $pendingRegistration['expires_at'] <= time())
) {
    $clearPendingRegistration();
    $pendingRegistration = null;
    $error = 'Your verification code expired. Submit your details to request a new one.';
} elseif ($pendingRegistration !== null) {
    $step = 'verify';
    $fullname = (string) ($pendingRegistration['fullname'] ?? '');
    $email = (string) ($pendingRegistration['email'] ?? '');
    $phoneValue = (string) ($pendingRegistration['phone'] ?? '');
}

$remoteAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? trim($_SERVER['REMOTE_ADDR']) : '';
$ipAddress = $remoteAddress !== '' ? $remoteAddress : 'unknown';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif ($action === 'send_phone_code') {
        $fullname = is_string($_POST['fullname'] ?? null) ? trim($_POST['fullname']) : '';
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
        $phoneValue = is_string($_POST['phone_number'] ?? null)
            ? trim(substr($_POST['phone_number'], 0, 30))
            : '';
        $rawPassword = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
        $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        $phone = kq_normalize_ph_mobile($phoneValue);
        $retryAfter = kq_registration_rate_limit($conn, $email, $ipAddress);

        if ($retryAfter !== null) {
            $error = 'Too many registration attempts. Please wait ' . (int) ceil($retryAfter / 60) . ' minute(s) and try again.';
        } elseif (strlen($fullname) < 2 || strlen($fullname) > 100 || !preg_match("/^[\p{L}\p{M} .'-]+$/u", $fullname)) {
            $error = 'Enter a valid name using letters and standard punctuation.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            $error = 'Enter a valid email address.';
        } elseif ($phone === null) {
            $error = 'Enter a valid Philippine mobile number, such as 0917 123 4567.';
        } elseif (strlen($rawPassword) < 8 || strlen($rawPassword) > 72) {
            $error = 'Use a password between 8 and 72 characters.';
        } elseif ($rawPassword !== $confirmPassword) {
            $error = 'Passwords do not match. Please re-enter your password.';
        } elseif (!kq_semaphore_is_configured()) {
            $error = 'Phone verification is temporarily unavailable. Please try again later or contact support.';
        } else {
            $emailStmt = $conn->prepare('SELECT userID FROM users WHERE email = ? LIMIT 1');
            $emailStmt->bind_param('s', $email);
            $emailStmt->execute();
            $emailExists = $emailStmt->get_result()->fetch_assoc() !== null;
            $emailStmt->close();

            if ($emailExists) {
                $error = 'This email address is already registered.';
            } else {
                $ipBucket = kq_rate_limit_consume($conn, 'register-phone-ip', $ipAddress, 10, 3600);
                $phoneBucket = kq_rate_limit_consume($conn, 'register-phone', $phone, 3, 3600);
                if (!$ipBucket['allowed'] || !$phoneBucket['allowed']) {
                    $error = 'Too many phone verification requests. Please wait before trying again.';
                } else {
                    $passwordHash = password_hash($rawPassword, PASSWORD_DEFAULT);
                    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $codeHash = password_hash($code, PASSWORD_DEFAULT);
                    if (!kq_send_semaphore_otp($phone, $code)) {
                        $error = 'We could not send the SMS code. Check the phone number or contact support.';
                    } else {
                        kq_regenerate_session_id_or_fail('Unable to secure the pending registration session.');
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        $_SESSION['kq_pending_registration'] = [
                            'fullname' => $fullname,
                            'email' => $email,
                            'phone' => $phone,
                            'password_hash' => $passwordHash,
                            'code_hash' => $codeHash,
                            'attempts' => 0,
                            'expires_at' => time() + 600,
                        ];
                        $_SESSION['kq_registration_flash_success'] =
                            'A verification code was sent to the phone ending in ' . substr($phone, -4) . '.';
                        header('Location: register.php');
                        exit();
                    }
                }
            }
        }
    } elseif ($action === 'verify_phone_code') {
        $pendingRegistration = is_array($_SESSION['kq_pending_registration'] ?? null)
            ? $_SESSION['kq_pending_registration']
            : null;
        $step = 'verify';
        if (
            $pendingRegistration === null
            || !is_int($pendingRegistration['expires_at'] ?? null)
            || $pendingRegistration['expires_at'] <= time()
        ) {
            $clearPendingRegistration();
            $pendingRegistration = null;
            $step = 'details';
            $error = 'Your verification code expired. Submit your details to request a new one.';
        } else {
            $fullname = (string) ($pendingRegistration['fullname'] ?? '');
            $email = (string) ($pendingRegistration['email'] ?? '');
            $phoneValue = (string) ($pendingRegistration['phone'] ?? '');
            $attemptBucket = kq_rate_limit_consume(
                $conn,
                'register-phone-attempt',
                $ipAddress . "\0" . $phoneValue,
                10,
                600
            );
            $code = is_string($_POST['phone_verification_code'] ?? null)
                ? trim($_POST['phone_verification_code'])
                : '';

            if (!$attemptBucket['allowed']) {
                $error = 'Too many verification attempts. Request a new code and try again later.';
            } elseif ((int) ($pendingRegistration['attempts'] ?? 0) >= 5) {
                $error = 'Too many incorrect codes. Resend a code to continue.';
            } elseif (!preg_match('/\A[0-9]{6}\z/', $code)) {
                $error = 'Enter the six-digit code sent to your phone.';
            } elseif (!password_verify($code, (string) ($pendingRegistration['code_hash'] ?? ''))) {
                $pendingRegistration['attempts'] = (int) ($pendingRegistration['attempts'] ?? 0) + 1;
                $_SESSION['kq_pending_registration'] = $pendingRegistration;
                $error = $pendingRegistration['attempts'] >= 5
                    ? 'Too many incorrect codes. Resend a code to continue.'
                    : 'That code is incorrect. Check it and try again.';
            } else {
                kq_regenerate_session_id_or_fail('Unable to secure the verified registration session.');
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                try {
                    $role = 'Client';
                    $verifiedFullname = (string) ($pendingRegistration['fullname'] ?? '');
                    $verifiedEmail = (string) ($pendingRegistration['email'] ?? '');
                    $verifiedPhone = (string) ($pendingRegistration['phone'] ?? '');
                    $verifiedPasswordHash = (string) ($pendingRegistration['password_hash'] ?? '');
                    $insertStmt = $conn->prepare(
                        'INSERT INTO users (fullname, email, phone, phoneVerifiedAt, passwordHash, role)
                         VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?, ?)'
                    );
                    $insertStmt->bind_param(
                        'sssss',
                        $verifiedFullname,
                        $verifiedEmail,
                        $verifiedPhone,
                        $verifiedPasswordHash,
                        $role
                    );
                    $insertStmt->execute();
                    $insertStmt->close();
                    $clearPendingRegistration();
                    header('Location: register.php?registered=1');
                    exit();
                } catch (mysqli_sql_exception $exception) {
                    if ($exception->getCode() === 1062) {
                        $clearPendingRegistration();
                        $pendingRegistration = null;
                        $step = 'details';
                        $error = 'This email address is already registered.';
                    } else {
                        error_log('KingQuiry account registration failed: ' . $exception->getMessage());
                        $error = 'We could not save your account. Please try again.';
                    }
                }
            }
        }
    } elseif ($action === 'resend_phone_code') {
        $pendingRegistration = is_array($_SESSION['kq_pending_registration'] ?? null)
            ? $_SESSION['kq_pending_registration']
            : null;
        $step = 'verify';
        if (
            $pendingRegistration === null
            || !is_int($pendingRegistration['expires_at'] ?? null)
            || $pendingRegistration['expires_at'] <= time()
        ) {
            $clearPendingRegistration();
            $pendingRegistration = null;
            $step = 'details';
            $error = 'Your verification code expired. Submit your details to request a new one.';
        } else {
            $phone = (string) ($pendingRegistration['phone'] ?? '');
            $ipBucket = kq_rate_limit_consume($conn, 'register-phone-ip', $ipAddress, 10, 3600);
            $phoneBucket = kq_rate_limit_consume($conn, 'register-phone', $phone, 3, 3600);
            if (!$ipBucket['allowed'] || !$phoneBucket['allowed']) {
                $error = 'Too many phone verification requests. Please wait before trying again.';
            } else {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $codeHash = password_hash($code, PASSWORD_DEFAULT);
                if (!kq_send_semaphore_otp($phone, $code)) {
                    $error = 'We could not send the SMS code. Check the phone number or contact support.';
                } else {
                    $pendingRegistration['code_hash'] = $codeHash;
                    $pendingRegistration['attempts'] = 0;
                    $pendingRegistration['expires_at'] = time() + 600;
                    $_SESSION['kq_pending_registration'] = $pendingRegistration;
                    $_SESSION['kq_registration_flash_success'] =
                        'A new verification code was sent to the phone ending in ' . substr($phone, -4) . '.';
                    header('Location: register.php');
                    exit();
                }
            }
        }
    } elseif ($action === 'start_over') {
        $clearPendingRegistration();
        $pendingRegistration = null;
        $step = 'details';
        $fullname = '';
        $email = '';
        $phoneValue = '';
        $message = '';
    } else {
        $error = 'Choose a valid registration action and try again.';
    }
}

$pendingRegistration = is_array($_SESSION['kq_pending_registration'] ?? null)
    ? $_SESSION['kq_pending_registration']
    : null;
if ($pendingRegistration !== null && $step !== 'details') {
    $step = 'verify';
    $fullname = (string) ($pendingRegistration['fullname'] ?? '');
    $email = (string) ($pendingRegistration['email'] ?? '');
    $phoneValue = (string) ($pendingRegistration['phone'] ?? '');
} elseif ($pendingRegistration === null && $step === 'verify') {
    $step = 'details';
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
                <p class="subcopy mb-4">Your phone is verified and ready for password recovery. You can now sign in.</p>

                <a href="login.php" class="kq-auth-submit" role="button">
                    Sign in
                </a>
            </div>
        <?php elseif ($step === 'verify'): ?>
            <?php $maskedRegistrationPhone = '+63 *** *** ' . substr($phoneValue, -4); ?>
            <h2>Verify your phone</h2>
            <p class="subcopy">Enter the 6-digit code sent to <strong><?= htmlspecialchars($maskedRegistrationPhone, ENT_QUOTES, 'UTF-8') ?></strong>. It expires in 10 minutes.</p>

            <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($message !== ''): ?><div class="alert alert-success py-2" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <form method="POST" autocomplete="off" class="kq-auth-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="verify_phone_code">
                <div class="kq-form-group">
                    <label for="register-phone-code">SMS verification code</label>
                    <input type="text" id="register-phone-code" name="phone_verification_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" required>
                </div>
                <button type="submit" class="kq-auth-submit">Verify and create account</button>
            </form>

            <form method="POST" class="kq-recovery-secondary-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="resend_phone_code">
                <button type="submit" class="kq-recovery-text-button">Resend code</button>
            </form>
            <form method="POST" class="kq-recovery-secondary-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="start_over">
                <button type="submit" class="kq-recovery-text-button">Use a different number</button>
            </form>
        <?php else: ?>
            <h2>Sign Up</h2>
            <p class="subcopy">Create your account and verify your phone for password recovery.</p>

            <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

            <form method="POST" autocomplete="off" class="kq-auth-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="send_phone_code">

                <div class="kq-form-group">
                    <label for="register-fullname">Full name</label>
                    <input type="text" id="register-fullname" name="fullname" required maxlength="100" value="<?= htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8') ?>" placeholder="Your full name" autocomplete="name">
                </div>

                <div class="kq-form-group">
                    <label for="register-email">Email address</label>
                    <input type="email" id="register-email" name="email" required maxlength="100" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="name@example.com" autocomplete="email">
                </div>

                <div class="kq-form-group">
                    <label for="register-phone">Mobile number</label>
                    <input type="tel" id="register-phone" name="phone_number" required maxlength="30" value="<?= htmlspecialchars($phoneValue, ENT_QUOTES, 'UTF-8') ?>" placeholder="0917 123 4567" autocomplete="tel" inputmode="tel">
                    <div class="form-text mt-2">Required. We will verify this number by SMS before creating your account.</div>
                </div>

                <div class="kq-form-group">
                    <label for="register-pass">Password</label>
                    <div class="kq-password-wrap">
                        <input type="password" id="register-pass" name="password" required minlength="8" maxlength="72" placeholder="At least 8 characters" autocomplete="new-password">
                        <button type="button" class="kq-toggle-password" data-toggle-password="register-pass" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                    <div class="form-text mt-2">Use at least 8 characters.</div>
                </div>

                <div class="kq-form-group">
                    <label for="register-confirm-pass">Confirm password</label>
                    <div class="kq-password-wrap">
                        <input type="password" id="register-confirm-pass" name="confirm_password" required minlength="8" maxlength="72" placeholder="Re-enter your password" autocomplete="new-password">
                        <button type="button" class="kq-toggle-password" data-toggle-password="register-confirm-pass" aria-label="Show password" aria-pressed="false">Show</button>
                    </div>
                </div>

                <button type="submit" class="kq-auth-submit">Create account</button>
            </form>

            <div class="kq-auth-register">
                Already have an account? <a href="login.php">Sign in here</a>
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

    const regForm = document.querySelector('.kq-auth-form');
    const regPass = document.getElementById('register-pass');
    const regConfirmPass = document.getElementById('register-confirm-pass');

    if (regForm && regPass && regConfirmPass) {
        function checkPasswordMatch() {
            if (regConfirmPass.value && regPass.value !== regConfirmPass.value) {
                regConfirmPass.setCustomValidity('Passwords do not match.');
            } else {
                regConfirmPass.setCustomValidity('');
            }
        }
        regPass.addEventListener('input', checkPasswordMatch);
        regConfirmPass.addEventListener('input', checkPasswordMatch);
        regForm.addEventListener('submit', () => {
            checkPasswordMatch();
        });
    }
</script>

<?php include 'includes/footer.php'; ?>