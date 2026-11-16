<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
require_once 'includes/transaction_tracking.php';
require_once 'includes/agent_feedback.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (normalized_user_role($_SESSION['user'] ?? null) !== 'Client') {
    header('Location: client_login.php');
    exit();
}

ensure_agent_reviews_table($conn);

include 'includes/header.php';
?>
<style>
    body {
        background: radial-gradient(circle at top, rgba(13, 37, 43, 0.85), rgba(6, 15, 19, 1) 52%);
    }

    .kq-client-shell {
        max-width: 1220px;
        margin: 0 auto;
        padding: 1.2rem 0 2rem;
    }

    .kq-dashboard-hero {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        padding: 1rem 1.2rem;
        margin-bottom: 1.5rem;
        border: 1px solid rgba(212, 175, 110, 0.28);
        border-radius: 1.1rem;
        background: linear-gradient(135deg, rgba(14, 29, 39, 0.92), rgba(25, 47, 58, 0.82));
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.14);
    }

    .kq-dashboard-brand {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 86px;
        padding: 0.5rem 0.8rem;
        border: 1px solid rgba(212, 175, 110, 0.4);
        border-radius: 999px;
        background: rgba(212, 175, 110, 0.12);
        color: #f2d79f;
        font-size: 0.7rem;
        font-weight: 800;
        letter-spacing: 0.2em;
        text-transform: uppercase;
    }

    .page-eyebrow {
        letter-spacing: .15em;
        color: #d9b87d;
    }

    .page-title {
        color: #f7f5f0;
        letter-spacing: -.05em;
    }

    /* Subtitle on the dark shell header outside cards */
    .kq-client-shell > div.mb-4 > .text-muted {
        color: rgba(245, 238, 227, 0.85) !important;
    }

    /* Turn white/light text into black inside all cards, timelines, and panels */
    .card,
    .card p,
    .card span,
    .card div,
    .card time,
    .card td,
    .card th,
    .kq-tracking-card,
    .kq-tracking-event {
        color: #000000;
    }

    .card .text-muted,
    .card .small.text-muted,
    .card time.text-muted,
    .card p.text-muted,
    .card span.text-muted,
    .card div.text-muted,
    .kq-tracking-card .text-muted,
    .kq-tracking-event-content .text-muted,
    .kq-tracking-timeline .text-muted,
    .kq-tracking-timeline time,
    .kq-tracking-event time,
    .kq-tracking-event p,
    .kq-tracking-event div {
        color: #000000 !important;
    }

    .card {
        border: 1px solid rgba(20, 34, 41, 0.08) !important;
        border-radius: 1.2rem !important;
        background: #ffffff;
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.12);
        color: #000000;
    }

    .kq-panel-header {
        border-bottom: 1px solid rgba(19, 45, 66, 0.08);
        padding-bottom: .75rem;
    }

    .kq-badge-soft {
        background: rgba(17, 53, 71, 0.08);
        color: #123b5a;
    }

    .kq-tracking-card,
    article.border.rounded-3,
    .table-responsive {
        border-color: rgba(19, 45, 66, 0.08) !important;
        border-radius: 1rem !important;
    }

    .btn-primary {
        background: linear-gradient(180deg, #173d56, #102334);
        border-color: #173d56;
    }

    .btn-primary:hover,
    .btn-primary:focus {
        background: linear-gradient(180deg, #1d486b, #132a3f);
        border-color: #1d486b;
    }

    .btn-outline-primary {
        border-color: rgba(17, 53, 71, 0.3);
        color: #123b5a;
    }

    .btn-outline-primary:hover,
    .btn-outline-primary:focus {
        background: rgba(17, 53, 71, 0.05);
        color: #123b5a;
    }

    .alert-info {
        background: rgba(17, 53, 71, 0.06);
        border-color: rgba(17, 53, 71, 0.12);
        color: #1b3f58;
    }

    .alert-success {
        background: rgba(25, 98, 73, 0.08);
        border-color: rgba(25, 98, 73, 0.18);
        color: #164b3b;
    }

    .alert-danger {
        background: rgba(166, 52, 52, 0.08);
        border-color: rgba(166, 52, 52, 0.18);
        color: #8f2f2f;
    }

    @media (max-width: 767.98px) {
        .kq-client-shell {
            padding-top: .75rem;
        }
    }
</style>
<?php
$clientID = (int) $_SESSION['user']['userID'];
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
$appointments = $appointmentStmt->get_result();
$inquiryStmt = $conn->prepare(
    "SELECT i.*, p.title
     FROM feedbackinquiries i
     JOIN properties p ON i.propertyID = p.propertyID
     WHERE i.clientID = ?
     ORDER BY i.inquiryID DESC
     LIMIT 100"
);
$inquiryStmt->bind_param('i', $clientID);
$inquiryStmt->execute();
$inquiries = $inquiryStmt->get_result();
$trackingStmt = $conn->prepare(
    "SELECT t.transactionID, t.propertyID, t.currentStage, t.salePrice, t.paymentMethod, t.createdAt, t.updatedAt,
            p.title, a.appointmentDate, a.appointmentTime,
            l.municipalityName, l.provinceName, p.location,
            ar.reviewID, ar.rating AS clientRating,
            COALESCE(agent.fullname, (
                SELECT u2.fullname FROM property_transaction_events e
                JOIN users u2 ON u2.userID = e.changedBy
                WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
            ), 'Assigned Agent') AS agentName,
            COALESCE(ar.agentID, (
                SELECT e.changedBy FROM property_transaction_events e
                WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
            )) AS agentID
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     LEFT JOIN agent_reviews ar ON ar.transactionID = t.transactionID
     LEFT JOIN users agent ON agent.userID = ar.agentID
     WHERE t.clientID = ?
     ORDER BY t.updatedAt DESC, t.transactionID DESC
     LIMIT 100"
);
$trackingStmt->bind_param('i', $clientID);
$trackingStmt->execute();
$trackingRows = $trackingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
    $payments = $conn->query(
        "SELECT paymentID, transactionID, amount, paymentMethod, referenceNumber, status,
                submittedAt, reviewedAt, reviewNote
         FROM property_transaction_payments
         WHERE transactionID IN ($transactionIdList)
         ORDER BY submittedAt DESC, paymentID DESC"
    );
    while ($payment = $payments->fetch_assoc()) {
        $paymentRowsByTransaction[(int) $payment['transactionID']][] = $payment;
    }
}

$reviewMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_agent_review'])) {
    $isAjax = !empty($_POST['is_ajax']);
    if (!has_valid_csrf_token()) {
        $reviewMessage = 'Your session expired. Refresh the page and try again.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $reviewMessage]);
            exit();
        }
    } else {
        $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
        $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
        $comment = is_string($_POST['comment'] ?? null) ? trim($_POST['comment']) : '';
        $comment = mb_substr($comment, 0, 1000, 'UTF-8');

        if ($transactionId === false || $transactionId <= 0 || $rating === false || $rating < 1 || $rating > 5) {
            $reviewMessage = 'Choose a valid rating before submitting feedback.';
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $reviewMessage]);
                exit();
            }
        } else {
            $reviewCheck = $conn->prepare(
                "SELECT transactionID FROM property_transactions WHERE transactionID = ? AND clientID = ? AND currentStage = 'completed'"
            );
            $reviewCheck->bind_param('ii', $transactionId, $clientID);
            $reviewCheck->execute();
            $reviewableTransaction = $reviewCheck->get_result()->fetch_assoc();

            if (!$reviewableTransaction) {
                $reviewMessage = 'This completed transaction is not eligible for a review.';
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $reviewMessage]);
                    exit();
                }
            } else {
                $latestAgent = $conn->prepare(
                    "SELECT changedBy FROM property_transaction_events WHERE transactionID = ? AND changedBy IS NOT NULL ORDER BY createdAt DESC, eventID DESC LIMIT 1"
                );
                $latestAgent->bind_param('i', $transactionId);
                $latestAgent->execute();
                $latestAgentResult = $latestAgent->get_result()->fetch_assoc();
                $agentId = $latestAgentResult !== null ? (int) $latestAgentResult['changedBy'] : null;

                $insertReview = $conn->prepare(
                    "INSERT INTO agent_reviews (transactionID, clientID, agentID, rating, comment)
                     VALUES (?, ?, ?, ?, ?)"
                );
                $insertReview->bind_param('iiiss', $transactionId, $clientID, $agentId, $rating, $comment);
                try {
                    $insertReview->execute();
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => true, 'message' => 'Thanks for the feedback', 'rating' => $rating]);
                        exit();
                    }
                    header('Location: client_dashboard.php?review_submitted=1#client-reviews');
                    exit();
                } catch (mysqli_sql_exception $exception) {
                    if ($exception->getCode() === 1062) {
                        $reviewMessage = 'You have already provided feedback for this completed transaction.';
                    } else {
                        error_log('KingQuiry agent review insert failed: ' . $exception->getMessage());
                        $reviewMessage = 'The review could not be saved. Please try again.';
                    }
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $reviewMessage]);
                        exit();
                    }
                }
            }
        }
    }
}

$completedReviewRows = $conn->query(
    "SELECT t.transactionID, t.updatedAt, p.title, p.location, l.municipalityName, l.provinceName,
            ar.reviewID, ar.rating, ar.comment, ar.createdAt AS reviewDate,
            COALESCE(agent.fullname, (
                SELECT u2.fullname FROM property_transaction_events e
                JOIN users u2 ON u2.userID = e.changedBy
                WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
            ), 'Assigned Agent') AS agentName,
            COALESCE(ar.agentID, (
                SELECT e.changedBy FROM property_transaction_events e
                WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL
                ORDER BY e.createdAt DESC, e.eventID DESC LIMIT 1
            )) AS targetAgentID
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     LEFT JOIN agent_reviews ar ON ar.transactionID = t.transactionID
     LEFT JOIN users agent ON agent.userID = ar.agentID
     WHERE t.clientID = $clientID AND t.currentStage = 'completed'
     ORDER BY t.updatedAt DESC"
);
$stageLabels = property_transaction_stages();
$progressStages = [
    'inquiry_received',
    'contacting_client',
    'viewing_requested',
    'viewing_confirmed',
    'viewing_completed',
    'payment_in_process',
    'document_verification',
    'payment_confirmation',
    'completed',
];
?>

<div class="kq-client-shell">
    <div class="kq-dashboard-hero">
        <span class="kq-dashboard-brand">4 KINGS</span>
        <div>
            <div class="page-eyebrow">Your account</div>
            <div class="text-white fw-semibold mb-0">Luxury client portal</div>
        </div>
    </div>
    <div class="mb-4">
        <div class="page-eyebrow">Your account</div>
        <h1 class="page-title h2 mb-1">Client dashboard</h1>
        <p class="text-muted mb-0">Welcome, <?= htmlspecialchars($_SESSION['user']['fullname'], ENT_QUOTES, 'UTF-8') ?>. Follow your property requests in one place.</p>
    </div>

<?php if (!empty($reviewMessage)): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($reviewMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (isset($_GET['review_submitted'])): ?>
    <div class="alert alert-success" role="status">Thank you for your feedback. Your agent rating has been recorded.</div>
<?php endif; ?>

<?php
$clientDashboardNotifs = get_user_notifications($conn, $clientID, 6);
$clientUnreadCount = get_user_unread_notification_count($conn, $clientID);
?>
<section class="card p-3 p-lg-4 mb-4" id="notifications">
    <div class="kq-panel-header mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="page-eyebrow">Activity &amp; Alerts</div>
            <h2 class="h4 fw-bold mb-0 text-dark">Property updates &amp; notifications</h2>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($clientUnreadCount > 0): ?>
                <span class="badge bg-danger rounded-pill px-2 py-1 small"><?= $clientUnreadCount ?> unread</span>
                <form method="POST" action="notifications_action.php" class="m-0">
                    <input type="hidden" name="action" value="mark_all_read">
                    <input type="hidden" name="redirect" value="client_dashboard.php#notifications">
                    <button type="submit" class="btn btn-sm btn-outline-primary fw-semibold">Mark all as read</button>
                </form>
            <?php else: ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 small">All caught up</span>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($clientDashboardNotifs)): ?>
        <div class="list-group list-group-flush border rounded-3 overflow-hidden">
            <?php foreach ($clientDashboardNotifs as $cNotif):
                $isUnread = !(bool) $cNotif['isRead'];
                $link = !empty($cNotif['link']) ? $cNotif['link'] : '#transaction-tracking';
            ?>
                <a href="notifications_action.php?action=read_and_go&id=<?= (int)$cNotif['notificationID'] ?>&to=<?= urlencode($link) ?>" class="list-group-item list-group-item-action p-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 <?= $isUnread ? 'bg-primary-subtle border-start border-4 border-primary' : 'bg-white' ?>" style="text-decoration: none;">
                    <div class="d-flex align-items-start gap-3">
                        <div class="rounded-circle p-2 d-flex align-items-center justify-content-center <?= $isUnread ? 'bg-primary text-white' : 'bg-light text-secondary border' ?>" style="width: 38px; height: 38px; flex-shrink: 0;">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                        </div>
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="fw-bold <?= $isUnread ? 'text-primary' : 'text-dark' ?>" style="font-size: 0.95rem;"><?= htmlspecialchars($cNotif['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($isUnread): ?>
                                    <span class="badge bg-primary small" style="font-size: 0.65rem;">New</span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-dark fw-medium mb-1"><?= htmlspecialchars($cNotif['message'], ENT_QUOTES, 'UTF-8') ?></div>
                            <time class="small text-muted" datetime="<?= htmlspecialchars(date('c', strtotime($cNotif['createdAt'])), ENT_QUOTES, 'UTF-8') ?>" style="font-size: 0.75rem;">
                                <?= htmlspecialchars(date('M j, Y g:i A', strtotime($cNotif['createdAt'])), ENT_QUOTES, 'UTF-8') ?>
                            </time>
                        </div>
                    </div>
                    <span class="btn btn-sm btn-outline-primary align-self-start align-self-sm-center text-nowrap mt-2 mt-sm-0">View details &rarr;</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="p-3 bg-light rounded-3 text-muted small">
            No updates yet. As soon as your sales agent updates your viewing, verifies documents, or confirms payments, notifications will be displayed here.
        </div>
    <?php endif; ?>
</section>

<section class="card p-3 p-lg-4 mb-4" id="transaction-tracking">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Sales progress</div>
            <h2 class="h4 fw-bold mb-0">Transaction tracking</h2>
        </div>
        <span class="kq-badge-soft">Interactive Property Tracker</span>
    </div>
    <div class="alert alert-info small mb-4" role="note">Click any property below to open its tracking window and view its <strong>Transaction Pipeline</strong>, <strong>Overall Progress</strong>, <strong>Payments &amp; Documents</strong>, and <strong>Transaction Updates</strong>.</div>
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
                $stageBadgeClass = match ($stage) {
                    'completed' => 'text-bg-success',
                    'cancelled' => 'text-bg-secondary',
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
                        
                        <?php if ($stage === 'completed' && empty($tracking['reviewID'])): ?>
                            <!-- TOP AGENT RATING BANNER -->
                            <div class="kq-top-rating-banner p-2 px-3 rounded-3 mb-3 bg-warning-subtle border border-warning d-flex align-items-center justify-content-between flex-wrap gap-2"
                                 id="topRatingBanner<?= $transactionId ?>"
                                 onclick="event.stopPropagation();"
                                 data-txid="<?= $transactionId ?>"
                                 role="region"
                                 aria-label="Rate your sales agent">
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="fs-5">⭐</span>
                                    <div>
                                        <div class="small fw-bold text-dark">
                                            Rate your sales agent (<?= htmlspecialchars($tracking['agentName'] ?? 'Assigned Agent', ENT_QUOTES, 'UTF-8') ?>):
                                        </div>
                                        <div class="small text-muted" style="font-size: 0.76rem;">Property purchase completed! Click 1–5 stars to rate:</div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="kq-top-star-picker d-inline-flex gap-1"
                                         data-txid="<?= $transactionId ?>"
                                         data-agent="<?= htmlspecialchars($tracking['agentName'] ?? 'Assigned Agent', ENT_QUOTES, 'UTF-8') ?>"
                                         data-csrf="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                        <?php for ($s = 1; $s <= 5; $s++): ?>
                                            <button type="button"
                                                    class="btn p-0 border-0 bg-transparent kq-top-star-btn"
                                                    data-rating="<?= $s ?>"
                                                    data-txid="<?= $transactionId ?>"
                                                    aria-label="Rate <?= $s ?> out of 5 stars"
                                                    title="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>">★</button>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="badge text-bg-light border text-muted small py-1 px-2 d-none d-sm-inline kq-top-rating-label" id="topRatingLabel<?= $transactionId ?>">
                                        Click a star
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div class="d-flex align-items-start gap-3">
                                <div class="rounded-3 p-3 d-flex align-items-center justify-content-center bg-primary-subtle text-primary flex-shrink-0" style="width: 52px; height: 52px;">
                                    <i class="bi bi-building fs-4"></i>
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
                                            <span>· <i class="bi bi-calendar-event me-1"></i>Viewing: <?= htmlspecialchars($tracking['appointmentDate'] . ' at ' . substr($tracking['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
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
                                <!-- Status / Celebration Banner -->
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
                                        <?php if (empty($tracking['reviewID'])): ?>
                                            <a href="#client-reviews" data-bs-dismiss="modal" class="btn btn-sm btn-warning text-dark fw-bold d-inline-flex align-items-center gap-1 shadow-sm modal-rate-btn-<?= $transactionId ?>">
                                                <span>⭐</span> Rate sales agent
                                            </a>
                                        <?php else: ?>
                                            <span class="badge bg-success text-white py-2 px-3 small modal-rate-btn-<?= $transactionId ?>">
                                                ★ <?= (int) $tracking['clientRating'] ?>/5 Rated
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif (in_array($stage, ['viewing_completed', 'payment_in_process', 'document_verification', 'payment_confirmation'], true)): ?>
                                    <div class="alert alert-info small py-2 mb-3 d-flex align-items-start gap-2" role="status">
                                        <i class="bi bi-shield-check fs-5 text-primary flex-shrink-0 mt-1"></i>
                                        <div>
                                            <strong>Viewing completed — Agent processing in progress:</strong>
                                            <?php if ($stage === 'payment_in_process'): ?>
                                                <?php if ($isSpotCash): ?>
                                                    Your site visit is complete. Your sales agent is processing your Spot Cash payment. Document verification is skipped for Spot Cash transactions, and your transaction advances directly to Payment Confirmation and deed preparation.
                                                <?php else: ?>
                                                    Your site visit is complete. Your sales agent is actively inspecting and recording payment and paperwork details.
                                                <?php endif; ?>
                                            <?php elseif ($stage === 'document_verification'): ?>
                                                Your sales agent is verifying required property legal documents, certificates, and clearance papers.
                                            <?php elseif ($stage === 'payment_confirmation'): ?>
                                                Paperwork and payments have been verified. Your sales agent is finalizing transaction turnover.
                                            <?php else: ?>
                                                Your property viewing has concluded. Your sales agent is initiating the paperwork and payment processing.
                                            <?php endif; ?>
                                            <div class="mt-1 text-dark fw-semibold">No action needed from you. The agent will handle all remaining steps until the transaction is successfully completed.</div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- 1. Transaction Pipeline Stepper -->
                                <?php if ($stage !== 'cancelled'): ?>
                                    <div class="border rounded-3 p-3 bg-light mb-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="small fw-bold text-dark"><i class="bi bi-diagram-3 me-1"></i> Transaction Pipeline</span>
                                            <span class="badge text-bg-light border text-dark small fw-semibold">Stage <?= $stageIndex !== false ? ($stageIndex + 1) : 0 ?> of <?= count($txProgressStages) ?></span>
                                        </div>
                                        <div class="kq-stage-stepper d-flex flex-wrap gap-2">
                                            <?php foreach ($txProgressStages as $idx => $stgKey):
                                                $isPassed = ($stageIndex !== false && $idx < $stageIndex) || $stage === 'completed';
                                                $isCurrent = ($stageIndex !== false && $idx === $stageIndex) && $stage !== 'completed';
                                                $stgName = $stageLabels[$stgKey] ?? $stgKey;
                                            ?>
                                                <div class="kq-step-item d-flex align-items-center gap-1 px-2 py-1 rounded small <?= $isCurrent ? 'bg-primary text-white fw-bold shadow-sm' : ($isPassed ? 'bg-success text-white' : 'bg-white border text-dark') ?>" style="font-size: 0.78rem;">
                                                    <?php if ($isPassed): ?>
                                                        <i class="bi bi-check-circle-fill"></i>
                                                    <?php elseif ($isCurrent): ?>
                                                        <i class="bi bi-arrow-right-circle-fill"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-circle text-muted"></i>
                                                    <?php endif; ?>
                                                    <span><?= ($idx + 1) ?>. <?= htmlspecialchars($stgName, ENT_QUOTES, 'UTF-8') ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($stageIndex !== false && $stageIndex >= 4 && $stage !== 'completed'): ?>
                                            <div class="small text-dark mt-2 pt-2 border-top">
                                                <i class="bi bi-info-circle text-primary me-1"></i>
                                                <strong>Agent at work:</strong> Property viewing is complete. Your sales agent is handling all paperwork, verification, and payment confirmation. Please wait for the final completion update.
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- 2. Overall Progress -->
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

                                <!-- 3. Payment and Document / Paperwork -->
                                <div class="border rounded-3 p-3 bg-white mb-3 shadow-none">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h4 class="h6 fw-bold mb-0 text-dark"><i class="bi bi-credit-card me-1"></i> Payments &amp; Paperwork</h4>
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
                                </div>

                                <!-- 4. Transaction Updates -->
                                <div class="border rounded-3 p-3 bg-white mb-2 shadow-none">
                                    <h4 class="h6 fw-bold mb-3 text-dark"><i class="bi bi-clock-history me-1"></i> Transaction Updates</h4>
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
                                                            <span class="fw-bold text-dark" style="color: #000000 !important; font-size: 0.95rem;"><?= htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                                            <time class="small fw-semibold text-dark" style="color: #212529 !important;" datetime="<?= htmlspecialchars(date('c', strtotime($event['createdAt'])), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($event['createdAt'])), ENT_QUOTES, 'UTF-8') ?></time>
                                                        </div>
                                                        <?php if ($eventIndex === 0): ?><div class="small text-primary fw-semibold mt-1">Latest update</div><?php endif; ?>
                                                        <?php if (!empty($event['changedByName'])): ?><div class="small fw-semibold text-dark mt-1" style="color: #212529 !important;">Updated by <?= htmlspecialchars($event['changedByName'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                                        <?php if (!empty($event['note'])): ?><p class="small text-dark text-break mb-0 mt-1 fw-normal" style="color: #000000 !important;"><?= htmlspecialchars($event['note'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                    <?php else: ?>
                                        <p class="small text-dark mb-0">No tracking updates have been recorded yet.</p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="modal-footer bg-light p-2 justify-content-between">
                                <a href="property_summary_pdf.php?property_id=<?= (int)$tracking['propertyID'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-file-earmark-pdf me-1"></i> Download summary PDF
                                </a>
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="rounded-3 border bg-light p-3 p-lg-4">
            <h3 class="h6 fw-bold mb-2">No property requests to track yet</h3>
            <p class="text-muted mb-3">Send an inquiry or request a property viewing to create a tracking reference. The sales team will add dated updates here as your request moves forward.</p>
            <a href="index.php" class="btn btn-primary">Browse properties</a>
        </div>
    <?php endif; ?>
</section>

<section class="card p-3 p-lg-4 mb-4">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Appointments</div>
            <h2 class="h4 fw-bold mb-0">Viewing requests</h2>
        </div>
    </div>
    <?php if ($appointments && $appointments->num_rows > 0): ?>
        <?php while($appt = $appointments->fetch_assoc()): ?>
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
                    <div class="col-sm-6"><span class="text-muted">Requested viewing</span><div class="fw-semibold"><?= htmlspecialchars($appt['appointmentDate'], ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars(substr($appt['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></div></div>
                    <div class="col-sm-6"><span class="text-muted">Reference number</span><div class="fw-semibold">#KQ-<?= 1000 + (int) $appt['appointmentID'] ?></div></div>
                </div>
                <div class="mt-3">
                    <a href="property_summary_pdf.php?property_id=<?= (int) $appt['propertyID'] ?>&appointment_id=<?= (int) $appt['appointmentID'] ?>" target="_blank" class="btn btn-outline-primary btn-sm">Download summary PDF</a>
                </div>
            </article>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="text-muted">You do not have any viewing requests yet. <a href="index.php">Browse available properties</a>.</div>
    <?php endif; ?>
</section>

<section class="card p-3 p-lg-4 mb-4" id="client-reviews">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Experience review</div>
            <h2 class="h4 fw-bold mb-0">Rate your sales experience</h2>
        </div>
    </div>
    <?php if ($completedReviewRows && $completedReviewRows->num_rows > 0): ?>
        <div class="row g-3">
            <?php while ($completedReview = $completedReviewRows->fetch_assoc()): ?>
                <?php $completedLocation = !empty($completedReview['municipalityName']) ? $completedReview['municipalityName'] . ', ' . $completedReview['provinceName'] : $completedReview['location']; ?>
                <div class="col-lg-6">
                    <article class="border rounded-3 p-3 h-100">
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <div>
                                <div class="small text-primary fw-semibold">#KQT-<?= (int) $completedReview['transactionID'] ?></div>
                                <h3 class="h6 fw-bold mb-1"><?= htmlspecialchars($completedReview['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <div class="small text-muted"><?= htmlspecialchars($completedLocation, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <?php if (!empty($completedReview['reviewID'])): ?>
                                <span class="badge rounded-pill text-bg-success">Submitted</span>
                            <?php else: ?>
                                <span class="badge rounded-pill text-bg-light">Awaiting review</span>
                            <?php endif; ?>
                        </div>

                        <div class="mb-2 small">
                            <span class="text-muted">Assigned Sales Agent:</span>
                            <strong class="text-dark"><i class="bi bi-person-badge text-primary me-1"></i><?= htmlspecialchars($completedReview['agentName'], ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>

                        <?php if (!empty($completedReview['reviewID'])): ?>
                            <div class="mb-2 d-flex align-items-center gap-2">
                                <div class="fs-5 lh-1" style="color: #f59e0b;" aria-label="Rated <?= (int) $completedReview['rating'] ?> out of 5 stars">
                                    <?php for ($starIndex = 1; $starIndex <= 5; $starIndex++): ?>
                                        <?= $starIndex <= (int) $completedReview['rating'] ? '★' : '☆' ?>
                                    <?php endfor; ?>
                                </div>
                                <span class="badge text-bg-warning text-dark fw-bold"><?= (int) $completedReview['rating'] ?> / 5 Stars</span>
                            </div>
                            <div class="small text-muted mb-2">Reviewed on <?= htmlspecialchars(date('M j, Y', strtotime((string) $completedReview['reviewDate'])), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($completedReview['comment'])): ?>
                                <p class="mb-0 text-break small bg-light p-2 rounded-2"><?= htmlspecialchars($completedReview['comment'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php else: ?>
                                <p class="mb-0 text-muted small">No written comment provided.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST" class="mt-2 review-rating-form" data-txid="<?= (int) $completedReview['transactionID'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="transactionID" value="<?= (int) $completedReview['transactionID'] ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold d-block mb-1">Rate your sales agent (1 to 5 stars) <span class="text-danger">*</span></label>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <div class="star-rating-container d-inline-flex gap-1" id="star-box-<?= (int) $completedReview['transactionID'] ?>" style="font-size: 1.6rem; cursor: pointer; user-select: none;">
                                            <?php for ($starIndex = 1; $starIndex <= 5; $starIndex++): ?>
                                                <span class="star-rating-btn" data-rating="<?= $starIndex ?>" data-txid="<?= (int) $completedReview['transactionID'] ?>" style="color: #cbd5e1; transition: color 0.15s ease;" title="<?= $starIndex ?> star<?= $starIndex > 1 ? 's' : '' ?>">★</span>
                                            <?php endfor; ?>
                                        </div>
                                        <input type="hidden" name="rating" id="rating-val-<?= (int) $completedReview['transactionID'] ?>" value="" required>
                                        <span class="badge text-bg-light border text-muted small py-1 px-2" id="rating-label-<?= (int) $completedReview['transactionID'] ?>">
                                            Click a star to rate
                                        </span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold" for="agent-review-comment-<?= (int) $completedReview['transactionID'] ?>">Your feedback / comments</label>
                                    <textarea id="agent-review-comment-<?= (int) $completedReview['transactionID'] ?>" name="comment" class="form-control form-control-sm" rows="3" maxlength="1000" placeholder="Share your experience working with <?= htmlspecialchars($completedReview['agentName'], ENT_QUOTES, 'UTF-8') ?> on your purchase."></textarea>
                                </div>
                                <button type="submit" name="submit_agent_review" class="btn btn-warning btn-sm text-dark fw-bold d-inline-flex align-items-center gap-1 shadow-sm">
                                    <span>⭐</span> Submit Agent Rating
                                </button>
                            </form>
                        <?php endif; ?>
                    </article>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="text-muted">No completed sales are available for rating yet.</div>
    <?php endif; ?>
</section>

<section class="card p-3 p-lg-4">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Messages</div>
            <h2 class="h4 fw-bold mb-0">Your property inquiries</h2>
        </div>
    </div>
    <?php if ($inquiries && $inquiries->num_rows > 0): ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Property</th><th>Your message</th><th>Sent</th><th>Status</th></tr></thead>
                <tbody>
                <?php while ($inquiry = $inquiries->fetch_assoc()): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars($inquiry['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-break"><?= htmlspecialchars($inquiry['message'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($inquiry['createdAt'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($inquiry['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="text-muted">No property inquiries yet.</div>
    <?php endif; ?>
</section>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const starLabels = {
        1: '★☆☆☆☆ 1 Star - Poor',
        2: '★★☆☆☆ 2 Stars - Fair',
        3: '★★★☆☆ 3 Stars - Good',
        4: '★★★★☆ 4 Stars - Very Good',
        5: '★★★★★ 5 Stars - Excellent!'
    };

    document.querySelectorAll('.star-rating-container').forEach(container => {
        const stars = container.querySelectorAll('.star-rating-btn');
        const txid = stars[0]?.getAttribute('data-txid');
        const input = document.getElementById('rating-val-' + txid);
        const label = document.getElementById('rating-label-' + txid);

        function highlightStars(val) {
            stars.forEach(s => {
                const sVal = parseInt(s.getAttribute('data-rating'), 10);
                if (sVal <= val) {
                    s.style.color = '#f59e0b';
                } else {
                    s.style.color = '#cbd5e1';
                }
            });
        }

        stars.forEach(star => {
            const val = parseInt(star.getAttribute('data-rating'), 10);
            star.addEventListener('mouseenter', () => {
                highlightStars(val);
                if (label) label.textContent = starLabels[val] || (val + ' Stars');
            });

            star.addEventListener('click', () => {
                input.value = val;
                highlightStars(val);
                if (label) {
                    label.textContent = starLabels[val] || (val + ' Stars');
                    label.className = 'badge text-bg-warning text-dark fw-bold small py-1 px-2';
                }
            });
        });

        container.addEventListener('mouseleave', () => {
            const currentVal = parseInt(input.value, 10) || 0;
            highlightStars(currentVal);
            if (label) {
                if (currentVal > 0) {
                    label.textContent = starLabels[currentVal] || (currentVal + ' Stars');
                    label.className = 'badge text-bg-warning text-dark fw-bold small py-1 px-2';
                } else {
                    label.textContent = 'Click a star to rate';
                    label.className = 'badge text-bg-light border text-muted small py-1 px-2';
                }
            }
        });
    });

    // Top rating prompt inside tracking cards
    document.querySelectorAll('.kq-top-star-picker').forEach(picker => {
        const stars = picker.querySelectorAll('.kq-top-star-btn');
        const txid = picker.getAttribute('data-txid');
        const csrf = picker.getAttribute('data-csrf');
        const label = document.getElementById('topRatingLabel' + txid);
        const banner = document.getElementById('topRatingBanner' + txid);
        let isSubmitting = false;

        function setHover(val) {
            stars.forEach(s => {
                const sVal = parseInt(s.getAttribute('data-rating'), 10);
                if (sVal <= val) {
                    s.style.color = '#f59e0b';
                    s.classList.add('is-active');
                } else {
                    s.style.color = '#cbd5e1';
                    s.classList.remove('is-active');
                }
            });
            if (label) {
                if (val > 0) {
                    label.textContent = starLabels[val] || (val + ' Stars');
                    label.className = 'badge text-bg-warning text-dark fw-bold small py-1 px-2 d-none d-sm-inline';
                } else {
                    label.textContent = 'Click a star';
                    label.className = 'badge text-bg-light border text-muted small py-1 px-2 d-none d-sm-inline';
                }
            }
        }

        stars.forEach(star => {
            const val = parseInt(star.getAttribute('data-rating'), 10);
            star.addEventListener('mouseenter', () => {
                if (!isSubmitting) setHover(val);
            });
            star.addEventListener('focus', () => {
                if (!isSubmitting) setHover(val);
            });

            star.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                if (isSubmitting) return;
                isSubmitting = true;

                stars.forEach(s => s.disabled = true);
                if (label) {
                    label.textContent = 'Submitting...';
                    label.className = 'badge text-bg-secondary text-white small py-1 px-2 d-none d-sm-inline';
                }

                const formData = new FormData();
                formData.append('submit_agent_review', '1');
                formData.append('transactionID', txid);
                formData.append('rating', val);
                formData.append('comment', '');
                formData.append('csrf_token', csrf);
                formData.append('is_ajax', '1');

                fetch('client_dashboard.php', {
                    method: 'POST',
                    body: formData,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.success) {
                        if (banner) {
                            banner.innerHTML = `
                                <div class="d-flex align-items-center justify-content-between w-100 py-1">
                                    <div class="d-flex align-items-center gap-2 text-success fw-bold small">
                                        <i class="bi bi-check-circle-fill fs-5"></i>
                                        <span>Thanks for the feedback</span>
                                    </div>
                                    <span class="badge bg-success text-white small px-2 py-1">
                                        ${val} ★ Submitted
                                    </span>
                                </div>
                            `;
                            banner.className = 'kq-top-rating-banner p-2 px-3 rounded-3 mb-3 bg-success-subtle border border-success d-flex align-items-center justify-content-between';

                            // Disappear after a few seconds
                            setTimeout(() => {
                                banner.style.transition = 'opacity 0.6s ease, max-height 0.6s ease, margin 0.6s ease, padding 0.6s ease';
                                banner.style.opacity = '0';
                                banner.style.maxHeight = '0';
                                banner.style.marginBottom = '0';
                                banner.style.paddingTop = '0';
                                banner.style.paddingBottom = '0';
                                banner.style.border = 'none';
                                banner.style.overflow = 'hidden';
                                setTimeout(() => {
                                    banner.remove();
                                }, 600);
                            }, 3000);
                        }

                        // Also update modal rate button if open or opened later
                        const modalRateBtn = document.querySelector('.modal-rate-btn-' + txid);
                        if (modalRateBtn) {
                            modalRateBtn.outerHTML = `<span class="badge bg-success text-white py-2 px-3 small modal-rate-btn-${txid}">★ ${val}/5 Rated</span>`;
                        }

                        // Also update review section on client dashboard if present
                        const reviewForm = document.querySelector(`.review-rating-form[data-txid="${txid}"]`);
                        if (reviewForm) {
                            const article = reviewForm.closest('article');
                            if (article) {
                                const statusBadge = article.querySelector('.badge.rounded-pill');
                                if (statusBadge) {
                                    statusBadge.className = 'badge rounded-pill text-bg-success';
                                    statusBadge.textContent = 'Submitted';
                                }
                                reviewForm.outerHTML = `
                                    <div class="mb-2 d-flex align-items-center gap-2">
                                        <div class="fs-5 lh-1" style="color: #f59e0b;">
                                            ${'★'.repeat(val)}${'☆'.repeat(5 - val)}
                                        </div>
                                        <span class="badge text-bg-warning text-dark fw-bold">${val} / 5 Stars</span>
                                    </div>
                                    <div class="small text-muted mb-2">Reviewed just now</div>
                                `;
                            }
                        }
                    } else {
                        alert((data && data.message) ? data.message : 'Unable to submit rating. Please try again.');
                        stars.forEach(s => s.disabled = false);
                        isSubmitting = false;
                        setHover(0);
                    }
                })
                .catch(err => {
                    console.error('Rating submit error:', err);
                    alert('Network error while saving rating. Please try again.');
                    stars.forEach(s => s.disabled = false);
                    isSubmitting = false;
                    setHover(0);
                });
            });
        });

        picker.addEventListener('mouseleave', () => {
            if (!isSubmitting) setHover(0);
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>