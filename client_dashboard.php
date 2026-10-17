<?php
include 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'Client') {
    header("Location: login.php");
    exit();
}

include 'includes/header.php';
$clientID = $_SESSION['user']['userID'];
$appointments = $conn->query("SELECT a.*, p.title, p.location FROM appointments a JOIN properties p ON a.propertyID = p.propertyID WHERE a.clientID = $clientID ORDER BY a.appointmentID DESC");
$inquiries = $conn->query("SELECT i.*, p.title FROM feedbackinquiries i JOIN properties p ON i.propertyID = p.propertyID WHERE i.clientID = $clientID ORDER BY i.inquiryID DESC");
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h2 class="fw-bold">Client Dashboard</h2>
        <p class="text-muted">Welcome, <?= htmlspecialchars($_SESSION['user']['fullname']) ?>! Track your property viewing status and inquiries.</p>
    </div>
</div>

<div class="card shadow-sm border-0 p-4 mb-5 bg-white rounded border-start border-primary border-4">
    <h4 class="fw-bold mb-2 text-primary">📦 Live Transaction Tracking</h4>
    <?php if ($appointments && $appointments->num_rows > 0): ?>
        <?php while($appt = $appointments->fetch_assoc()): ?>
            <div class="border rounded p-3 mb-3 bg-light">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($appt['title']) ?> <span class="small text-muted">(📍 <?= htmlspecialchars($appt['location']) ?>)</span></h5>
                    <span class="badge bg-primary">Ref ID: #KQ-<?= 1000 + $appt['appointmentID'] ?></span>
                </div>
                <p class="small text-muted mb-2">Schedule: <strong><?= $appt['appointmentDate'] ?></strong> at <strong><?= $appt['appointmentTime'] ?></strong></p>
                <div class="row text-center text-muted small my-3">
                    <div class="col"><div class="p-2 rounded bg-success text-white fw-bold mb-1">1. Booked</div><span>Request Sent</span></div>
                    <div class="col"><div class="p-2 rounded <?= $appt['status'] == 'Approved' ? 'bg-success text-white' : 'bg-warning text-dark' ?> fw-bold mb-1">2. Verification</div><span><?= $appt['status'] == 'Approved' ? 'Verified' : 'Pending' ?></span></div>
                    <div class="col"><div class="p-2 rounded <?= $appt['status'] == 'Approved' ? 'bg-primary text-white' : 'bg-secondary text-white' ?> fw-bold mb-1">3. Site Visit</div><span><?= $appt['status'] == 'Approved' ? 'Scheduled' : 'Awaiting' ?></span></div>
                    <div class="col"><div class="p-2 rounded bg-secondary text-white fw-bold mb-1">4. Closed</div><span>Contract</span></div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <p class="text-center text-muted mb-0">No active bookings found. Explore the <a href="index.php">Catalog</a>.</p>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>