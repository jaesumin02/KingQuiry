<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/rate_limit.php';
require_once 'includes/sms.php';

kq_start_session();

$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || empty($currentUser['userID'])) {
    header('Location: login.php');
    exit();
}

$currentRole = normalized_user_role($currentUser);
$userID = (int) $currentUser['userID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone_action'])) {
    $phoneAction = is_string($_POST['phone_action']) ? $_POST['phone_action'] : '';
    if (!has_valid_csrf_token()) {
        $_SESSION['flash_error'] = 'Your session expired. Refresh the page and try again.';
    } elseif ($phoneAction === 'send_code') {
        $phone = is_string($_POST['phone_number'] ?? null)
            ? kq_normalize_ph_mobile($_POST['phone_number'])
            : null;
        $currentPassword = is_string($_POST['phone_current_password'] ?? null)
            ? $_POST['phone_current_password']
            : '';

        if ($phone === null) {
            $_SESSION['flash_error'] = 'Enter a valid Philippine mobile number, such as 0917 123 4567.';
        } else {
            $credentialStmt = $conn->prepare(
                "SELECT passwordHash FROM users
                 WHERE userID = ? AND accountStatus = 'Active'
                 LIMIT 1"
            );
            $credentialStmt->bind_param('i', $userID);
            $credentialStmt->execute();
            $credential = $credentialStmt->get_result()->fetch_assoc();
            $credentialStmt->close();

            if (!$credential || $currentPassword === '' || !password_verify($currentPassword, (string) $credential['passwordHash'])) {
                $_SESSION['flash_error'] = 'Enter your current password to verify a recovery phone number.';
            } else {
                $ipAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
                $ipBucket = kq_rate_limit_consume($conn, 'phone-verify-ip', $ipAddress, 10, 3600);
                $userBucket = kq_rate_limit_consume($conn, 'phone-verify-user', (string) $userID, 5, 3600);
                if (!$ipBucket['allowed'] || !$userBucket['allowed']) {
                    $_SESSION['flash_error'] = 'Too many phone verification requests. Please wait before trying again.';
                } else {
                    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $codeHash = password_hash($code, PASSWORD_DEFAULT);
                    $otpStmt = $conn->prepare(
                        'INSERT INTO phone_verification_otps (userID, codeHash, attempts, expiresAt, createdAt)
                         VALUES (?, ?, 0, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE), CURRENT_TIMESTAMP)
                         ON DUPLICATE KEY UPDATE codeHash = VALUES(codeHash), attempts = 0,
                             expiresAt = VALUES(expiresAt), createdAt = CURRENT_TIMESTAMP'
                    );
                    $otpStmt->bind_param('is', $userID, $codeHash);
                    $otpStmt->execute();
                    $otpStmt->close();

                    if (!kq_send_semaphore_otp($phone, $code)) {
                        $deleteStmt = $conn->prepare('DELETE FROM phone_verification_otps WHERE userID = ?');
                        $deleteStmt->bind_param('i', $userID);
                        $deleteStmt->execute();
                        $deleteStmt->close();
                        $_SESSION['flash_error'] = 'We could not send the SMS code. Check the Semaphore setup or contact support.';
                    } else {
                        $_SESSION['kq_pending_phone_verification'] = [
                            'phone' => $phone,
                            'expires_at' => time() + 600,
                        ];
                        $_SESSION['flash_success'] = 'A verification code was sent to the phone ending in ' . substr($phone, -4) . '.';
                    }
                }
            }
        }
    } elseif ($phoneAction === 'verify_code') {
        $pendingPhone = $_SESSION['kq_pending_phone_verification'] ?? null;
        $phone = is_array($pendingPhone) && is_string($pendingPhone['phone'] ?? null)
            ? (string) $pendingPhone['phone']
            : '';
        $expiresAt = is_array($pendingPhone) ? (int) ($pendingPhone['expires_at'] ?? 0) : 0;
        $code = is_string($_POST['phone_verification_code'] ?? null)
            ? trim($_POST['phone_verification_code'])
            : '';

        if ($phone === '' || $expiresAt <= time()) {
            unset($_SESSION['kq_pending_phone_verification']);
            $_SESSION['flash_error'] = 'The phone verification code expired. Request a new code.';
        } elseif (!preg_match('/\A[0-9]{6}\z/', $code)) {
            $_SESSION['flash_error'] = 'Enter the six-digit code sent to your phone.';
        } else {
            $ipAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
            $attemptBucket = kq_rate_limit_consume(
                $conn,
                'phone-verify-attempt',
                $ipAddress . "\0" . $userID,
                10,
                600
            );

            if (!$attemptBucket['allowed']) {
                $_SESSION['flash_error'] = 'Too many verification attempts. Request a new code and try again later.';
            } else {
                $conn->begin_transaction();
                try {
                    $challengeStmt = $conn->prepare(
                        'SELECT codeHash, attempts FROM phone_verification_otps
                         WHERE userID = ? AND expiresAt > CURRENT_TIMESTAMP
                         LIMIT 1 FOR UPDATE'
                    );
                    $challengeStmt->bind_param('i', $userID);
                    $challengeStmt->execute();
                    $challenge = $challengeStmt->get_result()->fetch_assoc();
                    $challengeStmt->close();

                    if (!$challenge || (int) $challenge['attempts'] >= 5) {
                        $conn->rollback();
                        $_SESSION['flash_error'] = 'The code is invalid or expired. Request a new code.';
                    } elseif (!password_verify($code, (string) $challenge['codeHash'])) {
                        $attemptStmt = $conn->prepare(
                            'UPDATE phone_verification_otps SET attempts = attempts + 1
                             WHERE userID = ? AND attempts < 5'
                        );
                        $attemptStmt->bind_param('i', $userID);
                        $attemptStmt->execute();
                        $attemptStmt->close();
                        $conn->commit();
                        $_SESSION['flash_error'] = 'The code is incorrect. Check it and try again.';
                    } else {
                        $updateStmt = $conn->prepare(
                            "UPDATE users SET phone = ?, phoneVerifiedAt = CURRENT_TIMESTAMP
                             WHERE userID = ? AND accountStatus = 'Active'"
                        );
                        $updateStmt->bind_param('si', $phone, $userID);
                        $updateStmt->execute();
                        if ($updateStmt->affected_rows !== 1) {
                            throw new RuntimeException('The verified recovery phone could not be saved.');
                        }
                        $updateStmt->close();

                        $deleteStmt = $conn->prepare(
                            'DELETE FROM phone_verification_otps WHERE userID = ? AND codeHash = ?'
                        );
                        $deleteStmt->bind_param('is', $userID, $challenge['codeHash']);
                        $deleteStmt->execute();
                        if ($deleteStmt->affected_rows !== 1) {
                            throw new RuntimeException('The phone verification code could not be consumed.');
                        }
                        $deleteStmt->close();
                        $conn->commit();
                        unset($_SESSION['kq_pending_phone_verification']);
                        $_SESSION['flash_success'] = 'Your phone number is verified and can be used for account recovery.';
                    }
                } catch (Throwable $exception) {
                    $conn->rollback();
                    error_log('KingQuiry phone verification failed: ' . $exception->getMessage());
                    $_SESSION['flash_error'] = 'The phone number could not be verified. Please try again.';
                }
            }
        }
    }

    header('Location: manage_account.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_two_factor'])) {
    if (!has_valid_csrf_token()) {
        $_SESSION['flash_error'] = 'Your session expired. Refresh the page and try again.';
    } else {
        $_SESSION['flash_error'] = 'Sign-in verification is determined by account role and cannot be changed here.';
    }

    header('Location: manage_account.php');
    exit();
}

// Fetch latest user details from DB
$stmt = $conn->prepare('SELECT userID, fullname, email, phone, phoneVerifiedAt, address, role, accountStatus, createdAt FROM users WHERE userID = ? LIMIT 1');
$stmt->bind_param('i', $userID);
$stmt->execute();
$dbUser = $stmt->get_result()->fetch_assoc();
if ($dbUser) {
    $currentUser = array_merge($currentUser, $dbUser);
    $_SESSION['user'] = $currentUser;
}

$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
$pendingPhoneVerification = $_SESSION['kq_pending_phone_verification'] ?? null;
if (
    !is_array($pendingPhoneVerification)
    || !is_string($pendingPhoneVerification['phone'] ?? null)
    || (int) ($pendingPhoneVerification['expires_at'] ?? 0) <= time()
) {
    unset($_SESSION['kq_pending_phone_verification']);
    $pendingPhoneVerification = null;
}

include 'includes/header.php';
?>

<div class="container py-4 py-lg-5" style="max-width: 780px;">
    <div class="mb-4">
        <a href="javascript:history.back()" class="text-decoration-none small text-muted d-inline-flex align-items-center gap-1 mb-2">
            &larr; Back
        </a>
        <h1 class="h3 fw-bold mb-1">Manage Account</h1>
        <p class="text-muted mb-0">Review your profile details and update your password.</p>
    </div>

    <?php if ($flashSuccess !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Success:</strong> <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($flashError !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Error:</strong> <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Account Details Card -->
    <div class="card p-4 mb-4 border shadow-sm rounded-4">
        <h2 class="h5 fw-bold mb-3 d-flex align-items-center gap-2">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            <span>Profile Details</span>
        </h2>
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="text-muted small fw-semibold">Full Name</div>
                <div class="fw-bold text-dark"><?= htmlspecialchars((string) ($currentUser['fullname'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="col-sm-6">
                <div class="text-muted small fw-semibold">Email Address</div>
                <div class="text-dark"><?= htmlspecialchars((string) ($currentUser['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <?php if ($currentRole === 'Admin' || $currentRole === 'Sales Agent'): ?>
                <div class="col-sm-6">
                    <div class="text-muted small fw-semibold">Role</div>
                    <div><span class="badge bg-secondary"><?= htmlspecialchars((string) $currentRole, ENT_QUOTES, 'UTF-8') ?></span></div>
                </div>
            <?php endif; ?>
            <div class="col-sm-6">
                <div class="text-muted small fw-semibold">Member Since</div>
                <div class="text-dark"><?= !empty($currentUser['createdAt']) ? date('M j, Y', strtotime((string) $currentUser['createdAt'])) : 'N/A' ?></div>
            </div>
        </div>
    </div>

    <div class="card p-4 mb-4 border shadow-sm rounded-4">
        <h2 class="h5 fw-bold mb-2">Phone recovery verification</h2>
        <p class="text-muted small mb-3">Verify a Philippine mobile number now so you can choose SMS recovery if email codes are unavailable. A code is sent only after you confirm your current password.</p>
        <?php if (!empty($dbUser['phoneVerifiedAt'])): ?>
            <div class="alert alert-success py-2" role="status">Verified phone ending in <?= htmlspecialchars(substr((string) $dbUser['phone'], -4), ENT_QUOTES, 'UTF-8') ?> can be used for password recovery.</div>
        <?php else: ?>
            <div class="alert alert-warning py-2" role="status">No verified recovery phone is set. Unverified phone numbers cannot be used to reset your password.</div>
        <?php endif; ?>

        <form method="POST" class="row g-3 align-items-end mb-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="phone_action" value="send_code">
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="recovery-phone">Philippine mobile number</label>
                <input class="form-control" type="tel" id="recovery-phone" name="phone_number" value="<?= htmlspecialchars((string) ($dbUser['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="0917 123 4567" autocomplete="tel" maxlength="30" required>
                <div class="form-text">Accepted formats: 09xxxxxxxxx or +639xxxxxxxxx.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="phone-current-password">Current password</label>
                <input class="form-control" type="password" id="phone-current-password" name="phone_current_password" autocomplete="current-password" required>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-outline-primary fw-semibold">Send phone verification code</button>
            </div>
        </form>

        <?php if ($pendingPhoneVerification !== null): ?>
            <div class="border-top pt-3">
                <p class="small text-muted mb-2">Enter the six-digit code sent to the phone ending in <?= htmlspecialchars(substr((string) $pendingPhoneVerification['phone'], -4), ENT_QUOTES, 'UTF-8') ?>. It expires in 10 minutes.</p>
                <form method="POST" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="phone_action" value="verify_code">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold" for="phone-verification-code">Verification code</label>
                        <input class="form-control" type="text" id="phone-verification-code" name="phone_verification_code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                    </div>
                    <div class="col-sm-6">
                        <button type="submit" class="btn btn-primary fw-semibold">Verify phone number</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="card p-4 mb-4 border shadow-sm rounded-4">
        <h2 class="h5 fw-bold mb-2">Sign-in verification</h2>
        <?php if ($currentRole === 'Client'): ?>
            <p class="text-muted small mb-0">Client accounts sign in with their email and password. Email verification codes are not required.</p>
        <?php else: ?>
            <p class="text-muted small mb-0">Required for administrator and sales-agent accounts. A one-time code is sent to the registered email at each sign-in.</p>
        <?php endif; ?>
    </div>

    <!-- Change Password Card -->
    <div class="card p-4 border shadow-sm rounded-4">
        <h2 class="h5 fw-bold mb-2 d-flex align-items-center gap-2">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            <span>Change Password</span>
        </h2>
        <p class="text-muted small mb-4">Choose a strong password containing at least 8 characters.</p>

        <form method="POST" action="change_password.php" data-confirm="Change your account password now?">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

            <div class="mb-3">
                <label class="form-label fw-semibold" for="page-current-password">Current Password</label>
                <div class="password-field">
                    <input type="password" id="page-current-password" name="current_password" class="form-control" required placeholder="Enter your current password">
                    <button type="button" class="password-toggle" data-toggle-password="page-current-password">Show</button>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold" for="page-new-password">New Password</label>
                <div class="password-field">
                    <input type="password" id="page-new-password" name="new_password" class="form-control" minlength="8" maxlength="72" required placeholder="Enter new password">
                    <button type="button" class="password-toggle" data-toggle-password="page-new-password">Show</button>
                </div>
                <div class="form-text">Must be at least 8 characters long.</div>
            </div>

            <div class="mb-4">
                <label class="form-label fw-semibold" for="page-confirm-password">Confirm New Password</label>
                <div class="password-field">
                    <input type="password" id="page-confirm-password" name="confirm_password" class="form-control" minlength="8" maxlength="72" required placeholder="Re-enter new password">
                    <button type="button" class="password-toggle" data-toggle-password="page-confirm-password">Show</button>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                    Sign out
                </button>
                <button type="submit" class="btn btn-primary px-4 fw-semibold">
                    Save New Password
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
