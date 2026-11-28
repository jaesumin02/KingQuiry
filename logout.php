<?php
require_once 'includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

apply_secure_headers();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use the logout form.');
}
if (!has_valid_csrf_token()) {
    http_response_code(403);
    exit('Your session expired. Refresh and try again.');
}

$logoutUserId = (int) ($_SESSION['user']['userID'] ?? 0);
if ($logoutUserId > 0) {
    require_once 'config/db.php';
    if (isset($conn) && $conn instanceof mysqli) {
        kq_log_auth_event($conn, $logoutUserId, 'logout');
        if (function_exists('update_user_presence')) {
            update_user_presence($conn, $logoutUserId, false, 'offline');
        }
    }
}

$_SESSION = [];
session_destroy();

if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?? 'Lax',
    ]);
}

header('Location: login.php');
exit();
?>