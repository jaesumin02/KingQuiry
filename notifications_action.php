<?php
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

apply_secure_headers();
$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || empty($currentUser['userID'])) {
    http_response_code(401);
    exit('Sign in to manage notifications.');
}
$activeUserStmt = $conn->prepare('SELECT accountStatus FROM users WHERE userID = ? LIMIT 1');
$activeUserId = (int) $currentUser['userID'];
$activeUserStmt->bind_param('i', $activeUserId);
$activeUserStmt->execute();
$activeUser = $activeUserStmt->get_result()->fetch_assoc();
if (!$activeUser || ($activeUser['accountStatus'] ?? 'Inactive') !== 'Active') {
    unset($_SESSION['user']);
    http_response_code(401);
    exit('Sign in to manage notifications.');
}

function notification_local_target($value, string $default): string
{
    if (!is_string($value) || $value === '' || strlen($value) > 255 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
        return $default;
    }

    $parts = parse_url($value);
    if (
        $parts === false
        || isset($parts['scheme'])
        || isset($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || !isset($parts['path'])
        || !preg_match('/\A[A-Za-z0-9_-]+\.php\z/', $parts['path'])
    ) {
        return $default;
    }

    return $value;
}

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use POST to manage notifications.');
}
if (!has_valid_csrf_token()) {
    http_response_code(403);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Your session expired. Refresh and try again.']);
        exit();
    }
    exit('Your session expired. Refresh and try again.');
}

$userId = $activeUserId;
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$defaultRedirect = 'index.php';

try {
    if ($action === 'read_and_go') {
        $notifId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $target = notification_local_target($_POST['to'] ?? null, $defaultRedirect);
        if ($notifId !== false && $notifId > 0) {
            mark_notification_as_read($conn, (int) $notifId, $userId);
        }
        header('Location: ' . $target);
        exit();
    }

    if ($action === 'mark_all_read') {
        mark_all_notifications_as_read($conn, $userId);
        $redirect = notification_local_target($_POST['redirect'] ?? null, $defaultRedirect);
        header('Location: ' . $redirect);
        exit();
    }

    if ($action === 'mark_read_ajax') {
        $notifId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        header('Content-Type: application/json; charset=utf-8');
        if ($notifId === false || $notifId <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Choose a valid notification.']);
            exit();
        }
        echo json_encode(['success' => mark_notification_as_read($conn, (int) $notifId, $userId)]);
        exit();
    }

    if ($action === 'dismiss_notif') {
        $notifId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if ($notifId === false || $notifId <= 0) {
            http_response_code(400);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'error' => 'Choose a valid notification.']);
                exit();
            }
            exit('Choose a valid notification.');
        }

        $deleteStmt = $conn->prepare('DELETE FROM user_notifications WHERE notificationID = ? AND userID = ?');
        $deleteStmt->bind_param('ii', $notifId, $userId);
        $deleteStmt->execute();
        $deleteStmt->close();

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true]);
            exit();
        }

        $redirect = notification_local_target($_POST['redirect'] ?? null, 'agent_dashboard.php');
        header('Location: ' . $redirect);
        exit();
    }

    header('Location: index.php');
    exit();
} catch (Throwable $exception) {
    error_log('KingQuiry notification action failed: ' . $exception->getMessage());
    http_response_code(500);
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'The notification could not be updated. Please retry.']);
        exit();
    }
    exit('The notification could not be updated. Please retry.');
}
