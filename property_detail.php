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

$isAdminUser = user_has_role($_SESSION['user'] ?? null, 'Admin');
if ($property['status'] === 'Sold' && !$isAdminUser) {
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
$appointmentDuplicateMessage = '';
$successAction = is_string($_SESSION['flash_success_action'] ?? null)
    ? $_SESSION['flash_success_action']
    : '';
$successDetails = is_array($_SESSION['flash_success_details'] ?? null)
    ? $_SESSION['flash_success_details']
    : [];

if (!empty($_SESSION['flash_success'])) {
    $successMsg = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
unset($_SESSION['flash_success_action'], $_SESSION['flash_success_details']);

$userRole = normalized_user_role($_SESSION['user'] ?? null);
$isStaffUser = user_has_role($_SESSION['user'] ?? null, 'Sales Agent', 'Agent', 'Admin');
$currentUserId = isset($_SESSION['user']['userID']) ? (int) $_SESSION['user']['userID'] : null;
if (!$isSampleListing) {
    record_user_search_preference((string) $property['propertyType'], (int) ($property['locationID'] ?? 0), (float) $property['price']);
}
$mlRecommendations = get_personalized_recommendations($conn, $currentUserId, $propertyID, 3);
$businessTimeZone = new DateTimeZone('Asia/Manila');
$businessNow = new DateTimeImmutable('now', $businessTimeZone);

$submissionAction = isset($_POST['reserve_property'])
    ? 'reservation'
    : (isset($_POST['book_appointment'])
        ? 'appointment'
        : (isset($_POST['send_inquiry']) ? 'inquiry' : null));
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
foreach (['reservation', 'appointment', 'inquiry'] as $actionTokenType) {
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
        header("Location: login.php");
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
    } elseif ($submissionAction === 'reservation') {
        $clientID = (int) $_SESSION['user']['userID'];
        $paymentScheme = is_string($_POST['paymentScheme'] ?? null) ? trim($_POST['paymentScheme']) : '';
        $postedResAmount = $_POST['reservationAmount'] ?? null;
        $reservationAmount = ($postedResAmount === null || trim((string) $postedResAmount) === '')
            ? null
            : filter_var($postedResAmount, FILTER_VALIDATE_FLOAT);
        $paymentRef = is_string($_POST['paymentRef'] ?? null) ? trim($_POST['paymentRef']) : '';
        $reservationNote = is_string($_POST['reservationNote'] ?? null) ? trim($_POST['reservationNote']) : '';

        if ($postedResAmount !== null && trim((string) $postedResAmount) !== '' && ($reservationAmount === false || $reservationAmount <= 0)) {
            $errorMsg = 'Enter a valid reservation fee greater than zero, or leave it blank.';
        } else {
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
                    $errorMsg = 'This property is no longer available for reservation.';
                } else {
                    $reservationTxId = create_property_reservation(
                        $conn,
                        $propertyID,
                        $clientID,
                        $paymentScheme !== '' ? $paymentScheme : null,
                        $reservationAmount === false ? null : ($reservationAmount === null ? null : (float) $reservationAmount),
                        $paymentRef,
                        $reservationNote
                    );
                    $conn->commit();
                    $transactionStarted = false;
                    unset($_SESSION['property_action_tokens'][$propertyID]['reservation']);

                    try {
                        send_property_notification(
                            $conn,
                            $clientID,
                            'Property reservation request received',
                            "Your pre-viewing reservation request for '{$property['title']}' (#KQT-{$reservationTxId}) has been received. Once a Sales Agent confirms your reservation, your 7-day hold begins and you can schedule a property viewing.",
                            'client_dashboard.php#transaction-tracking',
                            'reservation_requested'
                        );
                        notify_agents_new_reservation(
                            $conn,
                            $reservationTxId,
                            $propertyID,
                            $clientID,
                            $paymentScheme !== '' ? $paymentScheme : null
                        );
                    } catch (Throwable $exception) {
                        error_log('KingQuiry reservation notification failed: ' . $exception->getMessage());
                    }

                    $_SESSION['flash_success'] = 'Your property reservation request has been sent. Once confirmed by a Sales Agent, you can schedule your property viewing.';
                    $_SESSION['flash_success_action'] = 'reservation';
                    $_SESSION['flash_success_details'] = [
                        'transactionID' => $reservationTxId,
                    ];
                    header('Location: property_detail.php?id=' . $propertyID . '#reservation-confirmation');
                    exit();
                }
            } catch (InvalidArgumentException $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                $errorMsg = $exception->getMessage();
            } catch (Throwable $exception) {
                if ($transactionStarted) {
                    $conn->rollback();
                }
                error_log('KingQuiry reservation creation failed: ' . $exception->getMessage());
                $errorMsg = 'We could not submit your reservation request. Please try again.';
            }
        }
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
                    $activeReservation = get_active_property_reservation($conn, $propertyID);
                    if (!$activeReservation) {
                        $conn->rollback();
                        $transactionStarted = false;
                        $errorMsg = 'Please reserve this property first before requesting a property viewing.';
                    } elseif ((int) $activeReservation['clientID'] !== $clientID) {
                        $conn->rollback();
                        $transactionStarted = false;
                        $errorMsg = 'This property is currently reserved by another buyer.';
                    } elseif ((string) $activeReservation['currentStage'] === 'reservation_requested') {
                        $conn->rollback();
                        $transactionStarted = false;
                        $errorMsg = 'Your reservation is still pending confirmation. You can request a viewing once a Sales Agent confirms it.';
                    } elseif ((string) $activeReservation['currentStage'] !== 'reservation_confirmed') {
                        $conn->rollback();
                        $transactionStarted = false;
                        $errorMsg = 'A viewing or transaction step is already active for your reservation.';
                    } else {
                        $activeViewingRequest = get_active_property_viewing_request($conn, $propertyID);
                        if ($activeViewingRequest !== null) {
                            $conn->rollback();
                            $transactionStarted = false;
                            $existingDate = date('M j, Y', strtotime((string) $activeViewingRequest['appointmentDate']));
                            $existingTime = date('g:i A', strtotime((string) $activeViewingRequest['appointmentTime']));
                            $appointmentDuplicateMessage = sprintf(
                                'Viewing reference #KQ-%d is already active for this property on %s at %s. Only one active viewing request is allowed per property. Please wait until the visit is completed or the existing request is cancelled.',
                                1000 + (int) $activeViewingRequest['appointmentID'],
                                $existingDate,
                                $existingTime
                            );
                        } else {
                        // ALGORITHM: Lock the listing, allow only one active viewing request, then save booking and tracking atomically.
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
                            : 'Your preferred viewing schedule has been sent to the Sales Agent team for confirmation.';
                        $_SESSION['flash_success_action'] = 'appointment';
                        $_SESSION['flash_success_details'] = [
                            'appointmentID' => $appointmentId,
                            'date' => $date,
                            'time' => $time,
                        ];
                        header('Location: property_detail.php?id=' . $propertyID . '#appointment-confirmation');
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
                                'client_dashboard.php#inquiries',
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
                            ? 'Your inquiry was saved, but we could not create all notifications. You can view your inquiries from your dashboard.'
                            : 'Your inquiry has been sent to our sales team.';
                        $_SESSION['flash_success_action'] = 'inquiry';
                        header('Location: property_detail.php?id=' . $propertyID . '#inquiry-confirmation');
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

<?php if ($successMsg !== '' && !in_array($successAction, ['reservation', 'appointment', 'inquiry'], true)): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($errorMsg !== '' && $appointmentDuplicateMessage === ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

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
            $galleryImages = get_property_gallery_images($conn, $propertyID);
            $imageName = basename((string) ($property['image'] ?? ''));
            if ($imageName === '' || $imageName === 'default.jpg') {
                $imageName = property_category_image((string) $property['propertyType']) ?? '';
            }
            if (empty($galleryImages) && $imageName !== '') {
                $galleryImages = [
                    [
                        'imageID' => 0,
                        'propertyID' => $propertyID,
                        'imagePath' => $imageName,
                        'caption' => 'Property Overview',
                        'isPrimary' => 1
                    ]
                ];
            }
            $activeImage = !empty($galleryImages) ? $galleryImages[0]['imagePath'] : $imageName;
            $activeCaption = !empty($galleryImages) ? ($galleryImages[0]['caption'] ?? '') : '';
            $activePath = __DIR__ . '/assets/images/' . $activeImage;
            $activeCredit = $activeImage !== '' && is_file($activePath) ? get_property_image_credit($activeImage) : null;
            $totalPhotos = count($galleryImages);
            ?>
            <?php if (!empty($galleryImages)): ?>
                <div class="kq-property-gallery-container mb-4" id="propertyGallery">
                    <!-- Main Featured Showcase Photo -->
                    <div class="position-relative overflow-hidden rounded-4 shadow-sm" style="background: #0f172a; min-height: 260px; max-height: 480px; cursor: pointer;" onclick="kqOpenLightbox(currentGalleryIdx)" title="Click to view full photo">
                        <img id="kqMainGalleryPhoto" src="assets/images/<?= rawurlencode($activeImage) ?>" class="w-100 rounded-4" alt="<?= htmlspecialchars($activeCaption ?: $displayTitle, ENT_QUOTES, 'UTF-8') ?>" style="max-height: 480px; object-fit: cover; transition: opacity 0.18s ease;">
                        
                        <?php if ($totalPhotos > 1): ?>
                            <span id="kqGalleryBadge" class="badge bg-dark bg-opacity-75 text-white position-absolute top-0 end-0 m-3 px-3 py-2 rounded-pill shadow-sm" style="font-size: 0.8rem; backdrop-filter: blur(4px);">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="me-1 align-text-bottom"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                <span id="kqGalleryCounter">1 of <?= $totalPhotos ?> photos</span>
                            </span>
                            <div class="position-absolute top-50 start-0 translate-middle-y ps-2">
                                <button type="button" class="btn btn-sm btn-dark bg-opacity-50 text-white rounded-circle p-2 shadow" onclick="event.stopPropagation(); kqGalleryPrev()" aria-label="Previous photo" style="width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; line-height: 1;">‹</button>
                            </div>
                            <div class="position-absolute top-50 end-0 translate-middle-y pe-2">
                                <button type="button" class="btn btn-sm btn-dark bg-opacity-50 text-white rounded-circle p-2 shadow" onclick="event.stopPropagation(); kqGalleryNext()" aria-label="Next photo" style="width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; line-height: 1;">›</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Caption & Credit -->
                    <div class="d-flex flex-wrap justify-content-between align-items-center mt-2 px-1 gap-2">
                        <div id="kqGalleryCaption" class="small text-muted fw-semibold"><?= htmlspecialchars($activeCaption, ENT_QUOTES, 'UTF-8') ?></div>
                        <div id="kqGalleryCreditBox">
                            <?php if ($activeCredit): ?>
                                <span class="small text-muted" style="font-size: 0.75rem;">
                                    Photo credit: <a href="<?= htmlspecialchars($activeCredit['sourceUrl'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($activeCredit['title'] . ' by ' . $activeCredit['creator'], ENT_QUOTES, 'UTF-8') ?></a>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Thumbnail Strip -->
                    <?php if ($totalPhotos > 1): ?>
                        <div class="d-flex gap-2 mt-2 overflow-x-auto pb-1 kq-gallery-thumbs" id="kqGalleryThumbs" style="scrollbar-width: thin;">
                            <?php foreach ($galleryImages as $idx => $img):
                                $thumbFile = basename($img['imagePath']);
                                $thumbCaption = $img['caption'] ?? '';
                            ?>
                                <button type="button" class="btn p-0 border-0 flex-shrink-0 kq-thumb-btn <?= $idx === 0 ? 'is-active' : '' ?>" 
                                    onclick="kqSelectGalleryPhoto(<?= $idx ?>)"
                                    aria-label="View photo <?= $idx + 1 ?>">
                                    <img src="assets/images/<?= rawurlencode($thumbFile) ?>" class="rounded-3" style="width: 86px; height: 60px; object-fit: cover; display: block;" alt="Thumbnail <?= $idx + 1 ?>">
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
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
            <h3 class="h5 fw-bold mt-4">Available documents</h3>
            <p class="text-muted mb-0"><?= !empty($property['availableDocuments']) ? nl2br(htmlspecialchars((string) $property['availableDocuments'], ENT_QUOTES, 'UTF-8')) : 'No documents have been listed. Confirm document availability and legal status directly with the owner and relevant authorities.' ?></p>

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
                        <strong class="text-dark">⏳ 7-Day Reservation Rule (Before Viewing):</strong>
                        <span class="text-muted">Placing and confirming a reservation comes before scheduling a property viewing, starting a strict 7-day hold to complete your viewing, down payment, and compliance IDs.</span>
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
        <?php else:
            $activePropertyReservation = get_active_property_reservation($conn, $propertyID);
            $isReservedByOther = $activePropertyReservation !== null && $currentUserId !== null && (int) $activePropertyReservation['clientID'] !== $currentUserId;
            $isReservedByGuest = $activePropertyReservation !== null && $currentUserId === null;
            $myActiveReservation = ($activePropertyReservation !== null && $currentUserId !== null && (int) $activePropertyReservation['clientID'] === $currentUserId)
                ? $activePropertyReservation
                : null;
            $myResStage = (string) ($myActiveReservation['currentStage'] ?? '');
            $canScheduleViewing = $myActiveReservation !== null && $myResStage === 'reservation_confirmed';
        ?>
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

            <!-- Step 1: Reserve Property (Required Before Viewing) -->
            <div class="card p-4 mb-4 shadow-sm border-warning-subtle" id="reservation">
                <?php if ($successMsg !== '' && $successAction === 'reservation'): ?>
                    <div class="alert alert-success border-success-subtle mb-4" id="reservation-confirmation" role="status" tabindex="-1" style="scroll-margin-top: 6rem;">
                        <div class="d-flex align-items-start gap-3">
                            <i class="bi bi-check-circle-fill fs-4 flex-shrink-0" aria-hidden="true"></i>
                            <div class="flex-grow-1">
                                <h4 class="h6 fw-bold mb-1">Reservation request submitted</h4>
                                <p class="small mb-2"><?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?></p>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="badge rounded-pill text-bg-warning">Awaiting Sales Agent confirmation</span>
                                    <?php if (!empty($successDetails['transactionID'])): ?>
                                        <span class="small text-muted">Ref #KQT-<?= (int) $successDetails['transactionID'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <a href="client_dashboard.php#transaction-tracking" class="btn btn-sm btn-success mt-3">Track reservation</a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="page-eyebrow mb-2">Step 1 · Required before viewing</div>
                <h3 class="h5 fw-bold mb-2">Reserve this property</h3>
                <p class="text-muted small mb-3">In Kings Land Properties, a reservation must be confirmed before scheduling a property viewing. Once confirmed, a 7-day reservation hold begins.</p>

                <?php if ($myActiveReservation !== null): ?>
                    <div class="alert <?= $canScheduleViewing ? 'alert-success' : 'alert-info' ?> small mb-0">
                        <div class="fw-bold mb-1">
                            <?= $canScheduleViewing
                                ? '✅ Reservation Confirmed (#KQT-' . (int) $myActiveReservation['transactionID'] . ')'
                                : ($myResStage === 'reservation_requested'
                                    ? '⏳ Reservation Pending Confirmation (#KQT-' . (int) $myActiveReservation['transactionID'] . ')'
                                    : '📋 Active Transaction (#KQT-' . (int) $myActiveReservation['transactionID'] . ')') ?>
                        </div>
                        <p class="mb-2">
                            <?php if ($canScheduleViewing): ?>
                                Your 7-day reservation hold is active! Use Step 2 below to choose your preferred property viewing date and time.
                            <?php elseif ($myResStage === 'reservation_requested'): ?>
                                Our Sales Agent team is reviewing your reservation request. Once confirmed, the viewing scheduler below will unlock.
                            <?php else: ?>
                                Your reservation and viewing progress are active. Check your Client Dashboard for the latest updates.
                            <?php endif; ?>
                        </p>
                        <a href="client_dashboard.php#transaction-tracking" class="btn btn-sm btn-outline-primary">Open Client Dashboard</a>
                    </div>
                <?php elseif ($isReservedByOther || $isReservedByGuest): ?>
                    <div class="alert alert-warning small mb-0">
                        <strong>Currently Reserved:</strong> Another buyer currently holds an active reservation on this property. Please check back later or browse other available properties.
                    </div>
                <?php elseif (normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
                    <form method="POST" data-confirm="Submit a property reservation request? Once confirmed by a Sales Agent, your 7-day hold will start and you can schedule a viewing.">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="submission_token" value="<?= htmlspecialchars($_SESSION['property_action_tokens'][$propertyID]['reservation'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="reservation-scheme">Preferred payment scheme</label>
                            <select id="reservation-scheme" name="paymentScheme" class="form-select">
                                <option value="">Decide later with Sales Agent</option>
                                <?php foreach (property_transaction_payment_schemes() as $schemeKey => $schemeInfo): ?>
                                    <option value="<?= htmlspecialchars($schemeKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($schemeInfo['label'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold small" for="reservation-amount">Reservation fee paid (₱) <span class="text-muted fw-normal">(optional)</span></label>
                                <input id="reservation-amount" type="number" name="reservationAmount" class="form-control form-control-sm" min="0.01" max="9999999999.99" step="0.01" placeholder="e.g. 20000.00">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label fw-semibold small" for="reservation-ref">Receipt / Ref # <span class="text-muted fw-normal">(optional)</span></label>
                                <input id="reservation-ref" type="text" name="paymentRef" class="form-control form-control-sm" maxlength="100" placeholder="If already paid offline">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small" for="reservation-note">Note for Sales Agent <span class="text-muted fw-normal">(optional)</span></label>
                            <input id="reservation-note" type="text" name="reservationNote" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Ready to settle reservation at the office.">
                        </div>
                        <button type="submit" name="reserve_property" class="btn btn-warning text-dark w-100 py-2 fw-bold shadow-sm">
                            <i class="bi bi-bookmark-check-fill me-1" aria-hidden="true"></i>Request property reservation
                        </button>
                    </form>
                <?php else: ?>
                    <p class="text-muted small">Sign in with a client account to reserve this property before scheduling a viewing.</p>
                    <a href="login.php" class="btn btn-warning text-dark fw-semibold w-100">Sign in to reserve</a>
                <?php endif; ?>
            </div>

            <div class="card p-4 mb-4 shadow-sm border-primary-subtle" id="appointment">
                <?php if ($appointmentDuplicateMessage !== ''): ?>
                    <div class="alert alert-warning mb-3" id="appointment-duplicate-alert" role="alert" tabindex="-1">
                        <div class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Duplicate viewing request</div>
                        <div class="small"><?= htmlspecialchars($appointmentDuplicateMessage, ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($successMsg !== '' && $successAction === 'appointment'): ?>
                    <div class="alert alert-success border-success-subtle mb-4" id="appointment-confirmation" role="status" tabindex="-1" style="scroll-margin-top: 6rem;">
                        <div class="d-flex align-items-start gap-3">
                            <i class="bi bi-check-circle-fill fs-4 flex-shrink-0" aria-hidden="true"></i>
                            <div class="flex-grow-1">
                                <h4 class="h6 fw-bold mb-1">Viewing request submitted successfully</h4>
                                <p class="small mb-2"><?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?></p>
                                <?php if (!empty($successDetails['date']) && !empty($successDetails['time'])): ?>
                                    <div class="small fw-semibold mb-2">
                                        Preferred schedule:
                                        <?= htmlspecialchars(date('M j, Y', strtotime((string) $successDetails['date'])), ENT_QUOTES, 'UTF-8') ?>
                                        at
                                        <?= htmlspecialchars(date('g:i A', strtotime((string) $successDetails['time'])), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endif; ?>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <span class="badge rounded-pill text-bg-warning">Awaiting Sales Agent confirmation</span>
                                    <?php if (!empty($successDetails['appointmentID'])): ?>
                                        <span class="small text-muted">Reference #KQ-<?= 1000 + (int) $successDetails['appointmentID'] ?></span>
                                    <?php endif; ?>
                                </div>
                                <a href="client_dashboard.php#viewing-requests" class="btn btn-sm btn-success mt-3">Track viewing request</a>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="page-eyebrow mb-2">Step 2 · Visit the property</div>
                <h3 class="h5 fw-bold mb-2">Request a viewing</h3>
                <p class="text-muted small mb-3">Choose a preferred date and time. Only confirmed reservations can schedule a site viewing.</p>
                <?php if ($canScheduleViewing && normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
                    <form method="POST" data-confirm="Submit your preferred viewing date and time? Your request will be sent to a Sales Agent, and the visit is not confirmed until the agent approves it.">
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
                        <button type="submit" name="book_appointment" class="btn btn-primary w-100 py-2 fw-semibold">
                            <i class="bi bi-calendar-check me-1" aria-hidden="true"></i>Request a viewing
                        </button>
                    </form>
                <?php elseif ($myActiveReservation !== null && $myResStage === 'reservation_requested'): ?>
                    <div class="alert alert-light border small text-muted mb-0">
                        🔒 <strong>Waiting for reservation confirmation:</strong> Once a Sales Agent confirms reservation #KQT-<?= (int) $myActiveReservation['transactionID'] ?>, this viewing form will unlock.
                    </div>
                <?php elseif ($myActiveReservation !== null): ?>
                    <div class="alert alert-light border small text-muted mb-0">
                        📅 You already have an active viewing or transaction stage for this property. <a href="client_dashboard.php#transaction-tracking">View status in your dashboard</a>.
                    </div>
                <?php elseif ($isReservedByOther || $isReservedByGuest): ?>
                    <div class="alert alert-light border small text-muted mb-0">
                        🔒 Property viewing is currently unavailable while another buyer holds an active reservation.
                    </div>
                <?php elseif (normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
                    <div class="alert alert-light border small text-muted mb-0">
                        🔒 <strong>Reservation required first:</strong> Submit and confirm your property reservation in Step 1 above before requesting a viewing.
                    </div>
                <?php else: ?>
                    <p class="text-muted small">Sign in with a client account, reserve the property first, and then schedule a viewing.</p>
                    <a href="login.php" class="btn btn-outline-primary w-100">Sign in to continue</a>
                <?php endif; ?>
            </div>

            <div class="card p-4" id="inquiry">
                <?php if ($successMsg !== '' && $successAction === 'inquiry'): ?>
                    <div class="alert alert-success" id="inquiry-confirmation" role="status" tabindex="-1" style="scroll-margin-top: 6rem;">
                        <strong>Inquiry sent successfully.</strong>
                        <div class="small"><?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?></div>
                        <a href="client_dashboard.php#transaction-tracking" class="btn btn-sm btn-success mt-2">Track inquiry</a>
                    </div>
                <?php endif; ?>
                <div class="page-eyebrow mb-2">Have a question?</div>
                <h3 class="h5 fw-bold mb-3">Contact the property team</h3>
                <?php if(normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
                    <form method="POST" data-single-submit data-confirm="<?= htmlspecialchars(
                        'Send your inquiry about "' . $displayTitle . '" to the property team? Confirm to send it once.',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>">
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
                    <a href="login.php" class="btn btn-outline-primary w-100">Sign in to continue</a>
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
const hasDuplicateViewingRequest = <?= $appointmentDuplicateMessage !== '' ? 'true' : 'false' ?>;
const hasExactPin = <?= $hasExactPin ? 'true' : 'false' ?>;
const propertyLocationCoords = <?= json_encode($propertyLocationCoords, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailTitle = <?= json_encode((string) $property['title'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailLocation = <?= json_encode(property_location_label($property), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailCategory = <?= json_encode((string) ($categoryLabels[$property['propertyType']] ?? $property['propertyType']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const propertyDetailPrice = <?= json_encode('₱' . number_format((float) $property['price'], 2), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

document.addEventListener('DOMContentLoaded', () => {
    const successNoticeId = <?= json_encode(
        $successAction === 'reservation' ? 'reservation-confirmation'
            : ($successAction === 'appointment' ? 'appointment-confirmation'
                : ($successAction === 'inquiry' ? 'inquiry-confirmation' : ''))
    ) ?>;
    if (successNoticeId !== '') {
        const successNotice = document.getElementById(successNoticeId);
        if (successNotice) {
            successNotice.focus({ preventScroll: true });
            requestAnimationFrame(() => {
                successNotice.scrollIntoView({ behavior: 'smooth', block: 'center' });
                window.setTimeout(
                    () => successNotice.scrollIntoView({ behavior: 'smooth', block: 'center' }),
                    300
                );
            });
        }
    }

    if (hasDuplicateViewingRequest) {
        const duplicateAlert = document.getElementById('appointment-duplicate-alert');
        if (duplicateAlert) {
            duplicateAlert.focus({ preventScroll: true });
            duplicateAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

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

const kqGalleryPhotos = <?= json_encode(array_map(function($img) {
    $file = basename($img['imagePath']);
    $credit = get_property_image_credit($file);
    return [
        'src' => 'assets/images/' . rawurlencode($file),
        'caption' => $img['caption'] ?? '',
        'creditTitle' => $credit['title'] ?? null,
        'creditCreator' => $credit['creator'] ?? null,
        'creditUrl' => $credit['sourceUrl'] ?? null,
    ];
}, $galleryImages), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

let currentGalleryIdx = 0;

function kqSelectGalleryPhoto(idx) {
    if (!kqGalleryPhotos || !kqGalleryPhotos[idx]) return;
    currentGalleryIdx = idx;
    const photo = kqGalleryPhotos[idx];
    const mainImg = document.getElementById('kqMainGalleryPhoto');
    if (mainImg) {
        mainImg.style.opacity = '0.3';
        setTimeout(() => {
            mainImg.src = photo.src;
            mainImg.style.opacity = '1';
        }, 120);
    }
    const counter = document.getElementById('kqGalleryCounter');
    if (counter) counter.textContent = (idx + 1) + ' of ' + kqGalleryPhotos.length + ' photos';
    
    const captionEl = document.getElementById('kqGalleryCaption');
    if (captionEl) captionEl.textContent = photo.caption || '';
    
    const creditBox = document.getElementById('kqGalleryCreditBox');
    if (creditBox) {
        if (photo.creditTitle && photo.creditUrl) {
            creditBox.innerHTML = '<span class="small text-muted" style="font-size: 0.75rem;">Photo credit: <a href="' + photo.creditUrl + '" target="_blank" rel="noopener noreferrer">' + photo.creditTitle + ' by ' + (photo.creditCreator || '') + '</a></span>';
        } else {
            creditBox.innerHTML = '';
        }
    }
    
    document.querySelectorAll('.kq-thumb-btn').forEach((btn, i) => {
        btn.classList.toggle('is-active', i === idx);
    });
}

function kqGalleryNext() {
    if (!kqGalleryPhotos || kqGalleryPhotos.length <= 1) return;
    const next = (currentGalleryIdx + 1) % kqGalleryPhotos.length;
    kqSelectGalleryPhoto(next);
}

function kqGalleryPrev() {
    if (!kqGalleryPhotos || kqGalleryPhotos.length <= 1) return;
    const prev = (currentGalleryIdx - 1 + kqGalleryPhotos.length) % kqGalleryPhotos.length;
    kqSelectGalleryPhoto(prev);
}

function kqOpenLightbox(idx) {
    const modalEl = document.getElementById('propertyLightboxModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        kqSelectLightboxPhoto(idx >= 0 ? idx : currentGalleryIdx);
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
}

function kqSelectLightboxPhoto(idx) {
    if (!kqGalleryPhotos || !kqGalleryPhotos[idx]) return;
    currentGalleryIdx = idx;
    const photo = kqGalleryPhotos[idx];
    const lightImg = document.getElementById('lightboxMainPhoto');
    if (lightImg) lightImg.src = photo.src;
    const counter = document.getElementById('lightboxCounter');
    if (counter) counter.textContent = 'Photo ' + (idx + 1) + ' of ' + kqGalleryPhotos.length;
    const caption = document.getElementById('lightboxCaption');
    if (caption) caption.textContent = photo.caption || '';
    kqSelectGalleryPhoto(idx);
}

function kqLightboxNext() {
    if (!kqGalleryPhotos || kqGalleryPhotos.length <= 1) return;
    const next = (currentGalleryIdx + 1) % kqGalleryPhotos.length;
    kqSelectLightboxPhoto(next);
}

function kqLightboxPrev() {
    if (!kqGalleryPhotos || kqGalleryPhotos.length <= 1) return;
    const prev = (currentGalleryIdx - 1 + kqGalleryPhotos.length) % kqGalleryPhotos.length;
    kqSelectLightboxPhoto(prev);
}
</script>

<!-- Property Photo Lightbox Modal -->
<div class="modal fade" id="propertyLightboxModal" tabindex="-1" aria-labelledby="propertyLightboxLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content bg-dark text-white border-0 shadow-lg" style="border-radius: 1rem; overflow: hidden;">
            <div class="modal-header border-0 pb-0 justify-content-between px-3 pt-3 px-md-4 pt-md-4">
                <div>
                    <h5 class="modal-title small text-white-50" id="propertyLightboxLabel"><?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?></h5>
                    <div id="lightboxCounter" class="small text-white fw-bold"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-2 p-md-4 position-relative">
                <img id="lightboxMainPhoto" src="" class="img-fluid rounded-3 shadow" style="max-height: 75vh; object-fit: contain;" alt="Full photo">
                <div class="position-absolute top-50 start-0 translate-middle-y ps-3 ps-md-4">
                    <button type="button" class="btn btn-outline-light rounded-circle shadow p-2" onclick="kqLightboxPrev()" aria-label="Previous" style="width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">‹</button>
                </div>
                <div class="position-absolute top-50 end-0 translate-middle-y pe-3 pe-md-4">
                    <button type="button" class="btn btn-outline-light rounded-circle shadow p-2" onclick="kqLightboxNext()" aria-label="Next" style="width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">›</button>
                </div>
                <div id="lightboxCaption" class="mt-3 text-center text-white-50 small fw-medium"></div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>