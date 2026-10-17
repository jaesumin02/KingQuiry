<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$currentRole = normalized_user_role($_SESSION['user'] ?? null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#142d4e">
    <title>KingQuiry | Land & Property</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --kq-ink: #18304c;
            --kq-muted: #687789;
            --kq-primary: #174a70;
            --kq-primary-dark: #123a59;
            --kq-accent: #d8ad63;
            --kq-line: #e4eaf0;
            --kq-surface: #fff;
            --kq-canvas: #f5f7fa;
            --kq-radius: 1rem;
            --kq-shadow: 0 12px 32px rgba(22, 48, 76, .07);
        }

        html { scroll-behavior: smooth; }
        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            color: var(--kq-ink);
            background: var(--kq-canvas);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        a { text-underline-offset: .18em; }
        .navbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            background: rgba(20, 45, 78, .97) !important;
            backdrop-filter: blur(12px);
        }
        .navbar .container { min-height: 68px; }
        .navbar-brand {
            color: #fff !important;
            font-size: 1.24rem;
            letter-spacing: -.035em;
        }
        .navbar-brand span { color: var(--kq-accent); }
        .navbar-nav { align-items: center; gap: .25rem; }
        .navbar .nav-link {
            padding: .55rem .8rem !important;
            border-radius: .6rem;
            color: rgba(255,255,255,.78) !important;
            transition: color .18s ease, background-color .18s ease;
        }
        .navbar .nav-link:hover, .navbar .nav-link:focus {
            color: #fff !important;
            background: rgba(255,255,255,.1);
        }
        main { flex: 1; }
        main.container { max-width: 1360px; }
        .card {
            border: 1px solid var(--kq-line);
            border-radius: var(--kq-radius);
            background: var(--kq-surface);
            box-shadow: var(--kq-shadow);
        }
        .card-header {
            border-color: var(--kq-line);
            border-radius: var(--kq-radius) var(--kq-radius) 0 0 !important;
            background: #fff;
        }
        .btn { border-radius: .65rem; font-weight: 600; transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease; }
        .btn:hover { transform: translateY(-1px); }
        .btn-primary {
            --bs-btn-bg: var(--kq-primary);
            --bs-btn-border-color: var(--kq-primary);
            --bs-btn-hover-bg: var(--kq-primary-dark);
            --bs-btn-hover-border-color: var(--kq-primary-dark);
            --bs-btn-active-bg: var(--kq-primary-dark);
        }
        .btn-outline-primary {
            --bs-btn-color: var(--kq-primary);
            --bs-btn-border-color: #bdd0df;
            --bs-btn-hover-bg: var(--kq-primary);
            --bs-btn-hover-border-color: var(--kq-primary);
        }
        .form-control, .form-select {
            min-height: 44px;
            border-color: #dbe3ea;
            border-radius: .65rem;
            color: var(--kq-ink);
        }
        textarea.form-control { min-height: 110px; }
        .form-control:focus, .form-select:focus {
            border-color: #77a0bf;
            box-shadow: 0 0 0 .22rem rgba(23,74,112,.12);
        }
        .text-muted { color: var(--kq-muted) !important; }
        .page-eyebrow {
            color: var(--kq-primary);
            font-size: .76rem;
            font-weight: 750;
            letter-spacing: .12em;
            text-transform: uppercase;
        }
        .page-title { color: var(--kq-ink); font-weight: 750; letter-spacing: -.04em; }
        .property-card { overflow: hidden; transition: transform .22s ease, box-shadow .22s ease; }
        .property-card:hover { transform: translateY(-4px); box-shadow: 0 18px 38px rgba(22,48,76,.13); }
        .property-card-image { height: 225px; object-fit: cover; background: #e8edf2; }
        .catalog-hero {
            overflow: hidden;
            position: relative;
            background: linear-gradient(115deg, #122f4d 0%, #1d5276 100%);
            box-shadow: 0 20px 40px rgba(18,47,77,.14);
        }
        .catalog-hero-mark {
            display: inline-grid;
            width: 150px;
            height: 150px;
            place-items: center;
            border: 1px solid rgba(255,255,255,.22);
            border-radius: 50%;
            color: rgba(255,255,255,.82);
            font-size: 3.5rem;
            font-weight: 750;
            letter-spacing: -.08em;
            box-shadow: 0 0 0 18px rgba(255,255,255,.04), 0 0 0 38px rgba(255,255,255,.025);
        }
        .property-placeholder {
            background: radial-gradient(circle at 78% 25%, rgba(216,173,99,.55), transparent 28%), linear-gradient(135deg, #dfe8ee, #bdceda);
        }
        .property-placeholder-mark {
            display: grid;
            width: 76px;
            height: 76px;
            place-items: center;
            border: 1px solid rgba(255,255,255,.8);
            border-radius: 50%;
            color: #174a70;
            background: rgba(255,255,255,.42);
            font-weight: 800;
            letter-spacing: -.05em;
        }
        .property-price { color: var(--kq-primary); font-size: 1.25rem; font-weight: 750; letter-spacing: -.025em; }
        .filter-card { position: relative; z-index: 1; }
        .table { --bs-table-color: var(--kq-ink); --bs-table-border-color: var(--kq-line); }
        .table thead th { color: #627184; font-size: .76rem; letter-spacing: .06em; text-transform: uppercase; white-space: nowrap; }
        .alert { border: 0; border-radius: .8rem; }
        .kq-tracking-timeline { padding-left: .5rem; }
        .kq-tracking-event {
            position: relative;
            margin-left: .45rem;
            padding: 0 0 1.1rem 1.25rem;
            border-left: 2px solid #dfe6ec;
        }
        .kq-tracking-event::before {
            position: absolute;
            top: .3rem;
            left: -.48rem;
            width: .85rem;
            height: .85rem;
            border: 2px solid #fff;
            border-radius: 50%;
            background: #aab6c2;
            box-shadow: 0 0 0 1px #aab6c2;
            content: "";
        }
        .kq-tracking-event.is-latest::before {
            width: 1rem;
            height: 1rem;
            left: -.55rem;
            background: #17865b;
            box-shadow: 0 0 0 2px rgba(23, 134, 91, .16);
        }
        .kq-tracking-event.is-completed::before { background: #17865b; }
        .kq-tracking-event:last-child { padding-bottom: 0; border-left-color: transparent; }
        .kq-tracking-event-content {
            padding: .75rem .9rem;
            border-radius: .7rem;
            background: #f7f9fb;
        }
        .kq-tracking-event.is-latest .kq-tracking-event-content {
            background: #f0f8f4;
            box-shadow: inset 3px 0 #17865b;
        }
        .kq-tracking-card { scroll-margin-top: 6rem; }
        .app-footer {
            margin-top: 3rem;
            padding: 2rem 0;
            color: rgba(255,255,255,.74);
            background: var(--kq-ink);
        }
        .app-footer a { color: #fff; }
        @media (max-width: 767.98px) {
            .navbar-nav { align-items: stretch; padding: .5rem 0 1rem; }
            .navbar .nav-link { padding: .7rem .8rem !important; }
            .property-card-image { height: 205px; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { transition-duration: .01ms !important; animation-duration: .01ms !important; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php">King<span>Quiry</span></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link" href="index.php">Property Catalog</a></li>
                    <?php if($currentRole !== null): ?>
                        <?php if($currentRole === 'Client'): ?>
                            <li class="nav-item"><a class="nav-link" href="client_dashboard.php">Client Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="client_dashboard.php#transaction-tracking">My Tracking</a></li>
                        <?php elseif($currentRole === 'Sales Agent'): ?>
                            <li class="nav-item"><a class="nav-link text-info fw-bold" href="agent_dashboard.php">Agent Portal</a></li>
                            <li class="nav-item"><a class="nav-link" href="transaction_tracking.php">Transaction Tracking</a></li>
                        <?php elseif($currentRole === 'Admin'): ?>
                            <li class="nav-item"><a class="nav-link text-warning fw-bold" href="admin_dashboard.php">Admin Analytics Panel</a></li>
                            <li class="nav-item"><a class="nav-link" href="transaction_tracking.php">Transaction Tracking</a></li>
                        <?php endif; ?>
                        <li class="nav-item"><a class="nav-link btn btn-danger text-white ms-2 px-3" href="logout.php">Logout</a></li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="login.php">Sign in</a></li>
                        <li class="nav-item"><a class="nav-link btn btn-primary text-white ms-2 px-3" href="register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <main class="container py-4 py-lg-5">