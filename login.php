<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user'])) {
    $role = normalized_user_role($_SESSION['user']);
    $destination = $role === 'Admin' ? 'admin_dashboard.php' : ($role === 'Sales Agent' ? 'agent_dashboard.php' : 'client_dashboard.php');
    header('Location: ' . $destination);
    exit();
}

header('Location: client_login.php');
exit();
