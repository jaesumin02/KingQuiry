<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
require_once 'includes/property_categories.php';
require_once 'includes/property_images.php';
require_once 'includes/notifications.php';
require_once 'includes/transaction_tracking.php';
require_once 'includes/ml_recommender.php';
require_once 'includes/appointment_management.php';
require_once 'includes/rate_limit.php';

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
    http_response_code(404);
    include 'includes/header.php';
    echo "<div class='alert alert-danger'>Property not found or invalid ID.</div>";
    include 'includes/footer.php';
    exit();
}

$property = $result->fetch_assoc();
if ((int) ($property['isDeleted'] ?? 0) === 1) {
    http_response_code(404);
    include 'includes/header.php';
    echo "<div class='alert alert-warning'>This property is not available.</div>";
    include 'includes/footer.php';
    exit();
}

$categoryLabels = property_type_labels();
$propertyTitle = (string) $property['title'];
$isSampleListing = str_starts_with($propertyTitle, '[SAMPLE]');
$displayTitle = $isSampleListing ? trim(substr($propertyTitle, 8)) : $propertyTitle;

if (!in_array((string) $property['status'], ['Available', 'Sold'], true)) {
    http_response_code(404);
    include 'includes/header.php';
    echo "<div class='alert alert-warning'>This property is not available.</div>";
    include 'includes/footer.php';
    exit();
}

$propertyMunicipality = (string) ($property['municipalityName'] ?? '');
if ($propertyMunicipality === '' && !empty($property['location'])) {
    $batangasMunicipalities = array_keys(get_batangas_municipality_coordinates());
    foreach ($batangasMunicipalities as $mName) {
        if (stripos((string) $property['location'], $mName) !== false) {
            $propertyMunicipality = $mName;
            break;
        }
    }
}

$savedLatitude = $property['latitude'] ?? null;
$savedLongitude = $property['longitude'] ?? null;
$hasExactPin = is_numeric($savedLatitude)
    && is_numeric($savedLongitude)
    && (float) $savedLatitude >= -90
    && (float) $savedLatitude <= 90
    && (float) $savedLongitude >= -180
    && (float) $savedLongitude <= 180;
$propertyLocationCoords = $hasExactPin
    ? [(float) $savedLatitude, (float) $savedLongitude]
    : find_municipality_coordinates($propertyMunicipality);

$successMsg = "";
$errorMsg = "";

if (!empty($_SESSION['flash_success'])) {
    $successMsg = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$userRole = normalized_user_role($_SESSION['user'] ?? null);
$isStaffUser = user_has_role($_SESSION['user'] ?? null, 'Sales Agent', 'Agent', 'Admin');
$currentUserId = isset($_SESSION['user']['userID']) ? (int) $_SESSION['user']['userID'] : null;
if (!$isSampleListing) {
    record_user_search_preference((string) $property['propertyType'], (int) ($property['locationID'] ?? 0), (float) $property['price']);
}
$mlRecommendations = get_personalized_recommendations($conn, $currentUserId, $propertyID, 3);
$businessTimeZone = new DateTimeZone('Asia/Manila');
$businessNow = new DateTimeImmutable('now', $businessTimeZone);

$submissionAction = isset($_POST['book_appointment'])
    ? 'appointment'
    : (isset($_POST['send_inquiry']) ? 'inquiry' : null);
if (!isset($_SESSION['property_action_tokens']) || !is_array($_SESSION['property_action_tokens'])) {
    $_SESSION['property_action_tokens'] = [];
}
if (!isset($_SESSION['property_action_tokens'][$propertyID])) {
    if (count($_SESSION['property_action_tokens']) >= 10) {
        $oldestPropertyId = array_key_first($_SESSION['property_action_tokens']);
        if ($oldestPropertyId !== null) {
            unset($_SESSION['property_action_tokens'][$oldestPropertyId]);
        }
    }
    $_SESSION['property_action_tokens'][$propertyID] = [];
}
foreach (['appointment', 'inquiry'] as $actionTokenType) {
    $existingToken = $_SESSION['property_action_tokens'][$propertyID][$actionTokenType] ?? null;
    if (!is_string($existingToken) || strlen($existingToken) !== 64) {
        $_SESSION['property_action_tokens'][$propertyID][$actionTokenType] = bin2hex(random_bytes(32));
    }
}
$submittedActionToken = $_POST['submission_token'] ?? null;
$hasValidSubmissionToken = $submissionAction !== null
    && is_string($submittedActionToken)
    && hash_equals(
        $_SESSION['property_action_tokens'][$propertyID][$submissionAction],
        $submittedActionToken
    );

$requestRateLimitMessage = null;
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && $submissionAction !== null
    && normalized_user_role($_SESSION['user'] ?? null) === 'Client'
    && has_valid_csrf_token()
    && $hasValidSubmissionToken
    && $property['status'] === 'Available'
) {
    $clientId = (int) $_SESSION['user']['userID'];
    $clientLimit = kq_rate_limit_consume($conn, 'property-request-client', (string) $clientId, 10, 600);
    $ipAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) && $_SERVER['REMOTE_ADDR'] !== ''
        ? $_SERVER['REMOTE_ADDR']
        : 'unknown';
    $ipLimit = kq_rate_limit_consume($conn, 'property-request-ip', $ipAddress, 30, 600);
    if (!$clientLimit['allowed'] || !$ipLimit['allowed']) {
        $retryAfter = max(1, $clientLimit['retry_after'], $ipLimit['retry_after']);
        $requestRateLimitMessage = 'You have sent too many property requests. Please wait '
            . (int) ceil($retryAfter / 60)
            . ' minute(s) before trying again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (normalized_user_role($_SESSION['user'] ?? null) !== 'Client') {
        header("Location: client_login.php");
        exit();
    } elseif (!has_valid_csrf_token()) {
        $errorMsg = 'Your session expired. Refresh the page and try again.';
    } elseif ($isSampleListing) {
        $errorMsg = 'Sample listings are examples only and cannot accept viewing requests or inquiries.';
    } elseif ($submissionAction === null) {
        $errorMsg = 'Choose a valid property request and try again.';
    } elseif (!$hasValidSubmissionToken) {
        $errorMsg = 'This request was already submitted or expired. Review the form and try again.';
    } elseif ($property['status'] !== 'Available') {
        $errorMsg = 'This property is not currently accepting new requests.';
    } elseif ($requestRateLimitMessage !== null) {
        $errorMsg = $requestRateLimitMessage;
    } elseif ($submissionAction === 'appointment') {
        $date = is_string($_POST['appointmentDate'] ?? null) ? $_POST['appointmentDate'] : '';
        $time = is_string($_POST['appointmentTime'] ?? null) ? $_POST['appointmentTime'] : '';

        if (!kq_is_future_appointment_datetime($date, $time, $businessNow)) {
            $errorMsg = 'Choose a valid future date and appointment time.';
        } else {
            $clientID = (int) $_SESSION['user']['userID'];
            $transactionStarted = false;
            try {
                $conn->begin_transaction();
                $transactionStarted = true;

                $availableStmt = $conn->prepare(
                    "SELECT propertyID FROM properties
                     WHERE propertyID = ? AND status = 'Available' AND isDeleted = 0
                     FOR UPDATE"
                );
                $availableStmt->bind_param('i', $propertyID);
                $availableStmt->execute();

                if (!$availableStmt->get_result()->fetch_assoc()) {
                    $conn->rollback();
                    $transactionStarted = false;
                    $errorMsg = 'This property is no longer available for viewing requests.';
                } else {
                    $duplicate = $conn->prepare(
                        "SELECT appointmentID FROM appointments
                         WHERE propertyID = ? AND clientID = ? AND appointmentDate = ? AND appointmentTime = ?
                           AND status IN ('Pending', 'Approved')
                         LIMIT 1"
                    );
                    $duplicate->bind_param('iiss', $propertyID, $clientID, $date, $time);
                    $duplicate->execute();

                    if ($duplicate->get_result()->num_rows > 0) {
                        $conn->rollback();
                        $transactionStarted = false;
                        $errorMsg = 'You already have an active request for this property at that date and time.';
                    } else {
                        // ALGORITHM: Lock the listing, reject duplicate or overlapping active visits, then save booking and tracking atomically.
                        $clashStmt = $conn->prepare(
                            "SELECT appointmentDate, appointmentTime FROM appointments
                             WHERE propertyID = ?
                               AND status IN ('Pending', 'Approved')
                               AND ABS(TIMESTAMPDIFF(
                                   SECOND,
                                   TIMESTAMP(appointmentDate, appointmentTime),
                                   TIMESTAMP(?, ?)
                               )) < 3600
                             LIMIT 1"
                        );
                        $clashStmt->bind_param('iss', $propertyID, $date, $time);
                        $clashStmt->execute();
                        $clashResult = $clashStmt->get_result()->fetch_assoc();

                        if ($clashResult) {
                            $conn->rollback();
                            $transactionStarted = false;
                            $clashTime = date('M j, Y', strtotime($clashResult['appointmentDate'])) . ' at ' . substr($clashResult['appointmentTime'], 0, 5);
                            $errorMsg = "Schedule conflict: This property already has a scheduled property viewing {$clashTime}. To ensure dedicated Sales Agent assistance and avoid overlapping viewings, please choose a time at least one hour before or after.";
                        } else {
                            $bookStmt = $conn->prepare(
                                "INSERT INTO appointments
                                    (propertyID, clientID, appointmentDate, appointmentTime, status)
                                 VALUES (?, ?, ?, ?, 'Pending')"
                            );
                            $bookStmt->bind_param('iiss', $propertyID, $clientID, $date, $time);
                            $bookStmt->execute();
                            $appointmentId = (int) $conn->insert_id;
                            create_property_transaction($conn, $propertyID, $clientID, 'appointment', $appointmentId, $clientID);
                            $conn->commit();
                            $transactionStarted = false;
                            unset($_SESSION['property_action_tokens'][$propertyID]['appointment']);

                            $notificationFailed = false;
                            try {
                                $clientNotified = send_property_notification(
                                    $conn,
                                    $clientID,
                                    'Viewing request received',
                                    "Thank you for requesting a property viewing for '{$property['title']}'. Your viewing request has been received and is now being reviewed by our Sales Agent team. Please monitor your dashboard for updates.",
                                    'client_dashboard.php#transaction-tracking',
                                    'viewing_request'
                                );
                                $agentsNotified = notify_agents_new_viewing($conn, $appointmentId, $propertyID, $clientID, $date, $time);
                                $notificationFailed = !$clientNotified || !$agentsNotified;
                                if ($notificationFailed) {
                                    error_log('KingQuiry viewing request notifications were incomplete for appointment ' . $appointmentId . '.');
                                }
                            } catch (Throwable $exception) {
                                error_log('KingQuiry appointment notification failed: ' . $exception->getMessage());
                                $notificationFailed = true;
                            }
                            $_SESSION['flash_success'] = $notificationFailed
                                ? 'Your viewing request was saved, but we could not send all notifications. You can track it from your dashboard.'
                                : 'Your viewing request has been sent. Track its progress and updates from your dashboard.';
                            header('Location: property_detail.php?id=' . $propertyID . '#appointment');
                            exit();
                        }
                    }
                }
            } catch (Throwable $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('KingQuiry appointment creation failed: ' . $exception->getMessage());
                $errorMsg = 'We could not submit your viewing request. Please try again.';
            }
        }
    } elseif ($submissionAction === 'inquiry') {
        $message = is_string($_POST['message'] ?? null) ? trim($_POST['message']) : '';
        $messageLength = preg_match_all('/./us', $message);
        if ($messageLength === false || $messageLength < 5 || $messageLength > 3000) {
            $errorMsg = 'Write an inquiry between 5 and 3,000 characters.';
        } else {
            $clientID = (int) $_SESSION['user']['userID'];
            $transactionStarted = false;
            try {
                $conn->begin_transaction();
                $transactionStarted = true;
                // ALGORITHM: Lock the listing and atomically save the inquiry plus tracking event before dispatching notifications.
                $availableStmt = $conn->prepare(
                    "SELECT propertyID FROM properties
                     WHERE propertyID = ? AND status = 'Available' AND isDeleted = 0
                     FOR UPDATE"
                );
                $availableStmt->bind_param('i', $propertyID);
                $availableStmt->execute();

                if (!$availableStmt->get_result()->fetch_assoc()) {
                    $conn->rollback();
                    $transactionStarted = false;
                    $errorMsg = 'This property is no longer available for inquiries.';
                } else {
                    // Deduplication check: prevent submitting the exact same inquiry for this property within 120 seconds
                    $dupCheckStmt = $conn->prepare(
                        "SELECT inquiryID FROM feedbackinquiries
                         WHERE propertyID = ? AND clientID = ? AND message = ?
                           AND createdAt >= DATE_SUB(NOW(), INTERVAL 120 SECOND)
                         LIMIT 1"
                    );
                    $dupCheckStmt->bind_param('iis', $propertyID, $clientID, $message);
                    $dupCheckStmt->execute();
                    if ($dupCheckStmt->get_result()->fetch_assoc()) {
                        $conn->rollback();
                        $transactionStarted = false;
                        $errorMsg = 'You have recently submitted this exact inquiry message. Our sales team is already reviewing it.';
                    } else {
                        $inqStmt = $conn->prepare(
                            "INSERT INTO feedbackinquiries (propertyID, clientID, message, status)
                             VALUES (?, ?, ?, 'Unread')"
                        );
                        $inqStmt->bind_param('iis', $propertyID, $clientID, $message);
                        $inqStmt->execute();
                        $inquiryId = (int) $conn->insert_id;
                        create_property_transaction($conn, $propertyID, $clientID, 'inquiry', $inquiryId, $clientID);
                        $conn->commit();
                        $transactionStarted = false;
                        unset($_SESSION['property_action_tokens'][$propertyID]['inquiry']);

                        $notificationFailed = false;
                        try {
                            $clientNotified = send_property_notification(
                                $conn,
                                $clientID,
                                'Property inquiry received',
                                "Thank you for reaching out about '{$property['title']}'. Your inquiry has been received and our team will review it shortly. Please check your dashboard for updates.",
                                'client_dashboard.php#transaction-tracking',
                                'inquiry_received'
                            );
                            $agentsNotified = notify_agents_new_inquiry($conn, $inquiryId, $propertyID, $clientID, $message);
                            $notificationFailed = !$clientNotified || !$agentsNotified;
                            if ($notificationFailed) {
                                error_log('KingQuiry inquiry notifications were incomplete for inquiry ' . $inquiryId . '.');
                            }
                        } catch (Throwable $exception) {
                            error_log('KingQuiry inquiry notification failed: ' . $exception->getMessage());
                            $notificationFailed = true;
                        }

                        $_SESSION['flash_success'] = $notificationFailed
                            ? 'Your inquiry was saved, but we could not create all notifications. You can track it from your dashboard.'
                            : 'Your inquiry has been sent. Track the progress from your dashboard.';
                        header('Location: property_detail.php?id=' . $propertyID . '#inquiry');
                        exit();
                    }
                }
            } catch (Throwable $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('KingQuiry inquiry submission failed: ' . $exception->getMessage());
                $errorMsg = 'We could not save your inquiry and tracking record. Please try again.';
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
            <?php if ($property['status'] === 'Sold'): ?>
                <div class="alert alert-dark border-0 p-3 rounded-3 d-flex align-items-center gap-3 mb-3">
                    <span class="badge bg-danger fs-6 px-3 py-2 text-uppercase">Sold</span>
                    <div>
                        <div class="fw-bold text-white">This property is officially sold and turned over.</div>
                        <div class="small text-white-50">It has been acquired by its new owner and is no longer available on public listings.</div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($isSampleListing): ?>
                <div class="alert alert-warning mb-3" role="note">Sample listing only. The property details, price, location, and illustrative photo are examples, not an actual offer. Viewing requests and inquiries are disabled.</div>
            <?php endif; ?>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($categoryLabels[$property['propertyType']] ?? $property['propertyType'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php if ($isSampleListing): ?><span class="badge rounded-pill text-bg-warning">Sample listing</span><?php endif; ?>
            </div>
            <h2 class="fw-bold mb-2"><?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="text-muted mb-3">Location: <strong><?= htmlspecialchars(property_location_label($property), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($property['barangay'])): ?>, <?= htmlspecialchars($property['barangay'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></strong></p>
            
            <?php
            $imageName = basename((string) ($property['image'] ?? ''));
            $imagePath = __DIR__ . '/assets/images/' . $imageName;
            $imageCredit = $imageName !== '' && is_file($imagePath) ? get_property_image_credit($imageName) : null;
            ?>
            <?php if ($imageName !== '' && is_file($imagePath)): ?>
                <img src="assets/images/<?= rawurlencode($imageName) ?>" class="img-fluid rounded-4 <?= $imageCredit ? 'mb-1' : 'mb-4' ?>" alt="<?= htmlspecialchars($imageCredit ? 'Illustrative photo for ' . $displayTitle : $displayTitle, ENT_QUOTES, 'UTF-8') ?>" style="max-height: 420px; width: 100%; object-fit: cover;">
                <?php if ($imageCredit): ?>
                    <p class="small text-muted mb-4" style="font-size: .78rem;">
                        Image credit: <a href="<?= htmlspecialchars($imageCredit['sourceUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($imageCredit['title'] . ' by ' . $imageCredit['creator'], ENT_QUOTES, 'UTF-8') ?></a>,
                        <a href="<?= htmlspecialchars($imageCredit['licenseUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($imageCredit['license'], ENT_QUOTES, 'UTF-8') ?></a>.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <div class="property-placeholder rounded-4 mb-4 d-flex align-items-center justify-content-center" style="height:280px" aria-label="No property image available"><span class="property-placeholder-mark">KQ</span></div>
            <?php endif; ?>

            <div class="bg-light p-3 rounded mb-4">
                <h4 class="text-primary fw-bold mb-1">₱<?= number_format($property['price'], 2) ?></h4>
                <?php if ($isSampleListing): ?><div class="small text-warning-emphasis fw-semibold mb-1">Illustrative sample price only; not an offer.</div><?php endif; ?>
                <?php if (!empty($property['areaSqm'])): ?><div class="text-muted small mb-1"><?= $property['propertyType'] === 'Condo' ? 'Floor area' : 'Land area' ?>: <strong><?= number_format((float) $property['areaSqm'], 2) ?> sq.m.</strong></div><?php endif; ?>
                <span class="text-muted">Price per Square Meter: <strong>₱<?= number_format($property['pricePerSqm'] ?? 0, 2) ?> / sq.m.</strong></span>
            </div>

            <h5 class="fw-bold"><?= $property['propertyType'] === 'Condo' ? 'Unit Characteristics' : 'Land Characteristics' ?></h5>
            <p class="text-dark mb-3"><?= htmlspecialchars($property['landCharacteristics'] ?: 'Contact our team for verified land characteristics.', ENT_QUOTES, 'UTF-8') ?></p>

            <h3 class="h5 fw-bold">Description</h3>
            <p class="text-muted mb-0"><?= nl2br(htmlspecialchars($property['description'], ENT_QUOTES, 'UTF-8')) ?></p>

            <!-- Land Estimation Formula & Acquisition Terms Calculator -->
            <div class="card p-3 p-lg-4 mt-4 border rounded-3 bg-light-subtle shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h4 class="h6 fw-bold mb-0 text-dark"><i class="bi bi-calculator me-1 text-primary"></i> Land Estimation &amp; Acquisition Calculator</h4>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle small fw-bold">Formula Engine</span>
                </div>
                <div class="small text-muted mb-3">
                    Standard formula: <code>Total Price = Lot Area (sq.m.) × Price per sq.m.</code>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold" for="calc-area-input">Lot Area (sq.m.)</label>
                        <input type="number" id="calc-area-input" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($property['areaSqm'] ?? 100), ENT_QUOTES, 'UTF-8') ?>" min="1" max="1000000" step="any">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small fw-semibold" for="calc-rate-input">Price / sq.m. (PHP)</label>
                        <input type="number" id="calc-rate-input" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($property['pricePerSqm'] ?? 1000), ENT_QUOTES, 'UTF-8') ?>" min="1" step="any" readonly>
                    </div>
                </div>

                <div class="p-3 bg-white border rounded-2 mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span class="small text-muted">Estimated Total Asking Price:</span>
                        <strong class="fs-5 text-primary" id="calc-total-display">₱<?= number_format(round((float) $property['price']), 2) ?></strong>
                    </div>
                    <div class="row g-2 mt-2 pt-2 border-top small">
                        <div class="col-6">
                            <span class="text-muted d-block">Spot Cash (DOAS):</span>
                            <strong class="text-success" id="calc-spot-display">₱<?= number_format(round((float) $property['price']), 2) ?></strong>
                            <div class="text-muted" style="font-size: 0.72rem;">Deed of Absolute Sale</div>
                        </div>
                        <div class="col-6">
                            <span class="text-muted d-block">20% Down Payment (CTS):</span>
                            <strong class="text-dark" id="calc-dp-display">₱<?= number_format(round((float) $property['price'] * 0.20), 2) ?></strong>
                            <div class="text-muted" style="font-size: 0.72rem;">Contract to Sell term</div>
                        </div>
                    </div>
                </div>

                <!-- Academic & Operational Policy Safeguards -->
                <div class="alert alert-warning py-2 px-3 small mb-0 d-flex flex-column gap-1">
                    <div>
                        <strong class="text-dark">⏳ 7-Day Reservation Rule:</strong>
                        <span class="text-muted">Placing a reservation initiates a strict 7-day period to settle the down payment and required compliance IDs.</span>
                    </div>
                    <div class="pt-1 border-top border-warning-subtle">
                        <strong class="text-dark">📑 Contract to Sell (CTS) vs. DOAS:</strong>
                        <span class="text-muted">Installment terms advance under Contract to Sell; full spot cash settlements proceed directly to Deed of Absolute Sale (DOAS).</span>
                    </div>
                    <div class="pt-1 border-top border-warning-subtle">
                        <strong class="text-dark">⚖️ Title Transfer Disclosures:</strong>
                        <span class="text-muted">Title transfer fees, registration expenses, and Documentary Stamp Taxes (DST) are strictly shouldered by the buyer as standard practice.</span>
                    </div>
                </div>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', () => {
                const areaInput = document.getElementById('calc-area-input');
                const rateInput = document.getElementById('calc-rate-input');
                const totalDisplay = document.getElementById('calc-total-display');
                const spotDisplay = document.getElementById('calc-spot-display');
                const dpDisplay = document.getElementById('calc-dp-display');

                if (areaInput && rateInput && totalDisplay && spotDisplay && dpDisplay) {
                    const baseArea = parseFloat('<?= (float)($property['areaSqm'] ?? 0) ?>');
                    const basePrice = Math.round(parseFloat('<?= (float)($property['price'] ?? 0) ?>'));

                    const formatPeso = (num) => '₱' + Math.round(num).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                    const updateCalc = () => {
                        const area = Math.max(0, parseFloat(areaInput.value) || 0);
                        const rate = Math.max(0, parseFloat(rateInput.value) || 0);

                        let total;
                        if (baseArea > 0 && Math.abs(area - baseArea) < 0.001) {
                            total = basePrice;
                        } else {
                            total = Math.round(area * rate);
                        }
                        const dp = Math.round(total * 0.20);

                        totalDisplay.textContent = formatPeso(total);
                        spotDisplay.textContent = formatPeso(total);
                        dpDisplay.textContent = formatPeso(dp);
                    };

                    areaInput.addEventListener('input', updateCalc);
                    rateInput.addEventListener('input', updateCalc);
                    updateCalc();
                }
            });
            </script>
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
            <div id="property-detail-map" class="kq-property-map" aria-label="Map location for <?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?>" style="height: 300px; width: 100%; border-radius: .85rem; overflow: hidden; border: 1px solid var(--kq-line);">
                <span class="kq-map-fallback">Loading property map…</span>
            </div>
            <p class="small text-muted mt-2 mb-0">
                <?= $hasExactPin ? 'Showing the saved property pin.' : ($propertyLocationCoords ? 'Approximate location: municipality center. Ask a Sales Agent to confirm the exact property pin.' : 'Map coordinates are not available for this property yet.') ?>
            </p>

            <div class="small text-muted mt-2 d-flex align-items-center justify-content-center gap-2">
                <span class="d-inline-block rounded-circle" style="width: 8px; height: 8px; background-color: var(--kq-primary, #174a70);"></span>
                <span>Pinned: <strong><?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?></strong></span>
            </div>
        </div>

        <?php if ($isSampleListing): ?>
            <div class="card p-4 text-center border-warning-subtle bg-warning-subtle mb-4">
                <span class="badge text-bg-warning align-self-center mb-2">Sample listing</span>
                <h4 class="h5 fw-bold mb-2">Example only</h4>
                <p class="text-muted small mb-3">This listing and its illustrative photo are not available for viewing or inquiry.</p>
                <a href="index.php#all-properties" class="btn btn-primary w-100">Browse all listings</a>
            </div>
        <?php elseif ($property['status'] === 'Sold'): ?>
            <div class="card p-4 text-center border-0 shadow-sm bg-light mb-4">
                <div class="rounded-circle bg-danger-subtle text-danger mx-auto d-flex align-items-center justify-content-center mb-3" style="width: 54px; height: 54px;">
                    <span class="fs-4">🏷️</span>
                </div>
                <span class="badge bg-danger fs-6 px-3 py-1 mb-2 align-self-center">Off the Market</span>
                <h4 class="h5 fw-bold mb-2">Property Officially Sold</h4>
                <p class="text-muted small mb-3">This property is officially owned and is no longer available for site viewings or inquiries.</p>
                <a href="index.php" class="btn btn-primary w-100">Browse Available Properties</a>
            </div>
        <?php elseif ($isStaffUser): ?>
            <!-- Staff Quick Action Card -->
            <div class="card p-3 p-md-4 mb-4 border-0 shadow-sm" style="background: linear-gradient(135deg, #142d4e 0%, #174a70 100%); color: #fff; border-radius: 14px;">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="fs-4">🛡️</span>
                    <div>
                        <div class="text-white-50 small fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase;">Staff View</div>
                        <h4 class="h6 fw-bold mb-0 text-white"><?= htmlspecialchars((string) $userRole, ENT_QUOTES, 'UTF-8') ?> Portal</h4>
                    </div>
                </div>
                <p class="small text-white-50 mb-3" style="font-size: 0.82rem;">You are viewing this listing with internal sales staff permissions. Inquiries and viewing requests for this property are managed in your pipeline.</p>
                <div class="d-grid gap-2">
                    <a href="transaction_tracking.php#transactions" class="btn btn-sm btn-light fw-bold text-dark d-inline-flex align-items-center justify-content-center gap-2 shadow-sm py-2">
                        <i class="bi bi-kanban-fill text-primary"></i>
                        <span>Open Transaction Pipeline</span>
                    </a>
                    <a href="agent_dashboard.php#appointments" class="btn btn-sm btn-outline-light d-inline-flex align-items-center justify-content-center gap-2 py-2">
                        <i class="bi bi-calendar-check-fill text-warning"></i>
                        <span>View Scheduled Viewings</span>
                    </a>
                    <?php if ($userRole === 'Admin'): ?>
                        <a href="admin_properties.php?edit=<?= (int)$propertyID ?>" class="btn btn-sm btn-warning fw-semibold text-dark d-inline-flex align-items-center justify-content-center gap-2 py-2">
                            <i class="bi bi-pencil-square"></i>
                            <span>Edit Listing Details</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- Direct Agent Chat Card -->
            <div class="card p-3 p-md-4 mb-4 border-primary-subtle shadow-sm" style="background: linear-gradient(135deg, rgba(23,74,112,0.04) 0%, rgba(216,173,99,0.07) 100%);">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px;">
                        <i class="bi bi-chat-dots-fill"></i>
                    </span>
                    <div>
                        <div class="page-eyebrow text-primary mb-0" style="font-size: 0.68rem;">Direct Communication</div>
                        <h4 class="h6 fw-bold mb-0 text-dark">Message an Agent</h4>
                    </div>
                </div>
                <p class="small text-muted mb-3">Have questions about pricing, payment terms, required documents, or title verification? Chat directly with an assigned Sales Agent.</p>
                <button type="button" class="btn btn-primary w-100 shadow-sm d-inline-flex align-items-center justify-content-center gap-2 fw-semibold py-2" onclick="openPropertyChat(<?= (int)$propertyID ?>, '<?= addslashes(htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8')) ?>')">
                    <i class="bi bi-chat-text-fill"></i>
                    <span>Message an Agent about this property</span>
                </button>
            </div>

            <div class="card p-4 mb-4" id="appointment">
                <div class="page-eyebrow mb-2">Visit the property</div>
                <h3 class="h5 fw-bold mb-3">Request a viewing</h3>
                <?php if(normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
                    <form method="POST" data-confirm="Send a viewing request for this property? The assigned Sales Agent will be notified.">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="submission_token" value="<?= htmlspecialchars($_SESSION['property_action_tokens'][$propertyID]['appointment'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="appointment-date">Preferred date</label>
                            <input type="date" id="appointment-date" name="appointmentDate" class="form-control" required min="<?= htmlspecialchars($businessNow->format('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="appointment-time">Preferred time</label>
                            <input type="time" id="appointment-time" name="appointmentTime" class="form-control" required>
                        </div>
                        <button type="submit" name="book_appointment" class="btn btn-primary w-100">Request a viewing</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted small">Sign in with a client account to request a viewing.</p>
                    <a href="client_login.php" class="btn btn-primary w-100">Sign in to continue</a>
                <?php endif; ?>
            </div>

            <div class="card p-4" id="inquiry">
                <div class="page-eyebrow mb-2">Have a question?</div>
                <h3 class="h5 fw-bold mb-3">Contact the property team</h3>
                <?php if(normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
                    <form method="POST" data-confirm="Send this inquiry to the property team?">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="submission_token" value="<?= htmlspecialchars($_SESSION['property_action_tokens'][$propertyID]['inquiry'], ENT_QUOTES, 'UTF-8') ?>">
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
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($mlRecommendations)): ?>
    <section class="mt-5 pt-4 border-top" aria-labelledby="related-properties-title">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h3 class="h4 fw-bold mb-0" id="related-properties-title">Suggested properties</h3>
                <p class="text-muted small mb-0">Suggestions are based on this listing's location, category, and price.</p>
            </div>
        </div>
        <div class="row g-3">
            <?php foreach ($mlRecommendations as $rec): ?>
                <?php
    $recommendationImageName = basename((string) ($rec['image'] ?? ''));
                $recommendationImagePath = __DIR__ . '/assets/images/' . $recommendationImageName;
                $hasRecommendationImage = $recommendationImageName !== '' && is_file($recommendationImagePath);
                ?>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm border overflow-hidden property-card" style="border-radius: 0.85rem; transition: transform 0.18s ease, box-shadow 0.18s ease;">
                        <?php if ($hasRecommendationImage): ?>
                            <img src="assets/images/<?= rawurlencode($recommendationImageName) ?>" class="card-img-top" alt="<?= htmlspecialchars($rec['title'], ENT_QUOTES, 'UTF-8') ?>" style="height: 165px; object-fit: cover;">
                        <?php else: ?>
                            <div class="property-placeholder d-flex align-items-center justify-content-center" style="height: 165px" aria-label="No property image available"><span class="property-placeholder-mark">KQ</span></div>
                        <?php endif; ?>
                        <div class="card-body p-3 d-flex flex-column justify-content-between">
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="badge text-bg-light small"><?= htmlspecialchars((string) ($categoryLabels[$rec['propertyType']] ?? $rec['propertyType']), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <h4 class="h6 fw-bold mb-1 text-truncate" title="<?= htmlspecialchars($rec['title'], ENT_QUOTES, 'UTF-8') ?>">
                                    <a href="property_detail.php?id=<?= (int) $rec['propertyID'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($rec['title'], ENT_QUOTES, 'UTF-8') ?></a>
                                </h4>
                                <div class="small text-muted mb-2"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars(property_location_label($rec), ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="small text-success fw-semibold mb-2" style="font-size: 0.76rem;"><?= htmlspecialchars($rec['match_reason'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                                <div>
                                    <div class="small text-muted" style="font-size: 0.7rem;">Asking Price</div>
                                    <span class="fw-bold text-primary">₱<?= number_format((float) $rec['price'], 2) ?></span>
                                </div>
                                <div class="d-flex align-items-center gap-1">
                                    <?php if (!$isStaffUser): ?>
                                        <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 shadow-sm px-2" onclick="openPropertyChat(<?= (int) $rec['propertyID'] ?>, '<?= addslashes(htmlspecialchars($rec['title'], ENT_QUOTES, 'UTF-8')) ?>')" title="Message an Agent about this property">
                                            <i class="bi bi-chat-dots-fill"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a href="property_detail.php?id=<?= (int) $rec['propertyID'] ?>" class="btn btn-sm btn-primary px-3 fw-semibold">View</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<script>
const hasExactPin = <?= $hasExactPin ? 'true' : 'false' ?>;
const propertyLocationCoords = <?= json_encode($propertyLocationCoords, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailTitle = <?= json_encode((string) $property['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailLocation = <?= json_encode(property_location_label($property), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailCategory = <?= json_encode((string) ($categoryLabels[$property['propertyType']] ?? $property['propertyType']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailPrice = <?= json_encode('₱' . number_format((float) $property['price'], 2), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

document.addEventListener('DOMContentLoaded', () => {
    const mapContainer = document.getElementById('property-detail-map');
    if (!mapContainer) return;
    if (!propertyLocationCoords) {
        mapContainer.textContent = 'Map coordinates are not available for this property yet.';
        return;
    }
    if (typeof L === 'undefined') {
        mapContainer.textContent = 'The map could not load. Check your internet connection and reload the page.';
        return;
    }

    mapContainer.replaceChildren();
    const map = L.map(mapContainer, {
            scrollWheelZoom: false,
            zoomControl: true
        }).setView(propertyLocationCoords, hasExactPin ? 15 : 13);

    const tileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 18,
    }).addTo(map);
    tileLayer.on('tileerror', () => {
        if (mapContainer.dataset.tileError === '1') return;
        mapContainer.dataset.tileError = '1';
        const notice = document.createElement('span');
        notice.className = 'kq-map-error-note';
        notice.textContent = 'Map tiles could not load. Check your internet connection.';
        mapContainer.append(notice);
    });

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
        const popup = document.createElement('div');
        popup.className = 'kq-property-map-popup';
        const category = document.createElement('span');
        category.className = 'badge rounded-pill text-bg-light mb-2';
        category.textContent = propertyDetailCategory;
        const title = document.createElement('strong');
        title.className = 'd-block mb-1';
        title.textContent = propertyDetailTitle;
        const location = document.createElement('span');
        location.className = 'd-block small text-muted mb-1';
        location.textContent = propertyDetailLocation;
        const price = document.createElement('strong');
        price.className = 'text-primary';
        price.textContent = propertyDetailPrice;
        popup.append(category, title, location, price);
        detailMarker.bindPopup(popup);
        detailMarker.openPopup();

    setTimeout(() => map.invalidateSize(), 250);
});
</script>

<?php include 'includes/footer.php'; ?>