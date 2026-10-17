<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
require_once 'includes/transaction_tracking.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (normalized_user_role($_SESSION['user'] ?? null) !== 'Client') {
    header('Location: login.php');
    exit();
}

include 'includes/header.php';
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
}
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

<div class="mb-4">
    <div class="page-eyebrow">Your account</div>
    <h1 class="page-title h2 mb-1">Client dashboard</h1>
    <p class="text-muted mb-0">Welcome, <?= htmlspecialchars($_SESSION['user']['fullname'], ENT_QUOTES, 'UTF-8') ?>. Follow your property requests in one place.</p>
</div>

<section class="card p-3 p-lg-4 mb-4" id="transaction-tracking">
    <div class="d-flex flex-column flex-sm-row justify-content-between gap-2 mb-3">
        <div>
        <div class="page-eyebrow">Sales progress</div>
        <h2 class="h4 fw-bold mb-1">Transaction tracking</h2>
            <p class="small text-muted mb-0">Follow each property request through dated updates from the sales team.</p>
        </div>
        <span class="badge rounded-pill text-bg-light align-self-sm-start">Lazada-style status timeline</span>
    </div>
    <div class="alert alert-info small mb-4" role="note">Your latest update appears first, followed by the earlier steps. Updates are posted by the sales team; this tracks the property transaction, not parcel GPS location or online payment.</div>
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

<section class="card p-3 p-lg-4 mb-4">
    <div class="mb-3"><div class="page-eyebrow">Appointments</div><h2 class="h4 fw-bold mb-0">Viewing requests</h2></div>
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
            </article>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="text-muted">You do not have any viewing requests yet. <a href="index.php">Browse available properties</a>.</div>
    <?php endif; ?>
</section>

<section class="card p-3 p-lg-4">
    <div class="mb-3"><div class="page-eyebrow">Messages</div><h2 class="h4 fw-bold mb-0">Your property inquiries</h2></div>
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

<?php include 'includes/footer.php'; ?>