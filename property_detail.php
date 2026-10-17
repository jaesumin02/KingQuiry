<?php
include 'config/db.php';
include 'includes/header.php';

$propertyID = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Kunin ang detalye ng property mula sa database
$stmt = $conn->prepare("SELECT * FROM properties WHERE propertyID = ?");
$stmt->bind_param("i", $propertyID);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<div class='alert alert-danger'>Property not found or invalid ID.</div>";
    include 'includes/footer.php';
    exit();
}

$property = $result->fetch_assoc();

$successMsg = "";
$errorMsg = "";

// Handle Appointment Booking / Property Inquiry (Client Side)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_SESSION['user'])) {
        header("Location: login.php");
        exit();
    }
    
    $clientID = $_SESSION['user']['userID'];

    if (isset($_POST['book_appointment'])) {
        $date = $_POST['appointmentDate'];
        $time = $_POST['appointmentTime'];
        
        $bookStmt = $conn->prepare("INSERT INTO appointments (propertyID, clientID, appointmentDate, appointmentTime, status) VALUES (?, ?, ?, ?, 'Pending')");
        $bookStmt->bind_param("iiss", $propertyID, $clientID, $date, $time);
        if ($bookStmt->execute()) {
            $successMsg = "Viewing appointment successfully booked! You can track its live status on your dashboard.";
        } else {
            $errorMsg = "Failed to book appointment. Please try again.";
        }
    }

    if (isset($_POST['send_inquiry'])) {
        $message = trim($_POST['message']);
        
        $inqStmt = $conn->prepare("INSERT INTO feedbackinquiries (propertyID, clientID, message, status) VALUES (?, ?, ?, 'Pending')");
        $inqStmt->bind_param("iis", $propertyID, $clientID, $message);
        if ($inqStmt->execute()) {
            $successMsg = "Inquiry sent successfully! Our sales agent will reach out to you shortly.";
        } else {
            $errorMsg = "Failed to send inquiry. Please try again.";
        }
    }
}
?>

<div class="row mb-3">
    <div class="col-md-12">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">&larr; Back to Catalog</a>
    </div>
</div>

<?php if($successMsg): ?><div class="alert alert-success"><?= $successMsg ?></div><?php endif; ?>
<?php if($errorMsg): ?><div class="alert alert-danger"><?= $errorMsg ?></div><?php endif; ?>

<div class="row">
    <!-- Property Detailed Information -->
    <div class="col-md-7 mb-4">
        <div class="card shadow-sm border-0 p-4">
            <span class="badge bg-dark w-25 mb-2"><?= htmlspecialchars($property['propertyType']) ?></span>
            <h2 class="fw-bold mb-2"><?= htmlspecialchars($property['title']) ?></h2>
            <p class="text-muted mb-3">📍 Location: <strong><?= htmlspecialchars($property['location']) ?></strong></p>
            
            <img src="assets/images/<?= htmlspecialchars($property['image'] ?? '') ?>" class="img-fluid rounded mb-4" alt="Property Image" style="max-height: 350px; width: 100%; object-fit: cover;" onerror="this.src='https://via.placeholder.com/600x350?text=KingQuiry+Property'">

            <div class="bg-light p-3 rounded mb-4">
                <h4 class="text-primary fw-bold mb-1">₱<?= number_format($property['price'], 2) ?></h4>
                <span class="text-muted">Price per Square Meter: <strong>₱<?= number_format($property['pricePerSqm'] ?? 0, 2) ?> / sq.m.</strong></span>
            </div>

            <h5 class="fw-bold">Land Characteristics</h5>
            <p class="text-dark mb-3"><?= htmlspecialchars($property['landCharacteristics'] ?? 'Standard real estate land feature') ?></p>

            <h5 class="fw-bold">Description</h5>
            <p class="text-muted"><?= nl2br(htmlspecialchars($property['description'])) ?></p>
        </div>
    </div>

    <!-- Booking & Inquiry Action Forms for Clients -->
    <div class="col-md-5">
        <div class="card shadow-sm border-0 p-4 mb-4">
            <h4 class="fw-bold mb-3 text-primary">📅 Book Viewing Appointment</h4>
            <?php if(isset($_SESSION['user'])): ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Preferred Date</label>
                        <input type="date" name="appointmentDate" class="form-control" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Preferred Time</label>
                        <input type="time" name="appointmentTime" class="form-control" required>
                    </div>
                    <button type="submit" name="book_appointment" class="btn btn-primary w-100 fw-bold">Submit Viewing Request</button>
                </form>
            <?php else: ?>
                <p class="text-muted small">You must be logged in as a Client to schedule an on-site viewing.</p>
                <a href="login.php" class="btn btn-dark w-100">Login to Book</a>
            <?php endif; ?>
        </div>

        <div class="card shadow-sm border-0 p-4">
            <h4 class="fw-bold mb-3 text-secondary">💬 Send Inquiry / Message</h4>
            <?php if(isset($_SESSION['user'])): ?>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Your Message or Question</label>
                        <textarea name="message" class="form-control" rows="3" required placeholder="Ask about payment terms, documents, etc..."></textarea>
                    </div>
                    <button type="submit" name="send_inquiry" class="btn btn-secondary w-100">Send Message</button>
                </form>
            <?php else: ?>
                <p class="text-muted small">Please log in to send a direct inquiry regarding this property.</p>
                <a href="login.php" class="btn btn-outline-dark w-100">Login to Inquire</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>