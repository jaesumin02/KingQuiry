<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/transaction_tracking.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
apply_secure_headers();
if (!user_has_role($_SESSION['user'] ?? null, 'Sales Agent')) {
    header('Location: agent_login.php');
    exit();
}

$statusMessage = isset($_GET['updated']) ? 'Viewing request and client tracking were updated.' : '';
$statusError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $appointmentID = filter_var($_POST['appointmentID'] ?? null, FILTER_VALIDATE_INT);
    $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
    if (!has_valid_csrf_token()) {
        $statusError = 'Your session expired. Refresh the page and try again.';
    } elseif ($appointmentID === false || $appointmentID <= 0 || !in_array($newStatus, ['Pending', 'Approved', 'Cancelled'], true)) {
        $statusError = 'Choose a valid appointment and status.';
    } else {
        try {
            update_appointment_tracking_status($conn, (int) $appointmentID, $newStatus, (int) $_SESSION['user']['userID']);
            header('Location: agent_dashboard.php?updated=1');
            exit();
        } catch (InvalidArgumentException $exception) {
            $statusError = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            error_log('KingQuiry appointment status update failed: ' . $exception->getMessage());
            $statusError = 'The appointment could not be updated. Please try again.';
        }
    }
}

$appointments = $conn->query("SELECT a.*, p.title, p.location, p.barangay, l.municipalityName, l.provinceName, u.fullname as clientName, u.email as clientEmail FROM appointments a JOIN properties p ON a.propertyID = p.propertyID JOIN users u ON a.clientID = u.userID LEFT JOIN property_locations l ON l.locationID = p.locationID ORDER BY a.appointmentDate DESC, a.appointmentTime DESC, a.appointmentID DESC LIMIT 300");
$inquiries = $conn->query("SELECT i.*, p.title, u.fullname as clientName, u.email as clientEmail FROM feedbackinquiries i JOIN properties p ON i.propertyID = p.propertyID JOIN users u ON i.clientID = u.userID ORDER BY i.createdAt DESC, i.inquiryID DESC LIMIT 300");
$stats = $conn->query("SELECT
    COUNT(*) AS total_appointments,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_appointments,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved_appointments,
    SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_appointments,
    (SELECT COUNT(*) FROM feedbackinquiries) AS total_inquiries
FROM appointments");
$statsRow = $stats && $stats->num_rows > 0 ? $stats->fetch_assoc() : ['total_appointments' => 0, 'pending_appointments' => 0, 'approved_appointments' => 0, 'cancelled_appointments' => 0, 'total_inquiries' => 0];
include 'includes/header.php';
?>
<style>
    body {
        background: radial-gradient(circle at top, rgba(13, 37, 43, 0.85), rgba(6, 15, 19, 1) 52%);
    }

    .kq-agent-shell {
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

    .btn-primary {
        background: linear-gradient(180deg, #173d56, #102334);
        border-color: #173d56;
    }

    .btn-primary:hover,
    .btn-primary:focus {
        background: linear-gradient(180deg, #1d486b, #132a3f);
        border-color: #1d486b;
    }

    .badge.text-bg-warning {
        color: #6d4a09 !important;
    }

    .table-responsive,
    table {
        border-radius: 1rem;
    }

    @media (max-width: 767.98px) {
        .kq-agent-shell {
            padding-top: .75rem;
        }
    }
</style>

<div class="kq-agent-shell">
    <div class="kq-dashboard-hero">
        <span class="kq-dashboard-brand">4 KINGS</span>
        <div>
            <div class="page-eyebrow">Client services</div>
            <div class="text-white fw-semibold mb-0">Private sales operations</div>
        </div>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div>
            <div class="page-eyebrow">Client services</div>
            <h1 class="page-title h2 mb-1">Sales agent portal</h1>
            <p class="text-muted mb-0">Welcome, <?= htmlspecialchars($_SESSION['user']['fullname'], ENT_QUOTES, 'UTF-8') ?>. Manage viewing requests and share verified transaction progress.</p>
        </div>
        <a href="transaction_tracking.php" class="btn btn-primary">Open transaction tracker</a>
    </div>

<?php if ($statusMessage !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($statusError !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($statusError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="page-eyebrow">Appointments</div>
                <h3 class="h2 mb-0"><?= (int) ($statsRow['total_appointments'] ?? 0) ?></h3>
                <small class="text-muted">Total viewing requests</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="page-eyebrow">Pending</div>
                <h3 class="h2 mb-0"><?= (int) ($statsRow['pending_appointments'] ?? 0) ?></h3>
                <small class="text-muted">Needs action</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="page-eyebrow">Approved</div>
                <h3 class="h2 mb-0"><?= (int) ($statsRow['approved_appointments'] ?? 0) ?></h3>
                <small class="text-muted">Confirmed site visits</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body">
                <div class="page-eyebrow">Inquiries</div>
                <h3 class="h2 mb-0"><?= (int) ($statsRow['total_inquiries'] ?? 0) ?></h3>
                <small class="text-muted">Customer messages</small>
            </div>
        </div>
    </div>
</div>

<section class="card p-3 p-lg-4 mb-4">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Scheduling</div>
            <h2 class="h4 fw-bold mb-0">Viewing requests</h2>
        </div>
        <span class="kq-badge-soft">Live queue</span>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-light"><tr><th>Ref ID</th><th>Client</th><th>Property</th><th>Location</th><th>Date & Time</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php if ($appointments && $appointments->num_rows > 0): ?>
                    <?php while($appt = $appointments->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-bold">#KQ-<?= 1000 + $appt['appointmentID'] ?></td>
                            <td><?= htmlspecialchars($appt['clientName']) ?><br><small class="text-muted"><?= htmlspecialchars($appt['clientEmail']) ?></small></td>
                            <td class="fw-bold"><?= htmlspecialchars($appt['title']) ?></td>
                            <td><?= htmlspecialchars(!empty($appt['municipalityName']) ? $appt['municipalityName'] . ', ' . $appt['provinceName'] : $appt['location'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($appt['barangay'])): ?><br><small class="text-muted"><?= htmlspecialchars($appt['barangay'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></td>
                            <td><?= htmlspecialchars($appt['appointmentDate'], ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars(substr($appt['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge rounded-pill <?= $appt['status'] === 'Approved' ? 'text-bg-success' : ($appt['status'] === 'Cancelled' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= htmlspecialchars($appt['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td>
                                <form method="POST" class="d-flex gap-1">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="appointmentID" value="<?= (int) $appt['appointmentID'] ?>">
                                    <select name="status" class="form-select form-select-sm" style="width: 130px;">
                                        <?php if ($appt['status'] === 'Pending'): ?>
                                            <option value="Pending" selected>Pending</option>
                                            <option value="Approved">Approved</option>
                                            <option value="Cancelled">Cancelled</option>
                                        <?php elseif ($appt['status'] === 'Approved'): ?>
                                            <option value="Approved" selected>Approved</option>
                                            <option value="Cancelled">Cancelled</option>
                                        <?php else: ?>
                                            <option value="Cancelled" selected>Cancelled</option>
                                        <?php endif; ?>
                                    </select>
                                    <button type="submit" name="update_status" class="btn btn-sm btn-primary">Update</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr><td colspan="7" class="text-center text-muted">No appointments found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card p-3 p-lg-4">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Customer questions</div>
            <h2 class="h4 fw-bold mb-0">Property inquiries</h2>
        </div>
        <span class="kq-badge-soft">Inbox</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Client</th><th>Property</th><th>Message</th><th>Received</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($inquiries && $inquiries->num_rows > 0): ?>
                <?php while ($inquiry = $inquiries->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($inquiry['clientName'], ENT_QUOTES, 'UTF-8') ?><br><small class="text-muted"><?= htmlspecialchars($inquiry['clientEmail'], ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td class="fw-semibold"><?= htmlspecialchars($inquiry['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-break"><?= htmlspecialchars($inquiry['message'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($inquiry['createdAt'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($inquiry['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No customer inquiries yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
</div>

<?php include 'includes/footer.php'; ?>