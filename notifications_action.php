<?php
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/notifications.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUser = $_SESSION['user'] ?? null;
if (!$currentUser || empty($currentUser['userID'])) {
    header('Location: client_login.php');
    exit();
}

$userId = (int) $currentUser['userID'];
$action = $_REQUEST['action'] ?? '';

if ($action === 'read_and_go') {
    $notifId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $target = $_GET['to'] ?? '';
    if ($notifId && $notifId > 0) {
        mark_notification_as_read($conn, (int) $notifId, $userId);
    }
    // Prevent open redirect; only allow local relative paths
    if ($target !== '' && !str_starts_with($target, 'http://') && !str_starts_with($target, 'https://') && !str_starts_with($target, '//')) {
        header('Location: ' . $target);
        exit();
    }
    header('Location: index.php');
    exit();
} elseif ($action === 'mark_all_read') {
    mark_all_notifications_as_read($conn, $userId);
    $redirect = $_POST['redirect'] ?? $_GET['redirect'] ?? 'index.php';
    if (str_starts_with($redirect, 'http://') || str_starts_with($redirect, 'https://') || str_starts_with($redirect, '//')) {
        $redirect = 'index.php';
    }
    header('Location: ' . $redirect);
    exit();
} elseif ($action === 'mark_read_ajax') {
    $notifId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    if ($notifId && $notifId > 0) {
        $success = mark_notification_as_read($conn, (int) $notifId, $userId);
        header('Content-Type: application/json');
        echo json_encode(['success' => $success]);
        exit();
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => false]);
    exit();
}

header('Location: index.php');
exit();
