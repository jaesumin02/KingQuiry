<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/property_images.php';
require_once 'includes/location_directory.php';
require_once 'includes/transaction_tracking.php';
require_once 'includes/rate_limit.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (normalized_user_role($_SESSION['user'] ?? null) !== 'Client') {
    header('Location: client_login.php');
    exit();
}

$paymentSubmissionError = '';
$clientID = (int) $_SESSION['user']['userID'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_client_payment'])) {
    // ALGORITHM: Validate client session/CSRF/replay token, throttle submissions, then atomically record an offline payment report for staff review.
    $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
    $paymentAmount = filter_var($_POST['paymentAmount'] ?? null, FILTER_VALIDATE_FLOAT);
    $paymentMethod = is_string($_POST['paymentMethod'] ?? null) ? trim($_POST['paymentMethod']) : '';
    $referenceNumber = is_string($_POST['referenceNumber'] ?? null) ? trim($_POST['referenceNumber']) : '';
    $submittedToken = $_POST['payment_submission_token'] ?? null;
    $storedToken = $transactionId !== false
        ? ($_SESSION['client_payment_tokens'][$transactionId] ?? null)
        : null;
    $hasValidPaymentToken = is_string($submittedToken)
        && is_string($storedToken)
        && hash_equals($storedToken, $submittedToken);

    if (!has_valid_csrf_token()) {
        $paymentSubmissionError = 'Your session expired. Refresh the page and try again.';
    } elseif ($transactionId === false || $transactionId <= 0) {
        $paymentSubmissionError = 'Choose a valid transaction.';
    } elseif (!$hasValidPaymentToken) {
        $paymentSubmissionError = 'This payment form was already submitted or expired. Refresh the dashboard and try again.';
    } else {
        try {
            $clientLimit = kq_rate_limit_consume($conn, 'client-payment', (string) $clientID, 10, 600);
            $ipAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) && $_SERVER['REMOTE_ADDR'] !== ''
                ? $_SERVER['REMOTE_ADDR']
                : 'unknown';
            $ipLimit = kq_rate_limit_consume($conn, 'client-payment-ip', $ipAddress, 30, 600);
            if (!$clientLimit['allowed'] || !$ipLimit['allowed']) {
                $retryAfter = max(1, $clientLimit['retry_after'], $ipLimit['retry_after']);
                $paymentSubmissionError = 'Too many payment submissions. Please wait '
                    . (int) ceil($retryAfter / 60)
                    . ' minute(s) before trying again.';
            } elseif ($paymentAmount === false) {
                $paymentSubmissionError = 'Enter a valid payment amount.';
            } else {
                submit_property_transaction_payment(
                    $conn,
                    (int) $transactionId,
                    $clientID,
                    (float) $paymentAmount,
                    $paymentMethod,
                    $referenceNumber
                );
                unset($_SESSION['client_payment_tokens'][$transactionId]);
                $_SESSION['flash_payment_success'] = 'Your payment record was submitted for staff verification.';
                header('Location: client_dashboard.php?open_modal=' . $transactionId . '#transaction-tracking');
                exit();
            }
        } catch (InvalidArgumentException $exception) {
            $paymentSubmissionError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('KingQuiry client payment submission failed: ' . $exception->getMessage());
            $paymentSubmissionError = 'We could not submit your payment record. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['choose_payment_scheme'])) {
    $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
    $selectedScheme = is_string($_POST['paymentScheme'] ?? null) ? trim($_POST['paymentScheme']) : '';
    $clientNote = is_string($_POST['clientPaymentNote'] ?? null) ? trim($_POST['clientPaymentNote']) : null;

    if (!has_valid_csrf_token()) {
        $paymentSubmissionError = 'Your session expired. Refresh the page and try again.';
    } elseif ($transactionId === false || $transactionId <= 0) {
        $paymentSubmissionError = 'Choose a valid transaction.';
    } else {
        try {
            set_property_transaction_payment_method($conn, (int) $transactionId, $clientID, $selectedScheme, $clientNote);
            $_SESSION['flash_payment_success'] = 'Preferred payment scheme saved! Your Sales Agent has been notified.';
            header('Location: client_dashboard.php?open_modal=' . $transactionId . '#transaction-tracking');
            exit();
        } catch (InvalidArgumentException $exception) {
            $paymentSubmissionError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('KingQuiry client payment scheme selection failed: ' . $exception->getMessage());
            $paymentSubmissionError = 'Could not save payment scheme. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_agent_review'])) {
    http_response_code(410);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Client reviews are no longer available.']);
    exit();
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
            p.title, p.image, p.propertyType, a.appointmentDate, a.appointmentTime,
            l.municipalityName, l.provinceName, p.location,
            COALESCE(
                apptAgent.fullname,
                inqAgent.fullname,
                reviewAgent.fullname,
                (
                    SELECT u2.fullname FROM property_transaction_events e
                    JOIN users u2 ON u2.userID = e.changedBy
                    WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                    ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
                ),
                'Kings Land Sales Agent'
            ) AS agentName,
            COALESCE(
                apptAgent.phone,
                inqAgent.phone,
                '0965 803 0933'
            ) AS agentPhone,
            COALESCE(
                apptAgent.email,
                inqAgent.email,
                'contact@kingsland.ph'
            ) AS agentEmail,
            COALESCE(
                a.agentID,
                ia.agentID,
                ar.agentID,
                (
                    SELECT e.changedBy FROM property_transaction_events e
                    WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                    ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
                )
            ) AS agentID
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     LEFT JOIN users apptAgent ON apptAgent.userID = a.agentID
     LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
     LEFT JOIN users inqAgent ON inqAgent.userID = ia.agentID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     LEFT JOIN agent_reviews ar ON ar.transactionID = t.transactionID
     LEFT JOIN users reviewAgent ON reviewAgent.userID = ar.agentID
     WHERE t.clientID = ?
     ORDER BY t.updatedAt DESC, t.transactionID DESC
     LIMIT 100"
);
$trackingStmt->bind_param('i', $clientID);
$trackingStmt->execute();
$trackingRows = $trackingStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$clientDashboardCountsStmt = $conn->prepare(
    "SELECT
        (SELECT COUNT(*) FROM property_transactions WHERE clientID = ?) AS trackedRequests,
        (SELECT COUNT(*) FROM property_transactions WHERE clientID = ? AND currentStage NOT IN ('completed', 'cancelled')) AS activePipeline,
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
$paymentRowsByTransaction = [];
if ($trackingRows !== []) {
    $transactionIds = array_map(static fn(array $row): int => (int) $row['transactionID'], $trackingRows);
    $transactionIdList = implode(',', $transactionIds);
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
    $paymentRowsByTransaction = get_transaction_payment_records($conn, $transactionIds);
}

$paymentSubmissionSuccess = isset($_SESSION['flash_payment_success'])
    ? (string) $_SESSION['flash_payment_success']
    : '';
unset($_SESSION['flash_payment_success']);

$stageLabels = property_transaction_stages();
$progressStages = [
    'inquiry_received',
    'contacting_client',
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

<?php if ($paymentSubmissionError !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($paymentSubmissionError, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($paymentSubmissionSuccess !== ''): ?>
    <div class="alert alert-success" role="status"><?= htmlspecialchars($paymentSubmissionSuccess, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

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
                <small class="text-muted" style="font-size: 0.72rem;"><a href="#transaction-tracking" class="text-decoration-none">Your in-progress transactions &rarr;</a></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-info" style="font-size: 0.68rem;">Viewing requests</div>
                <h3 class="h3 mb-0 text-info"><?= (int) $totalClientAppointments ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;"><a href="#viewing-requests" class="text-decoration-none">Your viewing requests</a></small>
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
    <div class="alert alert-info small mb-4" role="note">Click any property below to view its current status, overall progress, payment records, and transaction updates.</div>
    <?php if ($trackingRows !== []): ?>
        <div class="row g-3">
            <?php foreach ($trackingRows as $tracking):
                $transactionId = (int) $tracking['transactionID'];
                $stage = (string) $tracking['currentStage'];
                $canSubmitPayment = in_array($stage, ['payment_in_process', 'document_verification', 'payment_confirmation'], true);
                $paymentSubmissionToken = '';
                if ($canSubmitPayment) {
                    if (!isset($_SESSION['client_payment_tokens']) || !is_array($_SESSION['client_payment_tokens'])) {
                        $_SESSION['client_payment_tokens'] = [];
                    }
                    if (!isset($_SESSION['client_payment_tokens'][$transactionId])) {
                        if (count($_SESSION['client_payment_tokens']) >= 100) {
                            $oldestTransactionId = array_key_first($_SESSION['client_payment_tokens']);
                            if ($oldestTransactionId !== null) {
                                unset($_SESSION['client_payment_tokens'][$oldestTransactionId]);
                            }
                        }
                        $_SESSION['client_payment_tokens'][$transactionId] = bin2hex(random_bytes(32));
                    }
                    $paymentSubmissionToken = $_SESSION['client_payment_tokens'][$transactionId];
                }
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
                $stageBadgeClass = match ($stage) {
                    'completed' => 'text-bg-success',
                    'cancelled' => 'text-bg-secondary',
                    'reviewing_schedule' => 'text-bg-info text-dark',
                    'payment_in_process' => 'text-bg-warning text-dark',
                    'payment_confirmation' => 'text-bg-info text-dark',
                    default => 'text-bg-primary',
                };
                $transactionPayments = $paymentRowsByTransaction[$transactionId] ?? [];
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
                            $propImage = function_exists('property_representative_image') ? (property_representative_image((string) ($tracking['propertyType'] ?? '')) ?? '') : '';
                        }
                        $hasPropImage = $propImage !== '' && is_file(__DIR__ . '/assets/images/' . $propImage);
                        $propImgSrc = $hasPropImage ? ('assets/images/' . rawurlencode($propImage)) : 'assets/images/representative-residential-01.jpg';
                        ?>
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div class="d-flex align-items-start gap-3">
                                <div class="rounded-3 overflow-hidden flex-shrink-0 border shadow-sm position-relative" style="width: 58px; height: 58px; background: rgba(0,0,0,0.04);">
                                    <img src="<?= htmlspecialchars($propImgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100" style="object-fit: cover;" loading="lazy" onerror="this.onerror=null;this.src='assets/images/representative-residential-01.jpg';">
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                        <span class="small fw-bold text-primary">#KQT-<?= $transactionId ?></span>
                                        <span class="badge rounded-pill <?= $stageBadgeClass ?>"><?= htmlspecialchars($stageLabels[$stage] ?? 'Status unavailable', ENT_QUOTES, 'UTF-8') ?></span>
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
                                            💰 Verified sale price: ₱<?= number_format((float) $tracking['salePrice'], 2) ?>
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
                                            <span class="badge rounded-pill <?= $stageBadgeClass ?> px-2 py-1 small"><?= htmlspecialchars($stageLabels[$stage] ?? $stage, ENT_QUOTES, 'UTF-8') ?></span>
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
                                <?php if ((int)($_GET['open_modal'] ?? 0) === $transactionId && $paymentSubmissionSuccess !== ''): ?>
                                    <div class="alert alert-success alert-dismissible fade show py-2 mb-3 shadow-sm small" role="alert">
                                        <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($paymentSubmissionSuccess, ENT_QUOTES, 'UTF-8') ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php elseif ((int)($_POST['transactionID'] ?? 0) === $transactionId && $paymentSubmissionError !== ''): ?>
                                    <div class="alert alert-danger alert-dismissible fade show py-2 mb-3 shadow-sm small" role="alert">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($paymentSubmissionError, ENT_QUOTES, 'UTF-8') ?>
                                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                    </div>
                                <?php endif; ?>

                                <!-- Property Overview Banner -->
                                <div class="d-flex align-items-center gap-3 p-2 p-sm-3 mb-3 bg-body-tertiary border rounded-3">
                                    <div class="rounded-3 overflow-hidden flex-shrink-0 border shadow-sm" style="width: 64px; height: 64px;">
                                        <img src="<?= htmlspecialchars($propImgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100" style="object-fit: cover;" onerror="this.onerror=null;this.src='assets/images/representative-residential-01.jpg';">
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
                                <?php elseif ($stage === 'viewing_requested'): ?>
                                    <div class="alert alert-primary small py-2 mb-3 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-calendar-event fs-5 text-primary flex-shrink-0 mt-1"></i>
                                        <div>
                                            <strong>Step 1: Viewing Requested</strong>
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
                                                    Step 4: Viewing Completed — Choose Payment Plan
                                                <?php elseif ($stage === 'payment_in_process'): ?>
                                                    Step 5: Payment Processing &amp; Reservation
                                                <?php elseif ($stage === 'document_verification'): ?>
                                                    Step 6: Legal &amp; Document Verification
                                                <?php elseif ($stage === 'payment_confirmation'): ?>
                                                    Step 7: Payment Confirmation &amp; Turnover Clearance
                                                <?php endif; ?>
                                            </strong>
                                            <?php if ($stage === 'viewing_completed'): ?>
                                                <p class="mb-0 mt-1">Your property viewing was successfully conducted! Please select your preferred payment scheme below so your agent can prepare the contract and reservation paperwork.</p>
                                            <?php elseif ($stage === 'payment_in_process'): ?>
                                                <?php if ($isSpotCash): ?>
                                                    <p class="mb-0 mt-1">Spot Cash selected! Document verification is skipped for Spot Cash transactions. Settle the balance to advance directly to final Deed of Absolute Sale (DOAS) preparation and turnover.</p>
                                                <?php else: ?>
                                                    <p class="mb-0 mt-1">Payment processing is active. Settle your reservation deposit / down payment within the 7-day grace period. You can submit offline payment reports below for agent verification.</p>
                                                <?php endif; ?>
                                            <?php elseif ($stage === 'document_verification'): ?>
                                                <p class="mb-0 mt-1">Your Sales Agent is actively verifying property title deeds (TCT/CCT), tax declarations, and government clearance papers.</p>
                                            <?php elseif ($stage === 'payment_confirmation'): ?>
                                                <p class="mb-0 mt-1">All payments and legal paperwork have been verified. Your Sales Agent is finalizing deed notarization and scheduling turnover.</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- 7-Day Reservation & Legal Pathway Guidance -->
                                    <?php if (in_array($stage, ['viewing_completed', 'payment_in_process'], true)): ?>
                                        <div class="p-2 px-3 rounded-3 mb-3 bg-warning-subtle border border-warning small d-flex align-items-center justify-content-between flex-wrap gap-2">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="fs-5">⏳</span>
                                                <div>
                                                    <strong class="text-dark">7-Day Reservation Grace Period:</strong>
                                                    <div class="text-muted" style="font-size: 0.78rem;">Please settle the down payment and required compliance documents within 7 days to secure this parcel and initiate contract preparation.</div>
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
                                            <span>Current milestone: <strong><?= htmlspecialchars($stageLabels[$stage] ?? $stage, ENT_QUOTES, 'UTF-8') ?></strong></span>
                                            <span>Updated: <?= htmlspecialchars($tracking['updatedAt'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Unified Payment & Financing Section -->
                                <?php
                                $currentSchemeKey = (string) ($tracking['paymentMethod'] ?? '');
                                $allSchemes = property_transaction_payment_schemes();
                                $currentSchemeInfo = $allSchemes[$currentSchemeKey] ?? null;
                                $canChooseScheme = !in_array($stage, ['completed', 'cancelled'], true);
                                ?>
                                <div class="border rounded-3 p-3 bg-white mb-3 shadow-none">
                                    <!-- 1. Overarching Financing Scheme -->
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                                        <h4 class="h6 fw-bold mb-0 text-dark">
                                            <i class="bi bi-wallet2 me-1 text-primary"></i> 1. Payment &amp; Financing Scheme
                                        </h4>
                                        <?php if ($currentSchemeInfo !== null): ?>
                                            <span class="badge text-bg-success px-2 py-1"><?= htmlspecialchars($currentSchemeInfo['badge'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="badge text-bg-warning text-dark px-2 py-1">Action needed: Choose Scheme</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($currentSchemeInfo !== null): ?>
                                        <div class="p-3 bg-light rounded-3 border mb-2">
                                            <div class="d-flex align-items-start gap-2">
                                                <span class="fs-4"><?= $currentSchemeInfo['icon'] ?></span>
                                                <div class="flex-grow-1">
                                                    <div class="small text-muted text-uppercase fw-semibold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Your Selected Financing Plan</div>
                                                    <strong class="text-dark fs-6"><?= htmlspecialchars($currentSchemeInfo['label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <p class="small text-muted mb-0 mt-1"><?= htmlspecialchars($currentSchemeInfo['desc'], ENT_QUOTES, 'UTF-8') ?></p>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if ($canChooseScheme): ?>
                                            <div class="mb-2">
                                                <button class="btn btn-sm btn-outline-secondary py-1 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#changeSchemeCollapse<?= $transactionId ?>" aria-expanded="false">
                                                    <i class="bi bi-pencil-square me-1"></i> Change Financing Plan
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <p class="small text-muted mb-3">
                                            Choose how you plan to purchase this property. Your Sales Agent will coordinate the appropriate legal contract (e.g. Deed of Absolute Sale for Spot Cash, or Contract to Sell for Installment) based on your choice.
                                        </p>
                                    <?php endif; ?>

                                    <?php if ($canChooseScheme): ?>
                                        <div class="<?= $currentSchemeInfo !== null ? 'collapse mb-3' : 'mb-3' ?>" id="changeSchemeCollapse<?= $transactionId ?>">
                                            <form method="POST" action="client_dashboard.php">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="transactionID" value="<?= $transactionId ?>">

                                                <div class="d-grid gap-2 mb-3">
                                                    <?php foreach ($allSchemes as $schemeKey => $scheme): ?>
                                                        <label class="border rounded-3 p-3 bg-light d-flex align-items-start gap-3 position-relative <?= $currentSchemeKey === $schemeKey ? 'border-primary bg-primary-subtle' : '' ?>" style="cursor: pointer;">
                                                            <input type="radio" name="paymentScheme" value="<?= htmlspecialchars($schemeKey, ENT_QUOTES, 'UTF-8') ?>" class="form-check-input mt-1 flex-shrink-0" <?= $currentSchemeKey === $schemeKey ? 'checked' : '' ?> required>
                                                            <div>
                                                                <div class="fw-bold text-dark d-flex align-items-center gap-2">
                                                                    <span><?= $scheme['icon'] ?></span>
                                                                    <span><?= htmlspecialchars($scheme['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                                                </div>
                                                                <div class="small text-muted mt-1"><?= htmlspecialchars($scheme['desc'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            </div>
                                                        </label>
                                                    <?php endforeach; ?>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label small fw-semibold mb-1" for="clientNote-<?= $transactionId ?>">Note for Sales Agent <span class="text-muted fw-normal">(optional)</span></label>
                                                    <input type="text" id="clientNote-<?= $transactionId ?>" name="clientPaymentNote" class="form-control form-control-sm" maxlength="255" placeholder="e.g. Prefer 20% down payment, balance via bank transfer">
                                                </div>

                                                <button type="submit" name="choose_payment_scheme" class="btn btn-sm btn-primary fw-semibold px-3 py-2 shadow-sm">
                                                    💾 Save Preferred Financing Plan
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>

                                    <hr class="my-3 text-secondary-subtle">

                                    <!-- 2. Verified Payment Records & Offline Receipts -->
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <div>
                                            <h5 class="h6 fw-bold mb-0 text-dark"><i class="bi bi-receipt me-1 text-secondary"></i> 2. Verified Payment Records &amp; Receipts</h5>
                                            <div class="small text-muted" style="font-size: 0.75rem;">Official installments, down payments, and reservation deposits recorded for this property.</div>
                                        </div>
                                        <?php if ($transactionPayments !== []): ?>
                                            <span class="badge text-bg-light border text-muted small"><?= count($transactionPayments) ?> record<?= count($transactionPayments) > 1 ? 's' : '' ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($transactionPayments !== []): ?>
                                        <div class="d-grid gap-2">
                                            <?php foreach ($transactionPayments as $payment): ?>
                                                <div class="border rounded-2 p-2 bg-light small">
                                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                        <div>
                                                            <strong class="text-dark fs-6">₱<?= number_format((float) $payment['amount'], 2) ?></strong>
                                                            <span class="text-dark fw-medium">via <?= htmlspecialchars($payment['paymentMethod'], ENT_QUOTES, 'UTF-8') ?></span>
                                                            <div class="text-muted small"><?= htmlspecialchars($payment['sourceLabel'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <?php if (!empty($payment['referenceNumber'])): ?>
                                                                <div class="text-muted small mt-1">Reference / OR: <code><?= htmlspecialchars($payment['referenceNumber'], ENT_QUOTES, 'UTF-8') ?></code></div>
                                                            <?php endif; ?>
                                                            <?php if (!empty($payment['reviewNote'])): ?>
                                                                <div class="text-dark mt-1 bg-white p-2 rounded border small">
                                                                    <i class="bi bi-chat-left-text me-1 text-primary"></i> <?= htmlspecialchars($payment['reviewNote'], ENT_QUOTES, 'UTF-8') ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <span class="badge <?= $payment['status'] === 'confirmed' ? 'text-bg-success' : ($payment['status'] === 'rejected' ? 'text-bg-danger' : 'text-bg-warning') ?>">
                                                            <?= htmlspecialchars(ucfirst($payment['status']), ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    </div>
                                                    <div class="text-muted small mt-1" style="font-size: 0.72rem;">
                                                        Recorded on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($payment['submittedAt'])), ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="p-3 bg-light rounded text-muted small text-center">
                                            <i class="bi bi-receipt fs-4 d-block mb-1 text-secondary"></i>
                                            No payment records yet. Verified paperwork and payments will appear here.
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($canSubmitPayment): ?>
                                        <form method="POST" class="mt-3 p-3 border rounded-3 bg-light" data-confirm="Submit this offline payment report for staff verification? This does not transfer money.">
                                            <h6 class="fw-bold mb-1 text-dark"><i class="bi bi-cloud-arrow-up me-1 text-primary"></i> Report an Offline Payment / Deposit</h6>
                                            <p class="small text-muted mb-3" style="font-size: 0.78rem;">If you paid an installment or reservation deposit via bank wire, check, or cash deposit, report the details here for your agent to verify.</p>
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="transactionID" value="<?= $transactionId ?>">
                                            <input type="hidden" name="payment_submission_token" value="<?= htmlspecialchars($paymentSubmissionToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <div class="row g-2">
                                                <div class="col-sm-6">
                                                    <label class="form-label small fw-semibold mb-1" for="client-payment-amount-<?= $transactionId ?>">Amount paid (₱)</label>
                                                    <input id="client-payment-amount-<?= $transactionId ?>" type="number" name="paymentAmount" class="form-control form-control-sm" min="0.01" max="9999999999.99" step="0.01" required>
                                                </div>
                                                <div class="col-sm-6">
                                                    <label class="form-label small fw-semibold mb-1" for="client-payment-method-<?= $transactionId ?>">Remittance Channel</label>
                                                    <select id="client-payment-method-<?= $transactionId ?>" name="paymentMethod" class="form-select form-select-sm" required>
                                                        <option value="">Choose channel</option>
                                                        <option value="Bank Transfer">🏦 Bank Wire / Online Transfer</option>
                                                        <option value="Cash">💵 Cash Deposit</option>
                                                        <option value="Check">📝 Manager's Check / Cheque</option>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small fw-semibold mb-1" for="client-payment-reference-<?= $transactionId ?>">Deposit Slip / Reference / OR # (optional)</label>
                                                    <input id="client-payment-reference-<?= $transactionId ?>" type="text" name="referenceNumber" class="form-control form-control-sm" maxlength="100" placeholder="e.g. Bank Ref # or Branch Deposit Receipt">
                                                </div>
                                            </div>
                                            <button type="submit" name="submit_client_payment" value="1" class="btn btn-sm btn-primary mt-3">Submit Receipt for Staff Verification</button>
                                        </form>
                                    <?php endif; ?>
                                </div>

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
                                                            $eventTitle = $stageLabels[$event['stage']] ?? 'Status updated';
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
    <?php if ($appointmentRows !== []): ?>
        <?php foreach ($appointmentRows as $appt): ?>
            <?php $status = $appt['status']; ?>
            <article class="border rounded-3 p-3 p-lg-4 mb-3">
                <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                    <div>
                        <h3 class="h5 fw-bold mb-1"><?= htmlspecialchars($appt['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <div class="small text-muted"><?= htmlspecialchars(!empty($appt['municipalityName']) ? $appt['municipalityName'] . ', ' . $appt['provinceName'] : $appt['location'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($appt['barangay'])): ?>, <?= htmlspecialchars($appt['barangay'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></div>
                    </div>
                    <span class="badge rounded-pill <?= $status === 'Approved' ? 'text-bg-success' : ($status === 'Cancelled' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="row g-3 small">
                    <div class="col-sm-6"><span class="text-muted">Viewing date and time</span><div class="fw-semibold"><?= htmlspecialchars($appt['appointmentDate'], ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars(substr($appt['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></div></div>
                    <div class="col-sm-6"><span class="text-muted">Reference number</span><div class="fw-semibold">#KQ-<?= 1000 + (int) $appt['appointmentID'] ?></div></div>
                </div>
            </article>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="text-muted">You do not have any viewing requests yet. <a href="index.php">Browse available properties</a>.</div>
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