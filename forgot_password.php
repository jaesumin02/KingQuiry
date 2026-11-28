<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/notifications.php';

kq_start_session();
apply_secure_headers();

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $email = is_string($_POST['email'] ?? null) ? strtolower(trim(substr($_POST['email'], 0, 100))) : '';
        $ipAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        $ipBucket = kq_rate_limit_consume($conn, 'password-reset-ip', $ipAddress, 20, 3600);
        $emailBucket = kq_rate_limit_consume($conn, 'password-reset-email', $ipAddress . "\0" . $email, 5, 3600);

        if ($ipBucket['allowed'] && $emailBucket['allowed'] && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $userStmt = $conn->prepare(
                "SELECT userID FROM users
                 WHERE email = ? AND accountStatus = 'Active'
                 LIMIT 1"
            );
            $userStmt->bind_param('s', $email);
            $userStmt->execute();
            $user = $userStmt->get_result()->fetch_assoc();
            $userStmt->close();

            if ($user) {
                $appUrl = getenv('KINGQUIRY_APP_URL');
                if ($appUrl === false || trim($appUrl) === '') {
                    if (strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development')) === 'production') {
                        error_log('KINGQUIRY_APP_URL must be configured for password reset emails in production.');
                        $appUrl = '';
                    } else {
                        $appUrl = 'http://localhost/KingQuiry';
                    }
                }
                $appUrl = rtrim((string) $appUrl, '/');
                $appUrlParts = parse_url($appUrl);
                $validAppUrl = filter_var($appUrl, FILTER_VALIDATE_URL) !== false
                    && is_array($appUrlParts)
                    && isset($appUrlParts['host'])
                    && in_array(strtolower((string) ($appUrlParts['scheme'] ?? '')), ['http', 'https'], true)
                    && !isset($appUrlParts['user'])
                    && !isset($appUrlParts['pass'])
                    && !isset($appUrlParts['query'])
                    && !isset($appUrlParts['fragment'])
                    && (
                        strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development')) !== 'production'
                        || strtolower((string) ($appUrlParts['scheme'] ?? '')) === 'https'
                    );

                if ($validAppUrl) {
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $token);
                    $userId = (int) $user['userID'];
                    $conn->begin_transaction();
                    try {
                        $deleteStmt = $conn->prepare('DELETE FROM password_reset_tokens WHERE userID = ?');
                        $deleteStmt->bind_param('i', $userId);
                        $deleteStmt->execute();
                        $deleteStmt->close();

                        $insertStmt = $conn->prepare(
                            'INSERT INTO password_reset_tokens (tokenHash, userID, expiresAt)
                             VALUES (?, ?, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE))'
                        );
                        $insertStmt->bind_param('si', $tokenHash, $userId);
                        $insertStmt->execute();
                        $insertStmt->close();
                        $conn->commit();
                    } catch (Throwable $exception) {
                        $conn->rollback();
                        error_log('KingQuiry password reset token creation failed: ' . $exception->getMessage());
                        throw $exception;
                    }

                    $resetUrl = $appUrl . '/reset_password.php?token=' . rawurlencode($token);
                    if (!send_property_email_log(
                        $conn,
                        $userId,
                        'Reset your KingQuiry password',
                        "A password reset was requested for your KingQuiry account.\n\nUse this link within 30 minutes to choose a new password:\n{$resetUrl}\n\nIf you did not request this, you can ignore this message."
                    )) {
                        $deleteTokenStmt = $conn->prepare('DELETE FROM password_reset_tokens WHERE tokenHash = ?');
                        $deleteTokenStmt->bind_param('s', $tokenHash);
                        $deleteTokenStmt->execute();
                        $deleteTokenStmt->close();
                    }
                } else {
                    error_log('KingQuiry password reset email URL is invalid or insecure.');
                }
            }
        }

        $message = 'If an active account matches that email address, password reset instructions will be sent.';
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="container py-5" style="max-width: 520px;">
    <div class="card border-0 shadow-sm rounded-4 p-4 p-md-5">
        <h1 class="h3 fw-bold">Forgot password?</h1>
        <p class="text-muted">Enter the email address on your account. If it is active, we'll send a secure reset link.</p>
        <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($message !== ''): ?><div class="alert alert-info" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <form method="POST" class="d-grid gap-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label class="form-label fw-semibold" for="reset-email">Email address</label>
                <input class="form-control" type="email" name="email" id="reset-email" maxlength="100" autocomplete="email" required>
            </div>
            <button type="submit" class="btn btn-primary fw-semibold">Send reset link</button>
            <a class="text-center small" href="client_login.php">Back to sign in</a>
        </form>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
