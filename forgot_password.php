<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/sms.php';

kq_start_session();
apply_secure_headers();

$loginRoutes = [
    'client' => ['path' => 'login.php', 'label' => 'KingQuiry'],
    'agent' => ['path' => 'login.php', 'label' => 'KingQuiry'],
    'admin' => ['path' => 'login.php', 'label' => 'KingQuiry'],
];
$requestedPortal = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['portal'] ?? '')
    : ($_GET['portal'] ?? ($_SESSION['kq_password_reset_portal'] ?? 'client'));
$portal = is_string($requestedPortal) && isset($loginRoutes[$requestedPortal])
    ? $requestedPortal
    : 'client';
$loginRoute = $loginRoutes[$portal];

$clearRecoverySession = static function (): void {
    unset(
        $_SESSION['kq_password_reset_email'],
        $_SESSION['kq_password_reset_lookup'],
        $_SESSION['kq_password_reset_lookup_type'],
        $_SESSION['kq_password_reset_candidate_user_ids'],
        $_SESSION['kq_password_reset_verified_user_ids'],
        $_SESSION['kq_password_reset_otp_expires_at'],
        $_SESSION['kq_password_reset_verified_user_id'],
        $_SESSION['kq_password_reset_verified_until'],
        $_SESSION['kq_password_reset_phone'],
        $_SESSION['kq_password_reset_verified_phone'],
        $_SESSION['kq_password_reset_channel'],
        $_SESSION['kq_password_reset_portal']
    );
};

if (isset($_GET['new_email'])) {
    $clearRecoverySession();
}

$step = 'request';
$emailValue = '';
$identifierValue = '';
$otpExpiresAt = 0;
$verifiedUserId = filter_var(
    $_SESSION['kq_password_reset_verified_user_id'] ?? null,
    FILTER_VALIDATE_INT
);
$verifiedUntil = (int) ($_SESSION['kq_password_reset_verified_until'] ?? 0);
$pendingLookup = is_string($_SESSION['kq_password_reset_lookup'] ?? null)
    ? (string) $_SESSION['kq_password_reset_lookup']
    : '';
$pendingLookupType = is_string($_SESSION['kq_password_reset_lookup_type'] ?? null)
    ? (string) $_SESSION['kq_password_reset_lookup_type']
    : '';
$pendingCandidateIds = is_array($_SESSION['kq_password_reset_candidate_user_ids'] ?? null)
    ? array_values(array_unique(array_filter(
        array_map('intval', $_SESSION['kq_password_reset_candidate_user_ids']),
        static function (int $userId): bool {
            return $userId > 0;
        }
    )))
    : [];
$verifiedUserIds = is_array($_SESSION['kq_password_reset_verified_user_ids'] ?? null)
    ? array_values(array_unique(array_filter(
        array_map('intval', $_SESSION['kq_password_reset_verified_user_ids']),
        static function (int $userId): bool {
            return $userId > 0;
        }
    )))
    : [];
$pendingExpiresAt = (int) ($_SESSION['kq_password_reset_otp_expires_at'] ?? 0);
$pendingChannel = is_string($_SESSION['kq_password_reset_channel'] ?? null)
    ? (string) $_SESSION['kq_password_reset_channel']
    : 'email';
$pendingChannel = in_array($pendingChannel, ['email', 'phone'], true) ? $pendingChannel : 'email';
$hasVerifiedRecovery = $verifiedUserId !== false && $verifiedUserId > 0;

if ($hasVerifiedRecovery && $verifiedUntil > time()) {
    $step = 'password';
} elseif ($verifiedUntil > time() && $verifiedUserIds !== []) {
    if (count($verifiedUserIds) === 1) {
        $_SESSION['kq_password_reset_verified_user_id'] = $verifiedUserIds[0];
        $verifiedUserId = $verifiedUserIds[0];
        $step = 'password';
    } else {
        $step = 'choose';
    }
} elseif ($pendingLookup !== '' && in_array($pendingLookupType, ['email', 'phone'], true) && $pendingExpiresAt > time()) {
    $step = 'verify';
    $identifierValue = $pendingLookup;
    $emailValue = $pendingLookupType === 'email' ? $pendingLookup : '';
    $otpExpiresAt = $pendingExpiresAt;
} elseif ($pendingLookup !== '' || $hasVerifiedRecovery || $verifiedUserIds !== []) {
    $clearRecoverySession();
}

$message = '';
$error = '';
$success = false;
$loginPage = $loginRoute['path'];
$ipAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_valid_csrf_token()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        if (in_array($action, ['send_otp', 'resend_otp', 'switch_channel'], true)) {
            $usesPendingLookup = in_array($action, ['resend_otp', 'switch_channel'], true);
            $rawIdentifier = $usesPendingLookup
                ? $pendingLookup
                : (is_string($_POST['identifier'] ?? null) ? substr($_POST['identifier'], 0, 100) : '');
            $parsedIdentifier = $usesPendingLookup
                ? ['type' => $pendingLookupType, 'value' => $pendingLookup]
                : kq_parse_recovery_identifier($rawIdentifier);

            if ($usesPendingLookup && $pendingLookup === '') {
                $error = 'Start a new recovery request to find your account.';
            } elseif ($parsedIdentifier === null) {
                $identifierValue = $rawIdentifier;
                $error = 'Enter a valid email address or Philippine mobile number.';
            } else {
                $lookupType = (string) $parsedIdentifier['type'];
                $lookup = (string) $parsedIdentifier['value'];
                $identifierValue = $lookup;
                $emailValue = $lookupType === 'email' ? $lookup : '';
                $channel = $action === 'resend_otp'
                    ? $pendingChannel
                    : ($action === 'switch_channel'
                        ? ($pendingChannel === 'email' ? 'phone' : 'email')
                        : ($lookupType === 'phone' ? 'phone' : 'email'));

                if ($lookupType === 'phone' && $channel !== 'phone') {
                    $error = 'Use the email address to recover by email.';
                } elseif ($channel === 'phone' && !kq_semaphore_is_configured()) {
                    $step = $pendingLookup !== '' && $pendingExpiresAt > time() ? 'verify' : 'request';
                    $otpExpiresAt = $pendingExpiresAt;
                    $error = 'SMS recovery is not configured. Use email or contact support.';
                } else {
                    $identityKey = $lookupType . ':' . $lookup;
                    $ipBucket = kq_rate_limit_consume($conn, 'password-reset-ip', $ipAddress, 20, 3600);
                    $identityBucket = kq_rate_limit_consume(
                        $conn,
                        'password-reset-email',
                        $ipAddress . "\0" . $identityKey,
                        5,
                        3600
                    );

                    if (!$ipBucket['allowed'] || !$identityBucket['allowed']) {
                        $step = $pendingLookup !== '' && $pendingExpiresAt > time() ? 'verify' : 'request';
                        $otpExpiresAt = $pendingExpiresAt;
                        $error = 'Too many code requests. Please wait before trying again.';
                    } else {
                        $clearRecoverySession();
                        kq_regenerate_session_id_or_fail('Unable to secure the password recovery session.');
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        $matchedUsers = [];
                        $destinationPhone = '';
                        if ($lookupType === 'email') {
                            $userStmt = $conn->prepare(
                                "SELECT userID, email, phone, phoneVerifiedAt
                                 FROM users WHERE email = ? AND accountStatus = 'Active'
                                 LIMIT 1"
                            );
                            $userStmt->bind_param('s', $lookup);
                            $userStmt->execute();
                            $userResult = $userStmt->get_result();
                            while ($user = $userResult->fetch_assoc()) {
                                $matchedUsers[] = $user;
                            }
                            $userStmt->close();

                            if ($channel === 'phone' && $matchedUsers !== []) {
                                $userPhone = kq_login_sms_phone($matchedUsers[0]);
                                if ($userPhone !== null) {
                                    $destinationPhone = $userPhone;
                                } else {
                                    $matchedUsers = [];
                                }
                            } elseif ($channel === 'phone') {
                                $matchedUsers = [];
                            }
                        } else {
                            $destinationPhone = $lookup;
                            $phoneStmt = $conn->prepare(
                                "SELECT userID, email, phone, phoneVerifiedAt
                                 FROM users
                                 WHERE phoneVerifiedAt IS NOT NULL AND accountStatus = 'Active'
                                   AND phone IS NOT NULL"
                            );
                            $phoneStmt->execute();
                            $phoneResult = $phoneStmt->get_result();
                            while ($user = $phoneResult->fetch_assoc()) {
                                if (kq_login_sms_phone($user) === $lookup) {
                                    $matchedUsers[] = $user;
                                }
                            }
                            $phoneStmt->close();
                        }

                        $matchedUserIds = array_values(array_unique(array_map(
                            static function (array $user): int {
                                return (int) $user['userID'];
                            },
                            $matchedUsers
                        )));
                        $sent = false;
                        if ($matchedUserIds !== []) {
                            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                            $codeHash = password_hash($code, PASSWORD_DEFAULT);
                            $conn->begin_transaction();
                            try {
                                $otpStmt = $conn->prepare(
                                    'INSERT INTO password_reset_otps (userID, codeHash, attempts, expiresAt, createdAt)
                                     VALUES (?, ?, 0, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE), CURRENT_TIMESTAMP)
                                     ON DUPLICATE KEY UPDATE codeHash = VALUES(codeHash), attempts = 0,
                                         expiresAt = VALUES(expiresAt), createdAt = CURRENT_TIMESTAMP'
                                );
                                $userId = 0;
                                $otpStmt->bind_param('is', $userId, $codeHash);
                                foreach ($matchedUserIds as $candidateUserId) {
                                    $userId = $candidateUserId;
                                    $otpStmt->execute();
                                }
                                $otpStmt->close();
                                $conn->commit();
                            } catch (Throwable $exception) {
                                $conn->rollback();
                                error_log('KingQuiry recovery challenge could not be created: ' . $exception->getMessage());
                                throw $exception;
                            }

                            $sent = $channel === 'email'
                                ? send_property_email_log(
                                    $conn,
                                    $matchedUserIds[0],
                                    'Your KingQuiry password reset code',
                                    "Your six-digit KingQuiry password reset code is {$code}. It expires in 10 minutes and can only be used once. If you did not request this, you can ignore this message."
                                )
                                : kq_send_semaphore_otp($destinationPhone, $code);

                            if (!$sent) {
                                $deleteOtpStmt = $conn->prepare('DELETE FROM password_reset_otps WHERE userID = ?');
                                $userId = 0;
                                $deleteOtpStmt->bind_param('i', $userId);
                                foreach ($matchedUserIds as $candidateUserId) {
                                    $userId = $candidateUserId;
                                    $deleteOtpStmt->execute();
                                }
                                $deleteOtpStmt->close();
                                $matchedUserIds = [];
                                $message = '';
                                $error = 'We could not send a verification code. Try another method or contact support.';
                            }
                        }

                        $_SESSION['kq_password_reset_lookup'] = $lookup;
                        $_SESSION['kq_password_reset_lookup_type'] = $lookupType;
                        $_SESSION['kq_password_reset_candidate_user_ids'] = $matchedUserIds;
                        $_SESSION['kq_password_reset_otp_expires_at'] = time() + 600;
                        $_SESSION['kq_password_reset_channel'] = $channel;
                        if ($channel === 'phone') {
                            $_SESSION['kq_password_reset_phone'] = $destinationPhone;
                        }
                        $_SESSION['kq_password_reset_portal'] = $portal;
                        $step = 'verify';
                        $otpExpiresAt = (int) $_SESSION['kq_password_reset_otp_expires_at'];
                        $pendingChannel = $channel;
                        $message = $channel === 'email'
                            ? 'If an active account matches, a recovery code may be sent to its email address.'
                            : 'If an active account matches and the number is verified, a recovery code may be sent by SMS.';
                    }
                }
            }
        } elseif ($action === 'verify_otp') {
            $pendingLookup = is_string($_SESSION['kq_password_reset_lookup'] ?? null)
                ? (string) $_SESSION['kq_password_reset_lookup']
                : '';
            $pendingLookupType = is_string($_SESSION['kq_password_reset_lookup_type'] ?? null)
                ? (string) $_SESSION['kq_password_reset_lookup_type']
                : '';
            $pendingCandidateIds = is_array($_SESSION['kq_password_reset_candidate_user_ids'] ?? null)
                ? array_values(array_unique(array_filter(
                    array_map('intval', $_SESSION['kq_password_reset_candidate_user_ids']),
                    static function (int $userId): bool {
                        return $userId > 0;
                    }
                )))
                : [];
            $pendingExpiresAt = (int) ($_SESSION['kq_password_reset_otp_expires_at'] ?? 0);
            $step = 'verify';
            $identifierValue = $pendingLookup;
            $emailValue = $pendingLookupType === 'email' ? $pendingLookup : '';
            $otpExpiresAt = $pendingExpiresAt;

            if (
                $pendingLookup === ''
                || !in_array($pendingLookupType, ['email', 'phone'], true)
                || $pendingExpiresAt <= time()
            ) {
                $clearRecoverySession();
                $step = 'request';
                $error = 'Your verification code expired. Request a new code.';
            } else {
                $verifyBucket = kq_rate_limit_consume(
                    $conn,
                    'password-reset-verify',
                    $ipAddress . "\0" . $pendingLookupType . ':' . $pendingLookup,
                    10,
                    600
                );
                $code = is_string($_POST['verification_code'] ?? null)
                    ? trim($_POST['verification_code'])
                    : '';

                if (!$verifyBucket['allowed']) {
                    $error = 'Too many verification attempts. Request a new code and try again later.';
                } elseif (!preg_match('/\A[0-9]{6}\z/', $code)) {
                    $error = 'Enter the six-digit code sent to your selected recovery method.';
                } else {
                    $conn->begin_transaction();
                    try {
                        $challengeStmt = $conn->prepare(
                            "SELECT r.userID, r.codeHash, r.attempts, u.email, u.phone, u.phoneVerifiedAt
                             FROM password_reset_otps r
                             JOIN users u ON u.userID = r.userID
                             WHERE r.userID = ? AND u.accountStatus = 'Active'
                               AND r.expiresAt > CURRENT_TIMESTAMP
                             LIMIT 1 FOR UPDATE"
                        );
                        $candidateUserId = 0;
                        $challengeStmt->bind_param('i', $candidateUserId);
                        $challengeRows = [];
                        foreach ($pendingCandidateIds as $candidateId) {
                            $candidateUserId = $candidateId;
                            $challengeStmt->execute();
                            $challenge = $challengeStmt->get_result()->fetch_assoc();
                            if (!$challenge) {
                                continue;
                            }

                            $matchesVerifiedContact = $pendingChannel === 'phone'
                                ? !empty($challenge['phoneVerifiedAt'])
                                    && kq_normalize_ph_mobile((string) ($challenge['phone'] ?? ''))
                                        === (string) ($_SESSION['kq_password_reset_phone'] ?? '')
                                : strtolower((string) ($challenge['email'] ?? '')) === $pendingLookup;
                            if ($matchesVerifiedContact) {
                                $challengeRows[] = $challenge;
                            }
                        }
                        $challengeStmt->close();

                        $maxAttempts = 0;
                        foreach ($challengeRows as $challengeRow) {
                            $maxAttempts = max($maxAttempts, (int) $challengeRow['attempts']);
                        }
                        $hashesMatch = $challengeRows !== [];
                        $expectedHash = $challengeRows !== [] ? (string) $challengeRows[0]['codeHash'] : '';
                        foreach ($challengeRows as $challengeRow) {
                            if (!hash_equals($expectedHash, (string) $challengeRow['codeHash'])) {
                                $hashesMatch = false;
                                break;
                            }
                        }

                        if (!$hashesMatch || $maxAttempts >= 5) {
                            $conn->rollback();
                            $error = 'That code is invalid or expired. Request a new code and try again.';
                        } elseif (!password_verify($code, $expectedHash)) {
                            $attemptStmt = $conn->prepare(
                                'UPDATE password_reset_otps SET attempts = attempts + 1
                                 WHERE userID = ? AND attempts < 5'
                            );
                            $attemptUserId = 0;
                            $attemptStmt->bind_param('i', $attemptUserId);
                            foreach ($challengeRows as $challengeRow) {
                                $attemptUserId = (int) $challengeRow['userID'];
                                $attemptStmt->execute();
                            }
                            $attemptStmt->close();
                            $attemptsRemaining = 5 - ($maxAttempts + 1);
                            $conn->commit();
                            $error = $attemptsRemaining > 0
                                ? 'That code is incorrect. You have ' . $attemptsRemaining . ' attempt(s) left.'
                                : 'Too many incorrect codes. Request a new code.';
                        } else {
                            $consumeStmt = $conn->prepare(
                                'DELETE FROM password_reset_otps
                                 WHERE userID = ? AND codeHash = ? AND expiresAt > CURRENT_TIMESTAMP'
                            );
                            $consumeUserId = 0;
                            $consumeHash = $expectedHash;
                            $consumeStmt->bind_param('is', $consumeUserId, $consumeHash);
                            $consumed = true;
                            foreach ($challengeRows as $challengeRow) {
                                $consumeUserId = (int) $challengeRow['userID'];
                                $consumeStmt->execute();
                                if ($consumeStmt->affected_rows !== 1) {
                                    $consumed = false;
                                    break;
                                }
                            }
                            $consumeStmt->close();

                            if (!$consumed) {
                                $conn->rollback();
                                $error = 'That code is invalid or expired. Request a new code and try again.';
                            } else {
                                $conn->commit();
                                kq_regenerate_session_id_or_fail('Unable to secure the password recovery session.');
                                unset(
                                    $_SESSION['kq_password_reset_lookup'],
                                    $_SESSION['kq_password_reset_lookup_type'],
                                    $_SESSION['kq_password_reset_candidate_user_ids'],
                                    $_SESSION['kq_password_reset_otp_expires_at'],
                                    $_SESSION['kq_password_reset_channel']
                                );
                                $verifiedIds = array_map(
                                    static function (array $challengeRow): int {
                                        return (int) $challengeRow['userID'];
                                    },
                                    $challengeRows
                                );
                                $_SESSION['kq_password_reset_verified_user_ids'] = $verifiedIds;
                                $_SESSION['kq_password_reset_verified_until'] = time() + 600;
                                $_SESSION['kq_password_reset_portal'] = $portal;
                                if ($pendingChannel === 'phone') {
                                    $_SESSION['kq_password_reset_verified_phone'] = (string) ($_SESSION['kq_password_reset_phone'] ?? '');
                                    unset($_SESSION['kq_password_reset_phone']);
                                }
                                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                                if (count($verifiedIds) === 1) {
                                    $_SESSION['kq_password_reset_verified_user_id'] = $verifiedIds[0];
                                    $step = 'password';
                                    $message = 'Verification complete. Choose a new password.';
                                } else {
                                    $step = 'choose';
                                    $message = '';
                                }
                            }
                        }
                    } catch (Throwable $exception) {
                        $conn->rollback();
                        error_log('KingQuiry password reset code verification failed: ' . $exception->getMessage());
                        $error = 'The verification code could not be checked. Please try again.';
                    }
                }
            }
        } elseif ($action === 'select_account') {
            $verifiedUntil = (int) ($_SESSION['kq_password_reset_verified_until'] ?? 0);
            $verifiedUserIds = is_array($_SESSION['kq_password_reset_verified_user_ids'] ?? null)
                ? array_values(array_unique(array_filter(
                    array_map('intval', $_SESSION['kq_password_reset_verified_user_ids']),
                    static function (int $userId): bool {
                        return $userId > 0;
                    }
                )))
                : [];
            $selectedUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
            $step = 'choose';

            if ($verifiedUntil <= time() || $verifiedUserIds === []) {
                $clearRecoverySession();
                $step = 'request';
                $error = 'Your verified recovery session expired. Find your account again.';
            } elseif ($selectedUserId === false || !in_array((int) $selectedUserId, $verifiedUserIds, true)) {
                $error = 'Choose an account from the verified recovery list.';
            } else {
                $selectedStmt = $conn->prepare(
                    "SELECT userID, phone, phoneVerifiedAt
                     FROM users WHERE userID = ? AND accountStatus = 'Active'
                     LIMIT 1"
                );
                $selectedId = (int) $selectedUserId;
                $selectedStmt->bind_param('i', $selectedId);
                $selectedStmt->execute();
                $selectedUser = $selectedStmt->get_result()->fetch_assoc();
                $selectedStmt->close();

                $verifiedPhone = is_string($_SESSION['kq_password_reset_verified_phone'] ?? null)
                    ? (string) $_SESSION['kq_password_reset_verified_phone']
                    : '';
                if (
                    !$selectedUser
                    || $verifiedPhone === ''
                    || empty($selectedUser['phoneVerifiedAt'])
                    || kq_normalize_ph_mobile((string) ($selectedUser['phone'] ?? '')) !== $verifiedPhone
                ) {
                    $clearRecoverySession();
                    $step = 'request';
                    $error = 'That account is no longer available for this recovery. Start again or contact support.';
                } else {
                    $_SESSION['kq_password_reset_verified_user_id'] = $selectedId;
                    unset($_SESSION['kq_password_reset_verified_user_ids']);
                    $verifiedUserId = $selectedId;
                    $verifiedUntil = (int) $_SESSION['kq_password_reset_verified_until'];
                    $step = 'password';
                    $message = 'Verification complete. Choose a new password.';
                }
            }
        } elseif ($action === 'update_password') {
            $verifiedUserId = filter_var(
                $_SESSION['kq_password_reset_verified_user_id'] ?? null,
                FILTER_VALIDATE_INT
            );
            $verifiedUntil = (int) ($_SESSION['kq_password_reset_verified_until'] ?? 0);
            $step = 'password';

            if ($verifiedUserId === false || $verifiedUserId <= 0 || $verifiedUntil <= time()) {
                $clearRecoverySession();
                $step = 'request';
                $error = 'Your verified recovery session expired. Request a new code.';
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
                        $userStmt = $conn->prepare(
                            "SELECT userID, role FROM users
                             WHERE userID = ? AND accountStatus = 'Active'
                             LIMIT 1 FOR UPDATE"
                        );
                        $userStmt->bind_param('i', $verifiedUserId);
                        $userStmt->execute();
                        $user = $userStmt->get_result()->fetch_assoc();
                        $userStmt->close();

                        if (!$user) {
                            $conn->rollback();
                            $clearRecoverySession();
                            $step = 'request';
                            $error = 'This account is unavailable. Request a new recovery code or contact support.';
                        } else {
                            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                            $updateStmt = $conn->prepare(
                                "UPDATE users SET passwordHash = ?
                                 WHERE userID = ? AND accountStatus = 'Active'"
                            );
                            $updateStmt->bind_param('si', $passwordHash, $verifiedUserId);
                            $updateStmt->execute();
                            if ($updateStmt->affected_rows !== 1) {
                                throw new RuntimeException('The account password could not be updated.');
                            }
                            $updateStmt->close();

                            $deleteResetStmt = $conn->prepare('DELETE FROM password_reset_tokens WHERE userID = ?');
                            $deleteResetStmt->bind_param('i', $verifiedUserId);
                            $deleteResetStmt->execute();
                            $deleteResetStmt->close();
                            $deleteOtpStmt = $conn->prepare('DELETE FROM password_reset_otps WHERE userID = ?');
                            $deleteOtpStmt->bind_param('i', $verifiedUserId);
                            $deleteOtpStmt->execute();
                            $deleteOtpStmt->close();
                            $deleteChallengeStmt = $conn->prepare('DELETE FROM login_2fa_challenges WHERE userID = ?');
                            $deleteChallengeStmt->bind_param('i', $verifiedUserId);
                            $deleteChallengeStmt->execute();
                            $deleteChallengeStmt->close();
                            $deletePhoneVerifyStmt = $conn->prepare('DELETE FROM phone_verification_otps WHERE userID = ?');
                            $deletePhoneVerifyStmt->bind_param('i', $verifiedUserId);
                            $deletePhoneVerifyStmt->execute();
                            $deletePhoneVerifyStmt->close();
                            $conn->commit();

                            $role = normalize_role_name((string) $user['role']);
                            $rolePortal = $role === 'Admin' ? 'admin' : ($role === 'Sales Agent' ? 'agent' : 'client');
                            $loginPage = $loginRoutes[$rolePortal]['path'];
                            $clearRecoverySession();
                            $success = true;
                        }
                    } catch (Throwable $exception) {
                        $conn->rollback();
                        error_log('KingQuiry password reset failed: ' . $exception->getMessage());
                        $error = 'The password could not be reset. Please try again later.';
                    }
                }
            }
        } elseif ($action === 'start_over') {
            $clearRecoverySession();
            $_SESSION['kq_password_reset_portal'] = $portal;
            $step = 'request';
            $emailValue = '';
            $identifierValue = '';
        }
    }
}

$maskedEmail = '';
$emailSeparator = strrpos($emailValue, '@');
if ($emailSeparator !== false) {
    $maskedEmail = substr($emailValue, 0, 1) . '***' . substr($emailValue, $emailSeparator);
}
$recoveryPhone = is_string($_SESSION['kq_password_reset_phone'] ?? null)
    ? (string) $_SESSION['kq_password_reset_phone']
    : (is_string($_SESSION['kq_password_reset_verified_phone'] ?? null)
        ? (string) $_SESSION['kq_password_reset_verified_phone']
        : '');
$maskedPhone = $recoveryPhone !== ''
    ? '+63 *** *** ' . substr($recoveryPhone, -4)
    : '';
$deliveryChannel = is_string($_SESSION['kq_password_reset_channel'] ?? null)
    ? (string) $_SESSION['kq_password_reset_channel']
    : $pendingChannel;
$deliveryChannel = in_array($deliveryChannel, ['email', 'phone'], true) ? $deliveryChannel : 'email';
$alternateChannel = $deliveryChannel === 'email' ? 'phone' : 'email';
$lookupType = is_string($_SESSION['kq_password_reset_lookup_type'] ?? null)
    ? (string) $_SESSION['kq_password_reset_lookup_type']
    : $pendingLookupType;
$canSwitchRecoveryChannel = $lookupType === 'email'
    && ($alternateChannel !== 'phone' || kq_semaphore_is_configured());
$isDevelopmentEmailLogged = strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development')) !== 'production'
    && !filter_var(getenv('KINGQUIRY_DEV_MAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN);
$recoveryAccounts = [];
if ($step === 'choose') {
    $choiceIds = is_array($_SESSION['kq_password_reset_verified_user_ids'] ?? null)
        ? array_values(array_unique(array_filter(
            array_map('intval', $_SESSION['kq_password_reset_verified_user_ids']),
            static function (int $userId): bool {
                return $userId > 0;
            }
        )))
        : [];
    $verifiedPhone = is_string($_SESSION['kq_password_reset_verified_phone'] ?? null)
        ? (string) $_SESSION['kq_password_reset_verified_phone']
        : '';

    foreach ($choiceIds as $choiceId) {
        $accountStmt = $conn->prepare(
            "SELECT userID, fullname, email, role, phone, phoneVerifiedAt
             FROM users WHERE userID = ? AND accountStatus = 'Active'
             LIMIT 1"
        );
        $accountStmt->bind_param('i', $choiceId);
        $accountStmt->execute();
        $account = $accountStmt->get_result()->fetch_assoc();
        $accountStmt->close();

        if (
            $account
            && $verifiedPhone !== ''
            && !empty($account['phoneVerifiedAt'])
            && kq_normalize_ph_mobile((string) ($account['phone'] ?? '')) === $verifiedPhone
        ) {
            $accountEmail = (string) ($account['email'] ?? '');
            $separator = strrpos($accountEmail, '@');
            $recoveryAccounts[] = [
                'userID' => (int) $account['userID'],
                'fullname' => (string) ($account['fullname'] ?? 'KingQuiry account'),
                'email' => $separator === false
                    ? ''
                    : substr($accountEmail, 0, 1) . '***' . substr($accountEmail, $separator),
                'role' => normalize_role_name((string) ($account['role'] ?? 'Client')),
            ];
        }
    }

    if ($recoveryAccounts === []) {
        $clearRecoverySession();
        $step = 'request';
        $error = 'Those accounts are no longer available for recovery. Start again or contact support.';
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="kq-auth-page kq-recovery-page">
    <section class="kq-auth-panel kq-recovery-panel card" aria-labelledby="recovery-title">
        <div class="kq-brand" aria-label="4 Kings Realty Services logo">
            <img class="kq-official-logo kq-logo-auth" src="assets/images/kings-land-properties-logo.png" alt="4 Kings Realty Services">
            <div class="kq-brand-sub"><?= htmlspecialchars($loginRoute['label'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <div class="kq-recovery-eyebrow">Account recovery</div>

        <?php if ($success): ?>
            <h1 id="recovery-title">Password updated</h1>
            <p class="kq-recovery-copy">Your password has been changed. Sign in with your new password.</p>
            <a class="kq-auth-submit" href="<?= htmlspecialchars($loginPage, ENT_QUOTES, 'UTF-8') ?>?password_reset=1">Return to sign in</a>
        <?php elseif ($step === 'verify'): ?>
            <h1 id="recovery-title"><?= $deliveryChannel === 'phone' ? 'Check your phone' : 'Check your email' ?></h1>
            <?php if ($deliveryChannel === 'phone'): ?>
                <p class="kq-recovery-copy">
                    Enter the six-digit code sent to
                    <?= $maskedPhone !== '' ? '<strong>' . htmlspecialchars($maskedPhone, ENT_QUOTES, 'UTF-8') . '</strong>' : 'your verified phone number' ?>,
                    if an account matches. It expires in 10 minutes.
                </p>
            <?php else: ?>
                <p class="kq-recovery-copy">Enter the six-digit code sent to <strong><?= htmlspecialchars($maskedEmail, ENT_QUOTES, 'UTF-8') ?></strong>, if an account matches.</p>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger kq-recovery-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($error === '' && ($message !== '' || $deliveryChannel === 'email' && $isDevelopmentEmailLogged)): ?>
                <div class="alert alert-info kq-recovery-alert" role="status">
                    <?php if ($message !== ''): ?>
                        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                    <?php if ($deliveryChannel === 'email' && $isDevelopmentEmailLogged): ?>
                        <?php if ($message !== ''): ?><br><?php endif; ?>
                        Local email is not sent by default. For development, check <code>logs/email_notifications.log</code> or configure SMTP.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="kq-otp-expiry" role="timer" aria-label="Verification code expires in">
                <span>Code expires in</span>
                <strong id="otp-countdown" data-expires-at="<?= $otpExpiresAt ?>">10:00</strong>
            </div>
            <span class="visually-hidden" id="otp-expired-message" aria-live="polite"></span>
            <form method="POST" class="kq-recovery-form" id="otp-verification-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="verify_otp">
                <div class="kq-form-group">
                    <label for="verification-code"><?= $deliveryChannel === 'phone' ? 'SMS verification code' : 'Email verification code' ?></label>
                    <input class="form-control kq-recovery-otp-input" type="text" name="verification_code" id="verification-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000" aria-describedby="otp-expiry-note" required>
                </div>
                <p class="kq-recovery-note" id="otp-expiry-note">For your security, never share this code with anyone.</p>
                <button type="submit" class="kq-auth-submit" id="verify-otp-button">Verify code</button>
            </form>

            <form method="POST" class="kq-recovery-secondary-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="resend_otp">
                <button type="submit" class="kq-recovery-text-button">Resend code</button>
            </form>
            <?php if ($canSwitchRecoveryChannel): ?>
                <form method="POST" class="kq-recovery-secondary-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="switch_channel">
                    <input type="hidden" name="channel" value="<?= htmlspecialchars($alternateChannel, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="kq-recovery-text-button">Try another way (<?= $alternateChannel === 'phone' ? 'SMS' : 'email' ?>)</button>
                </form>
            <?php endif; ?>
            <div class="kq-recovery-secondary-form">
                <button type="button" class="kq-recovery-text-button" data-bs-toggle="modal" data-bs-target="#contactUsModal">Contact support</button>
            </div>
            <form method="POST" class="kq-recovery-secondary-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="start_over">
                <button type="submit" class="kq-recovery-text-button">Use a different email or mobile number</button>
            </form>
        <?php elseif ($step === 'choose'): ?>
            <h1 id="recovery-title">Choose your account</h1>
            <p class="kq-recovery-copy">
                These accounts match the phone number you verified. Select the account whose password you want to reset.
            </p>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger kq-recovery-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <div class="d-grid gap-2" aria-label="Accounts linked to the verified phone">
                <?php foreach ($recoveryAccounts as $account): ?>
                    <form method="POST" class="card border rounded-3 p-3 text-start">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="select_account">
                        <input type="hidden" name="user_id" value="<?= (int) $account['userID'] ?>">
                        <div class="fw-semibold"><?= htmlspecialchars($account['fullname'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($account['role'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($account['email'] !== ''): ?>
                            <div class="small text-muted"><?= htmlspecialchars($account['email'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-outline-primary mt-3">Use this account</button>
                    </form>
                <?php endforeach; ?>
            </div>
            <form method="POST" class="kq-recovery-secondary-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="start_over">
                <button type="submit" class="kq-recovery-text-button">Find another account</button>
            </form>
        <?php elseif ($step === 'password'): ?>
            <h1 id="recovery-title">Create a new password</h1>
            <p class="kq-recovery-copy">Your recovery code is verified. Choose a new password for your account.</p>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger kq-recovery-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="alert alert-info kq-recovery-alert" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="POST" class="kq-recovery-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_password">
                <div class="kq-form-group">
                    <label for="new-password">New password</label>
                    <input class="form-control" type="password" id="new-password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" required>
                </div>
                <div class="kq-form-group">
                    <label for="confirm-password">Confirm new password</label>
                    <input class="form-control" type="password" id="confirm-password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required>
                </div>
                <button type="submit" class="kq-auth-submit">Update password</button>
            </form>
        <?php else: ?>
            <h1 id="recovery-title">Find your account</h1>
            <p class="kq-recovery-copy">Enter the email or mobile number linked to your account. We only show matching account details after you verify a code.</p>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger kq-recovery-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($message !== ''): ?>
                <div class="alert alert-info kq-recovery-alert" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <form method="POST" class="kq-recovery-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="portal" value="<?= htmlspecialchars($portal, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="send_otp">
                <div class="kq-form-group">
                    <label for="reset-identifier">Email or mobile number</label>
                    <input class="form-control" type="text" name="identifier" id="reset-identifier" maxlength="100" autocomplete="username" placeholder="Email or mobile number" value="<?= htmlspecialchars($identifierValue, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <button type="submit" class="kq-auth-submit">
                    <svg class="kq-recovery-button-icon" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                    <span>Continue</span>
                </button>
            </form>
        <?php endif; ?>

        <?php if (!$success): ?>
            <a class="kq-recovery-back" href="<?= htmlspecialchars($loginRoute['path'], ENT_QUOTES, 'UTF-8') ?>">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/><path d="M9 12h12"/></svg>
                <span>Back to <?= htmlspecialchars($loginRoute['label'], ENT_QUOTES, 'UTF-8') ?> sign in</span>
            </a>
            <?php if (in_array($step, ['request', 'verify'], true)): ?>
                <p class="kq-recovery-note">Verification codes expire after 10 minutes and can only be used once.</p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?php if ($step === 'verify' && $otpExpiresAt > 0): ?>
<script>
(function () {
    var countdown = document.getElementById('otp-countdown');
    if (!countdown) return;

    var expiresAt = Number(countdown.getAttribute('data-expires-at')) * 1000;
    var verificationCode = document.getElementById('verification-code');
    var verifyButton = document.getElementById('verify-otp-button');

    function updateCountdown() {
        var remaining = Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
        var minutes = Math.floor(remaining / 60);
        var seconds = remaining % 60;
        countdown.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

        if (remaining === 0) {
            countdown.textContent = '00:00';
            countdown.classList.add('is-expired');
            var expiredMessage = document.getElementById('otp-expired-message');
            if (expiredMessage) expiredMessage.textContent = 'The verification code expired. Request a new code.';
            if (verificationCode) verificationCode.disabled = true;
            if (verifyButton) verifyButton.disabled = true;
        }
    }

    updateCountdown();
    window.setInterval(updateCountdown, 1000);
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
