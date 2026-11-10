<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/auth.php';

apply_secure_headers();
$currentUser = $_SESSION['user'] ?? null;
$currentRole = normalized_user_role($currentUser);
$displayName = is_array($currentUser) && !empty($currentUser['fullname'])
    ? (string) $currentUser['fullname']
    : (is_array($currentUser) && !empty($currentUser['email']) ? explode('@', (string) $currentUser['email'])[0] : 'My Account');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#142d4e">
    <title>KingQuiry | Land & Property</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <link rel="stylesheet" href="assets/css/style.css">
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
        .kq-stat-card {
            height: 100%;
            padding: 1.1rem 1.2rem;
            border: 1px solid rgba(23, 74, 112, 0.08);
            border-radius: 1rem;
            background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(243,247,250,.96));
            box-shadow: 0 8px 24px rgba(17, 43, 69, 0.06);
        }
        .kq-stat-label {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            color: var(--kq-primary);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        .kq-stat-value {
            margin-top: .55rem;
            color: var(--kq-ink);
            font-size: clamp(1.35rem, 2vw, 2rem);
            font-weight: 800;
            letter-spacing: -.04em;
        }
        .kq-stat-sub {
            margin-top: .35rem;
            color: var(--kq-muted);
            font-size: 0.85rem;
            line-height: 1.5;
        }
        .kq-panel-header {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.1rem;
            padding-bottom: .75rem;
            border-bottom: 1px solid var(--kq-line);
        }
        .kq-panel-header::before {
            content: "";
            position: absolute;
            left: 0;
            bottom: -1px;
            width: 56px;
            height: 3px;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--kq-primary), var(--kq-accent));
        }
        .kq-badge-soft {
            display: inline-flex;
            align-items: center;
            padding: .38rem .7rem;
            border-radius: 999px;
            background: rgba(23, 74, 112, 0.08);
            color: var(--kq-primary);
            font-size: .75rem;
            font-weight: 700;
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
            <div class="d-flex align-items-center gap-2">
                <?php if ($currentRole === null || $currentRole === 'Client'): ?>
                    <button type="button" class="btn btn-warning btn-sm fw-bold text-dark d-lg-none d-inline-flex align-items-center gap-1 kq-contact-btn-mobile" data-bs-toggle="modal" data-bs-target="#contactUsModal" title="Contact Kings Land Properties">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        <span>Contact Us</span>
                    </button>
                <?php endif; ?>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>
            <div class="collapse navbar-collapse justify-content-end" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item"><a class="nav-link" href="index.php">Property Catalog</a></li>
                    <?php if($currentRole !== null): ?>
                        <?php if($currentRole === 'Client'): ?>
                            <li class="nav-item"><a class="nav-link" href="client_dashboard.php">Client Dashboard</a></li>
                            <li class="nav-item"><a class="nav-link" href="client_dashboard.php#transaction-tracking">My Tracking</a></li>
                            <li class="nav-item ms-lg-2">
                                <button type="button" class="btn btn-warning fw-bold text-dark px-3 d-inline-flex align-items-center gap-2 kq-contact-btn" data-bs-toggle="modal" data-bs-target="#contactUsModal">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                    <span>Contact Us</span>
                                </button>
                            </li>
                        <?php elseif($currentRole === 'Sales Agent'): ?>
                            <li class="nav-item"><a class="nav-link text-info fw-bold" href="agent_dashboard.php">Agent Portal</a></li>
                            <li class="nav-item"><a class="nav-link" href="transaction_tracking.php">Transaction Tracking</a></li>
                        <?php elseif($currentRole === 'Admin'): ?>
                            <li class="nav-item"><a class="nav-link text-warning fw-bold" href="admin_dashboard.php">Admin Analytics Panel</a></li>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Manage</a>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="admin_clients.php">Clients</a></li>
                                    <li><a class="dropdown-item" href="admin_payments.php">Payments</a></li>
                                    <li><a class="dropdown-item" href="admin_sales.php">Sales &amp; transactions</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="transaction_tracking.php">Transaction tracking</a></li>
                                </ul>
                            </li>
                        <?php endif; ?>
                        <!-- Account Management Dropdown (Logged In) -->
                        <li class="nav-item dropdown ms-lg-2">
                            <a class="nav-link dropdown-toggle text-white fw-bold d-inline-flex align-items-center gap-2 kq-user-nav-btn" href="#" id="userAccountDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="kq-user-avatar-circle" aria-hidden="true">
                                    <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
                                </span>
                                <span class="kq-user-display-name text-truncate" style="max-width: 170px;"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 mt-2 py-2" aria-labelledby="userAccountDropdown" style="min-width: 220px;">
                                <li class="px-3 py-2 border-bottom mb-1 bg-light rounded-top">
                                    <div class="fw-bold small text-dark text-truncate"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($currentUser['email'])): ?>
                                        <div class="text-muted small text-truncate" style="font-size: 0.78rem;"><?= htmlspecialchars($currentUser['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if ($currentRole === 'Admin' || $currentRole === 'Sales Agent'): ?>
                                        <span class="badge bg-secondary-subtle text-secondary border mt-1" style="font-size: 0.68rem;"><?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </li>
                                <li>
                                    <a class="dropdown-item d-flex align-items-center gap-2 py-2" href="manage_account.php" data-bs-toggle="modal" data-bs-target="#manageAccountModal">
                                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                        <span>Manage Account</span>
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider my-1"></li>
                                <li>
                                    <button type="button" class="dropdown-item d-flex align-items-center gap-2 py-2 text-danger fw-semibold" data-bs-toggle="modal" data-bs-target="#logoutConfirmModal">
                                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                                        <span>Log Out</span>
                                    </button>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="client_login.php">Client Sign in</a></li>
                        <li class="nav-item"><a class="nav-link btn btn-primary text-white ms-2 px-3" href="register.php">Register</a></li>
                        <li class="nav-item ms-lg-2">
                            <button type="button" class="btn btn-warning fw-bold text-dark px-3 d-inline-flex align-items-center gap-2 kq-contact-btn" data-bs-toggle="modal" data-bs-target="#contactUsModal">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                <span>Contact Us</span>
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <?php
    $currentPageName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $mainClass = $currentPageName === 'index.php' ? 'main-catalog' : 'container py-4 py-lg-5';
    ?>
    <main class="<?= $mainClass ?>">