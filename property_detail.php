<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
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

$successMsg = "";
$errorMsg = "";

if (!empty($_SESSION['flash_success'])) {
    $successMsg = (string) $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (normalized_user_role($_SESSION['user'] ?? null) !== 'Client') {
        header("Location: login.php");
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
            <span class="badge rounded-pill text-bg-light align-self-start mb-2"><?= htmlspecialchars($property['propertyType'], ENT_QUOTES, 'UTF-8') ?></span>
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

    <!-- Booking & Inquiry Action Forms for Clients -->
    <div class="col-md-5">
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
            <?php else: ?>
                <p class="text-muted small">Sign in with a client account to request a viewing.</p>
                <a href="login.php" class="btn btn-primary w-100">Sign in to continue</a>
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
                <a href="login.php" class="btn btn-outline-primary w-100">Sign in to continue</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>