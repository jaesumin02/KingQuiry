<?php
require_once __DIR__ . '/includes/auth.php';
kq_start_session();

if (user_has_role($_SESSION['user'] ?? null, 'Admin')) {
    header('Location: admin_dashboard.php');
    exit();
}

if (user_has_role($_SESSION['user'] ?? null, 'Sales Agent', 'Agent')) {
    header('Location: agent_dashboard.php');
    exit();
}

header('Location: login.php');
exit();
