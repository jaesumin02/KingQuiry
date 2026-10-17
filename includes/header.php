<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KingQuiry - Property Inquiry & Management System</title>
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">KingQuiry</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link" href="index.php">Property Catalog</a></li>
                    <?php if(isset($_SESSION['user'])): ?>
                        <?php if($_SESSION['user']['role'] == 'Client'): ?>
                            <li class="nav-item"><a class="nav-link" href="client_dashboard.php">Client Dashboard</a></li>
                        <?php elseif($_SESSION['user']['role'] == 'Sales Agent'): ?>
                            <li class="nav-item"><a class="nav-link text-info fw-bold" href="agent_dashboard.php">Agent Portal</a></li>
                        <?php elseif($_SESSION['user']['role'] == 'Admin'): ?>
                            <li class="nav-item"><a class="nav-link text-warning fw-bold" href="admin_dashboard.php">Admin Analytics Panel</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="nav-link btn btn-danger text-white ms-2 px-3" href="logout.php">Logout</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="login.php">Login</a></li>
                        <li class="nav-item"><a class="nav-link btn btn-primary text-white ms-2 px-3" href="register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container my-4"></div>