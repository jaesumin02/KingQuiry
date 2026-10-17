<?php
include 'config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] != 'Admin') {
    header("Location: login.php");
    exit();
}

include 'includes/header.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_property'])) {
    $title = $_POST['title'];
    $description = $_POST['description'];
    $price = $_POST['price'];
    $pricePerSqm = $_POST['pricePerSqm'];
    $location = trim($_POST['location']);
    $propertyType = $_POST['propertyType'];
    $landCharacteristics = $_POST['landCharacteristics'];
    
    $stmt = $conn->prepare("INSERT INTO properties (title, description, price, pricePerSqm, location, propertyType, landCharacteristics) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssddsss", $title, $description, $price, $pricePerSqm, $location, $propertyType, $landCharacteristics);
    $stmt->execute();
    header("Location: admin_dashboard.php");
    exit();
}

if (isset($_GET['delete_prop'])) {
    $id = intval($_GET['delete_prop']);
    $conn->query("DELETE FROM properties WHERE propertyID = $id");
    header("Location: admin_dashboard.php");
    exit();
}

$totalProperties = $conn->query("SELECT COUNT(*) as count FROM properties")->fetch_assoc()['count'];
$totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$totalAppointments = $conn->query("SELECT COUNT(*) as count FROM appointments")->fetch_assoc()['count'];
$totalInquiries = $conn->query("SELECT COUNT(*) as count FROM feedbackinquiries")->fetch_assoc()['count'];

$catResidential = $conn->query("SELECT COUNT(*) as count FROM properties WHERE propertyType='Residential'")->fetch_assoc()['count'];
$catCommercial = $conn->query("SELECT COUNT(*) as count FROM properties WHERE propertyType='Commercial'")->fetch_assoc()['count'];
$catFarmlot = $conn->query("SELECT COUNT(*) as count FROM properties WHERE propertyType='Farmlot'")->fetch_assoc()['count'];
$catBeachlot = $conn->query("SELECT COUNT(*) as count FROM properties WHERE propertyType='Beachlot'")->fetch_assoc()['count'];

$appPending = $conn->query("SELECT COUNT(*) as count FROM appointments WHERE status='Pending'")->fetch_assoc()['count'];
$appApproved = $conn->query("SELECT COUNT(*) as count FROM appointments WHERE status='Approved'")->fetch_assoc()['count'];
$appCancelled = $conn->query("SELECT COUNT(*) as count FROM appointments WHERE status='Cancelled'")->fetch_assoc()['count'];

$properties = $conn->query("SELECT * FROM properties ORDER BY propertyID DESC");
$users = $conn->query("SELECT * FROM users ORDER BY userID DESC");
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<h2 class="fw-bold mb-4">👑 Admin Dashboard & Business Analytics</h2>

<div class="row mb-4">
    <div class="col-md-3 mb-3"><div class="card bg-primary text-white p-3"><h6 class="small fw-bold">Total Properties</h6><h2 class="fw-bold mb-0"><?= $totalProperties ?></h2></div></div>
    <div class="col-md-3 mb-3"><div class="card bg-success text-white p-3"><h6 class="small fw-bold">Registered Users</h6><h2 class="fw-bold mb-0"><?= $totalUsers ?></h2></div></div>
    <div class="col-md-3 mb-3"><div class="card bg-warning text-dark p-3"><h6 class="small fw-bold">Appointments</h6><h2 class="fw-bold mb-0"><?= $totalAppointments ?></h2></div></div>
    <div class="col-md-3 mb-3"><div class="card bg-info text-white p-3"><h6 class="small fw-bold">Inquiries</h6><h2 class="fw-bold mb-0"><?= $totalInquiries ?></h2></div></div>
</div>

<div class="row mb-5">
    <div class="col-md-7 mb-3"><div class="card p-4 h-100"><h4 class="fw-bold mb-3 text-secondary">📊 Property Categories</h4><canvas id="propertyCategoryChart" style="max-height: 250px;"></canvas></div></div>
    <div class="col-md-5 mb-3"><div class="card p-4 h-100"><h4 class="fw-bold mb-3 text-secondary">🥧 Appointment Status</h4><canvas id="appointmentStatusChart" style="max-height: 250px;"></canvas></div></div>
</div>

<!-- MACHINE LEARNING PREDICTOR MODULE -->
<div class="card shadow-sm border-0 p-4 mb-5 bg-white border-start border-success border-4">
    <h4 class="fw-bold mb-2 text-success">🤖 KingQuiry AI & Machine Learning Price Predictor</h4>
    <p class="text-muted small mb-4">Predict property market values based on historical regression models and square meter area.</p>

    <?php
    $mlQuery = $conn->query("SELECT AVG(pricePerSqm) as avgSqmPrice FROM properties WHERE pricePerSqm > 0");
    $mlData = $mlQuery->fetch_assoc();
    $avgSqmPrice = $mlData['avgSqmPrice'] ? $mlData['avgSqmPrice'] : 1500.00;

    $predictedPrice = 0;
    $inputSqm = 0;
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['run_prediction'])) {
        $inputSqm = floatval($_POST['sqm_input']);
        $locationFactor = floatval($_POST['location_factor']);
        $predictedPrice = ($inputSqm * $avgSqmPrice) * $locationFactor;
    }
    ?>

    <form method="POST" class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Land Area (Sq.M.)</label>
            <input type="number" step="any" name="sqm_input" class="form-control" required placeholder="e.g. 500" value="<?= $inputSqm ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">Location Weight</label>
            <select name="location_factor" class="form-select" required>
                <option value="1.2">Beachlot / Coastal (High Demand)</option>
                <option value="1.1">Commercial / Poblacion</option>
                <option value="1.0">Residential Subdivision</option>
                <option value="0.9">Farmlot / Agricultural</option>
            </select>
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <button type="submit" name="run_prediction" class="btn btn-success w-100 fw-bold">Run ML Price Prediction</button>
        </div>
    </form>

    <?php if ($predictedPrice > 0): ?>
        <div class="alert alert-success mt-4 mb-0">
            <h5 class="fw-bold mb-1">🎯 ML Prediction Result:</h5>
            <p class="mb-0">Estimated Market Value for <strong><?= number_format($inputSqm, 2) ?> sq.m.</strong> is <strong class="text-dark">₱<?= number_format($predictedPrice, 2) ?></strong> (Based on avg ₱<?= number_format($avgSqmPrice, 2) ?>/sqm regression weight).</p>
        </div>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0 p-4 mb-4">
    <h4 class="fw-bold mb-3 text-danger">➕ Add Property / Land Listing</h4>
    <form method="POST">
        <div class="row">
            <div class="col-md-4 mb-3"><label class="form-label">Title</label><input type="text" name="title" class="form-control" required placeholder="Sunset Farmlot"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Total Price (₱)</label><input type="number" step="0.01" name="price" class="form-control" required placeholder="1500000"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Price per Sq.M. (₱)</label><input type="number" step="0.01" name="pricePerSqm" class="form-control" required placeholder="1500"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Category</label><select name="propertyType" class="form-select" required><option value="Residential">Residential</option><option value="Commercial">Commercial</option><option value="Farmlot">Farmlot</option><option value="Beachlot">Beachlot</option></select></div>
            <div class="col-md-4 mb-3"><label class="form-label">Location (Batangas)</label><input type="text" name="location" class="form-control" required placeholder="Tuy, Calatagan, Nasugbu, Lian"></div>
            <div class="col-md-4 mb-3"><label class="form-label">Land Characteristics</label><input type="text" name="landCharacteristics" class="form-control" required placeholder="Flat terrain, near main road"></div>
            <div class="col-md-12 mb-3"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2" required placeholder="Details..."></textarea></div>
        </div>
        <button type="submit" name="add_property" class="btn btn-danger">Publish Listing</button>
    </form>
</div>

<div class="card shadow-sm border-0 p-3 mb-4">
    <h4 class="fw-bold mb-3">Manage Properties (CRUD)</h4>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead><tr><th>Title</th><th>Category</th><th>Location</th><th>Characteristics</th><th>Price / SqM</th><th>Action</th></tr></thead>
            <tbody>
                <?php while($row = $properties->fetch_assoc()): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($row['title']) ?></td>
                        <td><span class="badge bg-dark"><?= htmlspecialchars($row['propertyType']) ?></span></td>
                        <td><?= htmlspecialchars($row['location']) ?></td>
                        <td><small><?= htmlspecialchars($row['landCharacteristics'] ?? 'N/A') ?></small></td>
                        <td>₱<?= number_format($row['price'], 2) ?> <br><small class="text-muted">₱<?= number_format($row['pricePerSqm'] ?? 0, 2) ?>/sqm</small></td>
                        <td><a href="admin_dashboard.php?delete_prop=<?= $row['propertyID'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Delete</a></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    const ctxBar = document.getElementById('propertyCategoryChart').getContext('2d');
    new Chart(ctxBar, {
        type: 'bar',
        data: {
            labels: ['Residential', 'Commercial', 'Farmlot', 'Beachlot'],
            datasets: [{ label: 'Properties', data: [<?= $catResidential ?>, <?= $catCommercial ?>, <?= $catFarmlot ?>, <?= $catBeachlot ?>], backgroundColor: ['#0d6efd', '#198754', '#ffc107', '#0dcaf0'] }]
        },
        options: { responsive: true, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });

    const ctxDoughnut = document.getElementById('appointmentStatusChart').getContext('2d');
    new Chart(ctxDoughnut, {
        type: 'doughnut',
        data: { labels: ['Pending', 'Approved', 'Cancelled'], datasets: [{ data: [<?= $appPending ?>, <?= $appApproved ?>, <?= $appCancelled ?>], backgroundColor: ['#ffc107', '#198754', '#dc3545'] }] },
        options: { responsive: true }
    });
</script>

<?php include 'includes/footer.php'; ?>