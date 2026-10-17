<?php
include 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'Sales Agent') {
    header("Location: login.php");
    exit();
}

include 'includes/header.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $appointmentID = intval($_POST['appointmentID']);
    $newStatus = $_POST['status'];
    $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE appointmentID = ?");
    $stmt->bind_param("si", $newStatus, $appointmentID);
    $stmt->execute();
    header("Location: agent_dashboard.php");
    exit();
}

$appointments = $conn->query("SELECT a.*, p.title, p.location, u.fullname as clientName, u.email as clientEmail FROM appointments a JOIN properties p ON a.propertyID = p.propertyID JOIN users u ON a.clientID = u.userID ORDER BY a.appointmentID DESC");
$inquiries = $conn->query("SELECT i.*, p.title, u.fullname as clientName FROM feedbackinquiries i JOIN properties p ON i.propertyID = p.propertyID JOIN users u ON i.clientID = u.userID ORDER BY i.inquiryID DESC");
?>

<div class="row mb-4">
    <div class="col-md-12">
        <h2 class="fw-bold">Sales Agent Portal</h2>
        <p class="text-muted">Welcome, Agent <?= htmlspecialchars($_SESSION['user']['fullname']) ?>! Review and approve client viewing schedules.</p>
    </div>
</div>

<div class="card shadow-sm border-0 p-4 mb-5">
    <h4 class="fw-bold mb-3 text-primary">📅 Client Viewing Appointments</h4>
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
                            <td><?= htmlspecialchars($appt['location']) ?></td>
                            <td><?= htmlspecialchars($appt['appointmentDate']) ?> (<?= htmlspecialchars($appt['appointmentTime']) ?>)</td>
                            <td><span class="badge <?= $appt['status'] == 'Approved' ? 'bg-success' : ($appt['status'] == 'Cancelled' ? 'bg-danger' : 'bg-warning text-dark') ?>"><?= $appt['status'] ?></span></td>
                            <td>
                                <form method="POST" class="d-flex gap-1">
                                    <input type="hidden" name="appointmentID" value="<?= $appt['appointmentID'] ?>">
                                    <select name="status" class="form-select form-select-sm" style="width: 110px;">
                                        <option value="Pending" <?= $appt['status'] == 'Pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="Approved" <?= $appt['status'] == 'Approved' ? 'selected' : '' ?>>Approved</option>
                                        <option value="Cancelled" <?= $appt['status'] == 'Cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                    <button type="submit" name="update_status" class="btn btn-sm btn-primary">Save</button>
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
</div>

<?php include 'includes/footer.php'; ?>