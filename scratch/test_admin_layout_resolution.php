<?php
$_SESSION['user'] = [
    'userID' => 1,
    'fullname' => 'Admin User',
    'email' => 'admin@kingquiry.com',
    'role' => 'Admin'
];

$_SERVER['PHP_SELF'] = '/kingquiry/admin_dashboard.php';

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_layout.php';
require_once __DIR__ . '/../includes/notifications.php';

$currentUser = $_SESSION['user'] ?? null;
$currentRole = normalized_user_role($currentUser);
$currentPageName = basename($_SERVER['PHP_SELF'] ?? '');
$adminPage = ($currentRole === 'Admin' && function_exists('kq_admin_pages')) ? (kq_admin_pages()[$currentPageName] ?? null) : null;
$isAdminControlRoom = $adminPage !== null;

echo "Admin page resolved: " . json_encode($adminPage) . "\n";
echo "isAdminControlRoom: " . ($isAdminControlRoom ? "TRUE" : "FALSE") . "\n";
