<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
require_once 'includes/notifications.php';
require_once 'includes/transaction_tracking.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$propertyID = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$propertyID = $propertyID === false ? 0 : max(0, $propertyID);
$stmt = $conn->prepare(
    "SELECT p.*, l.municipalityName, l.provinceName, l.regionName
     FROM properties p
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     WHERE p.propertyID = ?"
);
$stmt->bind_param("i", $propertyID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    include 'includes/header.php';
    echo "<div class='alert alert-danger'>Property not found or invalid ID.</div>";
    include 'includes/footer.php';
    exit();
}

$property = $result->fetch_assoc();

$categoryLabels = [
    'Residential' => 'Residential Lot',
    'Farmlot'     => 'Farm Lot',
    'Beachlot'    => 'Beach Lot',
    'Condo'       => 'Condo',
    'Commercial'  => 'Commercial Property',
];

$propertyMunicipality = (string) ($property['municipalityName'] ?? '');
if ($propertyMunicipality === '' && !empty($property['location'])) {
    $batangasMunicipalities = [
        'Agoncillo', 'Alitagtag', 'Balayan', 'Balete', 'Batangas City', 'Bauan', 'Calaca City',
        'Calatagan', 'Cuenca', 'Ibaan', 'Laurel', 'Lemery', 'Lian', 'Lipa City', 'Lobo',
        'Mabini', 'Malvar', 'Mataasnakahoy', 'Nasugbu', 'Padre Garcia', 'Rosario', 'San Jose',
        'San Juan', 'San Luis', 'San Nicolas', 'San Pascual', 'Santa Teresita', 'Santo Tomas City',
        'Taal', 'Talisay', 'Tanauan City', 'Taysan', 'Tingloy', 'Tuy'
    ];
    foreach ($batangasMunicipalities as $mName) {
        if (stripos((string) $property['location'], $mName) !== false) {
            $propertyMunicipality = $mName;
            break;
        }
    }
}

$successMsg = "";
$errorMsg = "";

if (!empty($_SESSION['flash_success'])) {
    $successMsg = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (normalized_user_role($_SESSION['user'] ?? null) !== 'Client') {
        header("Location: client_login.php");
        exit();
    }

    if (!has_valid_csrf_token()) {
        $errorMsg = 'Your session expired. Refresh the page and try again.';
    } elseif ($property['status'] !== 'Available') {
        $errorMsg = 'This property is not currently accepting new requests.';
    } elseif (isset($_POST['book_appointment'])) {
        $date = is_string($_POST['appointmentDate'] ?? null) ? $_POST['appointmentDate'] : '';
        $time = is_string($_POST['appointmentTime'] ?? null) ? $_POST['appointmentTime'] : '';
        $dateValue = DateTime::createFromFormat('!Y-m-d', $date);
        $timeValue = DateTime::createFromFormat('!H:i', $time);
        $today = new DateTimeImmutable('today');

        if (!$dateValue || $dateValue->format('Y-m-d') !== $date || $dateValue < $today || !$timeValue || $timeValue->format('H:i') !== $time) {
            $errorMsg = 'Choose a valid future date and appointment time.';
        } else {
            $clientID = (int) $_SESSION['user']['userID'];
            $duplicate = $conn->prepare(
                "SELECT appointmentID FROM appointments
                 WHERE propertyID = ? AND clientID = ? AND appointmentDate = ? AND appointmentTime = ?
                   AND status IN ('Pending', 'Approved')
                 LIMIT 1"
            );
            $duplicate->bind_param('iiss', $propertyID, $clientID, $date, $time);
            $duplicate->execute();
            if ($duplicate->get_result()->num_rows > 0) {
                $errorMsg = 'You already have an active request for this property at that date and time.';
            } else {
                $bookStmt = $conn->prepare("INSERT INTO appointments (propertyID, clientID, appointmentDate, appointmentTime, status) VALUES (?, ?, ?, ?, 'Pending')");
                $bookStmt->bind_param('iiss', $propertyID, $clientID, $date, $time);
                $transactionStarted = false;
                try {
                    $conn->begin_transaction();
                    $transactionStarted = true;
                    $bookStmt->execute();
                    create_property_transaction($conn, $propertyID, $clientID, 'appointment', (int) $conn->insert_id, $clientID);
                    $conn->commit();
                    $transactionStarted = false;
                    send_property_notification(
                        $conn,
                        $clientID,
                        'Viewing request received',
                        "Thank you for booking a site visit for '{$property['title']}'. Your viewing request has been received and is now being reviewed by our sales team. Please monitor your dashboard for updates."
                    );
                    $_SESSION['flash_success'] = 'Your viewing request has been sent. Track its progress and updates from your dashboard.';
                    header('Location: property_detail.php?id=' . $propertyID . '#appointment');
                    exit();
                } catch (mysqli_sql_exception $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    error_log('KingQuiry appointment creation failed: ' . $exception->getMessage());
                    $errorMsg = 'We could not submit your viewing request. Please try again.';
                } catch (Throwable $exception) {
                    if ($transactionStarted) {
                        $conn->rollback();
                    }
                    error_log('KingQuiry appointment tracking creation failed: ' . $exception->getMessage());
                    $errorMsg = 'We could not submit the request and its tracking record. Please try again.';
                }
            }
        }
    } elseif (isset($_POST['send_inquiry'])) {
        $message = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';
        if (strlen($message) < 5 || strlen($message) > 3000) {
            $errorMsg = 'Write an inquiry between 5 and 3,000 characters.';
        } else {
            $clientID = (int) $_SESSION['user']['userID'];
            $inqStmt = $conn->prepare("INSERT INTO feedbackinquiries (propertyID, clientID, message, status) VALUES (?, ?, ?, 'Unread')");
            $inqStmt->bind_param('iis', $propertyID, $clientID, $message);
            $transactionStarted = false;
            try {
                $conn->begin_transaction();
                $transactionStarted = true;
                $inqStmt->execute();
                create_property_transaction($conn, $propertyID, $clientID, 'inquiry', (int) $conn->insert_id, $clientID);
                $conn->commit();
                $transactionStarted = false;
                send_property_notification(
                    $conn,
                    $clientID,
                    'Property inquiry received',
                    "Thank you for reaching out about '{$property['title']}'. Your inquiry has been received and our team will review it shortly. Please check your dashboard for updates."
                );
                $_SESSION['flash_success'] = 'Your inquiry has been sent. Track the progress from your dashboard.';
                header('Location: property_detail.php?id=' . $propertyID . '#inquiry');
                exit();
            } catch (mysqli_sql_exception $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('KingQuiry inquiry creation failed: ' . $exception->getMessage());
                $errorMsg = 'We could not send your inquiry. Please try again.';
            } catch (Throwable $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('KingQuiry inquiry tracking creation failed: ' . $exception->getMessage());
                $errorMsg = 'We could not send the inquiry and its tracking record. Please try again.';
            }
        }
    }
}
include 'includes/header.php';
?>

<div class="row mb-3">
    <div class="col-md-12">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Catalog</a>
    </div>
</div>

<?php if($successMsg): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if($errorMsg): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="row">
    <!-- Property Detailed Information -->
    <div class="col-md-7 mb-4">
        <div class="card shadow-sm border-0 p-4">
            <span class="badge rounded-pill text-bg-light align-self-start mb-2"><?= htmlspecialchars($categoryLabels[$property['propertyType']] ?? $property['propertyType'], ENT_QUOTES, 'UTF-8') ?></span>
            <h2 class="fw-bold mb-2"><?= htmlspecialchars($property['title']) ?></h2>
            <p class="text-muted mb-3">Location: <strong><?= htmlspecialchars(property_location_label($property), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($property['barangay'])): ?>, <?= htmlspecialchars($property['barangay'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></strong></p>
            
            <?php
            $imageName = basename((string) ($property['image'] ?? ''));
            $imagePath = __DIR__ . '/assets/images/' . $imageName;
            ?>
            <?php if ($imageName !== '' && is_file($imagePath)): ?>
                <img src="assets/images/<?= rawurlencode($imageName) ?>" class="img-fluid rounded-4 mb-4" alt="<?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?>" style="max-height: 420px; width: 100%; object-fit: cover;">
            <?php else: ?>
                <div class="property-placeholder rounded-4 mb-4 d-flex align-items-center justify-content-center" style="height:280px" aria-label="No property image available"><span class="property-placeholder-mark">KQ</span></div>
            <?php endif; ?>

            <div class="bg-light p-3 rounded mb-4">
                <h4 class="text-primary fw-bold mb-1">₱<?= number_format($property['price'], 2) ?></h4>
                <?php if (!empty($property['areaSqm'])): ?><div class="text-muted small mb-1">Land area: <strong><?= number_format((float) $property['areaSqm'], 2) ?> sq.m.</strong></div><?php endif; ?>
                <span class="text-muted">Price per Square Meter: <strong>₱<?= number_format($property['pricePerSqm'] ?? 0, 2) ?> / sq.m.</strong></span>
            </div>

            <h5 class="fw-bold">Land Characteristics</h5>
            <p class="text-dark mb-3"><?= htmlspecialchars($property['landCharacteristics'] ?: 'Contact our team for verified land characteristics.', ENT_QUOTES, 'UTF-8') ?></p>

            <h3 class="h5 fw-bold">Description</h3>
            <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($property['description'], ENT_QUOTES, 'UTF-8')) ?></p>
        </div>
    </div>

    <!-- Booking & Inquiry Action Forms for Clients and Pinned Property Map -->
    <div class="col-md-5">
        <!-- Pinned Property Location Map Card (Right side of property information) -->
        <div class="card p-3 p-lg-4 mb-4 shadow-sm" id="property-pinned-map-card">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="page-eyebrow mb-0">Property location</div>
                <span class="badge rounded-pill text-bg-light border text-muted small">
                    <?= htmlspecialchars($categoryLabels[$property['propertyType']] ?? $property['propertyType'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <h3 class="h5 fw-bold mb-1">Pinned property map</h3>
            <div class="text-muted small mb-3">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="me-1 text-primary align-text-bottom"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <strong><?= htmlspecialchars(property_location_label($property), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($property['barangay'])): ?>, <?= htmlspecialchars($property['barangay'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></strong>
            </div>

            <!-- Single pinned property map container -->
            <div id="property-detail-map" aria-label="Map location for <?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?>" style="height: 300px; width: 100%; border-radius: .85rem; overflow: hidden; border: 1px solid var(--kq-line);"></div>

            <div class="small text-muted mt-2 d-flex align-items-center justify-content-center gap-2">
                <span class="d-inline-block rounded-circle" style="width: 8px; height: 8px; background-color: var(--kq-primary, #174a70);"></span>
                <span>Pinned: <strong><?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?></strong></span>
            </div>
        </div>

        <div class="card p-4 mb-4" id="appointment">
            <div class="page-eyebrow mb-2">Visit the property</div>
            <h3 class="h5 fw-bold mb-3">Request a viewing</h3>
            <?php if(normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="appointment-date">Preferred date</label>
                        <input type="date" id="appointment-date" name="appointmentDate" class="form-control" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="appointment-time">Preferred time</label>
                        <input type="time" id="appointment-time" name="appointmentTime" class="form-control" required>
                    </div>
                    <button type="submit" name="book_appointment" class="btn btn-primary w-100">Request a viewing</button>
                </form>
                <a href="property_summary_pdf.php?property_id=<?= (int) $property['propertyID'] ?>" target="_blank" class="btn btn-outline-secondary w-100 mt-3">Download summary PDF</a>
            <?php else: ?>
                <p class="text-muted small">Sign in with a client account to request a viewing.</p>
                <a href="client_login.php" class="btn btn-primary w-100">Sign in to continue</a>
            <?php endif; ?>
        </div>

        <div class="card p-4" id="inquiry">
            <div class="page-eyebrow mb-2">Have a question?</div>
            <h3 class="h5 fw-bold mb-3">Contact the property team</h3>
            <?php if(normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="property-inquiry">Your message</label>
                        <textarea id="property-inquiry" name="message" class="form-control" rows="4" minlength="5" maxlength="3000" required placeholder="Ask about documents, terms, access, or other property details."></textarea>
                    </div>
                    <button type="submit" name="send_inquiry" class="btn btn-outline-primary w-100">Send inquiry</button>
                </form>
            <?php else: ?>
                <p class="text-muted small">Sign in with a client account to send a question about this listing.</p>
                <a href="client_login.php" class="btn btn-outline-primary w-100">Sign in to continue</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const propertyCoordinates = {
    'Agoncillo': [13.9379, 120.9282],
    'Alitagtag': [13.8633, 121.0032],
    'Balayan': [13.9392, 120.7231],
    'Balete': [13.7917, 121.1014],
    'Batangas City': [13.7565, 121.0583],
    'Bauan': [13.7917, 120.9458],
    'Calaca City': [13.9308, 120.8131],
    'Calatagan': [13.8319, 120.6340],
    'Cuenca': [13.8999, 121.0519],
    'Ibaan': [13.8170, 121.1319],
    'Laurel': [14.0481, 120.9184],
    'Lemery': [13.8814, 120.8458],
    'Lian': [13.9421, 120.6460],
    'Lipa City': [13.9413, 121.1632],
    'Lobo': [13.5845, 121.1956],
    'Mabini': [13.7154, 120.9502],
    'Malvar': [14.0471, 121.1572],
    'Mataasnakahoy': [13.9621, 121.0030],
    'Nasugbu': [14.0925, 120.6303],
    'Padre Garcia': [13.8738, 121.2136],
    'Rosario': [13.8446, 121.2134],
    'San Jose': [13.8718, 121.1057],
    'San Juan': [13.8248, 121.3963],
    'San Luis': [13.8345, 121.5181],
    'San Nicolas': [13.9282, 121.0759],
    'San Pascual': [13.8082, 121.0297],
    'Santa Teresita': [13.8559, 120.7007],
    'Santo Tomas City': [14.0900, 121.1470],
    'Taal': [13.8814, 120.9327],
    'Talisay': [14.0922, 120.7752],
    'Tanauan City': [14.0862, 121.1513],
    'Taysan': [13.7329, 121.1877],
    'Tingloy': [13.6460, 120.8644],
    'Tuy': [13.9791, 120.7350]
};
const propertyLocationName = <?= json_encode($propertyMunicipality !== '' ? $propertyMunicipality : (string) ($property['municipalityName'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const savedLat = <?= json_encode(isset($property['latitude']) && $property['latitude'] !== null && $property['latitude'] !== '' ? (float) $property['latitude'] : null) ?>;
const savedLng = <?= json_encode(isset($property['longitude']) && $property['longitude'] !== null && $property['longitude'] !== '' ? (float) $property['longitude'] : null) ?>;
const hasExactPin = (savedLat !== null && savedLng !== null);
const propertyLocationCoords = hasExactPin ? [savedLat, savedLng] : (propertyCoordinates[propertyLocationName] || [13.7565, 121.0583]);
const propertyDetailTitle = <?= json_encode((string) $property['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailLocation = <?= json_encode(property_location_label($property), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailCategory = <?= json_encode((string) ($categoryLabels[$property['propertyType']] ?? $property['propertyType']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailPrice = <?= json_encode('₱' . number_format((float) $property['price'], 2), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('property-detail-map') && typeof L !== 'undefined') {
        const map = L.map('property-detail-map', {
            scrollWheelZoom: false,
            zoomControl: true
        }).setView(propertyLocationCoords, hasExactPin ? 15 : 13);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 18,
        }).addTo(map);

        // Custom branded pin marker for the chosen property
        const singlePropertyPin = L.divIcon({
            className: 'kq-pinned-single-marker',
            html: `<div style="
                width: 34px; height: 34px;
                background: #174a70;
                border: 3px solid #d8ad63;
                border-radius: 50% 50% 50% 0;
                transform: rotate(-45deg);
                box-shadow: 0 4px 12px rgba(18, 40, 66, 0.35);
                display: grid; place-items: center;
            "><div style="
                transform: rotate(45deg);
                color: #ffffff;
                font-size: 13px;
                font-weight: 800;
            ">●</div></div>`,
            iconSize: [34, 34],
            iconAnchor: [17, 34],
            popupAnchor: [0, -34],
        });

        const detailMarker = L.marker(propertyLocationCoords, { icon: singlePropertyPin }).addTo(map);
        detailMarker.bindPopup(`
            <div style="min-width: 190px; max-width: 240px; padding: 2px;">
                <span style="display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; background:#f1f5f9; color:#174a70; padding:2px 8px; border-radius:12px; margin-bottom:5px;">
                    ${propertyDetailCategory}
                </span>
                <div style="font-weight: 700; font-size: 14px; color: #18304c; margin-bottom: 3px; line-height: 1.3;">
                    ${propertyDetailTitle}
                </div>
                <div style="font-size: 12px; color: #687789; margin-bottom: 5px;">
                    ${propertyDetailLocation}
                </div>
                <div style="font-weight: 800; color: #174a70; font-size: 13px;">
                    ${propertyDetailPrice}
                </div>
            </div>
        `);
        detailMarker.openPopup();

        setTimeout(() => {
            map.invalidateSize();
        }, 250);
    }
});
</script>

<?php include 'includes/footer.php'; ?>