<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
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

$categoryLabels = [
    'Residential' => 'Residential Lot',
    'Farmlot'     => 'Farm Lot',
    'Beachlot'    => 'Beach Lot',
    'Condo'       => 'Condo',
    'Commercial'  => 'Commercial Property',
];
$propertyTitle = (string) $property['title'];
$isSampleListing = str_starts_with($propertyTitle, '[SAMPLE]');
if ($isSampleListing) {
    http_response_code(404);
    include 'includes/header.php';
    echo "<div class='alert alert-warning'>This property is not available.</div>";
    include 'includes/footer.php';
    exit();
}
$displayTitle = $propertyTitle;

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

$currentUserId = isset($_SESSION['user']['userID']) ? (int) $_SESSION['user']['userID'] : null;
record_user_search_preference((string) $property['propertyType'], (int) ($property['locationID'] ?? 0), (float) $property['price']);
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
                            $errorMsg = "Schedule conflict: This property already has a scheduled site visit {$clashTime}. To ensure dedicated agent assistance and avoid overlapping viewings, please choose a time at least one hour before or after.";
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
                                    "Thank you for booking a site visit for '{$property['title']}'. Your viewing request has been received and is now being reviewed by our sales team. Please monitor your dashboard for updates.",
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
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($categoryLabels[$property['propertyType']] ?? $property['propertyType'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <h2 class="fw-bold mb-2"><?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?></h2>
            <p class="text-muted mb-3">Location: <strong><?= htmlspecialchars(property_location_label($property), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($property['barangay'])): ?>, <?= htmlspecialchars($property['barangay'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></strong></p>
            
            <?php
            $imageName = basename((string) ($property['image'] ?? ''));
            $imagePath = __DIR__ . '/assets/images/' . $imageName;
            $imageCredit = $imageName !== '' && is_file($imagePath) ? get_property_image_credit($imageName) : null;
            ?>
            <?php if ($imageName !== '' && is_file($imagePath)): ?>
                <img src="assets/images/<?= rawurlencode($imageName) ?>" class="img-fluid rounded-4 <?= $imageCredit ? 'mb-1' : 'mb-4' ?>" alt="<?= htmlspecialchars($imageCredit ? 'Representative image for ' . $displayTitle : $displayTitle, ENT_QUOTES, 'UTF-8') ?>" style="max-height: 420px; width: 100%; object-fit: cover;">
                <?php if ($imageCredit): ?>
                    <p class="small text-muted mb-4" style="font-size: .78rem;">
                        Representative image. <a href="<?= htmlspecialchars($imageCredit['sourceUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($imageCredit['title'] . ' by ' . $imageCredit['creator'], ENT_QUOTES, 'UTF-8') ?></a>,
                        <a href="<?= htmlspecialchars($imageCredit['licenseUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($imageCredit['license'], ENT_QUOTES, 'UTF-8') ?></a>.
                    </p>
                <?php endif; ?>
            <?php else: ?>
                <div class="property-placeholder rounded-4 mb-4 d-flex align-items-center justify-content-center" style="height:280px" aria-label="No property image available"><span class="property-placeholder-mark">KQ</span></div>
            <?php endif; ?>

            <div class="bg-light p-3 rounded mb-4">
                <h4 class="text-primary fw-bold mb-1">₱<?= number_format($property['price'], 2) ?></h4>
                <?php if (!empty($property['areaSqm'])): ?><div class="text-muted small mb-1"><?= $property['propertyType'] === 'Condo' ? 'Floor area' : 'Land area' ?>: <strong><?= number_format((float) $property['areaSqm'], 2) ?> sq.m.</strong></div><?php endif; ?>
                <span class="text-muted">Price per Square Meter: <strong>₱<?= number_format($property['pricePerSqm'] ?? 0, 2) ?> / sq.m.</strong></span>
            </div>

            <h5 class="fw-bold"><?= $property['propertyType'] === 'Condo' ? 'Unit Characteristics' : 'Land Characteristics' ?></h5>
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
            <div id="property-detail-map" class="kq-property-map" aria-label="Map location for <?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?>" style="height: 300px; width: 100%; border-radius: .85rem; overflow: hidden; border: 1px solid var(--kq-line);">
                <span class="kq-map-fallback">Loading property map…</span>
            </div>
            <p class="small text-muted mt-2 mb-0">
                <?= $hasExactPin ? 'Showing the saved property pin.' : ($propertyLocationCoords ? 'Approximate location: municipality center. Ask the agent to confirm the exact property pin.' : 'Map coordinates are not available for this property yet.') ?>
            </p>

            <div class="small text-muted mt-2 d-flex align-items-center justify-content-center gap-2">
                <span class="d-inline-block rounded-circle" style="width: 8px; height: 8px; background-color: var(--kq-primary, #174a70);"></span>
                <span>Pinned: <strong><?= htmlspecialchars($property['title'], ENT_QUOTES, 'UTF-8') ?></strong></span>
            </div>
        </div>

        <?php if ($property['status'] === 'Sold'): ?>
            <div class="card p-4 text-center border-0 shadow-sm bg-light mb-4">
                <div class="rounded-circle bg-danger-subtle text-danger mx-auto d-flex align-items-center justify-content-center mb-3" style="width: 54px; height: 54px;">
                    <span class="fs-4">🏷️</span>
                </div>
                <span class="badge bg-danger fs-6 px-3 py-1 mb-2 align-self-center">Off the Market</span>
                <h4 class="h5 fw-bold mb-2">Property Officially Sold</h4>
                <p class="text-muted small mb-3">This property is officially owned and is no longer available for site viewings or inquiries.</p>
                <a href="index.php" class="btn btn-primary w-100">Browse Available Properties</a>
                <a href="property_summary_pdf.php?property_id=<?= (int) $property['propertyID'] ?>" target="_blank" class="btn btn-outline-secondary w-100 mt-2">Download archived summary PDF</a>
            </div>
        <?php else: ?>
            <div class="card p-4 mb-4" id="appointment">
                <div class="page-eyebrow mb-2">Visit the property</div>
                <h3 class="h5 fw-bold mb-3">Request a viewing</h3>
                <?php if(normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
                    <form method="POST">
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
                                <a href="property_detail.php?id=<?= (int) $rec['propertyID'] ?>" class="btn btn-sm btn-primary px-3 fw-semibold">View</a>
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