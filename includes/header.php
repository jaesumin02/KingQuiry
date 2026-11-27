<?php
require_once __DIR__ . '/auth.php';
kq_start_session();
require_once __DIR__ . '/admin_layout.php';
require_once __DIR__ . '/notifications.php';

apply_secure_headers();
$currentUser = $_SESSION['user'] ?? null;
$currentRole = normalized_user_role($currentUser);
$currentPageName = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ($_SERVER['PHP_SELF'] ?? ''));
if ($currentPageName === '' || $currentPageName === 'kingquiry') {
    $currentPageName = 'index.php';
}
$isAuthPage = in_array($currentPageName, ['register.php', 'client_login.php', 'agent_login.php', 'admin_login.php', 'login.php'], true);
$needsLeaflet = in_array($currentPageName, ['property_detail.php', 'agent_dashboard.php', 'admin_dashboard.php'], true);
$adminPage = ($currentRole === 'Admin' && function_exists('kq_admin_pages')) ? (kq_admin_pages()[$currentPageName] ?? null) : null;
$isAdminControlRoom = $adminPage !== null;
$displayName = is_array($currentUser) && !empty($currentUser['fullname'])
    ? (string) $currentUser['fullname']
    : (is_array($currentUser) && !empty($currentUser['email']) ? explode('@', (string) $currentUser['email'])[0] : 'My Account');
$clockNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$clockEpochMs = $clockNow->getTimestamp() * 1000;

$unreadNotificationCount = 0;
$userNotifications = [];
if (!empty($currentUser['userID'])) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        @include_once __DIR__ . '/../config/db.php';
    }
    if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
        $currentUser = kq_sync_session_user($conn);
        if ($currentUser) {
            $headerUserId = (int) $currentUser['userID'];
            $unreadNotificationCount = get_user_unread_notification_count($conn, $headerUserId);
            $allUserNotifications = get_user_notifications($conn, $headerUserId, 60);
            $userNotifications = array_slice($allUserNotifications, 0, 10);

            $navClientTransactions = [];
            if (normalized_user_role($currentUser) === 'Client') {
                $navTxStmt = $conn->prepare(
                    "SELECT t.transactionID, t.propertyID, p.title, p.image, p.propertyType,
                            COALESCE(transactionAgent.fullname, agentUser.fullname, inqAgentUser.fullname, 'Assigned Sales Agent') AS agentName
                     FROM property_transactions t
                     JOIN properties p ON p.propertyID = t.propertyID
                     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
                     LEFT JOIN users agentUser ON agentUser.userID = a.agentID
                     LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
                     LEFT JOIN users inqAgentUser ON inqAgentUser.userID = ia.agentID
                     LEFT JOIN users transactionAgent ON transactionAgent.userID = t.assignedAgentID
                     WHERE t.clientID = ?
                     ORDER BY (t.currentStage NOT IN ('completed', 'cancelled')) DESC, t.updatedAt DESC
                     LIMIT 10"
                );
                if ($navTxStmt) {
                    $navTxStmt->bind_param('i', $headerUserId);
                    $navTxStmt->execute();
                    $navClientTransactions = $navTxStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                }
            }
        }
    }
}

$currentRole = normalized_user_role($currentUser);
$currentPageName = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ($_SERVER['PHP_SELF'] ?? ''));
if ($currentPageName === '' || $currentPageName === 'kingquiry') {
    $currentPageName = 'index.php';
}
$adminPage = ($currentRole === 'Admin' && function_exists('kq_admin_pages')) ? (kq_admin_pages()[$currentPageName] ?? null) : null;
$isAdminControlRoom = $adminPage !== null;
$displayName = is_array($currentUser) && !empty($currentUser['fullname'])
    ? (string) $currentUser['fullname']
    : (is_array($currentUser) && !empty($currentUser['email']) ? explode('@', (string) $currentUser['email'])[0] : 'My Account');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#102a43">
    <title>KingQuiry | <?= htmlspecialchars($isAdminControlRoom ? $adminPage['title'] : 'Land & Property', ENT_QUOTES, 'UTF-8') ?></title>
    <script>
        (function() {
            try {
                var theme = localStorage.getItem('kq_theme') || 'light';
                document.documentElement.setAttribute('data-bs-theme', theme);
            } catch (e) {}
        })();
    </script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <?php if ($needsLeaflet): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/style.css') ?>">
    <style>
        :root {
            --kq-content-width: 1480px;
            --kq-auth-width: 460px;
            --kq-control-height: 44px;
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

        :root[data-bs-theme="dark"],
        [data-bs-theme="dark"] {
            --kq-ink: #f1f5f9;
            --kq-muted: #94a3b8;
            --kq-primary: #60a5fa;
            --kq-primary-dark: #3b82f6;
            --kq-accent: #f59e0b;
            --kq-line: #1e3a4e;
            --kq-surface: #0f1f2b;
            --kq-canvas: #09131a;
            --kq-shadow: 0 12px 32px rgba(0, 0, 0, .45);
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
            background: #102a43 !important;
            backdrop-filter: blur(12px);
        }
        #property-results { scroll-margin-top: 6rem; }
        .kq-live-clock {
            text-align: right;
            font-variant-numeric: tabular-nums;
            line-height: 1.2;
            padding-left: 0.95rem;
            border-left: 1px solid rgba(255, 255, 255, 0.2);
        }
        .kq-live-clock-date {
            display: block;
            color: rgba(255, 255, 255, .82);
            font-size: .82rem;
            white-space: nowrap;
        }
        .kq-live-clock-time {
            display: block;
            color: #ffffff;
            font-size: 1.12rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: .02em;
            white-space: nowrap;
        }
        .navbar .container,
        .navbar .container-fluid { min-height: 68px; }
        .navbar-brand {
            display: inline-flex;
            align-items: center;
            gap: 1.5rem;
            color: #fff !important;
        }
        .kq-navbar-wordmark {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            color: #fff;
            font-size: 1.15rem;
            font-weight: 650;
            letter-spacing: 0;
        }
        .navbar-nav { align-items: stretch; gap: .25rem; }
        .navbar .nav-link {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            padding: .52rem .85rem !important;
            border: 1px solid transparent;
            border-radius: .6rem;
            background: transparent;
            color: rgba(255,255,255,.72) !important;
            font-size: .95rem;
            font-weight: 500;
            white-space: nowrap;
            transition: color .18s ease, background-color .18s ease, border-color .18s ease;
        }
        .navbar .nav-link:hover, .navbar .nav-link:focus {
            color: #fff !important;
            border-color: rgba(255,255,255,.3);
            background: rgba(255,255,255,.08);
        }
        .navbar .nav-link.active {
            color: #fff !important;
            font-weight: 700;
            border-color: rgba(255,255,255,.45);
            background: rgba(255,255,255,.16);
            box-shadow: inset 0 -2px 0 var(--kq-gold, #e0a96d);
        }
        .navbar .nav-link.btn-primary {
            border-color: var(--kq-primary);
            background: var(--kq-primary);
        }
        .navbar .nav-link.btn-primary:hover,
        .navbar .nav-link.btn-primary:focus {
            border-color: var(--kq-primary-dark);
            background: var(--kq-primary-dark);
        }
        main { flex: 1; }
        main.container { max-width: var(--kq-content-width); }
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
            min-height: var(--kq-control-height);
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
        .property-card-description {
            display: -webkit-box;
            min-height: 4.5em;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 3;
            line-clamp: 3;
        }
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
        :root[data-bs-theme="dark"] .kq-tracking-event,
        [data-bs-theme="dark"] .kq-tracking-event {
            border-left-color: #1e3a4e !important;
        }
        :root[data-bs-theme="dark"] .kq-tracking-event::before,
        [data-bs-theme="dark"] .kq-tracking-event::before {
            background: #162838 !important;
            box-shadow: 0 0 0 1px #24415c !important;
        }
        :root[data-bs-theme="dark"] .kq-tracking-event.is-latest::before,
        [data-bs-theme="dark"] .kq-tracking-event.is-latest::before,
        :root[data-bs-theme="dark"] .kq-tracking-event.is-completed::before,
        [data-bs-theme="dark"] .kq-tracking-event.is-completed::before {
            background: #10b981 !important;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.25) !important;
        }
        :root[data-bs-theme="dark"] .kq-tracking-event-content,
        [data-bs-theme="dark"] .kq-tracking-event-content {
            background: #09141d !important;
            border: 1px solid #1e3a4e !important;
            color: #f1f5f9 !important;
        }
        :root[data-bs-theme="dark"] .kq-tracking-event.is-latest .kq-tracking-event-content,
        [data-bs-theme="dark"] .kq-tracking-event.is-latest .kq-tracking-event-content {
            background: #092019 !important;
            border: 1px solid #17543e !important;
            box-shadow: inset 4px 0 0 #10b981 !important;
        }
        [data-bs-theme="dark"] .kq-tracking-updates-box {
            background: #0d1b26 !important;
            border-color: #1e3a4e !important;
        }
        [data-bs-theme="dark"] .kq-tracking-updates-title { color: #f1f5f9 !important; }
        [data-bs-theme="dark"] .kq-tracking-event-title { color: #f8fafc !important; }
        [data-bs-theme="dark"] .kq-tracking-event-time { color: #94a3b8 !important; }
        [data-bs-theme="dark"] .kq-tracking-event-author { color: #cbd5e1 !important; }
        [data-bs-theme="dark"] .kq-tracking-event-note { color: #e2e8f0 !important; }
        [data-bs-theme="dark"] .kq-tracking-empty-note { color: #94a3b8 !important; }
        [data-bs-theme="dark"] .kq-tracking-event-latest { color: #34d399 !important; }
        .kq-tracking-card { scroll-margin-top: 6rem; }
        .app-footer {
            margin-top: auto;
            padding: 2.2rem 0;
            color: rgba(255, 255, 255, .78);
            background: #102a43 !important;
            border-top: 1px solid rgba(255, 255, 255, .08);
        }
        .app-footer a { color: #fff; }
        .app-footer a:hover,
        .app-footer a:focus { color: var(--kq-accent, #d8ad63); }
        :root[data-bs-theme="dark"] .app-footer,
        [data-bs-theme="dark"] .app-footer {
            background: #081522 !important;
            border-top: 1px solid rgba(255, 255, 255, .1) !important;
            color: rgba(241, 245, 249, .78) !important;
        }
        :root[data-bs-theme="dark"] .app-footer .text-white,
        [data-bs-theme="dark"] .app-footer .text-white {
            color: #f1f5f9 !important;
        }
        :root[data-bs-theme="dark"] .app-footer .text-white-50,
        [data-bs-theme="dark"] .app-footer .text-white-50 {
            color: rgba(241, 245, 249, .65) !important;
        }
        .kq-notif-bell-btn {
            border-radius: 50%;
            width: 38px;
            height: 38px;
            transition: background 0.15s ease;
            color: #ffffff;
        }
        .kq-notif-bell-btn:hover,
        .kq-notif-bell-btn:focus {
            background: rgba(255, 255, 255, 0.16);
            color: #ffffff;
        }
        .kq-notif-dropdown-menu {
            border-radius: 0.85rem !important;
            box-shadow: 0 16px 38px rgba(10, 25, 45, 0.22) !important;
            width: 350px;
            max-width: 92vw;
        }
        .kq-notif-item {
            transition: background 0.15s ease;
            text-decoration: none;
            color: #1a202c !important;
            display: block;
        }
        .kq-notif-item:hover {
            background-color: #f1f5f9 !important;
        }
        .kq-notif-item.is-unread {
            background-color: #f0f7ff !important;
            border-left: 3px solid #174a70;
        }
        .navbar {
            position: relative;
            z-index: 1050;
        }
        .navbar-collapse.show,
        .navbar-collapse.collapsing {
            position: relative;
            z-index: 1051;
        }
        .navbar-nav { align-items: stretch; padding: .5rem 0 1rem; gap: .35rem; }
        .navbar .nav-link { width: 100%; }
        .navbar-nav .ms-lg-1,
        .navbar-nav .ms-lg-2 { margin-left: 0 !important; }
        .navbar .navbar-collapse {
            max-height: calc(100vh - 5rem);
            overflow-y: auto;
            overflow-x: hidden;
        }
        .navbar .navbar-collapse .dropdown-menu { position: static; margin-top: 0; }
        .navbar .navbar-nav .nav-item > .btn,
        .navbar .navbar-nav .kq-contact-btn,
        .navbar .navbar-nav .kq-chat-nav-btn,
        .navbar .navbar-nav .kq-help-btn,
        .navbar .navbar-nav .kq-theme-toggle-btn {
            min-height: 44px;
            border-radius: .55rem;
            font-size: .875rem;
            width: 100%;
        }
        .navbar .navbar-nav .kq-contact-btn { justify-content: flex-start; }
        .navbar .navbar-nav .kq-chat-nav-btn { justify-content: flex-start; }
        .navbar .navbar-nav .kq-help-btn { justify-content: flex-start; width: 100%; }
        .navbar .navbar-nav .kq-theme-toggle-btn {
            justify-content: flex-start;
            width: 100%;
            padding: 0.52rem 0.85rem !important;
            gap: 0.5rem;
        }
        .navbar .navbar-nav .kq-theme-toggle-btn::after {
            content: "Toggle Dark / Light Theme";
            font-size: .875rem;
            font-weight: 500;
        }
        .navbar .navbar-nav .kq-notif-bell-btn {
            justify-content: flex-start !important;
            width: 100%;
            gap: 0.5rem;
            padding: 0.52rem 0.85rem !important;
        }
        .navbar .navbar-nav .kq-notif-bell-btn::after {
            content: "Notifications";
            font-size: .875rem;
            font-weight: 500;
        }
        .navbar .navbar-nav .kq-notif-bell-btn .badge {
            position: static !important;
            transform: none !important;
            margin-left: auto;
        }
        @media (max-width: 1399.98px) {
            .navbar .nav-link {
                border-radius: .55rem;
                padding: .55rem .85rem !important;
                border-color: transparent !important;
            }
            .navbar .nav-link:hover,
            .navbar .nav-link:focus {
                background: rgba(255, 255, 255, 0.08);
                color: #fff !important;
            }
            .navbar .nav-link.active {
                background: rgba(255, 255, 255, 0.12) !important;
                border-color: rgba(255, 255, 255, 0.2) !important;
                border-left: 3px solid var(--kq-gold, #e0a96d) !important;
                border-radius: 0 .55rem .55rem 0 !important;
                box-shadow: none !important;
            }
        }
        @media (min-width: 1400px) {
            .navbar .navbar-collapse {
                max-height: none;
                min-width: 0;
                overflow: visible;
            }
            .navbar .navbar-nav {
                width: 100%;
                max-width: 100%;
                flex-wrap: nowrap;
                align-items: center;
                justify-content: flex-start;
                gap: .45rem;
                row-gap: .2rem;
                padding: .35rem 0;
            }
            .navbar .navbar-nav .kq-nav-actions-start {
                margin-left: auto !important;
                padding-left: .5rem;
            }
            .navbar .nav-link {
                width: auto;
                padding: .48rem .65rem !important;
                font-size: .95rem;
            }
            .navbar .navbar-collapse .dropdown-menu {
                position: absolute;
                margin-top: .35rem;
            }
            .navbar .navbar-nav .nav-item > .btn,
            .navbar .navbar-nav .kq-contact-btn,
            .navbar .navbar-nav .kq-chat-nav-btn,
            .navbar .navbar-nav .kq-help-btn,
            .navbar .navbar-nav .kq-theme-toggle-btn {
                min-height: 44px;
                padding: .48rem .85rem !important;
                font-size: .92rem;
                white-space: nowrap;
                width: auto;
            }
            .navbar .navbar-nav .kq-help-btn { width: auto; justify-content: center; }
            .navbar .navbar-nav .kq-theme-toggle-btn {
                width: 44px !important;
                padding: .48rem 0 !important;
                justify-content: center !important;
            }
            .navbar .navbar-nav .kq-theme-toggle-btn::after {
                display: none;
            }
            .navbar .navbar-nav .kq-notif-bell-btn {
                justify-content: center !important;
                width: auto !important;
                padding: .48rem .65rem !important;
            }
            .navbar .navbar-nav .kq-notif-bell-btn::after {
                display: none;
            }
            .navbar .navbar-nav .kq-notif-bell-btn .badge {
                position: absolute !important;
                top: 0 !important;
                left: 100% !important;
                transform: translate(-50%, -50%) !important;
                margin-left: 0 !important;
            }
        }
        @media (max-width: 767.98px) {
            .property-card-image { height: 205px; }
            .kq-live-clock {
                border-left: none;
                padding-left: 0;
            }
            .kq-live-clock-date { display: none; }
            .kq-live-clock-time { font-size: .84rem; font-weight: 600; }
        }
        @media (max-width: 480px) {
            .kq-live-clock-date { display: none; }
            .kq-live-clock-time { font-size: .84rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { transition-duration: .01ms !important; animation-duration: .01ms !important; }
        }
    </style>
</head>
<body<?= $isAdminControlRoom || $isAuthPage ? ' class="' . ($isAdminControlRoom ? 'admin-control-room ' : '') . ($isAuthPage ? 'kq-auth-body' : '') . '"' : '' ?>>
    <nav class="navbar navbar-expand-xxl navbar-dark bg-dark shadow-sm">
        <div class="container-fluid px-3 px-sm-4 px-xl-5">
            <a class="navbar-brand d-inline-flex align-items-center" href="index.php" aria-label="KingQuiry by 4 Kings Realty Services">
                <img class="kq-official-logo kq-logo-navbar kq-logo-on-dark" src="assets/images/kings-land-properties-logo-light.png" alt="4 Kings Realty Services">
            </a>
            <div class="d-flex align-items-center gap-2 order-xxl-3 ms-xxl-3">
                <div class="kq-live-clock" id="kq-live-clock" data-server-epoch-ms="<?= (int) $clockEpochMs ?>" aria-label="Current time in the Philippines">
                    <time class="kq-live-clock-date" id="kq-live-clock-date" datetime="<?= htmlspecialchars($clockNow->format(DateTimeInterface::ATOM), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($clockNow->format('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></time>
                    <time class="kq-live-clock-time" id="kq-live-clock-time" datetime="<?= htmlspecialchars($clockNow->format(DateTimeInterface::ATOM), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($clockNow->format('h:i:s A'), ENT_QUOTES, 'UTF-8') ?></time>
                </div>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>
            </div>
            <div class="collapse navbar-collapse justify-content-end order-xxl-2" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link<?= $currentPageName === 'index.php' ? ' active' : '' ?>" href="index.php"<?= $currentPageName === 'index.php' ? ' aria-current="page"' : '' ?>>Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link<?= $currentPageName === 'resources.php' ? ' active' : '' ?>" href="resources.php"<?= $currentPageName === 'resources.php' ? ' aria-current="page"' : '' ?>>Resources &amp; Guides</a>
                    </li>
                    <?php
                    $dashboardRoute = $currentRole === 'Client'
                        ? 'client_dashboard.php'
                        : (($currentRole === 'Sales Agent' || $currentRole === 'Agent') ? 'agent_dashboard.php' : ($currentRole === 'Admin' ? 'admin_dashboard.php' : null));
                    ?>
                    <?php if ($dashboardRoute !== null): ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $currentPageName === $dashboardRoute ? ' active' : '' ?>" href="<?= htmlspecialchars($dashboardRoute, ENT_QUOTES, 'UTF-8') ?>"<?= $currentPageName === $dashboardRoute ? ' aria-current="page"' : '' ?>>Dashboard</a>
                        </li>
                    <?php endif; ?>
                    <?php if($currentRole !== null): ?>
                        <?php if($currentRole === 'Client'): ?>
                            <li class="nav-item ms-lg-2 kq-nav-actions-start">
                                <button type="button" class="btn fw-bold px-3 d-inline-flex align-items-center gap-2 kq-contact-btn" data-bs-toggle="modal" data-bs-target="#contactUsModal">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                    <span>Contact Us</span>
                                </button>
                            </li>
                            <?php if (!$isAuthPage): ?>
                                <li class="nav-item ms-lg-2">
                                    <?php if (!empty($navClientTransactions) && count($navClientTransactions) === 1): ?>
                                        <button type="button" class="btn fw-bold px-3 d-inline-flex align-items-center gap-2 kq-chat-nav-btn" onclick="openDirectChat(<?= (int)$navClientTransactions[0]['transactionID'] ?>, '<?= addslashes(htmlspecialchars($navClientTransactions[0]['title'], ENT_QUOTES, 'UTF-8')) ?>', '<?= addslashes(htmlspecialchars($navClientTransactions[0]['agentName'], ENT_QUOTES, 'UTF-8')) ?>')" title="Direct chat with your Sales Agent">
                                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                            <span>Chat an Agent</span>
                                        </button>
                                    <?php elseif (!empty($navClientTransactions) && count($navClientTransactions) > 1): ?>
                                        <button type="button" class="btn fw-bold px-3 d-inline-flex align-items-center gap-2 kq-chat-nav-btn" data-bs-toggle="modal" data-bs-target="#clientChatPickerModal" title="Chat with an assigned Sales Agent">
                                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                            <span>Chat an Agent</span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn fw-bold px-3 d-inline-flex align-items-center gap-2 kq-chat-nav-btn" data-bs-toggle="modal" data-bs-target="#noAgentChatModal" title="Chat with a Sales Agent">
                                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                            <span>Chat an Agent</span>
                                        </button>
                                    <?php endif; ?>
                                </li>
                            <?php endif; ?>
                        <?php endif; ?>
                        <!-- Notifications Bell Dropdown -->
                        <li class="nav-item dropdown ms-lg-2 <?= $currentRole === 'Client' ? '' : 'kq-nav-actions-start' ?>">
                            <a class="nav-link position-relative d-inline-flex align-items-center justify-content-center kq-notif-bell-btn text-white" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">
                                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                    <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                                </svg>
                                <?php if ($unreadNotificationCount > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem; padding: 0.25em 0.5em;">
                                        <?= $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount ?>
                                        <span class="visually-hidden">unread notifications</span>
                                    </span>
                                <?php endif; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 mt-2 py-0 kq-notif-dropdown-menu" aria-labelledby="notifDropdown">
                                <li class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light rounded-top">
                                    <div class="fw-bold text-dark small d-flex align-items-center gap-2">
                                        <span>Notifications</span>
                                        <?php if ($unreadNotificationCount > 0): ?>
                                            <span class="badge bg-primary rounded-pill" style="font-size: 0.7rem;"><?= $unreadNotificationCount ?> new</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($unreadNotificationCount > 0): ?>
                                        <form method="POST" action="notifications_action.php" class="m-0" data-confirm="Mark all notifications as read?">
                                            <input type="hidden" name="action" value="mark_all_read">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="redirect" value="<?= htmlspecialchars(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php'), ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="btn btn-link p-0 text-decoration-none text-primary fw-semibold" style="font-size: 0.75rem;">Mark all as read</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                                <li class="kq-notif-list" style="max-height: 350px; overflow-y: auto;">
                                    <?php if (!empty($userNotifications)): ?>
                                        <?php foreach ($userNotifications as $notif):
                                            $isUnread = !(bool) $notif['isRead'];
                                            $targetLink = !empty($notif['link']) ? $notif['link'] : 'index.php';
                                        ?>
                                            <form method="POST" action="notifications_action.php" class="m-0">
                                                <input type="hidden" name="action" value="read_and_go">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="id" value="<?= (int) $notif['notificationID'] ?>">
                                                <input type="hidden" name="to" value="<?= htmlspecialchars($targetLink, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="dropdown-item px-3 py-2 border-bottom text-wrap kq-notif-item <?= $isUnread ? 'is-unread' : '' ?>" style="font-size: 0.82rem;">
                                                <span class="d-flex justify-content-between align-items-start gap-1 mb-1">
                                                    <span class="fw-bold <?= $isUnread ? 'text-primary' : 'text-dark' ?>" style="font-size: 0.84rem;"><?= htmlspecialchars($notif['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php if ($isUnread): ?>
                                                        <span class="badge rounded-pill bg-primary" style="width: 7px; height: 7px; padding: 0; display: inline-block;"></span>
                                                    <?php endif; ?>
                                                </span>
                                                <span class="d-block mb-1 text-dark text-break fw-normal" style="font-size: 0.77rem; line-height: 1.35;"><?= htmlspecialchars($notif['message'], ENT_QUOTES, 'UTF-8') ?></span>
                                                <time class="text-secondary small" style="font-size: 0.7rem;"><?= htmlspecialchars(date('M j, g:i A', strtotime($notif['createdAt'])), ENT_QUOTES, 'UTF-8') ?></time>
                                                </button>
                                            </form>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="px-3 py-4 text-center text-muted small">
                                            <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" class="mb-2 text-secondary"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                                            <div>No notifications yet.</div>
                                        </div>
                                    <?php endif; ?>
                                </li>
                                <li class="p-2 border-top bg-light text-center rounded-bottom">
                                    <button type="button" class="btn btn-link p-0 text-decoration-none small fw-bold text-primary" data-bs-toggle="modal" data-bs-target="#allNotificationsModal">View all &rarr;</button>
                                </li>
                            </ul>
                        </li>

                        <?php if ($currentRole === 'Sales Agent' || $currentRole === 'Agent'):
                            $hdrPres = function_exists('get_agent_presence') && isset($conn) && ($conn instanceof mysqli)
                                ? get_agent_presence($conn, (int) ($currentUser['userID'] ?? 0))
                                : ['is_online' => true];
                            $isHdrOnline = !empty($hdrPres['is_online']);
                        ?>
                            <li class="nav-item ms-lg-2 d-flex align-items-center">
                                <button type="button" class="btn btn-sm rounded-pill fw-semibold shadow-sm d-inline-flex align-items-center gap-1 <?= $isHdrOnline ? 'btn-outline-success text-success bg-success-subtle' : 'btn-outline-secondary text-secondary bg-body' ?>" id="kqHeaderAgentStatusBtn" onclick="kqToggleHeaderAgentStatus()" title="Toggle live chat availability (Online / Offline)">
                                    <span id="kqHeaderStatusDot"><?= $isHdrOnline ? '🟢' : '⚪' ?></span>
                                    <span id="kqHeaderStatusText"><?= $isHdrOnline ? 'Online' : 'Offline' ?></span>
                                </button>
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
                                        <span>Sign out</span>
                                    </button>
                                </li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item"><a class="nav-link" href="client_login.php">Sign in</a></li>
                        <li class="nav-item"><a class="nav-link btn btn-primary text-white ms-2 px-3" href="register.php">Create account</a></li>
                        <li class="nav-item ms-lg-2 kq-nav-actions-start">
                            <button type="button" class="btn fw-bold px-3 d-inline-flex align-items-center gap-2 kq-contact-btn" data-bs-toggle="modal" data-bs-target="#contactUsModal">
                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                <span>Contact Us</span>
                            </button>
                        </li>
                        <?php if (!$isAuthPage): ?>
                            <li class="nav-item ms-lg-2">
                                <button type="button" class="btn fw-bold px-3 d-inline-flex align-items-center gap-2 kq-chat-nav-btn" data-bs-toggle="modal" data-bs-target="#guestChatModal" title="Chat with a Sales Agent">
                                    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    <span>Chat an Agent</span>
                                </button>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>
                    <!-- Help & Guidelines / User Manual -->
                    <li class="nav-item ms-lg-2">
                        <button type="button" class="btn fw-bold px-3 kq-help-btn d-inline-flex align-items-center gap-1.5" data-bs-toggle="modal" data-bs-target="#userManualModal" title="KingQuiry User Manual & Guidelines">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span>Help</span>
                        </button>
                    </li>
                    <!-- Theme Toggle Switcher -->
                    <li class="nav-item ms-lg-1">
                        <button type="button" class="btn kq-theme-toggle-btn d-inline-flex align-items-center justify-content-center" id="kqThemeToggle" aria-label="Toggle theme" title="Toggle dark/light theme">
                            <span id="themeToggleIcon" class="d-inline-flex align-items-center justify-content-center">
                                <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                            </span>
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php
    $currentPageName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $mainClass = $currentPageName === 'index.php' ? 'main-catalog' : 'container py-4 py-lg-5';
    if ($isAdminControlRoom) {
        $mainClass .= ' kq-admin-shell';
    }
    ?>
    <main class="<?= $mainClass ?>">
        <?php if ($isAdminControlRoom): ?>
            <div class="kq-admin-workspace">
                <aside class="kq-admin-sidebar" aria-label="Administration navigation">
                    <?php include __DIR__ . '/admin_nav.php'; ?>
                </aside>
                <div class="kq-admin-content">
                    <?php include __DIR__ . '/admin_page_header.php'; ?>
        <?php endif; ?>
