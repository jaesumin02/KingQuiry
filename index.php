<?php
include 'config/db.php';
require_once 'includes/location_directory.php';
include 'includes/header.php';

$allowedCategories = ['Residential', 'Commercial', 'Farmlot', 'Beachlot'];
$search = $_GET['search'] ?? '';
$search = is_string($search) ? trim(substr($search, 0, 120)) : '';
$category = $_GET['category'] ?? '';
$category = is_string($category) && in_array($category, $allowedCategories, true) ? $category : '';
$locationFilter = filter_var($_GET['location_filter'] ?? 0, FILTER_VALIDATE_INT);
$locationFilter = $locationFilter === false ? 0 : max(0, $locationFilter);

$conditions = ["p.status = 'Available'"];
$parameters = [];
$parameterTypes = '';
if ($search !== '') {
    $conditions[] = "(p.title LIKE ? OR p.description LIKE ? OR p.location LIKE ? OR l.municipalityName LIKE ?)";
    $searchPattern = '%' . $search . '%';
    array_push($parameters, $searchPattern, $searchPattern, $searchPattern, $searchPattern);
    $parameterTypes .= 'ssss';
}
if ($category !== '') {
    $conditions[] = 'p.propertyType = ?';
    $parameters[] = $category;
    $parameterTypes .= 's';
}
if ($locationFilter > 0) {
    $conditions[] = 'p.locationID = ?';
    $parameters[] = $locationFilter;
    $parameterTypes .= 'i';
}

$sql = "SELECT p.*, l.municipalityName, l.provinceName, l.regionName
        FROM properties p
        LEFT JOIN property_locations l ON l.locationID = p.locationID
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY p.createdAt DESC, p.propertyID DESC";
$stmt = $conn->prepare($sql);
if ($parameters) {
    $bindParameters = [$parameterTypes];
    foreach ($parameters as $index => &$parameter) {
        $bindParameters[] = &$parameter;
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParameters);
    unset($parameter);
}
$stmt->execute();
$result = $stmt->get_result();
$locations = get_property_locations($conn);

$myAppointments = null;
if (normalized_user_role($_SESSION['user'] ?? null) === 'Client') {
    $clientID = (int) $_SESSION['user']['userID'];
    $appointmentStmt = $conn->prepare(
        "SELECT a.*, p.title, p.location, l.municipalityName, l.provinceName
         FROM appointments a
         JOIN properties p ON a.propertyID = p.propertyID
         LEFT JOIN property_locations l ON l.locationID = p.locationID
         WHERE a.clientID = ?
         ORDER BY a.appointmentID DESC
         LIMIT 3"
    );
    $appointmentStmt->bind_param('i', $clientID);
    $appointmentStmt->execute();
    $myAppointments = $appointmentStmt->get_result();
}
?>

<section class="catalog-hero p-4 p-lg-5 mb-4 rounded-4 text-white">
    <div class="row align-items-center g-4">
        <div class="col-lg-8">
            <div class="text-uppercase small fw-bold mb-2" style="letter-spacing:.14em;color:#efd29d">Kings Land Properties</div>
            <h1 class="display-5 fw-bold mb-3">Find the right land for what comes next.</h1>
            <p class="lead mb-0 text-white-50">Explore curated property listings by verified location and land type, with clear asking prices.</p>
        </div>
        <div class="col-lg-4 d-none d-lg-block text-lg-end">
            <div class="catalog-hero-mark" aria-hidden="true">KQ</div>
        </div>
    </div>
</section>

<section class="card filter-card p-3 p-lg-4 mb-4" aria-label="Search properties">
    <form method="GET" action="index.php" class="row g-3 align-items-end">
        <div class="col-lg-5">
            <label class="form-label fw-semibold" for="property-search">Search listings</label>
            <input id="property-search" type="search" name="search" class="form-control" maxlength="120" placeholder="Search by property, details, or municipality" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label fw-semibold" for="location-filter">City or municipality</label>
            <select id="location-filter" name="location_filter" class="form-select">
                <option value="0">All locations</option>
                <?php foreach ($locations as $location): ?>
                    <option value="<?= (int) $location['locationID'] ?>" <?= $locationFilter === (int) $location['locationID'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($location['municipalityName'] . ', ' . $location['provinceName'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <label class="form-label fw-semibold" for="property-category">Property type</label>
            <select id="property-category" name="category" class="form-select">
                <option value="">All types</option>
                <?php foreach ($allowedCategories as $propertyCategory): ?>
                    <option value="<?= $propertyCategory ?>" <?= $category === $propertyCategory ? 'selected' : '' ?>><?= $propertyCategory ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2">
            <button class="btn btn-primary w-100" type="submit">Search properties</button>
        </div>
    </form>
</section>

<div class="d-flex flex-column flex-sm-row align-items-sm-end justify-content-between gap-2 mb-3">
    <div>
        <div class="page-eyebrow">Property collection</div>
        <h2 class="page-title h3 mb-0">Explore available listings</h2>
    </div>
    <div class="text-muted small"><?= number_format($result->num_rows) ?> listing<?= $result->num_rows === 1 ? '' : 's' ?> found</div>
</div>

<?php if (normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
    <section class="card p-3 p-lg-4 mb-5">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
                <div class="page-eyebrow">Your activity</div>
                <h2 class="h5 fw-bold mb-0">Recent viewing requests</h2>
            </div>
            <a href="client_dashboard.php" class="btn btn-sm btn-outline-primary">View dashboard</a>
        </div>
        <?php if ($myAppointments && $myAppointments->num_rows > 0): ?>
            <?php while($appt = $myAppointments->fetch_assoc()): ?>
                <div class="border rounded-3 p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <h3 class="h6 fw-bold mb-1"><?= htmlspecialchars($appt['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="small text-muted"><?= htmlspecialchars(property_location_label($appt), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <span class="badge rounded-pill text-bg-light">#KQ-<?= 1000 + (int) $appt['appointmentID'] ?></span>
                    </div>
                    <div class="small text-muted">Requested for <strong><?= htmlspecialchars($appt['appointmentDate'], ENT_QUOTES, 'UTF-8') ?></strong> at <strong><?= htmlspecialchars(substr($appt['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></strong></div>
                    <span class="badge mt-2 <?= $appt['status'] === 'Approved' ? 'text-bg-success' : ($appt['status'] === 'Cancelled' ? 'text-bg-danger' : 'text-bg-warning') ?>">
                        <?= htmlspecialchars($appt['status'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="text-muted">No viewing requests yet. Open a listing to schedule a visit.</div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<div class="row g-4">
    <?php if ($result->num_rows > 0): ?>
        <?php while($row = $result->fetch_assoc()): ?>
            <?php
            $imageName = basename((string) ($row['image'] ?? ''));
            $imagePath = __DIR__ . '/assets/images/' . $imageName;
            $hasImage = $imageName !== '' && is_file($imagePath);
            ?>
            <div class="col-md-6 col-xl-4">
                <article class="card property-card h-100">
                    <?php if ($hasImage): ?>
                        <img src="assets/images/<?= rawurlencode($imageName) ?>" class="card-img-top property-card-image" alt="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <div class="property-placeholder property-card-image d-flex align-items-center justify-content-center" aria-label="No property image available">
                            <span class="property-placeholder-mark" aria-hidden="true">KQ</span>
                        </div>
                    <?php endif; ?>
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($row['propertyType'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="small text-muted"><?= htmlspecialchars(property_location_label($row), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <h3 class="h5 fw-bold mb-2"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <?php if (!empty($row['areaSqm'])): ?><div class="small text-muted mb-2"><?= number_format((float) $row['areaSqm'], 2) ?> sq.m. lot</div><?php endif; ?>
                        <p class="text-muted small mb-3"><?= htmlspecialchars($row['landCharacteristics'] ?: 'Contact our team for property characteristics.', ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="border-top pt-3 mt-auto">
                            <div class="property-price">₱<?= number_format((float) $row['price'], 2) ?></div>
                            <?php if ((float) $row['pricePerSqm'] > 0): ?>
                                <div class="small text-muted mb-3">₱<?= number_format((float) $row['pricePerSqm'], 2) ?> per sq.m.</div>
                            <?php else: ?>
                                <div class="small text-muted mb-3">Contact us for price per sq.m.</div>
                            <?php endif; ?>
                            <a href="property_detail.php?id=<?= (int) $row['propertyID'] ?>" class="btn btn-outline-primary w-100">View property details</a>
                        </div>
                    </div>
                </article>
            </div>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="col-12">
            <div class="card text-center px-4 py-5">
                <h3 class="h5 fw-bold">No matching properties yet</h3>
                <p class="text-muted mb-3">Try another search or reset your filters to see all available listings.</p>
                <a href="index.php" class="btn btn-outline-primary align-self-center">Clear filters</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>