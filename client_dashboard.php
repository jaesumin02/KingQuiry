<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/property_images.php';
require_once 'includes/location_directory.php';
require_once 'includes/transaction_tracking.php';
require_once 'includes/appointment_management.php';
require_once 'includes/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (normalized_user_role($_SESSION['user'] ?? null) !== 'Client') {
    header('Location: client_login.php');
    exit();
}

$clientID = (int) $_SESSION['user']['userID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['client_reschedule_appointment'])) {
        if (!has_valid_csrf_token()) {
            $_SESSION['flash_error'] = 'Your session has expired. Please try again.';
            header('Location: client_dashboard.php#viewing-requests');
            exit();
        }
        $apptId = (int) ($_POST['appointmentID'] ?? 0);
        $newDate = trim((string) ($_POST['appointmentDate'] ?? ''));
        $newTime = trim((string) ($_POST['appointmentTime'] ?? ''));
        $reason = trim((string) ($_POST['rescheduleReason'] ?? ''));

        $checkStmt = $conn->prepare("SELECT a.*, p.title, p.propertyID FROM appointments a JOIN properties p ON p.propertyID = a.propertyID WHERE a.appointmentID = ? AND a.clientID = ? LIMIT 1");
        $checkStmt->bind_param('ii', $apptId, $clientID);
        $checkStmt->execute();
        $appt = $checkStmt->get_result()->fetch_assoc();

        if (!$appt) {
            $_SESSION['flash_error'] = 'Viewing request not found.';
            header('Location: client_dashboard.php#viewing-requests');
            exit();
        }
        if ($appt['status'] === 'Cancelled') {
            $_SESSION['flash_error'] = 'Cancelled viewing requests cannot be rescheduled.';
            header('Location: client_dashboard.php#viewing-requests');
            exit();
        }

        if (!kq_is_future_appointment_datetime($newDate, $newTime)) {
            $_SESSION['flash_error'] = 'Please select a valid future date and time.';
            header('Location: client_dashboard.php#viewing-requests');
            exit();
        }

        $updateStmt = $conn->prepare("UPDATE appointments SET appointmentDate = ?, appointmentTime = ?, status = 'Pending' WHERE appointmentID = ? AND clientID = ?");
        $updateStmt->bind_param('ssii', $newDate, $newTime, $apptId, $clientID);
        $updateStmt->execute();

        $transStmt = $conn->prepare("SELECT transactionID, currentStage FROM property_transactions WHERE appointmentID = ? LIMIT 1");
        $transStmt->bind_param('i', $apptId);
        $transStmt->execute();
        $transRow = $transStmt->get_result()->fetch_assoc();
        if ($transRow) {
            $transId = (int) $transRow['transactionID'];
            if (in_array((string) $transRow['currentStage'], ['reviewing_schedule', 'viewing_confirmed'], true)) {
                $resetStageStmt = $conn->prepare("UPDATE property_transactions SET currentStage = 'viewing_requested', updatedAt = CURRENT_TIMESTAMP WHERE transactionID = ?");
                $resetStageStmt->bind_param('i', $transId);
                $resetStageStmt->execute();
            }
            $eventMsg = "Client requested to reschedule viewing to {$newDate} at {$newTime}." . ($reason !== '' ? " Note: {$reason}" : "");
            add_property_transaction_event($conn, $transId, 'viewing_requested', $eventMsg, $clientID);
        }

        $notifyMsg = "Client requested to reschedule viewing for '{$appt['title']}' to {$newDate} at {$newTime}." . ($reason !== '' ? " Note: {$reason}" : "");
        if (!empty($appt['agentID'])) {
            send_property_notification($conn, (int) $appt['agentID'], 'Viewing Reschedule Request', $notifyMsg, 'transaction_tracking.php', 'viewing_reschedule');
        } else {
            notify_agents_new_viewing($conn, $apptId, (int) $appt['propertyID'], $clientID, $newDate, $newTime);
        }

        $_SESSION['flash_success'] = "Viewing request rescheduled to {$newDate} at {$newTime}. The sales agent team has been notified.";
        header('Location: client_dashboard.php#viewing-requests');
        exit();
    }

    if (isset($_POST['client_cancel_appointment'])) {
        if (!has_valid_csrf_token()) {
            $_SESSION['flash_error'] = 'Your session has expired. Please try again.';
            header('Location: client_dashboard.php#viewing-requests');
            exit();
        }
        $apptId = (int) ($_POST['appointmentID'] ?? 0);

        $checkStmt = $conn->prepare("SELECT a.*, p.title FROM appointments a JOIN properties p ON p.propertyID = a.propertyID WHERE a.appointmentID = ? AND a.clientID = ? LIMIT 1");
        $checkStmt->bind_param('ii', $apptId, $clientID);
        $checkStmt->execute();
        $appt = $checkStmt->get_result()->fetch_assoc();

        if (!$appt) {
            $_SESSION['flash_error'] = 'Viewing request not found.';
            header('Location: client_dashboard.php#viewing-requests');
            exit();
        }

        $cancelStmt = $conn->prepare("UPDATE appointments SET status = 'Cancelled' WHERE appointmentID = ? AND clientID = ?");
        $cancelStmt->bind_param('ii', $apptId, $clientID);
        $cancelStmt->execute();

        $transStmt = $conn->prepare("SELECT transactionID FROM property_transactions WHERE appointmentID = ? LIMIT 1");
        $transStmt->bind_param('i', $apptId);
        $transStmt->execute();
        $transRow = $transStmt->get_result()->fetch_assoc();
        if ($transRow) {
            $transId = (int) $transRow['transactionID'];
            $cancelTxStmt = $conn->prepare("UPDATE property_transactions SET currentStage = 'cancelled', updatedAt = CURRENT_TIMESTAMP WHERE transactionID = ? AND currentStage NOT IN ('completed', 'cancelled')");
            $cancelTxStmt->bind_param('i', $transId);
            $cancelTxStmt->execute();
            add_property_transaction_event($conn, $transId, 'cancelled', 'Client cancelled the viewing request for this property.', $clientID);
        }

        if (!empty($appt['agentID'])) {
            send_property_notification($conn, (int) $appt['agentID'], 'Viewing Request Cancelled', "Client cancelled the viewing appointment for '{$appt['title']}'.", 'transaction_tracking.php', 'viewing_cancelled');
        }

        $_SESSION['flash_success'] = "Viewing request #KQ-" . (1000 + $apptId) . " has been cancelled.";
        header('Location: client_dashboard.php#viewing-requests');
        exit();
    }

    if (isset($_POST['client_confirm_appointment'])) {
        if (!has_valid_csrf_token()) {
            $_SESSION['flash_error'] = 'Your session has expired. Please try again.';
            header('Location: client_dashboard.php#viewing-requests');
            exit();
        }
        $apptId = (int) ($_POST['appointmentID'] ?? 0);
        $checkStmt = $conn->prepare("SELECT a.*, p.title FROM appointments a JOIN properties p ON p.propertyID = a.propertyID WHERE a.appointmentID = ? AND a.clientID = ? LIMIT 1");
        $checkStmt->bind_param('ii', $apptId, $clientID);
        $checkStmt->execute();
        $appt = $checkStmt->get_result()->fetch_assoc();
        if (!$appt) {
            $_SESSION['flash_error'] = 'Viewing request not found.';
            header('Location: client_dashboard.php#viewing-requests');
            exit();
        }
        $transStmt = $conn->prepare("SELECT transactionID, currentStage FROM property_transactions WHERE appointmentID = ? LIMIT 1");
        $transStmt->bind_param('i', $apptId);
        $transStmt->execute();
        $transRow = $transStmt->get_result()->fetch_assoc();
        if ($transRow) {
            add_property_transaction_event(
                $conn,
                (int) $transRow['transactionID'],
                (string) $transRow['currentStage'],
                "Client confirmed attendance for the viewing schedule on {$appt['appointmentDate']} at " . substr($appt['appointmentTime'], 0, 5) . ".",
                $clientID
            );
        }
        if (!empty($appt['agentID'])) {
            send_property_notification($conn, (int) $appt['agentID'], 'Viewing Schedule Confirmed by Client', "Client confirmed they will attend the scheduled viewing for '{$appt['title']}' on {$appt['appointmentDate']}.", 'transaction_tracking.php', 'viewing_confirmed');
        }
        $_SESSION['flash_success'] = "Thank you! You have confirmed your attendance for the viewing on {$appt['appointmentDate']}.";
        header('Location: client_dashboard.php#viewing-requests');
        exit();
    }
}

include 'includes/header.php';
?>
<style>
    .kq-client-shell {
        --kq-client-card-muted: #536579;
    }

    html[data-bs-theme="dark"] .kq-client-shell {
        --kq-client-card-muted: #94a3b8;
    }

    .kq-client-shell {
        max-width: 1360px;
        width: 100%;
        margin: 0 auto;
        padding: 1.2rem 0 2rem;
    }

    .kq-dashboard-hero {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.9rem;
        padding: 1rem 1.2rem;
        margin-bottom: 1.5rem;
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 1.1rem;
        background: rgba(27, 46, 53, 0.9);
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.14);
    }

    .page-eyebrow {
        letter-spacing: .15em;
        color: #d9a74a;
    }

    .page-title {
        color: #ffffff;
        letter-spacing: -.05em;
    }

    .kq-client-shell .kq-dashboard-hero .text-muted {
        color: #b8c7ce !important;
    }

    .card {
        border-radius: 1rem !important;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.14);
    }

    html[data-bs-theme="light"] .card {
        border: 1px solid #dfe7ed !important;
        background: #ffffff;
        color: #0f2942;
    }

    html[data-bs-theme="light"] .card .text-muted,
    html[data-bs-theme="light"] .kq-tracking-card .text-muted,
    html[data-bs-theme="light"] .kq-tracking-event-content .text-muted,
    html[data-bs-theme="light"] .kq-tracking-timeline .text-muted,
    html[data-bs-theme="light"] .kq-tracking-timeline time.text-muted,
    html[data-bs-theme="light"] .kq-tracking-event time.text-muted {
        color: #536579 !important;
    }

    html[data-bs-theme="light"] .card .page-eyebrow {
        color: #805a20;
    }

    html[data-bs-theme="light"] .kq-tracking-card {
        color: #132d42;
    }

    .kq-panel-header {
        border-bottom: 1px solid rgba(19, 45, 66, 0.08);
        padding-bottom: .75rem;
    }

    html[data-bs-theme="light"] .kq-badge-soft {
        background: rgba(17, 53, 71, 0.08);
        color: #123b5a;
    }

    html[data-bs-theme="light"] .kq-tracking-card,
    html[data-bs-theme="light"] article.border.rounded-3,
    html[data-bs-theme="light"] .table-responsive {
        border-color: rgba(19, 45, 66, 0.08) !important;
        border-radius: 1rem !important;
    }

    html[data-bs-theme="light"] .btn-primary {
        background: linear-gradient(180deg, #173d56, #102334);
        border-color: #173d56;
    }

    html[data-bs-theme="light"] .btn-primary:hover,
    html[data-bs-theme="light"] .btn-primary:focus {
        background: linear-gradient(180deg, #1d486b, #132a3f);
        border-color: #1d486b;
    }

    html[data-bs-theme="light"] .btn-outline-primary {
        border-color: rgba(17, 53, 71, 0.3);
        color: #123b5a;
    }

    html[data-bs-theme="light"] .btn-outline-primary:hover,
    html[data-bs-theme="light"] .btn-outline-primary:focus {
        background: rgba(17, 53, 71, 0.05);
        color: #123b5a;
    }

    html[data-bs-theme="light"] .alert-info {
        background: rgba(17, 53, 71, 0.06);
        border-color: rgba(17, 53, 71, 0.12);
        color: #1b3f58;
    }

    html[data-bs-theme="light"] .alert-success {
        background: rgba(25, 98, 73, 0.08);
        border-color: rgba(25, 98, 73, 0.18);
        color: #164b3b;
    }

    html[data-bs-theme="light"] .alert-danger {
        background: rgba(166, 52, 52, 0.08);
        border-color: rgba(166, 52, 52, 0.18);
        color: #8f2f2f;
    }

    html[data-bs-theme="light"] body {
        background: #f4f7fb;
    }

    /* Force high-contrast crisp white typography on Client Menu portal sidebar */
    .kq-portal-sidebar .kq-portal-nav a,
    .kq-portal-sidebar .kq-portal-nav a:link,
    .kq-portal-sidebar .kq-portal-nav a:visited {
        display: flex;
        min-height: 42px;
        align-items: center;
        padding: 0.55rem 0.85rem;
        border: 1px solid rgba(255, 255, 255, 0.12) !important;
        border-radius: 0.65rem;
        background: #1e323a !important;
        color: #f1f5f9 !important;
        font-size: 0.9rem;
        font-weight: 600;
        text-decoration: none !important;
    }
    .kq-portal-sidebar .kq-portal-nav a:hover,
    .kq-portal-sidebar .kq-portal-nav a:focus,
    .kq-portal-sidebar .kq-portal-nav a:focus-visible {
        border-color: rgba(255, 255, 255, 0.35) !important;
        background: #2b4652 !important;
        color: #ffffff !important;
    }
    .kq-portal-sidebar .kq-portal-nav a[aria-current="location"],
    .kq-portal-sidebar .kq-portal-nav a.active {
        border-color: #d9a74a !important;
        background: #1b4d6e !important;
        color: #ffffff !important;
        box-shadow: inset 0 -2px 0 #d9a74a, 0 0 0 1px rgba(217, 167, 74, 0.35) !important;
    }

    /* ══════════ TRANSACTION UPDATES & TIMELINE (LIGHT & DARK THEMES) ══════════ */
    .kq-tracking-updates-box {
        background: #ffffff;
        border-color: #e2e8f0 !important;
    }
    .kq-tracking-updates-title { color: #0f2942; }
    .kq-tracking-event-title { color: #0f2942; }
    .kq-tracking-event-time { color: #64748b; }
    .kq-tracking-event-author { color: #334155; }
    .kq-tracking-event-note { color: #1e293b; }
    .kq-tracking-empty-note { color: #64748b; }
    .kq-tracking-event-latest { color: #059669; }

    html[data-bs-theme="light"] .kq-tracking-event-content {
        background: #f8fafc !important;
        border: 1px solid #e2e8f0 !important;
    }
    html[data-bs-theme="light"] .kq-tracking-event.is-latest .kq-tracking-event-content {
        background: #f0fdf4 !important;
        border: 1px solid #bbf7d0 !important;
        box-shadow: inset 4px 0 0 #10b981 !important;
    }

    /* Dark Mode fix for Transaction Updates (Screenshot 1 issue) */
    html[data-bs-theme="dark"] .kq-tracking-updates-box {
        background: #0d1b26 !important;
        border-color: #1e3a4e !important;
    }
    html[data-bs-theme="dark"] .kq-tracking-updates-title { color: #f1f5f9 !important; }
    html[data-bs-theme="dark"] .kq-tracking-event-content {
        background: #09141d !important;
        border: 1px solid #1e3a4e !important;
    }
    html[data-bs-theme="dark"] .kq-tracking-event.is-latest .kq-tracking-event-content {
        background: #092019 !important;
        border: 1px solid #17543e !important;
        box-shadow: inset 4px 0 0 #10b981 !important;
    }
    html[data-bs-theme="dark"] .kq-tracking-event-title { color: #f8fafc !important; }
    html[data-bs-theme="dark"] .kq-tracking-event-time { color: #94a3b8 !important; }
    html[data-bs-theme="dark"] .kq-tracking-event-author { color: #cbd5e1 !important; }
    html[data-bs-theme="dark"] .kq-tracking-event-note { color: #e2e8f0 !important; }
    html[data-bs-theme="dark"] .kq-tracking-empty-note { color: #94a3b8 !important; }
    html[data-bs-theme="dark"] .kq-tracking-event-latest { color: #34d399 !important; }

    /* Dark mode support for modals */
    html[data-bs-theme="dark"] .modal .bg-light,
    html[data-bs-theme="dark"] .modal .card.bg-light,
    html[data-bs-theme="dark"] .modal .modal-footer.bg-light {
        background: #0f1f2b !important;
        border-color: #1e3a4e !important;
        color: #f1f5f9 !important;
    }
    html[data-bs-theme="dark"] .modal-content {
        background-color: #0d1a24;
        border-color: #1e3a4e;
        color: #f1f5f9;
    }

    @media (max-width: 767.98px) {
        .kq-client-shell {
            padding-top: .75rem;
        }
    }
</style>
<?php
$appointmentStmt = $conn->prepare(
    "SELECT a.*, p.title, p.location, p.barangay, l.municipalityName, l.provinceName
     FROM appointments a
     JOIN properties p ON a.propertyID = p.propertyID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     WHERE a.clientID = ?
     ORDER BY a.appointmentDate DESC, a.appointmentTime DESC, a.appointmentID DESC"
);
$appointmentStmt->bind_param('i', $clientID);
$appointmentStmt->execute();
$appointmentRows = $appointmentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$trackingStmt = $conn->prepare(
    "SELECT t.transactionID, t.propertyID, t.currentStage, t.salePrice, t.paymentMethod, t.createdAt, t.updatedAt,
            p.title, p.image, p.propertyType, p.price AS listingPrice, a.appointmentDate, a.appointmentTime,
            l.municipalityName, l.provinceName, p.location,
            COALESCE(
                txAgent.fullname,
                apptAgent.fullname,
                inqAgent.fullname,
                (
                    SELECT u2.fullname FROM property_transaction_events e
                    JOIN users u2 ON u2.userID = e.changedBy
                    WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                    ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
                ),
                'Kings Land Sales Agent'
            ) AS agentName,
            COALESCE(
                txAgent.phone,
                apptAgent.phone,
                inqAgent.phone,
                '0965 803 0933'
            ) AS agentPhone,
            COALESCE(
                txAgent.email,
                apptAgent.email,
                inqAgent.email,
                'contact@kingsland.ph'
            ) AS agentEmail,
            COALESCE(
                t.assignedAgentID,
                a.agentID,
                ia.agentID,
                (
                    SELECT e.changedBy FROM property_transaction_events e
                    WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                    ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
                )
            ) AS agentID
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     LEFT JOIN users txAgent ON txAgent.userID = t.assignedAgentID
     LEFT JOIN users apptAgent ON apptAgent.userID = a.agentID
     LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
     LEFT JOIN users inqAgent ON inqAgent.userID = ia.agentID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     WHERE t.clientID = ? AND t.currentStage NOT IN ('inquiry_received', 'contacting_client')
     ORDER BY t.updatedAt DESC, t.transactionID DESC
     LIMIT 100"
);
$trackingStmt->bind_param('i', $clientID);
$trackingStmt->execute();
$trackingRows = $trackingStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$clientDashboardCountsStmt = $conn->prepare(
    "SELECT
        (SELECT COUNT(*) FROM property_transactions WHERE clientID = ? AND currentStage NOT IN ('inquiry_received', 'contacting_client')) AS trackedRequests,
        (SELECT COUNT(*) FROM property_transactions WHERE clientID = ? AND currentStage NOT IN ('completed', 'cancelled', 'inquiry_received', 'contacting_client')) AS activePipeline,
        (SELECT COUNT(*) FROM property_transactions WHERE clientID = ? AND currentStage = 'completed') AS completedSales,
        (SELECT COUNT(*) FROM appointments WHERE clientID = ?) AS viewingRequests,
        (SELECT COUNT(*) FROM appointments WHERE clientID = ? AND status = 'Pending') AS pendingViewingRequests,
        (SELECT COUNT(*) FROM feedbackinquiries WHERE clientID = ?) AS inquiries"
);
$clientDashboardCountsStmt->bind_param(
    'iiiiii',
    $clientID,
    $clientID,
    $clientID,
    $clientID,
    $clientID,
    $clientID
);
$clientDashboardCountsStmt->execute();
$clientDashboardCounts = $clientDashboardCountsStmt->get_result()->fetch_assoc();
$totalTrackedRequests = (int) $clientDashboardCounts['trackedRequests'];
$clientActivePipeline = (int) $clientDashboardCounts['activePipeline'];
$clientCompletedSales = (int) $clientDashboardCounts['completedSales'];
$totalClientAppointments = (int) $clientDashboardCounts['viewingRequests'];
$pendingClientAppointments = (int) $clientDashboardCounts['pendingViewingRequests'];
$totalClientInquiries = (int) $clientDashboardCounts['inquiries'];
$trackingEvents = [];
$paymentSummaries = [];
$paymentRecordsByTx = [];
if ($trackingRows !== []) {
    $transactionIds = array_map(static fn(array $row): int => (int) $row['transactionID'], $trackingRows);
    $transactionIdList = implode(',', $transactionIds);
    $paymentSummaries = get_transaction_payment_summaries($conn, $transactionIds);
    $paymentRecordsByTx = get_transaction_payment_records($conn, $transactionIds);
    $events = $conn->query(
        "SELECT e.eventID, e.transactionID, e.stage, e.note, e.createdAt,
                u.fullname AS changedByName
         FROM property_transaction_events e
         LEFT JOIN users u ON u.userID = e.changedBy
         WHERE e.transactionID IN ($transactionIdList)
         ORDER BY e.createdAt DESC, e.eventID DESC"
    );
    while ($event = $events->fetch_assoc()) {
        $trackingEvents[(int) $event['transactionID']][] = $event;
    }
}

$stageLabels = property_transaction_stages();
$progressStages = [
    'reservation_requested',
    'reservation_confirmed',
    'viewing_requested',
    'reviewing_schedule',
    'viewing_confirmed',
    'viewing_completed',
    'payment_in_process',
    'document_verification',
    'payment_confirmation',
    'completed',
];
?>

<div class="kq-client-shell">
    <div class="kq-portal-workspace">
        <aside class="kq-portal-sidebar" aria-label="Client dashboard navigation">
            <button class="kq-menu-toggle" type="button" data-portal-menu-toggle data-menu-label="Client menu" aria-label="Open Client menu" aria-controls="client-navigation" aria-expanded="false">
                <span class="kq-menu-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>Client menu</span>
            </button>
            <div class="kq-portal-sidebar-heading">Client menu</div>
            <nav class="kq-portal-nav" id="client-navigation" aria-label="Client dashboard sections">
                <a href="#client-overview">Overview</a>
                <a href="#transaction-tracking">Transaction tracking</a>
                <a href="#viewing-requests">Viewing requests</a>
            </nav>
        </aside>
        <div class="kq-portal-main">
    <header class="kq-dashboard-hero" id="client-overview">
        <img class="kq-official-logo kq-logo-dashboard kq-logo-on-dark" src="assets/images/kings-land-properties-logo-light.png" alt="4 Kings Realty Services">
        <div class="kq-dashboard-hero-copy">
            <div class="page-eyebrow">CLIENT WORKSPACE</div>
            <h1 class="page-title h2 mb-1">Client dashboard</h1>
            <p class="text-muted mb-0">Welcome, <?= htmlspecialchars($_SESSION['user']['fullname'], ENT_QUOTES, 'UTF-8') ?>. Follow your property requests in one place.</p>
        </div>
</header>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow" style="font-size: 0.68rem;">Tracked requests</div>
                <h3 class="h3 mb-0"><?= number_format($totalTrackedRequests) ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Your transaction records</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-primary" style="font-size: 0.68rem;">Active Pipeline</div>
                <h3 class="h3 mb-0 text-primary"><?= (int) $clientActivePipeline ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Your in-progress transactions</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-info" style="font-size: 0.68rem;">Viewing requests</div>
                <h3 class="h3 mb-0 text-info"><?= (int) $totalClientAppointments ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Your viewing requests</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-warning" style="font-size: 0.68rem;">Pending viewing requests</div>
                <h3 class="h3 mb-0 text-warning"><?= (int) $pendingClientAppointments ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Awaiting confirmation</small>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-4 col-xl">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-success" style="font-size: 0.68rem;">Completed sales</div>
                <h3 class="h3 mb-0 text-success"><?= (int) $clientCompletedSales ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Your completed transactions</small>
            </div>
        </div>
    </div>
</div>

<section class="card p-3 p-lg-4 mb-4" id="transaction-tracking">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Sales progress</div>
            <h2 class="h4 fw-bold mb-0">Transaction tracking</h2>
        </div>
        <span class="kq-badge-soft">Interactive Property Tracker</span>
    </div>
    <div class="alert alert-info small mb-4" role="note">Click any property below to view its current status, overall progress, and milestone updates.</div>
    <?php if ($trackingRows !== []): ?>
        <div class="row g-3">
            <?php foreach ($trackingRows as $tracking):
                $transactionId = (int) $tracking['transactionID'];
                $stage = (string) $tracking['currentStage'];
                $isSpotCash = strcasecmp((string)($tracking['paymentMethod'] ?? ''), 'Spot Cash') === 0;
                $txProgressStages = $progressStages;
                if ($isSpotCash) {
                    $txProgressStages = array_values(array_filter($progressStages, static fn($s) => $s !== 'document_verification'));
                }
                $stageIndex = array_search($stage, $txProgressStages, true);
                $progress = $stage === 'cancelled'
                    ? 0
                    : ($stageIndex === false ? 0 : (int) round(($stageIndex / (count($txProgressStages) - 1)) * 100));
                $location = !empty($tracking['municipalityName'])
                    ? $tracking['municipalityName'] . ', ' . $tracking['provinceName']
                    : $tracking['location'];
                $stageBadgeClass = property_transaction_stage_badge_class($stage);
                $txPaySummary = $paymentSummaries[$transactionId] ?? ['verifiedTotal' => 0.0, 'verifiedCount' => 0, 'pendingTotal' => 0.0, 'pendingCount' => 0];
                $txPayRecords = $paymentRecordsByTx[$transactionId] ?? [];
                $txVerifiedPaid = (float) $txPaySummary['verifiedTotal'];
                $txPendingPaid = (float) $txPaySummary['pendingTotal'];
                $txTargetPrice = $tracking['salePrice'] !== null ? (float) $tracking['salePrice'] : (float) ($tracking['listingPrice'] ?? 0);
                $txRemaining = $txTargetPrice > 0 ? max($txTargetPrice - $txVerifiedPaid, 0.0) : 0.0;
                $txPaidPercent = $txTargetPrice > 0 ? min(100, (int) round(($txVerifiedPaid / $txTargetPrice) * 100)) : 0;
            ?>
                <!-- Clickable Property Card Button -->
                <div class="col-12" id="trackingCardCol<?= $transactionId ?>">
                    <div class="card p-3 p-md-4 border shadow-sm kq-tracking-card-btn position-relative"
                         data-bs-toggle="modal"
                         data-bs-target="#trackingModal<?= $transactionId ?>"
                         role="button"
                         tabindex="0"
                         aria-label="View tracking details for <?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?>"
                         style="cursor: pointer; border-radius: 0.85rem; transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;">
                        
                        <?php
                        $propImage = basename((string) ($tracking['image'] ?? ''));
                        if ($propImage === '' || $propImage === 'default.jpg' || !is_file(__DIR__ . '/assets/images/' . $propImage)) {
                            $propImage = function_exists('property_category_image') ? (property_category_image((string) ($tracking['propertyType'] ?? '')) ?? '') : '';
                        }
                        $hasPropImage = $propImage !== '' && is_file(__DIR__ . '/assets/images/' . $propImage);
                        $propImgSrc = $hasPropImage ? ('assets/images/' . rawurlencode($propImage)) : 'assets/images/residential-property-01.jpg';
                        ?>
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div class="d-flex align-items-start gap-3">
                                <div class="rounded-3 overflow-hidden flex-shrink-0 border shadow-sm position-relative" style="width: 58px; height: 58px; background: rgba(0,0,0,0.04);">
                                    <img src="<?= htmlspecialchars($propImgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100" style="object-fit: cover;" loading="lazy" onerror="this.onerror=null;this.src='assets/images/residential-property-01.jpg';">
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                        <span class="small fw-bold text-primary">#KQT-<?= $transactionId ?></span>
                                        <span class="badge rounded-pill <?= $stageBadgeClass ?>"><?= htmlspecialchars(property_transaction_stage_label($stage), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($isSpotCash): ?>
                                            <span class="badge text-bg-warning text-dark small"><i class="bi bi-lightning-charge-fill me-1"></i>Spot Cash</span>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="h5 fw-bold mb-1 text-dark tracking-card-title"><?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <div class="small text-muted d-flex align-items-center gap-2 flex-wrap">
                                        <span><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($tracking['appointmentDate'])): ?>
                                            <span>· <i class="bi bi-calendar-event me-1"></i>Viewing schedule: <?= htmlspecialchars($tracking['appointmentDate'] . ' at ' . substr($tracking['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <span>· <i class="bi bi-person-badge me-1"></i>Sales Agent: <strong><?= htmlspecialchars($tracking['agentName'], ENT_QUOTES, 'UTF-8') ?></strong></span>
                                    </div>
                                    <?php if ($stage === 'completed' && $tracking['salePrice'] !== null): ?>
                                        <div class="small fw-semibold text-success mt-1">
                                            💰 Verified sale price: ₱<?= number_format((float) $tracking['salePrice'], 2) ?> · Paid: ₱<?= number_format($txVerifiedPaid, 2) ?>
                                        </div>
                                    <?php elseif ($txVerifiedPaid > 0 || $txPendingPaid > 0): ?>
                                        <div class="small text-muted mt-1">
                                            💳 Verified paid: <strong class="text-success">₱<?= number_format($txVerifiedPaid, 2) ?></strong>
                                            <?php if ($txTargetPrice > 0): ?>
                                                · Balance: <strong>₱<?= number_format($txRemaining, 2) ?></strong>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3 align-self-stretch align-self-md-center justify-content-between justify-content-md-end">
                                <div class="text-sm-end" style="min-width: 140px;">
                                    <div class="small text-muted mb-1">Overall Progress</div>
                                    <div class="d-flex align-items-center gap-2 justify-content-sm-end">
                                        <div class="progress flex-grow-1 flex-sm-grow-0" style="width: 90px; height: 8px; border-radius: 999px;">
                                            <div class="progress-bar <?= $stage === 'completed' ? 'bg-success' : 'bg-primary' ?>" style="width: <?= $progress ?>%"></div>
                                        </div>
                                        <span class="small fw-bold text-dark"><?= $progress ?>%</span>
                                    </div>
                                    <div class="small text-muted mt-1" style="font-size: 0.72rem;">
                                        <?= $stageIndex !== false ? 'Step ' . ($stageIndex + 1) . ' of ' . count($txProgressStages) : '' ?>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-primary btn-sm fw-semibold text-nowrap d-inline-flex align-items-center gap-1 shadow-sm px-3 py-2" data-bs-toggle="modal" data-bs-target="#trackingModal<?= $transactionId ?>">
                                    <i class="bi bi-window-stack"></i> View tracking <span class="d-none d-sm-inline">&rarr;</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pop-Up Modal Window for Transaction Details -->
                <div class="modal fade" id="trackingModal<?= $transactionId ?>" tabindex="-1" aria-labelledby="trackingModalLabel<?= $transactionId ?>" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-dark text-white">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fs-5">📋</span>
                                    <div>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <h5 class="modal-title h6 mb-0 text-white" id="trackingModalLabel<?= $transactionId ?>">
                                                Transaction Tracking — #KQT-<?= $transactionId ?>
                                            </h5>
                                            <span class="badge rounded-pill <?= $stageBadgeClass ?> px-2 py-1 small"><?= htmlspecialchars(property_transaction_stage_label($stage), ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($isSpotCash): ?>
                                                <span class="badge text-bg-warning text-dark small"><i class="bi bi-lightning-charge-fill me-1"></i>Spot Cash</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="small text-white-50 mt-1">
                                            <?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>

                            <div class="modal-body p-3 p-md-4 text-start">

                                <!-- Property Overview Banner -->
                                <div class="d-flex align-items-center gap-3 p-2 p-sm-3 mb-3 bg-body-tertiary border rounded-3">
                                    <div class="rounded-3 overflow-hidden flex-shrink-0 border shadow-sm" style="width: 64px; height: 64px;">
                                        <img src="<?= htmlspecialchars($propImgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100" style="object-fit: cover;" onerror="this.onerror=null;this.src='assets/images/residential-property-01.jpg';">
                                    </div>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <h6 class="fw-bold mb-1 text-dark text-truncate"><?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?></h6>
                                        <div class="small text-muted text-truncate"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>

                                <!-- Status / Milestone Guidance Banner -->
                                <?php if ($stage === 'completed'): ?>
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 p-3 bg-success-subtle border border-success-subtle rounded-3 mb-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="fs-4">🎉</span>
                                            <div>
                                                <strong class="text-success fs-6">Property Officially Owned &amp; Closed!</strong>
                                                <div class="small text-muted">
                                                    <?php if ($tracking['salePrice'] !== null): ?>Verified sale price: <strong>₱<?= number_format((float) $tracking['salePrice'], 2) ?></strong> · <?php endif; ?>
                                                    This property has been successfully turned over to you.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif ($stage === 'cancelled'): ?>
                                    <div class="alert alert-secondary small py-2 mb-3 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-x-circle fs-5 text-secondary flex-shrink-0 mt-1"></i>
                                        <div>
                                            <strong>Transaction Cancelled:</strong>
                                            This transaction tracking request was cancelled. If you wish to inquire again, feel free to submit a new inquiry or message our sales team.
                                        </div>
                                    </div>
                                <?php elseif ($stage === 'reservation_requested'): ?>
                                    <div class="alert alert-warning small py-2 mb-3 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-bookmark-check fs-5 text-warning-emphasis flex-shrink-0 mt-1"></i>
                                        <div>
                                            <strong>Step 1: Reservation Requested</strong>
                                            <p class="mb-0 mt-1">Your pre-viewing reservation request has been received. Once a Sales Agent confirms your reservation, your 7-day reservation hold will start and you can schedule a property viewing.</p>
                                        </div>
                                    </div>
                                <?php elseif ($stage === 'reservation_confirmed'):
                                    $stageUpdateTs = !empty($tracking['updatedAt']) ? strtotime($tracking['updatedAt']) : time();
                                    $daysElapsed = (int) floor((time() - $stageUpdateTs) / 86400);
                                    $daysLeft = max(0, 7 - $daysElapsed);
                                ?>
                                    <div class="alert alert-success small py-2 mb-3 d-flex align-items-start justify-content-between flex-wrap gap-2" role="status">
                                        <div class="d-flex align-items-start gap-2">
                                            <i class="bi bi-check-circle-fill fs-5 text-success flex-shrink-0 mt-1"></i>
                                            <div>
                                                <strong>Step 2: Reservation Confirmed — Schedule Your Viewing</strong>
                                                <p class="mb-2 mt-1">Your 7-day reservation hold is active (<strong><?= $daysLeft > 0 ? ($daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' remaining') : 'Window elapsed' ?></strong>). You can now schedule your property viewing!</p>
                                                <a href="property_detail.php?id=<?= (int) $tracking['propertyID'] ?>#appointment" class="btn btn-sm btn-success fw-semibold">Schedule Property Viewing &rarr;</a>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif ($stage === 'viewing_requested'): ?>
                                    <div class="alert alert-primary small py-2 mb-3 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-calendar-event fs-5 text-primary flex-shrink-0 mt-1"></i>
                                        <div>
                                            <strong>Viewing Requested</strong>
                                            <p class="mb-0 mt-1">Your request to view this property has been received. Your assigned Sales Agent will review availability and accept your viewing schedule shortly.</p>
                                        </div>
                                    </div>
                                <?php elseif ($stage === 'reviewing_schedule'): ?>
                                    <div class="alert alert-info small py-2 mb-3 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-calendar-check fs-5 text-info flex-shrink-0 mt-1"></i>
                                        <div>
                                            <strong>Step 2: Reviewing Schedule</strong>
                                            <p class="mb-0 mt-1">Your Sales Agent has accepted your viewing request! Your agent is currently coordinating the viewing schedule details with you. You can chat or call your agent directly below.</p>
                                        </div>
                                    </div>
                                <?php elseif ($stage === 'viewing_confirmed'): ?>
                                    <div class="alert alert-success small py-2 mb-3 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-check-circle-fill fs-5 text-success flex-shrink-0 mt-1"></i>
                                        <div>
                                            <strong>Step 3: Viewing Approved &amp; Confirmed</strong>
                                            <p class="mb-0 mt-1">Your property viewing is confirmed! Please meet your assigned Sales Agent at the scheduled date, time, and location.</p>
                                        </div>
                                    </div>
                                <?php elseif (in_array($stage, ['viewing_completed', 'payment_in_process', 'document_verification', 'payment_confirmation'], true)): ?>
                                    <div class="alert alert-info small py-2 mb-3 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-shield-check fs-5 text-primary flex-shrink-0 mt-1"></i>
                                        <div>
                                            <strong>
                                                <?php if ($stage === 'viewing_completed'): ?>
                                                    Step 4: Viewing Completed
                                                <?php elseif ($stage === 'payment_in_process'): ?>
                                                    Step 5: Payment Processing &amp; Reservation
                                                <?php elseif ($stage === 'document_verification'): ?>
                                                    Step 6: Legal &amp; Document Verification
                                                <?php elseif ($stage === 'payment_confirmation'): ?>
                                                    Step 7: Payment Confirmation &amp; Turnover Clearance
                                                <?php endif; ?>
                                            </strong>
                                            <?php if ($stage === 'viewing_completed'): ?>
                                                <p class="mb-0 mt-1">Your property viewing was successfully conducted! Coordinate with your Sales Agent to finalize your payment terms and down payment paperwork.</p>
                                            <?php elseif ($stage === 'payment_in_process'): ?>
                                                <?php if ($isSpotCash): ?>
                                                    <p class="mb-0 mt-1">Spot Cash selected! Document verification is skipped for Spot Cash transactions. Settle the balance to advance directly to final Deed of Absolute Sale (DOAS) preparation and turnover.</p>
                                                <?php else: ?>
                                                    <p class="mb-0 mt-1">Payment processing is active. Settle your down payment / balance with your Sales Agent to proceed with document verification.</p>
                                                <?php endif; ?>
                                            <?php elseif ($stage === 'document_verification'): ?>
                                                <p class="mb-0 mt-1">Your Sales Agent is actively verifying property title deeds (TCT/CCT), tax declarations, and government clearance papers.</p>
                                            <?php elseif ($stage === 'payment_confirmation'): ?>
                                                <p class="mb-0 mt-1">All payments and legal paperwork have been verified. Your Sales Agent is finalizing deed notarization and scheduling turnover.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- 7-Day Reservation & Legal Pathway Guidance -->
                                    <?php if ($stage === 'viewing_completed'): ?>
                                        <div class="p-2 px-3 rounded-3 mb-3 bg-light border small d-flex align-items-center justify-content-between flex-wrap gap-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="fs-5">⏳</span>
                                                <div>
                                                    <strong class="text-dark">7-Day Reservation Policy:</strong>
                                                    <div class="text-muted" style="font-size: 0.78rem;">
                                                        Complete your down payment and compliance documents within your 7-day reservation window to advance to contract preparation.
                                                    </div>
                                                </div>
                                            </div>
                                            <span class="badge text-bg-warning text-dark fw-bold">7-Day Rule</span>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($isSpotCash): ?>
                                        <div class="p-2 px-3 rounded-3 mb-3 bg-success-subtle border border-success small">
                                            <div class="fw-bold text-success d-flex align-items-center gap-1">
                                                <i class="bi bi-file-earmark-check-fill"></i> Legal Pathway: Deed of Absolute Sale (DOAS)
                                            </div>
                                            <div class="text-muted mt-1" style="font-size: 0.78rem;">
                                                Spot Cash / Full Payment selected. A notarized Deed of Absolute Sale (DOAS) will be prepared upon full settlement. <em>Note: Title transfer, Documentary Stamp Tax (DST), and registration fees are shouldered by the buyer as standard.</em>
                                            </div>
                                        </div>
                                    <?php elseif (!empty($tracking['paymentMethod'])): ?>
                                        <div class="p-2 px-3 rounded-3 mb-3 bg-info-subtle border border-info small">
                                            <div class="fw-bold text-primary d-flex align-items-center gap-1">
                                                <i class="bi bi-file-earmark-text-fill"></i> Legal Pathway: Contract to Sell (CTS)
                                            </div>
                                            <div class="text-muted mt-1" style="font-size: 0.78rem;">
                                                Installment plan active. A notarized Contract to Sell (CTS) preserves your ownership rights while amortizations are completed. <em>Note: Final title transfer upon completion is shouldered by the buyer.</em>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <!-- Dedicated Assigned Sales Agent Card -->
                                <div class="card p-3 mb-3 border rounded-3 bg-light">
                                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                                        <div class="d-flex align-items-center gap-3">
                                            <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle text-primary fw-bold fs-5" style="width: 44px; height: 44px;">
                                                👨‍💼
                                            </div>
                                            <div>
                                                <span class="badge bg-primary text-white mb-1" style="font-size: 0.68rem;">Assigned Sales Agent</span>
                                                <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($tracking['agentName'], ENT_QUOTES, 'UTF-8') ?></h6>
                                                <div class="small text-muted" style="font-size: 0.78rem;">
                                                    <span><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($tracking['agentEmail'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <span class="ms-2"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($tracking['agentPhone'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <?php
                                            $hasSchedViewing = !empty($tracking['appointmentDate']) || in_array($stage, ['viewing_approved', 'viewing_confirmed', 'reviewing_schedule', 'viewing_completed', 'reservation_deposit', 'reservation_under_review', 'reservation_agreement', 'payment_in_process', 'payment_confirmation', 'completed'], true);
                                            ?>
                                            <button type="button" class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1 shadow-sm" onclick="openDirectChat(<?= $transactionId ?>, '<?= addslashes(htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8')) ?>', '<?= addslashes(htmlspecialchars($tracking['agentName'], ENT_QUOTES, 'UTF-8')) ?>', <?= $hasSchedViewing ? 'true' : 'false' ?>)">
                                                <span>💬 Chat with Agent</span>
                                            </button>
                                            <a href="tel:<?= htmlspecialchars($tracking['agentPhone'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-success d-inline-flex align-items-center gap-1 shadow-sm">
                                                <span>📞 Call / SMS</span>
                                            </a>
                                            <a href="mailto:<?= htmlspecialchars($tracking['agentEmail'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 shadow-sm">
                                                <span>✉️ Email</span>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($stage !== 'cancelled'): ?>
                                    <div class="border rounded-3 p-3 bg-white mb-3 shadow-none">
                                        <div class="d-flex justify-content-between align-items-center small mb-2">
                                            <span class="fw-bold text-dark"><i class="bi bi-speedometer2 me-1"></i> Overall Progress</span>
                                            <span class="fw-bold text-dark fs-6"><?= $progress ?>%</span>
                                        </div>
                                        <div class="progress" role="progressbar" aria-label="Transaction progress" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100" style="height: .75rem; border-radius: 999px;">
                                            <div class="progress-bar <?= $stage === 'completed' ? 'bg-success' : 'bg-primary' ?>" style="width: <?= $progress ?>%"></div>
                                        </div>
                                        <div class="small text-muted mt-2 d-flex justify-content-between align-items-center">
                                            <span>Current milestone: <strong><?= htmlspecialchars(property_transaction_stage_label($stage), ENT_QUOTES, 'UTF-8') ?></strong></span>
                                            <span>Updated: <?= htmlspecialchars($tracking['updatedAt'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($txVerifiedPaid > 0 || $txPendingPaid > 0 || $txPayRecords !== [] || in_array($stage, ['viewing_completed', 'payment_in_process', 'document_verification', 'payment_confirmation', 'completed'], true)): ?>
                                    <div class="border rounded-3 p-3 bg-white mb-3 shadow-none">
                                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                            <h4 class="h6 fw-bold mb-0"><i class="bi bi-wallet2 me-1"></i> Payment Summary &amp; Receipts</h4>
                                            <?php if ($txTargetPrice > 0): ?>
                                                <span class="small fw-semibold text-muted"><?= $txPaidPercent ?>% settled</span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="small text-muted mb-2">Payments are settled offline with your Sales Agent or office. Once verified, they appear below.</p>
                                        <div class="row g-2 small text-center mb-2">
                                            <div class="col-4">
                                                <div class="p-2 rounded-2 bg-body-tertiary border">
                                                    <div class="text-muted" style="font-size: 0.72rem;"><?= $tracking['salePrice'] !== null ? 'Agreed Price' : 'Listing Price' ?></div>
                                                    <div class="fw-bold text-dark">₱<?= number_format($txTargetPrice, 2) ?></div>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="p-2 rounded-2 bg-body-tertiary border">
                                                    <div class="text-muted" style="font-size: 0.72rem;">Verified Paid</div>
                                                    <div class="fw-bold text-success">₱<?= number_format($txVerifiedPaid, 2) ?></div>
                                                </div>
                                            </div>
                                            <div class="col-4">
                                                <div class="p-2 rounded-2 bg-body-tertiary border">
                                                    <div class="text-muted" style="font-size: 0.72rem;">Remaining</div>
                                                    <div class="fw-bold">
                                                        <?= ($txRemaining <= 0.01 && $txTargetPrice > 0) ? 'Fully paid' : ('₱' . number_format($txRemaining, 2)) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($txTargetPrice > 0): ?>
                                            <div class="progress mb-2" style="height: 0.5rem;" role="progressbar" aria-label="Payment progress" aria-valuenow="<?= $txPaidPercent ?>" aria-valuemin="0" aria-valuemax="100">
                                                <div class="progress-bar bg-success" style="width: <?= $txPaidPercent ?>%"></div>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($txPayRecords !== []): ?>
                                            <div class="d-grid gap-2 mt-2">
                                                <?php foreach ($txPayRecords as $payRecord):
                                                    $payBadgeClass = match ($payRecord['status']) {
                                                        'confirmed' => 'text-bg-success',
                                                        'rejected' => 'text-bg-danger',
                                                        default => 'text-bg-warning text-dark',
                                                    };
                                                    $payStatusLabel = match ($payRecord['status']) {
                                                        'confirmed' => 'Verified',
                                                        'rejected' => 'Rejected',
                                                        default => 'Pending review',
                                                    };
                                                ?>
                                                    <div class="border rounded-2 p-2 small bg-body-tertiary d-flex justify-content-between align-items-center flex-wrap gap-2">
                                                        <div>
                                                            <div class="fw-semibold">
                                                                ₱<?= number_format((float) $payRecord['amount'], 2) ?> · <?= htmlspecialchars($payRecord['paymentType'] ?? $payRecord['paymentMethod'], ENT_QUOTES, 'UTF-8') ?>
                                                                <span class="text-muted fw-normal">(<?= htmlspecialchars($payRecord['paymentMethod'], ENT_QUOTES, 'UTF-8') ?>)</span>
                                                            </div>
                                                            <div class="text-muted" style="font-size: 0.76rem;">
                                                                <?= htmlspecialchars(date('M j, Y', strtotime((string) $payRecord['submittedAt'])), ENT_QUOTES, 'UTF-8') ?>
                                                                <?php if (!empty($payRecord['referenceNumber'])): ?> · Ref: <?= htmlspecialchars($payRecord['referenceNumber'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <span class="badge <?= $payBadgeClass ?>"><?= htmlspecialchars($payStatusLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <!-- 4. Transaction Updates -->
                                <div class="border rounded-3 p-3 kq-tracking-updates-box mb-2 shadow-none">
                                    <h4 class="h6 fw-bold mb-3 kq-tracking-updates-title"><i class="bi bi-clock-history me-1"></i> Transaction Updates</h4>
                                    <?php if (!empty($trackingEvents[$transactionId])): ?>
                                        <ol class="list-unstyled kq-tracking-timeline mb-0" aria-label="Transaction update history">
                                            <?php foreach ($trackingEvents[$transactionId] as $eventIndex => $event): ?>
                                                <li class="kq-tracking-event <?= $eventIndex === 0 ? 'is-latest' : '' ?> <?= $event['stage'] === 'completed' ? 'is-completed' : '' ?>" <?= $eventIndex === 0 ? 'aria-current="step"' : '' ?>>
                                                    <div class="kq-tracking-event-content">
                                                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                            <?php
                                                            $eventTitle = property_transaction_stage_label((string) $event['stage']);
                                                            if (!empty($event['note']) && (str_contains($event['note'], 'Agent recorded face-to-face payment') || str_contains($event['note'], 'face-to-face payment and paperwork') || str_contains($event['note'], 'Payment and paperwork'))) {
                                                                if (str_contains($event['note'], '(Status: confirmed)')) {
                                                                    $eventTitle = 'Payment complete';
                                                                } else {
                                                                    $eventTitle = 'Payment in process';
                                                                }
                                                            }
                                                            ?>
                                                            <span class="fw-bold kq-tracking-event-title" style="font-size: 0.95rem;"><?= htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                                            <time class="small fw-semibold kq-tracking-event-time" datetime="<?= htmlspecialchars(date('c', strtotime($event['createdAt'])), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($event['createdAt'])), ENT_QUOTES, 'UTF-8') ?></time>
                                                        </div>
                                                        <?php if ($eventIndex === 0): ?><div class="small kq-tracking-event-latest fw-semibold mt-1">Latest update</div><?php endif; ?>
                                                        <?php if (!empty($event['changedByName'])): ?><div class="small fw-semibold kq-tracking-event-author mt-1">Updated by <?= htmlspecialchars($event['changedByName'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                                        <?php if (!empty($event['note'])): ?><p class="small kq-tracking-event-note text-break mb-0 mt-1 fw-normal"><?= htmlspecialchars($event['note'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php else: ?>
                                        <p class="small kq-tracking-empty-note mb-0">No tracking updates have been recorded yet.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="rounded-3 border bg-light p-3 p-lg-4">
            <h3 class="h6 fw-bold mb-2">No property requests to track yet</h3>
            <p class="text-muted mb-3">Send an inquiry or request a property viewing to create a tracking reference. The Sales Agent team will add dated updates here as your request moves forward.</p>
            <a href="index.php" class="btn btn-primary">Browse properties</a>
        </div>
    <?php endif; ?>
</section>

<section class="card p-3 p-lg-4 mb-4" id="viewing-requests">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Viewing requests</div>
            <h2 class="h4 fw-bold mb-0">Viewing requests</h2>
        </div>
    </div>
    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show small" role="status">
            <?= htmlspecialchars((string) $_SESSION['flash_success'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
    <?php elseif (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show small" role="alert">
            <?= htmlspecialchars((string) $_SESSION['flash_error'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>
    <?php if ($appointmentRows !== []): ?>
        <?php foreach ($appointmentRows as $appt): ?>
            <?php $status = $appt['status']; ?>
            <article class="border rounded-3 p-3 p-lg-4 mb-3 bg-white shadow-sm">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h3 class="h5 fw-bold mb-1"><?= htmlspecialchars($appt['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <div class="small text-muted"><?= htmlspecialchars(!empty($appt['municipalityName']) ? $appt['municipalityName'] . ', ' . $appt['provinceName'] : $appt['location'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($appt['barangay'])): ?>, <?= htmlspecialchars($appt['barangay'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></div>
                    </div>
                    <div>
                        <?php if ($status === 'Approved'): ?>
                            <span class="badge rounded-pill text-bg-success px-3 py-2 fw-semibold">✓ Schedule Confirmed</span>
                        <?php elseif ($status === 'Cancelled'): ?>
                            <span class="badge rounded-pill text-bg-secondary px-3 py-2 fw-semibold">Cancelled</span>
                        <?php else: ?>
                            <span class="badge rounded-pill text-bg-warning text-dark px-3 py-2 fw-semibold">Awaiting Agent Confirmation</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="row g-3 small mb-3">
                    <div class="col-sm-6"><span class="text-muted d-block">Viewing date and time</span><div class="fw-bold fs-6 text-dark"><?= htmlspecialchars(date('F j, Y', strtotime($appt['appointmentDate'])), ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars(date('g:i A', strtotime($appt['appointmentTime'])), ENT_QUOTES, 'UTF-8') ?></div></div>
                    <div class="col-sm-6"><span class="text-muted d-block">Reference number</span><div class="fw-semibold">#KQ-<?= 1000 + (int) $appt['appointmentID'] ?></div></div>
                </div>
                <div class="d-flex flex-wrap gap-2 pt-2 border-top">
                    <a href="property_detail.php?id=<?= (int) $appt['propertyID'] ?>" class="btn btn-sm btn-outline-primary">View Property</a>
                    <?php if ($status !== 'Cancelled'): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#rescheduleModal_<?= (int) $appt['appointmentID'] ?>">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="me-1 align-text-bottom"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            Request Reschedule
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal_<?= (int) $appt['appointmentID'] ?>">
                            Cancel Viewing
                        </button>
                        <?php if ($status === 'Approved'): ?>
                            <form method="POST" action="client_dashboard.php#viewing-requests" class="d-inline m-0">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="appointmentID" value="<?= (int) $appt['appointmentID'] ?>">
                                <input type="hidden" name="client_confirm_appointment" value="1">
                                <button type="submit" class="btn btn-sm btn-success">✓ Confirm Attendance</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>

            <!-- Reschedule Modal -->
            <div class="modal fade" id="rescheduleModal_<?= (int) $appt['appointmentID'] ?>" tabindex="-1" aria-labelledby="rescheduleModalLabel_<?= (int) $appt['appointmentID'] ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content rounded-4 border-0 shadow">
                        <form method="POST" action="client_dashboard.php#viewing-requests">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="client_reschedule_appointment" value="1">
                            <input type="hidden" name="appointmentID" value="<?= (int) $appt['appointmentID'] ?>">
                            <div class="modal-header border-bottom">
                                <h5 class="modal-title fw-bold" id="rescheduleModalLabel_<?= (int) $appt['appointmentID'] ?>">Request Reschedule</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <p class="small text-muted mb-3">Choose a new preferred date and time for viewing <strong><?= htmlspecialchars($appt['title'], ENT_QUOTES, 'UTF-8') ?></strong>. Our sales team will verify agent availability.</p>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">New Preferred Date</label>
                                    <input type="date" name="appointmentDate" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($appt['appointmentDate'], ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold small">New Preferred Time</label>
                                    <input type="time" name="appointmentTime" class="form-control" required min="08:00" max="17:00" value="<?= htmlspecialchars(substr($appt['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label fw-semibold small">Reason or Note <span class="text-muted fw-normal">(optional)</span></label>
                                    <textarea name="rescheduleReason" class="form-control" rows="2" placeholder="e.g. Can we meet in the afternoon instead?"></textarea>
                                </div>
                            </div>
                            <div class="modal-footer border-top bg-light rounded-bottom-4">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                <button type="submit" class="btn btn-primary btn-sm px-4">Submit Reschedule Request</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Cancel Confirmation Modal -->
            <div class="modal fade" id="cancelModal_<?= (int) $appt['appointmentID'] ?>" tabindex="-1" aria-labelledby="cancelModalLabel_<?= (int) $appt['appointmentID'] ?>" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-sm">
                    <div class="modal-content rounded-4 border-0 shadow">
                        <form method="POST" action="client_dashboard.php#viewing-requests">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="client_cancel_appointment" value="1">
                            <input type="hidden" name="appointmentID" value="<?= (int) $appt['appointmentID'] ?>">
                            <div class="modal-body text-center p-4">
                                <div class="text-danger mb-3">
                                    <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                                </div>
                                <h5 class="fw-bold mb-2">Cancel Viewing Request?</h5>
                                <p class="small text-muted mb-0">Are you sure you want to cancel your viewing request for <strong><?= htmlspecialchars($appt['title'], ENT_QUOTES, 'UTF-8') ?></strong>?</p>
                            </div>
                            <div class="modal-footer border-top bg-light justify-content-center p-2 rounded-bottom-4">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Keep Request</button>
                                <button type="submit" class="btn btn-sm btn-danger px-3">Yes, Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="text-center py-5 px-3 bg-light rounded-4 border">
            <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" class="text-secondary mb-3"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>
            <h4 class="h5 fw-bold mb-1">No Viewing Requests Yet</h4>
            <p class="text-muted small mb-3" style="max-width: 440px; margin: 0 auto;">When you find a lot or property you love in our Batangas catalog, book a viewing appointment to inspect the land in person with a licensed sales agent.</p>
            <a href="index.php" class="btn btn-primary px-4 shadow-sm">Explore Available Listings &rarr;</a>
        </div>
    <?php endif; ?>
</section>
</div>
</div>
</div>

<script>
// Session keepalive to prevent dashboard timeout while viewing
setInterval(function() {
    fetch('chat_api.php?action=heartbeat', { cache: 'no-store', credentials: 'same-origin' }).catch(function(){});
}, 300000);

// Auto-reopen tracking modal when confirming actions or encountering validation feedback
document.addEventListener('DOMContentLoaded', function() {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const openModalId = urlParams.get('open_modal') || '<?= (int)($_POST['transactionID'] ?? 0) ?>';
        if (openModalId && parseInt(openModalId, 10) > 0) {
            const modalEl = document.getElementById('trackingModal' + openModalId);
            if (modalEl && typeof bootstrap !== 'undefined') {
                const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
                modalInstance.show();
            }
        }
    } catch (e) {
        console.warn('Could not auto-open tracking modal:', e);
    }
});
</script>

<?php include 'includes/footer.php'; ?>