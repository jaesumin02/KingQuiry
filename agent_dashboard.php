<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
require_once 'includes/property_images.php';
require_once 'includes/transaction_tracking.php';
require_once 'includes/appointment_management.php';
require_once 'includes/notifications.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
apply_secure_headers();
if (!user_has_role($_SESSION['user'] ?? null, 'Sales Agent')) {
    header('Location: agent_login.php');
    exit();
}

ensure_appointment_management_schema($conn);
$currentAgentId = (int) $_SESSION['user']['userID'];

$locations = get_property_locations($conn);
$statusMessage = isset($_GET['property_added'])
    ? 'The property listing has been published with its pinned location.'
    : (isset($_GET['updated'])
        ? 'Viewing request and client tracking were updated.'
        : (isset($_GET['dismissed'])
            ? 'The taken viewing request was removed from your queue.'
            : (isset($_GET['claimed']) ? 'The inquiry was assigned to your account.' : '')));
$statusError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_valid_csrf_token()) {
        $statusError = 'Your session expired. Refresh the page and try again.';
    } elseif (isset($_POST['dismiss_taken_viewing'])) {
        $appointmentID = filter_var($_POST['appointmentID'] ?? null, FILTER_VALIDATE_INT);
        if ($appointmentID && $appointmentID > 0) {
            dismiss_appointment_for_agent($conn, $currentAgentId, (int) $appointmentID);
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'appointmentID' => $appointmentID]);
                exit();
            }
            header('Location: agent_dashboard.php?dismissed=1#appointments');
            exit();
        }
    } elseif (isset($_POST['claim_inquiry'])) {
        $inquiryID = filter_var($_POST['inquiryID'] ?? null, FILTER_VALIDATE_INT);
        if ($inquiryID === false || $inquiryID <= 0) {
            $statusError = 'Choose a valid inquiry.';
        } else {
            try {
                claim_inquiry_for_agent($conn, $currentAgentId, (int) $inquiryID);
                header('Location: agent_dashboard.php?claimed=1#agent-inquiries');
                exit();
            } catch (InvalidArgumentException $exception) {
                $statusError = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                error_log('KingQuiry inquiry assignment failed: ' . $exception->getMessage());
                $statusError = 'The inquiry could not be claimed. Refresh and try again.';
            }
        }
    } elseif (isset($_POST['update_status'])) {
        $appointmentID = filter_var($_POST['appointmentID'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if ($appointmentID === false || $appointmentID <= 0 || !in_array($newStatus, ['Pending', 'Approved', 'Cancelled'], true)) {
            $statusError = 'Choose a valid appointment and status.';
        } else {
            try {
                update_appointment_tracking_status($conn, (int) $appointmentID, $newStatus, $currentAgentId);
                header('Location: agent_dashboard.php?updated=1');
                exit();
            } catch (InvalidArgumentException $exception) {
                $statusError = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                error_log('KingQuiry appointment status update failed: ' . $exception->getMessage());
                $statusError = 'The appointment could not be updated. Please try again.';
            }
        }
    } elseif (isset($_POST['add_property'])) {
        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $areaSqm = filter_var($_POST['areaSqm'] ?? null, FILTER_VALIDATE_FLOAT);
        $locationId = filter_var($_POST['locationID'] ?? null, FILTER_VALIDATE_INT);
        $propertyType = is_string($_POST['propertyType'] ?? null) ? $_POST['propertyType'] : '';
        $landCharacteristics = is_string($_POST['landCharacteristics'] ?? null) ? trim($_POST['landCharacteristics']) : '';
        $barangay = is_string($_POST['barangay'] ?? null) ? trim($_POST['barangay']) : '';
        $location = $locationId === false ? null : find_property_location($locations, (int) $locationId);

        $latitude = filter_var($_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $latitude < -90 || $latitude > 90) {
            $latitude = null;
        }
        if ($longitude === false || $longitude < -180 || $longitude > 180) {
            $longitude = null;
        }
        if ($latitude === null || $longitude === null) {
            $latitude = null;
            $longitude = null;
        }

        if (strlen($title) < 3 || strlen($title) > 150 || strlen($description) < 10 || strlen($description) > 10000) {
            $statusError = 'Enter a title (3-150 characters) and a description (10-10,000 characters).';
        } elseif ($price === false || $price <= 0 || $price > 9999999999.99 || $areaSqm === false || $areaSqm < 0.01 || $areaSqm > 1000000) {
            $statusError = 'Enter a valid total price and a land area between 0 and 1,000,000 sq.m.';
        } elseif (!in_array($propertyType, ['Residential', 'Commercial', 'Farmlot', 'Beachlot', 'Condo'], true)) {
            $statusError = 'Choose a valid property category.';
        } elseif (!$location || strlen($barangay) > 120 || strlen($landCharacteristics) > 2000) {
            $statusError = 'Choose an active directory location and check the optional land details.';
        } else {
            $canonicalLocation = $location['municipalityName'] . ', ' . $location['provinceName'];
            $pricePerSqm = round((float) $price / (float) $areaSqm, 2);
            if ($pricePerSqm > 9999999999.99) {
                $statusError = 'The calculated price per square meter is outside the supported price range.';
            } else {
                try {
                    $imageName = store_property_image($_FILES['propertyImage'] ?? []);
                    $stmt = $conn->prepare(
                        "INSERT INTO properties (title, description, price, pricePerSqm, location, propertyType, landCharacteristics, locationID, latitude, longitude, barangay, areaSqm, image)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('ssddsssiddsds', $title, $description, $price, $pricePerSqm, $canonicalLocation, $propertyType, $landCharacteristics, $locationId, $latitude, $longitude, $barangay, $areaSqm, $imageName);
                    try {
                        $stmt->execute();
                        header('Location: agent_dashboard.php?property_added=1#add-property');
                        exit();
                    } catch (mysqli_sql_exception $exception) {
                        if ($imageName !== 'default.jpg') {
                            unlink(__DIR__ . '/assets/images/' . $imageName);
                        }
                        throw $exception;
                    }
                } catch (InvalidArgumentException $exception) {
                    $statusError = $exception->getMessage();
                } catch (mysqli_sql_exception $exception) {
                    error_log('KingQuiry property insert failed: ' . $exception->getMessage());
                    $statusError = 'The property could not be saved. Check the database connection and try again.';
                } catch (RuntimeException $exception) {
                    $statusError = $exception->getMessage();
                }
            }
        }
    }
}

$apptStmt = $conn->prepare(
    "SELECT a.*, p.title, p.location, p.barangay, l.municipalityName, l.provinceName,
            u.fullname as clientName, u.email as clientEmail,
            ag.fullname as agentName
     FROM appointments a
     JOIN properties p ON a.propertyID = p.propertyID
     JOIN users u ON a.clientID = u.userID
     LEFT JOIN users ag ON a.agentID = ag.userID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     LEFT JOIN agent_dismissed_appointments ada ON ada.appointmentID = a.appointmentID AND ada.agentID = ?
     WHERE a.agentID = ?
        OR (a.agentID IS NULL AND a.status = 'Pending' AND ada.appointmentID IS NULL)
     ORDER BY a.appointmentDate DESC, a.appointmentTime DESC, a.appointmentID DESC
     LIMIT 300"
);
$apptStmt->bind_param('ii', $currentAgentId, $currentAgentId);
$apptStmt->execute();
$appointments = $apptStmt->get_result();

$inquiriesStmt = $conn->prepare(
    'SELECT i.*, p.title, u.fullname AS clientName, u.email AS clientEmail,
            ia.agentID
     FROM feedbackinquiries i
     JOIN properties p ON i.propertyID = p.propertyID
     JOIN users u ON i.clientID = u.userID
     LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = i.inquiryID
     WHERE ia.agentID IS NULL OR ia.agentID = ?
     ORDER BY i.createdAt DESC, i.inquiryID DESC
     LIMIT 300'
);
$inquiriesStmt->bind_param('i', $currentAgentId);
$inquiriesStmt->execute();
$inquiries = $inquiriesStmt->get_result();

$statsStmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total_appointments,
        SUM(CASE WHEN a.status = 'Pending' THEN 1 ELSE 0 END) AS pending_appointments,
        SUM(CASE WHEN a.status = 'Approved' THEN 1 ELSE 0 END) AS approved_appointments,
        SUM(CASE WHEN a.status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_appointments,
        (SELECT COUNT(*)
         FROM feedbackinquiries i
         LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = i.inquiryID
         WHERE ia.agentID IS NULL OR ia.agentID = ?) AS total_inquiries
     FROM appointments a
     LEFT JOIN agent_dismissed_appointments ada ON ada.appointmentID = a.appointmentID AND ada.agentID = ?
     WHERE a.agentID = ?
        OR (a.agentID IS NULL AND a.status = 'Pending' AND ada.appointmentID IS NULL)"
);
$statsStmt->bind_param('iii', $currentAgentId, $currentAgentId, $currentAgentId);
$statsStmt->execute();
$stats = $statsStmt->get_result();
$statsRow = $stats && $stats->num_rows > 0 ? $stats->fetch_assoc() : ['total_appointments' => 0, 'pending_appointments' => 0, 'approved_appointments' => 0, 'cancelled_appointments' => 0, 'total_inquiries' => 0];
include 'includes/header.php';
?>
<style>
    .kq-agent-shell {
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

    .kq-agent-shell .kq-dashboard-hero .text-muted {
        color: #b8c7ce !important;
    }

    .card {
        border: 1px solid #dfe7ed !important;
        border-radius: 1rem !important;
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.14);
        color: #0f2942;
    }

    .kq-agent-shell .card .text-muted {
        color: #536579 !important;
    }

    .kq-agent-shell .card .page-eyebrow {
        color: #805a20;
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

    html[data-bs-theme="light"] body {
        background: #f4f7fb;
    }

    @media (max-width: 767.98px) {
        .kq-agent-shell {
            padding-top: .75rem;
        }
    }
</style>

<div class="kq-agent-shell">
    <div class="kq-portal-workspace">
        <aside class="kq-portal-sidebar" aria-label="Agent dashboard navigation">
            <button class="kq-menu-toggle" type="button" data-portal-menu-toggle data-menu-label="Agent menu" aria-label="Open Agent menu" aria-controls="agent-navigation" aria-expanded="false">
                <span class="kq-menu-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>Agent menu</span>
            </button>
            <div class="kq-portal-sidebar-heading">Agent menu</div>
            <nav class="kq-portal-nav" id="agent-navigation" aria-label="Agent dashboard sections">
                <a href="#agent-overview">Overview</a>
                <a href="#appointments">Viewing requests</a>
                <a href="#agent-inquiries">Inquiries</a>
                <a href="#add-property">Publish a listing</a>
                <a href="transaction_tracking.php">Transaction tracking</a>
                <a href="admin_chat.php">💬 Customer chat inbox</a>
            </nav>
        </aside>
        <div class="kq-portal-main">
    <header class="kq-dashboard-hero" id="agent-overview">
        <img class="kq-official-logo kq-logo-dashboard kq-logo-on-dark" src="assets/images/kings-land-properties-logo-light.png" alt="4 Kings Realty Services">
        <div class="kq-dashboard-hero-copy">
            <div class="page-eyebrow">SALES AGENT WORKSPACE</div>
            <h1 class="page-title h2 mb-1">Agent dashboard</h1>
            <p class="text-muted mb-0">Welcome, <?= htmlspecialchars($_SESSION['user']['fullname'], ENT_QUOTES, 'UTF-8') ?>. Manage viewing requests and share transaction updates.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="admin_chat.php" class="btn btn-outline-light flex-shrink-0">💬 Customer chat</a>
            <a href="transaction_tracking.php" class="btn btn-primary flex-shrink-0">Transaction tracking</a>
        </div>
    </header>

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

<?php
$agentId = (int) $_SESSION['user']['userID'];
$agentNotifs = get_user_notifications($conn, $agentId, 6);
$agentUnreadCount = get_user_unread_notification_count($conn, $agentId);
?>
<?php if (!empty($agentNotifs)): ?>
    <section class="card p-3 p-lg-4 mb-4 border-0 shadow-sm" id="viewing-alerts">
        <div class="kq-panel-header mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="page-eyebrow">Real-time alerts</div>
                <h2 class="h4 fw-bold mb-0 text-dark">Viewing alerts &amp; notifications</h2>
            </div>
            <div class="d-flex align-items-center gap-2">
                <?php if ($agentUnreadCount > 0): ?>
                    <span class="badge bg-danger rounded-pill px-2 py-1 small"><?= $agentUnreadCount ?> unread</span>
                    <form method="POST" action="notifications_action.php" class="m-0">
                        <input type="hidden" name="action" value="mark_all_read">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="redirect" value="agent_dashboard.php#viewing-alerts">
                        <button type="submit" class="btn btn-sm btn-outline-primary fw-semibold">Mark all as read</button>
                    </form>
                <?php else: ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 small">Up to date</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="list-group list-group-flush border rounded-3 overflow-hidden">
            <?php foreach ($agentNotifs as $aNotif):
                $notifId = (int) $aNotif['notificationID'];
                $isUnread = !(bool) $aNotif['isRead'];
                $link = !empty($aNotif['link']) ? $aNotif['link'] : 'agent_dashboard.php#appointments';
                $isTakenNotif = ($aNotif['type'] === 'viewing_taken' || stripos($aNotif['title'], 'taken') !== false);
            ?>
                <div class="list-group-item p-3 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 position-relative <?= $isUnread ? 'bg-primary-subtle border-start border-4 border-primary' : 'bg-white' ?>" id="agent-notif-<?= $notifId ?>">
                    <!-- Top-right X button to dismiss notification -->
                    <button type="button" class="btn-close position-absolute top-0 end-0 m-2 kq-dismiss-notif-btn" data-notif-id="<?= $notifId ?>" data-csrf-token="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>" title="Dismiss alert" aria-label="Dismiss"></button>
                    
                    <form method="POST" action="notifications_action.php" class="m-0 flex-grow-1 pe-4">
                        <input type="hidden" name="action" value="read_and_go">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="id" value="<?= $notifId ?>">
                        <input type="hidden" name="to" value="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn p-0 border-0 bg-transparent text-start text-dark d-flex align-items-start gap-3 w-100">
                        <span class="rounded-circle p-2 d-flex align-items-center justify-content-center <?= $isTakenNotif ? 'bg-secondary text-white' : ($isUnread ? 'bg-primary text-white' : 'bg-light text-secondary border') ?>" style="width: 38px; height: 38px; flex-shrink: 0;">
                            <?php if ($isTakenNotif): ?>
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
                            <?php else: ?>
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
                            <?php endif; ?>
                        </span>
                        <span>
                            <span class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                <span class="fw-bold <?= $isUnread ? 'text-primary' : 'text-dark' ?>" style="font-size: 0.95rem;"><?= htmlspecialchars($aNotif['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($isTakenNotif): ?>
                                    <span class="badge bg-secondary small" style="font-size: 0.65rem;">Taken by another agent</span>
                                <?php elseif ($isUnread): ?>
                                    <span class="badge bg-danger small" style="font-size: 0.65rem;">New viewing request</span>
                                <?php endif; ?>
                            </span>
                            <span class="d-block small text-dark fw-medium mb-1"><?= htmlspecialchars($aNotif['message'], ENT_QUOTES, 'UTF-8') ?></span>
                            <time class="small text-muted" datetime="<?= htmlspecialchars(date('c', strtotime($aNotif['createdAt'])), ENT_QUOTES, 'UTF-8') ?>" style="font-size: 0.75rem;">
                                <?= htmlspecialchars(date('M j, Y g:i A', strtotime($aNotif['createdAt'])), ENT_QUOTES, 'UTF-8') ?>
                            </time>
                        </span>
                        </button>
                    </form>
                    <form method="POST" action="notifications_action.php" class="m-0">
                        <input type="hidden" name="action" value="read_and_go">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="id" value="<?= $notifId ?>">
                        <input type="hidden" name="to" value="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary align-self-start align-self-sm-center text-nowrap mt-2 mt-sm-0">View queue &rarr;</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="card p-3 p-lg-4 mb-4" id="appointments">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Scheduling</div>
            <h2 class="h4 fw-bold mb-0">Viewing requests</h2>
        </div>
        <span class="kq-badge-soft">Live queue</span>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered align-middle mb-0">
            <thead class="table-light"><tr><th>Ref ID</th><th>Client</th><th>Property</th><th>Location</th><th>Date & Time</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php if ($appointments && $appointments->num_rows > 0): ?>
                    <?php while($appt = $appointments->fetch_assoc()): 
                        $apptId = (int) $appt['appointmentID'];
                        $isApproved = ($appt['status'] === 'Approved');
                        $assignedAgentId = !empty($appt['agentID']) ? (int) $appt['agentID'] : null;
                        $isTakenByOther = ($isApproved && $assignedAgentId !== null && $assignedAgentId !== $currentAgentId);
                        $isTakenByMe = ($isApproved && $assignedAgentId !== null && $assignedAgentId === $currentAgentId);
                        $agentName = !empty($appt['agentName']) ? $appt['agentName'] : 'another agent';
                    ?>
                        <tr class="kq-appt-row <?= $isTakenByOther ? 'table-light text-muted' : '' ?>" id="appt-row-<?= $apptId ?>" data-appointment-id="<?= $apptId ?>">
                            <td class="fw-bold position-relative">
                                #KQ-<?= 1000 + $apptId ?>
                            </td>
                            <td><?= htmlspecialchars($appt['clientName']) ?><br><small class="text-muted"><?= htmlspecialchars($appt['clientEmail']) ?></small></td>
                            <td class="fw-bold"><?= htmlspecialchars($appt['title']) ?></td>
                            <td><?= htmlspecialchars(!empty($appt['municipalityName']) ? $appt['municipalityName'] . ', ' . $appt['provinceName'] : $appt['location'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($appt['barangay'])): ?><br><small class="text-muted"><?= htmlspecialchars($appt['barangay'], ENT_QUOTES, 'UTF-8') ?></small><?php endif; ?></td>
                            <td><?= htmlspecialchars($appt['appointmentDate'], ENT_QUOTES, 'UTF-8') ?> at <?= htmlspecialchars(substr($appt['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($isTakenByOther): ?>
                                    <span class="badge rounded-pill text-bg-secondary px-2 py-1 d-inline-flex align-items-center gap-1 shadow-sm">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
                                        Taken by <?= htmlspecialchars($agentName) ?>
                                    </span>
                                <?php elseif ($isTakenByMe): ?>
                                    <span class="badge rounded-pill text-bg-success px-2 py-1 shadow-sm">
                                        Confirmed by You
                                    </span>
                                <?php elseif ($appt['status'] === 'Cancelled'): ?>
                                    <span class="badge rounded-pill text-bg-danger px-2 py-1">Cancelled</span>
                                <?php else: ?>
                                    <span class="badge rounded-pill text-bg-warning px-2 py-1 text-dark fw-bold">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="position-relative">
                                <?php if ($isTakenByOther): ?>
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <span class="small text-secondary fw-semibold">Taken by <?= htmlspecialchars($agentName) ?></span>
                                        <form method="POST" class="m-0 dismiss-taken-form" data-appointment-id="<?= $apptId ?>">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="dismiss_taken_viewing" value="1">
                                            <input type="hidden" name="appointmentID" value="<?= $apptId ?>">
                                            <button type="button" class="btn btn-sm btn-outline-danger p-1 d-inline-flex align-items-center justify-content-center kq-dismiss-btn" title="Remove taken viewing (<?= htmlspecialchars($agentName) ?>) from your queue" aria-label="Remove taken viewing" style="width: 30px; height: 30px; border-radius: 50%;">
                                                <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            </button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" class="d-flex gap-1">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="appointmentID" value="<?= $apptId ?>">
                                        <select name="status" class="form-select form-select-sm" style="width: 130px;">
                                            <?php if ($appt['status'] === 'Pending'): ?>
                                                <option value="Pending" selected>Pending</option>
                                                <option value="Approved">Confirm (Approve)</option>
                                                <option value="Cancelled">Cancel</option>
                                            <?php elseif ($appt['status'] === 'Approved'): ?>
                                                <option value="Approved" selected>Approved</option>
                                                <option value="Cancelled">Cancel</option>
                                            <?php else: ?>
                                                <option value="Cancelled" selected>Cancelled</option>
                                            <?php endif; ?>
                                        </select>
                                        <button type="submit" name="update_status" class="btn btn-sm btn-primary">Update</button>
                                    </form>
                                <?php endif; ?>
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

<section class="card p-3 p-lg-4" id="agent-inquiries">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Customer questions</div>
            <h2 class="h4 fw-bold mb-0">Property inquiries</h2>
        </div>
        <span class="kq-badge-soft">Inbox</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Client</th><th>Property</th><th>Message</th><th>Received</th><th>Status</th><th>Assignment</th></tr></thead>
            <tbody>
            <?php if ($inquiries && $inquiries->num_rows > 0): ?>
                <?php while ($inquiry = $inquiries->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($inquiry['clientName'], ENT_QUOTES, 'UTF-8') ?><br><small class="text-muted"><?= htmlspecialchars($inquiry['clientEmail'], ENT_QUOTES, 'UTF-8') ?></small></td>
                        <td class="fw-semibold"><?= htmlspecialchars($inquiry['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-break"><?= htmlspecialchars($inquiry['message'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($inquiry['createdAt'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($inquiry['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td>
                            <?php if ((int) ($inquiry['agentID'] ?? 0) === $currentAgentId): ?>
                                <span class="badge text-bg-success">Assigned to you</span>
                            <?php else: ?>
                                <form method="POST" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="claim_inquiry" value="1">
                                    <input type="hidden" name="inquiryID" value="<?= (int) $inquiry['inquiryID'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">Claim inquiry</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No unassigned or assigned inquiries.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- ═══════════════════ ADD A PROPERTY LISTING (WITH INTERACTIVE PIN MAP) ═══════════════════ -->
<section class="card p-4 p-lg-5 mb-4" id="add-property">
    <div class="kq-panel-header mb-3">
        <div>
            <div class="page-eyebrow">Listing management</div>
            <h2 class="h4 fw-bold mb-0">Publish a new property</h2>
        </div>
        <span class="kq-badge-soft">Agent tools</span>
    </div>
    <p class="text-muted small mb-4">Add a new property to the KingQuiry catalog. Use the interactive map below to pin the exact location of the land.</p>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="row g-3 kq-property-form-grid">
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="property-title">Listing title</label>
                <input id="property-title" type="text" name="title" class="form-control" required minlength="3" maxlength="150" placeholder="e.g. Scenic Residential lot in Nasugbu">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="property-area">Land area (sq.m.)</label>
                <input id="property-area" type="number" min="0.01" max="1000000" step="0.01" name="areaSqm" class="form-control" required placeholder="e.g. 500">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold" for="property-price">Total asking price (PHP)</label>
                <input id="property-price" type="number" min="0.01" max="9999999999.99" step="0.01" name="price" class="form-control" required placeholder="1500000">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="property-type">Property category</label>
                <select id="property-type" name="propertyType" class="form-select" required>
                    <option value="">Choose a category</option>
                    <option value="Residential">Residential Lot</option>
                    <option value="Farmlot">Farm Lot</option>
                    <option value="Beachlot">Beach Lot</option>
                    <option value="Condo">Condo</option>
                    <option value="Commercial">Commercial</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="property-location">City / municipality</label>
                <select id="property-location" name="locationID" class="form-select" required>
                    <?= property_location_options($locations) ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="property-barangay">Barangay <span class="text-muted fw-normal">(optional)</span></label>
                <input id="property-barangay" type="text" maxlength="120" name="barangay" class="form-control" placeholder="Enter verified barangay">
            </div>

            <!-- Interactive Map Picker for Pinning Land Location -->
            <div class="col-12">
                <input type="hidden" id="property-latitude" name="latitude" value="">
                <input type="hidden" id="property-longitude" name="longitude" value="">

                <div class="card bg-light border p-3 p-md-4 rounded-3">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div>
                            <label class="form-label fw-bold mb-0" for="property-picker-map">
                                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="me-1 text-primary align-text-bottom"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                Pin exact land location on the interactive map
                            </label>
                            <div class="text-muted small">Select a municipality to center the map, then click the exact location or drag its pin. The municipality center will not be saved as the property pin.</div>
                        </div>
                        <span class="badge text-bg-light border text-dark fw-semibold" id="pin-coords-badge">
                            Select municipality or click map to pin
                        </span>
                    </div>

                    <div id="property-picker-map" style="height: 360px; width: 100%; border-radius: .75rem; overflow: hidden; border: 1px solid #ced4da; background: #e9ecef; position: relative; z-index: 1;"></div>

                    <div class="d-flex align-items-center justify-content-between mt-2 flex-wrap gap-2">
                        <small class="text-muted">
                            Pinned coordinates: <strong class="text-primary" id="pin-coords-text">No exact pin selected</strong>
                        </small>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btn-recenter-municipality">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="me-1 align-text-bottom"><circle cx="12" cy="12" r="10"/><path d="m12 8 4 4-4 4M8 12h8"/></svg>Center on selected city
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold" for="property-characteristics">Land characteristics <span class="text-muted fw-normal">(optional)</span></label>
                <input id="property-characteristics" type="text" maxlength="2000" name="landCharacteristics" class="form-control" placeholder="Road access, terrain, utilities, lot dimensions">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="property-image">Property image <span class="text-muted fw-normal">(optional, JPG/PNG/WebP up to 5 MB)</span></label>
                <input id="property-image" type="file" name="propertyImage" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="property-description">Description</label>
                <textarea id="property-description" name="description" class="form-control" rows="4" required minlength="10" maxlength="10000" placeholder="Share accurate property details, terms, documents available, and viewing instructions."></textarea>
            </div>
        </div>
        <button type="submit" name="add_property" class="btn btn-primary mt-3 px-4">Publish listing with pinned location</button>
    </form>
</section>
</div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const locSelect = document.getElementById('property-location');
    const latInput = document.getElementById('property-latitude');
    const lngInput = document.getElementById('property-longitude');
    const coordsText = document.getElementById('pin-coords-text');
    const coordsBadge = document.getElementById('pin-coords-badge');
    const recenterBtn = document.getElementById('btn-recenter-municipality');
    const pickerMapContainer = document.getElementById('property-picker-map');

    if (!pickerMapContainer || typeof L === 'undefined') return;

    const defaultBatangasCenter = [13.7565, 121.0583];
    const pickerMap = L.map('property-picker-map', {
        scrollWheelZoom: true,
        zoomControl: true
    }).setView(defaultBatangasCenter, 10);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 18,
    }).addTo(pickerMap);

    const pickerPinIcon = L.divIcon({
        className: 'kq-picker-pin',
        html: `<div style="
            width: 36px; height: 36px;
            background: #174a70;
            border: 3px solid #d8ad63;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            box-shadow: 0 4px 14px rgba(18, 40, 66, 0.4);
            display: grid; place-items: center;
            cursor: grab;
        "><div style="
            transform: rotate(45deg);
            color: #ffffff;
            font-size: 14px;
            font-weight: 800;
        ">★</div></div>`,
        iconSize: [36, 36],
        iconAnchor: [18, 36],
        popupAnchor: [0, -36],
    });

    let pickerMarker = null;

    function setPin(lat, lng, zoomTo = false) {
        const fixedLat = Number(lat).toFixed(6);
        const fixedLng = Number(lng).toFixed(6);
        latInput.value = fixedLat;
        lngInput.value = fixedLng;
        coordsText.textContent = `${fixedLat}, ${fixedLng}`;
        coordsBadge.textContent = `Pinned: ${fixedLat}, ${fixedLng}`;
        coordsBadge.className = 'badge text-bg-success text-white fw-bold px-2 py-1';

        if (!pickerMarker) {
            pickerMarker = L.marker([lat, lng], {
                icon: pickerPinIcon,
                draggable: true
            }).addTo(pickerMap);

            pickerMarker.bindPopup(`<strong>Pinned Land Location</strong><br><small>${fixedLat}, ${fixedLng}</small>`);

            pickerMarker.on('dragend', function (e) {
                const pos = e.target.getLatLng();
                setPin(pos.lat, pos.lng, false);
                pickerMarker.openPopup();
            });
        } else {
            pickerMarker.setLatLng([lat, lng]);
            pickerMarker.setPopupContent(`<strong>Pinned Land Location</strong><br><small>${fixedLat}, ${fixedLng}</small>`);
        }

        if (zoomTo) {
            pickerMap.setView([lat, lng], 14, { animate: true });
        }
    }

    pickerMap.on('click', function(e) {
        setPin(e.latlng.lat, e.latlng.lng, false);
        if (pickerMarker) pickerMarker.openPopup();
    });

    function syncWithMunicipality(zoom = true) {
        if (!locSelect) return;
        const opt = locSelect.options[locSelect.selectedIndex];
        if (opt && opt.dataset.lat && opt.dataset.lng) {
            const lat = parseFloat(opt.dataset.lat);
            const lng = parseFloat(opt.dataset.lng);
            setPin(lat, lng, zoom);
        }
    }

    if (locSelect) {
        locSelect.addEventListener('change', () => syncWithMunicipality(true));
        if (locSelect.value !== '') {
            syncWithMunicipality(false);
        }
    }

    if (recenterBtn) {
        recenterBtn.addEventListener('click', () => syncWithMunicipality(true));
    }

    setTimeout(() => {
        pickerMap.invalidateSize();
    }, 250);

    // Dismiss taken viewings with smooth zero-reload animation
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.kq-dismiss-btn');
        if (!btn) return;
        e.preventDefault();
        const form = btn.closest('form');
        if (!form) return;
        const row = btn.closest('.kq-appt-row') || form.closest('tr');
        
        // Smooth visual removal
        if (row) {
            row.style.transition = 'all 0.35s ease';
            row.style.opacity = '0';
            row.style.transform = 'translateX(25px)';
            setTimeout(() => row.remove(), 350);
        }

        const formData = new FormData(form);
        fetch('agent_dashboard.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).catch(err => console.error(err));
    });

    // Dismiss viewing alert notifications
    document.addEventListener('click', function(e) {
        const notifCloseBtn = e.target.closest('.kq-dismiss-notif-btn');
        if (!notifCloseBtn) return;
        e.preventDefault();
        const notifId = notifCloseBtn.getAttribute('data-notif-id');
        const notifCard = document.getElementById('agent-notif-' + notifId) || notifCloseBtn.closest('.list-group-item');

        const formData = new FormData();
        formData.append('action', 'dismiss_notif');
        formData.append('id', notifId);
        formData.append('csrf_token', notifCloseBtn.getAttribute('data-csrf-token'));
        notifCloseBtn.disabled = true;
        fetch('notifications_action.php', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(response => {
            if (!response.ok) throw new Error('Notification update failed.');
            return response.json();
        }).then(data => {
            if (!data.success) throw new Error(data.error || 'Notification update failed.');
            if (notifCard) {
                notifCard.style.transition = 'all 0.35s ease';
                notifCard.style.opacity = '0';
                notifCard.style.transform = 'translateY(-15px)';
                setTimeout(() => notifCard.remove(), 350);
            }
        }).catch(() => {
            notifCloseBtn.disabled = false;
            window.alert('The notification could not be dismissed. Please try again.');
        });
    });
});
</script>

<?php include 'includes/footer.php'; ?>