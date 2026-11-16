<?php
// Mock test for includes/header.php with Admin role
session_start();
$_SESSION['user'] = [
    'userID' => 1,
    'fullname' => 'Admin User',
    'email' => 'admin@kingquiry.com',
    'role' => 'Admin'
];

$_SERVER['PHP_SELF'] = '/kingquiry/admin_dashboard.php';

ob_start();
require __DIR__ . '/../includes/header.php';
$output = ob_get_clean();

echo "Header executed successfully with Admin role!\n";
echo "Header output length: " . strlen($output) . " bytes\n";
echo "Contains adminPage title: " . (strpos($output, 'Admin dashboard') !== false ? 'YES' : 'NO') . "\n";
echo "Contains navbar: " . (strpos($output, 'Admin dashboard') !== false ? 'YES' : 'NO') . "\n";
