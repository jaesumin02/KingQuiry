<?php
require_once __DIR__ . '/includes/auth.php';
kq_start_session();

if (isset($_SESSION['user'])) {
    $role = normalized_user_role($_SESSION['user']);
    $destination = $role === 'Admin' ? 'admin_dashboard.php' : ($role === 'Sales Agent' ? 'agent_dashboard.php' : 'index.php');
    header('Location: ' . $destination);
    exit();
}

header('Location: client_login.php');
exit();
