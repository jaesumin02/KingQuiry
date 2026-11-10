<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
require_once 'includes/property_images.php';
require_once 'includes/transaction_tracking.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
apply_secure_headers();
if (!user_has_role($_SESSION['user'] ?? null, 'Sales Agent')) {
    header('Location: agent_login.php');
    exit();
}

$locations = get_property_locations($conn);
$statusMessage = isset($_GET['property_added']) ? 'The property listing has been published with its pinned location.' : (isset($_GET['updated']) ? 'Viewing request and client tracking were updated.' : '');
$statusError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_valid_csrf_token()) {
        $statusError = 'Your session expired. Refresh the page and try again.';
    } elseif (isset($_POST['update_status'])) {
        $appointmentID = filter_var($_POST['appointmentID'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
        if ($appointmentID === false || $appointmentID <= 0 || !in_array($newStatus, ['Pending', 'Approved', 'Cancelled'], true)) {
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
        if ($location) {
            $defaultCoords = find_municipality_center_coordinates($location['municipalityName']);
            if ($latitude === null) {
                $latitude = (float) $defaultCoords[0];
            }
            if ($longitude === null) {
                $longitude = (float) $defaultCoords[1];
            }
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
        <div class="row g-3">
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
                            <div class="text-muted small">Select a municipality to zoom in, then click anywhere on the map or drag the pin marker to specify the exact land location.</div>
                        </div>
                        <span class="badge text-bg-light border text-dark fw-semibold" id="pin-coords-badge">
                            Select municipality or click map to pin
                        </span>
                    </div>

                    <div id="property-picker-map" style="height: 360px; width: 100%; border-radius: .75rem; overflow: hidden; border: 1px solid #ced4da; background: #e9ecef; position: relative; z-index: 1;"></div>

                    <div class="d-flex align-items-center justify-content-between mt-2 flex-wrap gap-2">
                        <small class="text-muted">
                            Pinned coordinates: <strong class="text-primary" id="pin-coords-text">Not placed yet (will default to municipality center)</strong>
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
});
</script>

<?php include 'includes/footer.php'; ?>