<?php
include 'config/db.php';
include 'includes/header.php';

$search = isset($_GET['search']) ? $_GET['search'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : '';
$locationFilter = isset($_GET['location_filter']) ? $_GET['location_filter'] : '';

$sql = "SELECT * FROM properties WHERE (title LIKE '%$search%' OR description LIKE '%$search%')";
if ($category != '') {
    $sql .= " AND propertyType = '$category'";
}
if ($locationFilter != '') {
    $sql .= " AND location = '$locationFilter'";
}
$sql .= " ORDER BY propertyID DESC";
$result = $conn->query($sql);

$locationsResult = $conn->query("SELECT DISTINCT location FROM properties ORDER BY location ASC");
?>

<div class="row mb-4">
    <div class="col-md-9 mx-auto text-center">
        <h1 class="fw-bold">KingQuiry Real Estate Catalog</h1>
        <p class="text-muted">Browse exclusive land and property listings with detailed land characteristics and pricing.</p>
        
        <form method="GET" action="index.php" class="row g-2 justify-content-center mb-3">
            <div class="col-md-5">
                <input type="text" name="search" class="form-control" placeholder="Search by title or keyword..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <select name="location_filter" class="form-select">
                    <option value="">All Locations (Batangas)</option>
                    <?php while($loc = $locationsResult->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($loc['location']) ?>" <?= $locationFilter == $loc['location'] ? 'selected' : '' ?>><?= htmlspecialchars($loc['location']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" type="submit">Filter</button>
            </div>
        </form>

        <div class="d-flex justify-content-center gap-2 flex-wrap">
            <a href="index.php" class="btn btn-sm <?= $category == '' ? 'btn-dark' : 'btn-outline-dark' ?>">All Categories</a>
            <a href="index.php?category=Residential" class="btn btn-sm <?= $category == 'Residential' ? 'btn-dark' : 'btn-outline-dark' ?>">Residential</a>
            <a href="index.php?category=Commercial" class="btn btn-sm <?= $category == 'Commercial' ? 'btn-dark' : 'btn-outline-dark' ?>">Commercial</a>
            <a href="index.php?category=Farmlot" class="btn btn-sm <?= $category == 'Farmlot' ? 'btn-dark' : 'btn-outline-dark' ?>">Farmlot</a>
            <a href="index.php?category=Beachlot" class="btn btn-sm <?= $category == 'Beachlot' ? 'btn-dark' : 'btn-outline-dark' ?>">Beachlot</a>
        </div>
    </div>
</div>

<!-- Lazada-Style Transaction Tracking for Clients -->
<?php if (isset($_SESSION['user']) && $_SESSION['user']['role'] == 'Client'): ?>
    <?php 
        $clientID = $_SESSION['user']['userID'];
        $myAppointments = $conn->query("SELECT a.*, p.title, p.location FROM appointments a JOIN properties p ON a.propertyID = p.propertyID WHERE a.clientID = $clientID ORDER BY a.appointmentID DESC LIMIT 3");
    ?>
    <div class="card shadow-sm border-0 p-4 mb-5 bg-white rounded border-start border-primary border-4">
        <h4 class="fw-bold mb-2 text-primary">📦 Lazada-Style Transaction & Order Tracking</h4>
        <p class="text-muted small mb-4">Monitor real-time status updates of your property viewing appointments.</p>
        
        <?php if ($myAppointments && $myAppointments->num_rows > 0): ?>
            <?php while($appt = $myAppointments->fetch_assoc()): ?>
                <div class="border rounded p-3 mb-3 bg-light">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($appt['title']) ?> <span class="small text-muted">(📍 <?= htmlspecialchars($appt['location']) ?>)</span></h5>
                        <span class="badge bg-primary">Ref ID: #KQ-<?= 1000 + $appt['appointmentID'] ?></span>
                    </div>
                    
                    <div class="row text-center text-muted small my-3">
                        <div class="col">
                            <div class="p-2 rounded bg-success text-white fw-bold mb-1">1. Booked</div>
                            <span>Request Sent</span>
                        </div>
                        <div class="col">
                            <div class="p-2 rounded <?= $appt['status'] == 'Approved' ? 'bg-success text-white' : 'bg-warning text-dark' ?> fw-bold mb-1">2. Verification</div>
                            <span><?= $appt['status'] == 'Approved' ? 'Verified' : 'Pending Review' ?></span>
                        </div>
                        <div class="col">
                            <div class="p-2 rounded <?= $appt['status'] == 'Approved' ? 'bg-primary text-white' : 'bg-secondary text-white' ?> fw-bold mb-1">3. Site Visit</div>
                            <span><?= $appt['status'] == 'Approved' ? 'Scheduled On-Site' : 'Awaiting Approval' ?></span>
                        </div>
                        <div class="col">
                            <div class="p-2 rounded bg-secondary text-white fw-bold mb-1">4. Closed Deal</div>
                            <span>Contract Phase</span>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p class="text-center text-muted mb-0">No active viewing appointments found. Browse catalog below to book.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<h3 class="fw-bold mb-3">Property & Land Catalog</h3>
<div class="row">
    <?php if ($result && $result->num_rows > 0): ?>
        <?php while($row = $result->fetch_assoc()): ?>
            <div class="col-md-4 mb-4">
                <div class="card h-100 shadow-sm border-0">
                    <div class="position-absolute top-0 end-0 m-2">
                        <span class="badge bg-dark"><?= htmlspecialchars($row['propertyType']) ?></span>
                    </div>
                    <img src="assets/images/<?= htmlspecialchars($row['image'] ?? '') ?>" class="card-img-top" alt="Property" style="height: 200px; object-fit: cover;" onerror="this.src='https://via.placeholder.com/300x200?text=KingQuiry+Property'">
                    
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title fw-bold"><?= htmlspecialchars($row['title']) ?></h5>
                        <p class="text-muted small mb-2">📍 <strong>Location:</strong> <?= htmlspecialchars($row['location']) ?></p>
                        
                        <div class="mb-3 bg-light p-2 rounded">
                            <h5 class="text-primary fw-bold mb-0">₱<?= number_format($row['price'], 2) ?></h5>
                            <span class="text-muted small">₱<?= number_format($row['pricePerSqm'] ?? 0, 2) ?> / sq.m.</span>
                        </div>

                        <p class="card-text small mb-1"><strong>Characteristics:</strong> <?= htmlspecialchars($row['landCharacteristics'] ?? 'Standard real estate plot') ?></p>
                        <p class="card-text text-truncate small text-muted mb-3"><?= htmlspecialchars($row['description']) ?></p>
                        
                        <div class="mt-auto">
                            <a href="property_detail.php?id=<?= $row['propertyID'] ?>" class="btn btn-outline-primary w-100 btn-sm">View Full Details & Book</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="col-12 text-center py-5">
            <p class="text-muted">No properties found matching your filter criteria.</p>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>