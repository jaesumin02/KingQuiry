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

    .text-muted {
        color: rgba(245, 238, 227, 0.74) !important;
    }

    .card {
        border: 1px solid rgba(20, 34, 41, 0.08) !important;
        border-radius: 1.2rem !important;
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.12);
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
$paymentMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_transaction_payment'])) {
    $postedTransactionId = $_POST['transactionID'] ?? null;
    $transactionId = is_string($postedTransactionId)
        ? filter_var($postedTransactionId, FILTER_VALIDATE_INT)
        : false;
    $postedAmount = $_POST['amount'] ?? null;
    $amount = is_string($postedAmount) && $postedAmount !== ''
        ? filter_var($postedAmount, FILTER_VALIDATE_FLOAT)
        : false;
    $paymentMethod = is_string($_POST['paymentMethod'] ?? null) ? trim($_POST['paymentMethod']) : '';
    $referenceNumber = is_string($_POST['referenceNumber'] ?? null) ? trim($_POST['referenceNumber']) : '';
    if (!has_valid_csrf_token()) {
        $paymentMessage = 'Your session expired. Refresh the page and try again.';
    } elseif ($transactionId === false || $transactionId <= 0 || $amount === false || $amount === null) {
        $paymentMessage = 'Enter a valid payment amount and transaction.';
    } else {
        try {
            submit_property_transaction_payment(
                $conn,
                (int) $transactionId,
                $clientID,
                (float) $amount,
                $paymentMethod,
                $referenceNumber
            );
            header('Location: client_dashboard.php?payment_submitted=1#transaction-tracking');
            exit();
        } catch (InvalidArgumentException $exception) {
            $paymentMessage = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            error_log('KingQuiry client payment submission failed: ' . $exception->getMessage());
            $paymentMessage = 'Your payment details could not be saved. Please try again.';
        } catch (Throwable $exception) {
            error_log('KingQuiry client payment submission failed: ' . $exception->getMessage());
            $paymentMessage = 'Your payment details could not be saved. Please try again.';
        }
    }
}
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
    "SELECT t.transactionID, t.propertyID, t.currentStage, t.salePrice, t.createdAt, t.updatedAt,
            p.title, a.appointmentDate, a.appointmentTime,
            l.municipalityName, l.provinceName, p.location
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
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
    if (!has_valid_csrf_token()) {
        $reviewMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
        $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
        $comment = is_string($_POST['comment'] ?? null) ? trim($_POST['comment']) : '';
        $comment = mb_substr($comment, 0, 1000, 'UTF-8');

        if ($transactionId === false || $transactionId <= 0 || $rating === false || $rating < 1 || $rating > 5) {
            $reviewMessage = 'Choose a valid rating before submitting feedback.';
        } else {
            $reviewCheck = $conn->prepare(
                "SELECT transactionID FROM property_transactions WHERE transactionID = ? AND clientID = ? AND currentStage = 'completed'"
            );
            $reviewCheck->bind_param('ii', $transactionId, $clientID);
            $reviewCheck->execute();
            $reviewableTransaction = $reviewCheck->get_result()->fetch_assoc();

            if (!$reviewableTransaction) {
                $reviewMessage = 'This completed transaction is not eligible for a review.';
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
                    header('Location: client_dashboard.php?review_submitted=1#client-reviews');
                    exit();
                } catch (mysqli_sql_exception $exception) {
                    if ($exception->getCode() === 1062) {
                        $reviewMessage = 'You have already provided feedback for this completed transaction.';
                    } else {
                        error_log('KingQuiry agent review insert failed: ' . $exception->getMessage());
                        $reviewMessage = 'The review could not be saved. Please try again.';
                    }
                }
            }
        }
    }
}

$completedReviewRows = $conn->query(
    "SELECT t.transactionID, t.updatedAt, p.title, p.location, l.municipalityName, l.provinceName,
            ar.reviewID, ar.rating, ar.comment, ar.createdAt AS reviewDate,
            agent.fullname AS agentName
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
    'offer_negotiation',
    'reservation_processing',
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
<?php if ($paymentMessage !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= htmlspecialchars($paymentMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if (isset($_GET['payment_submitted'])): ?>
    <div class="alert alert-success" role="status">Payment details submitted. The sales team will review and confirm the payment.</div>
<?php endif; ?>

<section class="card p-3 p-lg-4 mb-4" id="transaction-tracking">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Sales progress</div>
            <h2 class="h4 fw-bold mb-0">Transaction tracking</h2>
        </div>
        <span class="kq-badge-soft">Lazada-style timeline</span>
    </div>
    <div class="alert alert-info small mb-4" role="note">You can record a payment made through any method during document verification. Payment details are reviewed by the sales team; this form does not transfer money. Do not enter card numbers, passwords, or other sensitive account details.</div>
    <?php if ($trackingRows !== []): ?>
        <?php foreach ($trackingRows as $tracking):
            $transactionId = (int) $tracking['transactionID'];
            $stage = (string) $tracking['currentStage'];
            $stageIndex = array_search($stage, $progressStages, true);
            $progress = $stage === 'cancelled'
                ? 0
                : ($stageIndex === false ? 0 : (int) round(($stageIndex / (count($progressStages) - 1)) * 100));
            $location = !empty($tracking['municipalityName'])
                ? $tracking['municipalityName'] . ', ' . $tracking['provinceName']
                : $tracking['location'];
        ?>
            <article class="border rounded-3 p-3 p-lg-4 mb-3 kq-tracking-card">
                <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-start gap-2 mb-3">
                    <div>
                        <div class="small fw-semibold text-primary">Tracking reference #KQT-<?= $transactionId ?></div>
                        <h3 class="h5 fw-bold mb-1"><?= htmlspecialchars($tracking['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <div class="small text-muted"><?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($tracking['appointmentDate'])): ?>
                            <div class="small text-muted mt-1">Viewing: <?= htmlspecialchars($tracking['appointmentDate'] . ' at ' . substr($tracking['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                    <span class="badge rounded-pill <?= $stage === 'completed' ? 'text-bg-success' : ($stage === 'cancelled' ? 'text-bg-secondary' : 'text-bg-primary') ?>"><?= htmlspecialchars($stageLabels[$stage] ?? 'Status unavailable', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php if ($stage === 'completed' && $tracking['salePrice'] !== null): ?>
                    <div class="alert alert-success small py-2 mb-3" role="status">
                        Verified sale price: <strong>₱<?= number_format((float) $tracking['salePrice'], 2) ?></strong>
                    </div>
                <?php endif; ?>
                <?php $transactionPayments = $paymentRowsByTransaction[$transactionId] ?? []; ?>
                <?php if ($transactionPayments !== []): ?>
                    <div class="border rounded-3 p-3 mb-3">
                        <h4 class="h6 fw-bold mb-2">Payments</h4>
                        <?php foreach ($transactionPayments as $payment): ?>
                            <div class="d-flex flex-wrap justify-content-between gap-2 small py-2 border-bottom">
                                <div>
                                    <strong>₱<?= number_format((float) $payment['amount'], 2) ?></strong>
                                    via <?= htmlspecialchars($payment['paymentMethod'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if (!empty($payment['referenceNumber'])): ?>
                                        <span class="text-muted">· Ref: <?= htmlspecialchars($payment['referenceNumber'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($payment['reviewNote'])): ?>
                                        <div class="text-muted"><?= htmlspecialchars($payment['reviewNote'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </div>
                                <span class="badge <?= $payment['status'] === 'confirmed' ? 'text-bg-success' : ($payment['status'] === 'rejected' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= htmlspecialchars(ucfirst($payment['status']), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (in_array($stage, ['document_verification', 'payment_confirmation'], true)): ?>
                    <form method="POST" class="border rounded-3 p-3 mb-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="transactionID" value="<?= $transactionId ?>">
                        <h4 class="h6 fw-bold">Record a payment</h4>
                        <p class="small text-muted">After paying through your agreed method, submit the amount and method so the sales team can verify it. Any method is accepted; this does not charge your account.</p>
                        <div class="row g-2">
                            <div class="col-sm-4">
                                <label class="form-label small fw-semibold" for="payment-amount-<?= $transactionId ?>">Amount (₱)</label>
                                <input id="payment-amount-<?= $transactionId ?>" type="number" name="amount" class="form-control form-control-sm" min="0.01" max="9999999999.99" step="0.01" required>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label small fw-semibold" for="payment-method-<?= $transactionId ?>">Payment method</label>
                                <input id="payment-method-<?= $transactionId ?>" type="text" name="paymentMethod" class="form-control form-control-sm" list="payment-method-options" maxlength="50" placeholder="Cash, bank transfer, e-wallet, etc." required>
                            </div>
                            <div class="col-sm-4">
                                <label class="form-label small fw-semibold" for="payment-reference-<?= $transactionId ?>">Reference (optional)</label>
                                <input id="payment-reference-<?= $transactionId ?>" type="text" name="referenceNumber" class="form-control form-control-sm" maxlength="100">
                            </div>
                        </div>
                        <button type="submit" name="submit_transaction_payment" value="1" class="btn btn-sm btn-primary mt-3">Submit payment details</button>
                    </form>
                <?php endif; ?>
                <?php if ($stage !== 'cancelled'): ?>
                    <div class="d-flex justify-content-between align-items-center small mb-2">
                        <span class="fw-semibold">Overall progress</span>
                        <span class="text-muted"><?= $progress ?>%</span>
                    </div>
                    <div class="progress mb-4" role="progressbar" aria-label="Transaction progress" aria-valuenow="<?= $progress ?>" aria-valuemin="0" aria-valuemax="100" style="height: .55rem;">
                        <div class="progress-bar <?= $stage === 'completed' ? 'bg-success' : 'bg-primary' ?>" style="width: <?= $progress ?>%"></div>
                    </div>
                <?php endif; ?>
                <h4 class="h6 fw-bold mb-3">Transaction updates</h4>
                <?php if (!empty($trackingEvents[$transactionId])): ?>
                    <ol class="list-unstyled kq-tracking-timeline mb-0" aria-label="Transaction update history">
                        <?php foreach ($trackingEvents[$transactionId] as $eventIndex => $event): ?>
                            <li class="kq-tracking-event <?= $eventIndex === 0 ? 'is-latest' : '' ?> <?= $event['stage'] === 'completed' ? 'is-completed' : '' ?>" <?= $eventIndex === 0 ? 'aria-current="step"' : '' ?>>
                                <div class="kq-tracking-event-content">
                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                        <span class="fw-bold"><?= htmlspecialchars($stageLabels[$event['stage']] ?? 'Status updated', ENT_QUOTES, 'UTF-8') ?></span>
                                        <time class="small text-muted" datetime="<?= htmlspecialchars(date('c', strtotime($event['createdAt'])), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($event['createdAt'])), ENT_QUOTES, 'UTF-8') ?></time>
                                    </div>
                                    <?php if ($eventIndex === 0): ?><div class="small text-primary fw-semibold mt-1">Latest update</div><?php endif; ?>
                                    <?php if (!empty($event['changedByName'])): ?><div class="small text-muted mt-1">Updated by <?= htmlspecialchars($event['changedByName'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                    <?php if (!empty($event['note'])): ?><p class="small text-muted text-break mb-0 mt-1"><?= htmlspecialchars($event['note'], ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php else: ?>
                    <p class="small text-muted mb-0">No tracking updates have been recorded yet.</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="rounded-3 border bg-light p-3 p-lg-4">
            <h3 class="h6 fw-bold mb-2">No property requests to track yet</h3>
            <p class="text-muted mb-3">Send an inquiry or request a property viewing to create a tracking reference. The sales team will add dated updates here as your request moves forward.</p>
            <a href="index.php" class="btn btn-primary">Browse properties</a>
        </div>
    <?php endif; ?>
</section>

<datalist id="payment-method-options">
    <option value="Cash">
    <option value="Bank transfer">
    <option value="E-wallet">
    <option value="Check">
    <option value="Other">
</datalist>

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

                        <?php if (!empty($completedReview['reviewID'])): ?>
                            <div class="mb-2 text-warning lh-1" aria-label="Rated <?= (int) $completedReview['rating'] ?> out of 5 stars">
                                <?php for ($starIndex = 1; $starIndex <= 5; $starIndex++): ?>
                                    <?= $starIndex <= (int) $completedReview['rating'] ? '★' : '☆' ?>
                                <?php endfor; ?>
                            </div>
                            <div class="small text-muted mb-2">Reviewed on <?= htmlspecialchars(date('M j, Y', strtotime((string) $completedReview['reviewDate'])), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($completedReview['comment'])): ?>
                                <p class="mb-0 text-break small"><?= htmlspecialchars($completedReview['comment'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php else: ?>
                                <p class="mb-0 text-muted small">No written comment provided.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="transactionID" value="<?= (int) $completedReview['transactionID'] ?>">
                                <label class="form-label small fw-semibold">Your rating</label>
                                <div class="d-flex gap-2 mb-3 flex-wrap">
                                    <?php for ($starIndex = 1; $starIndex <= 5; $starIndex++): ?>
                                        <label class="btn btn-outline-warning btn-sm px-2 py-1 mb-0">
                                            <input type="radio" name="rating" value="<?= $starIndex ?>" required class="visually-hidden">
                                            <span>★</span>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-semibold" for="agent-review-comment-<?= (int) $completedReview['transactionID'] ?>">Your feedback</label>
                                    <textarea id="agent-review-comment-<?= (int) $completedReview['transactionID'] ?>" name="comment" class="form-control" rows="3" maxlength="1000" placeholder="Tell us how the sales process worked for you."></textarea>
                                </div>
                                <button type="submit" name="submit_agent_review" class="btn btn-primary btn-sm">Submit review</button>
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

<?php include 'includes/footer.php'; ?>