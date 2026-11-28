<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

kq_start_session();
apply_secure_headers();

$token = is_string($_POST['token'] ?? null)
    ? $_POST['token']
    : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$tokenIsValid = preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1;
$tokenHash = $tokenIsValid ? hash('sha256', $token) : '';
if ($tokenIsValid && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $validTokenStmt = $conn->prepare(
        'SELECT 1 FROM password_reset_tokens
         WHERE tokenHash = ? AND expiresAt > CURRENT_TIMESTAMP
         LIMIT 1'
    );
    $validTokenStmt->bind_param('s', $tokenHash);
    $validTokenStmt->execute();
    $tokenIsValid = (bool) $validTokenStmt->get_result()->fetch_row();
    $validTokenStmt->close();
}
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!$tokenIsValid) {
        $error = 'This reset link is invalid or has expired. Request a new one.';
    } else {
        $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
        $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
        if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
            $error = 'Choose a password between 8 and 72 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'The password confirmation does not match.';
        } else {
            $conn->begin_transaction();
            try {
                $tokenStmt = $conn->prepare(
                    'SELECT userID FROM password_reset_tokens
                     WHERE tokenHash = ? AND expiresAt > CURRENT_TIMESTAMP
                     LIMIT 1 FOR UPDATE'
                );
                $tokenStmt->bind_param('s', $tokenHash);
                $tokenStmt->execute();
                $resetRecord = $tokenStmt->get_result()->fetch_assoc();
                $tokenStmt->close();

                if (!$resetRecord) {
                    $conn->rollback();
                    $error = 'This reset link is invalid or has expired. Request a new one.';
                } else {
                    $userId = (int) $resetRecord['userID'];
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                    if ($passwordHash === false) {
                        throw new RuntimeException('Unable to securely hash the replacement password.');
                    }
                    $updateStmt = $conn->prepare("UPDATE users SET passwordHash = ? WHERE userID = ? AND accountStatus = 'Active'");
                    $updateStmt->bind_param('si', $passwordHash, $userId);
                    $updateStmt->execute();
                    if ($updateStmt->affected_rows !== 1) {
                        throw new RuntimeException('The account password could not be updated.');
                    }
                    $updateStmt->close();

                    $deleteResetStmt = $conn->prepare('DELETE FROM password_reset_tokens WHERE userID = ?');
                    $deleteResetStmt->bind_param('i', $userId);
                    $deleteResetStmt->execute();
                    $deleteResetStmt->close();
                    $deleteChallengeStmt = $conn->prepare('DELETE FROM login_2fa_challenges WHERE userID = ?');
                    $deleteChallengeStmt->bind_param('i', $userId);
                    $deleteChallengeStmt->execute();
                    $deleteChallengeStmt->close();
                    $conn->commit();
                    $success = true;
                }
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log('KingQuiry password reset failed: ' . $exception->getMessage());
                $error = 'The password could not be reset. Please try again later.';
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="container py-5" style="max-width: 520px;">
    <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5">
        <?php if ($success): ?>
            <h1 class="h3 fw-bold">Password updated</h1>
            <p class="text-muted">Your password has been changed. Sign in with your new password.</p>
            <a class="btn btn-primary fw-semibold" href="client_login.php?password_reset=1">Return to sign in</a>
        <?php else: ?>
            <h1 class="h3 fw-bold">Choose a new password</h1>
            <p class="text-muted">Reset links expire after 30 minutes and can only be used once.</p>
            <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
            <?php if ($tokenIsValid): ?>
                <form method="POST" class="d-grid gap-3">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <div>
                        <label class="form-label fw-semibold" for="new-password">New password</label>
                        <input class="form-control" type="password" id="new-password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <div>
                        <label class="form-label fw-semibold" for="confirm-password">Confirm new password</label>
                        <input class="form-control" type="password" id="confirm-password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-primary fw-semibold">Reset password</button>
                </form>
            <?php else: ?>
                <?php if ($error === ''): ?><div class="alert alert-danger" role="alert">This reset link is invalid. Request a new reset link.</div><?php endif; ?>
                <a class="btn btn-outline-primary" href="forgot_password.php">Request a reset link</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
