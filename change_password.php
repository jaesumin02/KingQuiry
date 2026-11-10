<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = $_SESSION['user'] ?? null;
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if (!$currentUser || empty($currentUser['userID'])) {
    if ($isAjax) {
        apply_secure_headers('application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'You must be signed in to manage your account.']);
        exit();
    }
    header('Location: client_login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        apply_secure_headers('application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
        exit();
    }
    header('Location: manage_account.php');
    exit();
}

if (!has_valid_csrf_token()) {
    $errorMsg = 'Session security token has expired. Please refresh the page and try again.';
    if ($isAjax) {
        apply_secure_headers('application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $errorMsg]);
        exit();
    }
    $_SESSION['flash_error'] = $errorMsg;
    header('Location: manage_account.php');
    exit();
}

$currentPassword = (string) ($_POST['current_password'] ?? '');
$newPassword = (string) ($_POST['new_password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');
$userID = (int) $currentUser['userID'];

$error = '';
if ($currentPassword === '') {
    $error = 'Please enter your current password.';
} elseif (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
    $error = 'New password must be between 8 and 72 characters long.';
} elseif ($newPassword !== $confirmPassword) {
    $error = 'New password and confirmation password do not match.';
}

if ($error === '') {
    $stmt = $conn->prepare('SELECT passwordHash, accountStatus FROM users WHERE userID = ? LIMIT 1');
    $stmt->bind_param('i', $userID);
    $stmt->execute();
    $userData = $stmt->get_result()->fetch_assoc();

    if (!$userData) {
        $error = 'User account not found.';
    } elseif (($userData['accountStatus'] ?? 'Active') === 'Inactive') {
        $error = 'This account is inactive. Please contact the administrator.';
    } elseif (!password_verify($currentPassword, $userData['passwordHash'])) {
        $error = 'Current password is incorrect.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($newHash === false) {
            $error = 'Failed to securely hash password. Please try again.';
        } else {
            $updateStmt = $conn->prepare('UPDATE users SET passwordHash = ? WHERE userID = ?');
            $updateStmt->bind_param('si', $newHash, $userID);
            if ($updateStmt->execute()) {
                if (isset($_SESSION['user'])) {
                    $_SESSION['user']['passwordHash'] = $newHash;
                }
                $successMsg = 'Password updated successfully!';
                if ($isAjax) {
                    apply_secure_headers('application/json');
                    echo json_encode(['success' => true, 'message' => $successMsg]);
                    exit();
                }
                $_SESSION['flash_success'] = $successMsg;
                header('Location: manage_account.php');
                exit();
            } else {
                $error = 'Failed to update password in database.';
            }
        }
    }
}

if ($isAjax) {
    apply_secure_headers('application/json');
    echo json_encode(['success' => false, 'error' => $error]);
    exit();
}

$_SESSION['flash_error'] = $error;
header('Location: manage_account.php');
exit();
