<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/location_directory.php';
require_once 'includes/property_estimator.php';
require_once 'includes/property_images.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (normalized_user_role($_SESSION['user'] ?? null) !== 'Admin') {
    header('Location: login.php');
    exit();
}

$locationList = get_property_locations($conn, true);
$locations = array_values(array_filter(
    $locationList,
    static fn(array $location): bool => (int) $location['isActive'] === 1
));
$formError = '';
$formSuccess = isset($_GET['property_added']) ? 'The property listing has been published.' : '';
$estimate = null;
$estimateError = '';
$inputSqm = '';
$inputType = 'Residential';
$estimateLocationId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } elseif (isset($_POST['add_location'])) {
        $regionCode = strtoupper(trim(is_string($_POST['regionCode'] ?? null) ? $_POST['regionCode'] : ''));
        $regionName = trim(is_string($_POST['regionName'] ?? null) ? $_POST['regionName'] : '');
        $provinceName = trim(is_string($_POST['provinceName'] ?? null) ? $_POST['provinceName'] : '');
        $municipalityName = trim(is_string($_POST['municipalityName'] ?? null) ? $_POST['municipalityName'] : '');

        if (!preg_match('/^[A-Z0-9-]{2,10}$/', $regionCode)
            || strlen($regionName) < 2 || strlen($regionName) > 120
            || strlen($provinceName) < 2 || strlen($provinceName) > 120
            || strlen($municipalityName) < 2 || strlen($municipalityName) > 120) {
            $formError = 'Enter a valid region code and names (2-120 characters each).';
        } else {
            $locationStmt = $conn->prepare(
                'INSERT INTO property_locations (regionCode, regionName, provinceName, municipalityName, isActive)
                 VALUES (?, ?, ?, ?, 1)'
            );
            $locationStmt->bind_param('ssss', $regionCode, $regionName, $provinceName, $municipalityName);
            try {
                $locationStmt->execute();
                header('Location: admin_dashboard.php?location_added=1#locations');
                exit();
            } catch (mysqli_sql_exception $exception) {
                if ($exception->getCode() === 1062) {
                    $formError = 'That location is already in the directory. Existing locations are reactivated from the list below.';
                } else {
                    error_log('KingQuiry location insert failed: ' . $exception->getMessage());
                    $formError = 'The location could not be saved. Check the database connection and try again.';
                }
            }
        }
    } elseif (isset($_POST['set_location_status'])) {
        $locationId = filter_var($_POST['locationID'] ?? null, FILTER_VALIDATE_INT);
        $active = filter_var($_POST['isActive'] ?? null, FILTER_VALIDATE_INT);
        if ($locationId === false || $locationId <= 0 || !in_array($active, [0, 1], true)) {
            $formError = 'Choose a valid location and status.';
        } else {
            $statusStmt = $conn->prepare('UPDATE property_locations SET isActive = ? WHERE locationID = ?');
            $statusStmt->bind_param('ii', $active, $locationId);
            $statusStmt->execute();
            if ($statusStmt->affected_rows > 0) {
                header('Location: admin_dashboard.php?location_status_updated=1#locations');
                exit();
            }

            $checkStmt = $conn->prepare('SELECT locationID FROM property_locations WHERE locationID = ?');
            $checkStmt->bind_param('i', $locationId);
            $checkStmt->execute();
            if (!$checkStmt->get_result()->fetch_assoc()) {
                $formError = 'The selected location was not found.';
            } else {
                header('Location: admin_dashboard.php?location_status_updated=1#locations');
                exit();
            }
        }
    } elseif (isset($_POST['add_property'])) {
        $title = is_string($_POST['title'] ?? null) ? trim($_POST['title']) : '';
        $description = is_string($_POST['description'] ?? null) ? trim($_POST['description']) : '';
        $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $areaSqm = filter_var($_POST['areaSqm'] ?? null, FILTER_VALIDATE_FLOAT);
        $locationId = filter_var($_POST['locationID'] ?? null, FILTER_VALIDATE_INT);
        $propertyType = is_string($_POST['propertyType'] ?? null) ? $_POST['propertyType'] : '';
        $landCharacteristics = is_string($_POST['landCharacteristics'] ?? null) ? trim($_POST['landCharacteristics']) : '';
        $barangay = is_string($_POST['barangay'] ?? null) ? trim($_POST['barangay']) : '';
        $location = $locationId === false ? null : find_property_location($locations, (int) $locationId);

        if (strlen($title) < 3 || strlen($title) > 150 || strlen($description) < 10 || strlen($description) > 10000) {
            $formError = 'Enter a title (3-150 characters) and a description (10-10,000 characters).';
        } elseif ($price === false || $price <= 0 || $price > 9999999999.99 || $areaSqm === false || $areaSqm < 0.01 || $areaSqm > 1000000) {
            $formError = 'Enter a valid total price and a land area between 0 and 1,000,000 sq.m.';
        } elseif (!in_array($propertyType, ['Residential', 'Commercial', 'Farmlot', 'Beachlot'], true)) {
            $formError = 'Choose a valid property category.';
        } elseif (!$location || strlen($barangay) > 120 || strlen($landCharacteristics) > 2000) {
            $formError = 'Choose an active directory location and check the optional land details.';
        } else {
            $canonicalLocation = $location['municipalityName'] . ', ' . $location['provinceName'];
            $pricePerSqm = round((float) $price / (float) $areaSqm, 2);
            if ($pricePerSqm > 9999999999.99) {
                $formError = 'The calculated price per square meter is outside the supported price range.';
            } else {
            try {
                $imageName = store_property_image($_FILES['propertyImage'] ?? []);
                $stmt = $conn->prepare(
                    "INSERT INTO properties (title, description, price, pricePerSqm, location, propertyType, landCharacteristics, locationID, barangay, areaSqm, image)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('ssddsssisds', $title, $description, $price, $pricePerSqm, $canonicalLocation, $propertyType, $landCharacteristics, $locationId, $barangay, $areaSqm, $imageName);
                try {
                    $stmt->execute();
                    header('Location: admin_dashboard.php?property_added=1#properties');
                    exit();
                } catch (mysqli_sql_exception $exception) {
                    if ($imageName !== 'default.jpg') {
                        unlink(__DIR__ . '/assets/images/' . $imageName);
                    }
                    throw $exception;
                }
            } catch (InvalidArgumentException $exception) {
                $formError = $exception->getMessage();
            } catch (mysqli_sql_exception $exception) {
                error_log('KingQuiry property insert failed: ' . $exception->getMessage());
                $formError = 'The property could not be saved. Check the database connection and try again.';
            } catch (RuntimeException $exception) {
                $formError = $exception->getMessage();
            }
            }
        }
    } elseif (isset($_POST['delete_property'])) {
        $propertyId = filter_var($_POST['propertyID'] ?? null, FILTER_VALIDATE_INT);
        if ($propertyId === false || $propertyId <= 0) {
            $formError = 'Choose a valid property to remove.';
        } else {
            $deleteStmt = $conn->prepare('DELETE FROM properties WHERE propertyID = ?');
            $deleteStmt->bind_param('i', $propertyId);
            try {
                $deleteStmt->execute();
                header('Location: admin_dashboard.php?property_deleted=1#properties');
                exit();
            } catch (mysqli_sql_exception $exception) {
                error_log('KingQuiry property delete failed: ' . $exception->getMessage());
                $formError = 'The property could not be removed. Check for related appointments and try again.';
            }
        }
    } elseif (isset($_POST['run_prediction'])) {
        $inputSqm = filter_var($_POST['sqm_input'] ?? null, FILTER_VALIDATE_FLOAT);
        $inputType = is_string($_POST['property_type'] ?? null) ? $_POST['property_type'] : '';
        $estimateLocationId = filter_var($_POST['locationID'] ?? null, FILTER_VALIDATE_INT);
        if ($inputSqm === false || $estimateLocationId === false) {
            $estimateError = 'Enter a valid land area and choose a location.';
        } else {
            try {
                $estimate = estimate_property_value($conn, (float) $inputSqm, $inputType, (int) $estimateLocationId);
            } catch (InvalidArgumentException | RuntimeException $exception) {
                $estimateError = $exception->getMessage();
            }
        }
    }
}

if (isset($_GET['property_deleted'])) {
    $formSuccess = 'The property listing has been removed.';
}
if (isset($_GET['location_added'])) {
    $formSuccess = 'The location has been added and is available in listing filters.';
} elseif (isset($_GET['location_status_updated'])) {
    $formSuccess = 'The location availability has been updated.';
}

include 'includes/header.php';
$totalProperties = $conn->query("SELECT COUNT(*) as count FROM properties")->fetch_assoc()['count'];
$totalUsers = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$totalAppointments = $conn->query("SELECT COUNT(*) as count FROM appointments")->fetch_assoc()['count'];
$totalInquiries = $conn->query("SELECT COUNT(*) as count FROM feedbackinquiries")->fetch_assoc()['count'];

$standardPropertyCategories = ['Residential', 'Commercial', 'Farmlot', 'Beachlot'];
$propertyCategoryCounts = [
    'Residential' => 0,
    'Commercial' => 0,
    'Farmlot' => 0,
    'Beachlot' => 0,
];
foreach ($conn->query('SELECT propertyType, COUNT(*) AS total FROM properties GROUP BY propertyType') as $row) {
    $category = trim((string) $row['propertyType']);
    if ($category === '') {
        $category = 'Uncategorized';
    }
    if (!in_array($category, $standardPropertyCategories, true)) {
        $category = 'Other / legacy';
    }
    $propertyCategoryCounts[$category] = ($propertyCategoryCounts[$category] ?? 0) + (int) $row['total'];
}
$propertyCategoryTotal = array_sum($propertyCategoryCounts);
$propertyCategoryMaximum = max(1, ...array_values($propertyCategoryCounts));

$appointmentStatusCounts = [
    'Pending' => 0,
    'Approved' => 0,
    'Cancelled' => 0,
];
foreach ($conn->query('SELECT status, COUNT(*) AS total FROM appointments GROUP BY status') as $row) {
    $status = trim((string) $row['status']);
    if ($status === '') {
        $status = 'Unspecified';
    }
    $appointmentStatusCounts[$status] = ($appointmentStatusCounts[$status] ?? 0) + (int) $row['total'];
}
$appointmentStatusTotal = array_sum($appointmentStatusCounts);
$appointmentStatusMaximum = max(1, ...array_values($appointmentStatusCounts));

$salesSummary = $conn->query(
    "SELECT COUNT(*) AS verifiedSales, COALESCE(SUM(salePrice), 0) AS actualRevenue
     FROM property_transactions
     WHERE currentStage = 'completed' AND salePrice IS NOT NULL"
)->fetch_assoc();
$verifiedSalesCount = (int) $salesSummary['verifiedSales'];
$actualRevenue = (float) $salesSummary['actualRevenue'];
$activePipeline = $conn->query(
    "SELECT COUNT(*) AS activeCases, COALESCE(SUM(p.price), 0) AS askingPriceValue
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     WHERE t.currentStage NOT IN ('completed', 'cancelled')"
)->fetch_assoc();
$activePipelineCount = (int) $activePipeline['activeCases'];
$activePipelineAskingValue = (float) $activePipeline['askingPriceValue'];

$salesMonthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$salesRangeStart = $salesMonthStart->format('Y-m-d');
$salesRangeEnd = $salesMonthStart->modify('+6 months')->format('Y-m-d');
$monthlySales = [];
for ($monthOffset = 0; $monthOffset < 6; $monthOffset++) {
    $month = $salesMonthStart->modify('+' . $monthOffset . ' months');
    $monthKey = $month->format('Y-m');
    $monthlySales[$monthKey] = [
        'label' => $month->format('M Y'),
        'sales' => 0,
        'revenue' => 0.0,
    ];
}
$monthlySalesStmt = $conn->prepare(
    "SELECT DATE_FORMAT(e.createdAt, '%Y-%m') AS monthKey,
            COUNT(DISTINCT t.transactionID) AS sales,
            COALESCE(SUM(t.salePrice), 0) AS revenue
     FROM property_transaction_events e
     JOIN property_transactions t ON t.transactionID = e.transactionID
     WHERE e.stage = 'completed'
       AND t.currentStage = 'completed'
       AND t.salePrice IS NOT NULL
       AND e.createdAt >= ?
       AND e.createdAt < ?
     GROUP BY monthKey"
);
$monthlySalesStmt->bind_param('ss', $salesRangeStart, $salesRangeEnd);
$monthlySalesStmt->execute();
foreach ($monthlySalesStmt->get_result() as $row) {
    $monthKey = (string) $row['monthKey'];
    if (isset($monthlySales[$monthKey])) {
        $monthlySales[$monthKey]['sales'] = (int) $row['sales'];
        $monthlySales[$monthKey]['revenue'] = (float) $row['revenue'];
    }
}
$monthlyRevenueMaximum = max(1.0, ...array_column($monthlySales, 'revenue'));
$hasMonthlySales = array_sum(array_column($monthlySales, 'sales')) > 0;
$monthlyRevenuePoints = [];
foreach (array_values($monthlySales) as $index => $month) {
    $x = 100 + ($index * 110);
    $y = 245 - ((float) $month['revenue'] / $monthlyRevenueMaximum * 205);
    $monthlyRevenuePoints[] = [
        'x' => $x,
        'y' => round($y, 2),
        'label' => $month['label'],
        'revenue' => (float) $month['revenue'],
        'sales' => (int) $month['sales'],
    ];
}
$monthlyRevenueLine = implode(' ', array_map(
    static fn(array $point): string => $point['x'] . ',' . number_format($point['y'], 2, '.', ''),
    $monthlyRevenuePoints
));

$topSalesProperties = $conn->query(
    "SELECT p.title, COUNT(*) AS sales, SUM(t.salePrice) AS revenue
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     WHERE t.currentStage = 'completed' AND t.salePrice IS NOT NULL
     GROUP BY p.propertyID, p.title
     ORDER BY revenue DESC, sales DESC, p.title ASC
     LIMIT 5"
)->fetch_all(MYSQLI_ASSOC);
$topSalesMaximum = $topSalesProperties === []
    ? 1.0
    : max(1.0, ...array_map(static fn(array $row): float => (float) $row['revenue'], $topSalesProperties));
$formatCompactPeso = static function (float $amount): string {
    if ($amount >= 1000000) {
        return '₱' . number_format($amount / 1000000, 1) . 'M';
    }
    if ($amount >= 1000) {
        return '₱' . number_format($amount / 1000, 1) . 'K';
    }
    return '₱' . number_format($amount, 0);
};

$properties = $conn->query(
    "SELECT p.*, l.municipalityName, l.provinceName
     FROM properties p
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     ORDER BY p.createdAt DESC, p.propertyID DESC"
);
?>

<div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-end gap-3 mb-4">
    <div>
        <div class="page-eyebrow">KingQuiry management</div>
        <h1 class="page-title h2 mb-1">Admin dashboard</h1>
        <p class="text-muted mb-0">A clear overview of listings and customer activity.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="transaction_tracking.php" class="btn btn-primary">Transaction tracking</a>
        <a href="admin_chat.php" class="btn btn-outline-primary">Customer inbox</a>
    </div>
</div>

<section class="mb-4" aria-labelledby="business-analytics-title">
    <div class="mb-3">
        <div class="page-eyebrow">Live business analytics</div>
        <h2 class="h4 fw-bold mb-1" id="business-analytics-title">Sales performance</h2>
        <p class="small text-muted mb-0">Automatically refreshed from verified transaction records whenever this dashboard loads.</p>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-4">
            <article class="card h-100 p-3 p-lg-4">
                <div class="small text-muted fw-semibold">Verified completed sales</div>
                <div class="display-6 fw-bold mt-2"><?= number_format($verifiedSalesCount) ?></div>
                <div class="small text-muted mt-1">Completed transactions with an actual sale price recorded</div>
            </article>
        </div>
        <div class="col-sm-6 col-xl-4">
            <article class="card h-100 p-3 p-lg-4">
                <div class="small text-muted fw-semibold">Recorded sales revenue</div>
                <div class="h2 fw-bold mt-2 mb-0">₱<?= number_format($actualRevenue, 2) ?></div>
                <div class="small text-muted mt-1">Sum of verified actual sale prices, not listing estimates</div>
            </article>
        </div>
        <div class="col-sm-6 col-xl-4">
            <article class="card h-100 p-3 p-lg-4">
                <div class="small text-muted fw-semibold">Active sales pipeline</div>
                <div class="h2 fw-bold mt-2 mb-0"><?= number_format($activePipelineCount) ?> case(s)</div>
                <div class="small text-muted mt-1">₱<?= number_format($activePipelineAskingValue, 2) ?> combined listing-price value; not booked revenue</div>
            </article>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="card h-100 p-3 p-lg-4" aria-labelledby="monthly-sales-title">
                <div class="mb-3">
                    <h3 class="h5 fw-bold mb-1" id="monthly-sales-title">Monthly actual sales revenue</h3>
                    <p class="small text-muted mb-0">Last six months · based on staff-recorded sale prices</p>
                </div>
                <div class="overflow-auto">
                    <svg class="w-100" style="min-width: 600px;" viewBox="0 0 720 300" role="img" aria-labelledby="monthly-sales-title monthly-sales-description">
                        <desc id="monthly-sales-description">Line graph of verified actual sale revenue by month for the last six months. Months without recorded sales have a value of zero.</desc>
                        <?php for ($gridLine = 0; $gridLine <= 4; $gridLine++):
                            $gridY = 245 - ($gridLine * 51);
                            $gridValue = $monthlyRevenueMaximum * $gridLine / 4;
                        ?>
                            <line x1="78" y1="<?= $gridY ?>" x2="700" y2="<?= $gridY ?>" stroke="#e5ebf0" stroke-width="1"/>
                            <text x="70" y="<?= $gridY - 5 ?>" text-anchor="end" fill="#687789" font-size="12"><?= htmlspecialchars($formatCompactPeso($gridValue), ENT_QUOTES, 'UTF-8') ?></text>
                        <?php endfor; ?>
                        <line x1="78" y1="40" x2="78" y2="245" stroke="#aab6c2" stroke-width="1"/>
                        <line x1="78" y1="245" x2="700" y2="245" stroke="#aab6c2" stroke-width="1"/>
                        <polyline points="<?= htmlspecialchars($monthlyRevenueLine, ENT_QUOTES, 'UTF-8') ?>" fill="none" stroke="#174a70" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        <?php foreach ($monthlyRevenuePoints as $point): ?>
                            <circle cx="<?= $point['x'] ?>" cy="<?= number_format($point['y'], 2, '.', '') ?>" r="5" fill="#174a70" stroke="#fff" stroke-width="2">
                                <title><?= htmlspecialchars($point['label'] . ': ₱' . number_format($point['revenue'], 2) . ' (' . $point['sales'] . ' sale(s))', ENT_QUOTES, 'UTF-8') ?></title>
                            </circle>
                            <text x="<?= $point['x'] ?>" y="274" text-anchor="middle" fill="#687789" font-size="12"><?= htmlspecialchars($point['label'], ENT_QUOTES, 'UTF-8') ?></text>
                        <?php endforeach; ?>
                    </svg>
                </div>
                <?php if (!$hasMonthlySales): ?>
                    <p class="small text-muted mb-0 mt-2">No verified sales with a recorded sale price in this period. The graph is ready and will update when staff records a completed sale.</p>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="card h-100 p-3 p-lg-4" aria-labelledby="top-sales-title">
                <div class="mb-3">
                    <h3 class="h5 fw-bold mb-1" id="top-sales-title">Top-selling properties</h3>
                    <p class="small text-muted mb-0">Ranked by verified recorded sale revenue</p>
                </div>
                <?php if ($topSalesProperties === []): ?>
                    <div class="border rounded-3 p-3 bg-light" role="status">
                        <div class="d-flex justify-content-between small fw-semibold text-muted mb-2"><span>Verified sale revenue</span><span>₱0</span></div>
                        <div class="progress" role="progressbar" aria-label="Top-selling property sales revenue" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="height: .65rem;">
                            <div class="progress-bar bg-success" style="width: 0%"></div>
                        </div>
                        <p class="small text-muted mb-0 mt-3">No verified sales have been recorded yet. Top-selling properties will appear here automatically after staff completes a tracked sale and records its actual price.</p>
                    </div>
                <?php else: ?>
                    <ol class="list-unstyled d-grid gap-3 mb-0" aria-label="Top-selling properties by actual sale revenue">
                        <?php foreach ($topSalesProperties as $rank => $propertySale):
                            $revenue = (float) $propertySale['revenue'];
                            $barPercent = (int) round(($revenue / $topSalesMaximum) * 100);
                        ?>
                            <li>
                                <div class="d-flex justify-content-between align-items-start gap-3 small mb-1">
                                    <span class="fw-semibold text-break"><?= ($rank + 1) ?>. <?= htmlspecialchars($propertySale['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-end text-nowrap">₱<?= number_format($revenue, 2) ?><span class="d-block text-muted"><?= number_format((int) $propertySale['sales']) ?> sale(s)</span></span>
                                </div>
                                <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars($propertySale['title'], ENT_QUOTES, 'UTF-8') ?> sales revenue" aria-valuenow="<?= $barPercent ?>" aria-valuemin="0" aria-valuemax="100" style="height: .55rem;">
                                    <div class="progress-bar bg-success" style="width: <?= $barPercent ?>%"></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
        </div>
    </div>
</section>

<?php if ($formSuccess !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($formSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($formError !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3"><div class="card h-100 p-3 p-lg-4"><div class="small text-muted fw-semibold">Property listings</div><div class="display-6 fw-bold mt-2"><?= number_format((int) $totalProperties) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100 p-3 p-lg-4"><div class="small text-muted fw-semibold">Registered users</div><div class="display-6 fw-bold mt-2"><?= number_format((int) $totalUsers) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100 p-3 p-lg-4"><div class="small text-muted fw-semibold">Viewing requests</div><div class="display-6 fw-bold mt-2"><?= number_format((int) $totalAppointments) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="card h-100 p-3 p-lg-4"><div class="small text-muted fw-semibold">Property inquiries</div><div class="display-6 fw-bold mt-2"><?= number_format((int) $totalInquiries) ?></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <section class="card p-3 p-lg-4 h-100" aria-labelledby="property-category-chart-title">
            <div class="mb-3">
                <h2 class="h5 fw-bold mb-1" id="property-category-chart-title">Listings by property type</h2>
                <p class="small text-muted mb-0"><?= number_format((int) $propertyCategoryTotal) ?> listing(s) across all categories</p>
            </div>
            <?php if ($propertyCategoryTotal === 0): ?>
                <p class="small text-muted mb-0 py-3">Category data will appear here when listings are added.</p>
            <?php else: ?>
                <div class="d-grid gap-3" role="list" aria-label="Property listing counts by category">
                    <?php foreach ($propertyCategoryCounts as $category => $count):
                        $percentage = (int) round(((int) $count / $propertyCategoryMaximum) * 100);
                        $barClass = [
                            'Residential' => 'bg-primary',
                            'Commercial' => 'bg-success',
                            'Farmlot' => 'bg-warning',
                            'Beachlot' => 'bg-info',
                            'Other / legacy' => 'bg-secondary',
                        ][$category] ?? 'bg-secondary';
                    ?>
                        <div role="listitem">
                            <div class="d-flex justify-content-between gap-3 small mb-1">
                                <span class="text-break"><?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= number_format((int) $count) ?></strong>
                            </div>
                            <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?> listings" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100" style="height: .65rem;">
                                <div class="progress-bar <?= $barClass ?>" style="width: <?= $percentage ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-5">
        <section class="card p-3 p-lg-4 h-100" aria-labelledby="appointment-status-chart-title">
            <div class="mb-3">
                <h2 class="h5 fw-bold mb-1" id="appointment-status-chart-title">Viewing request status</h2>
                <p class="small text-muted mb-0"><?= number_format((int) $appointmentStatusTotal) ?> request(s) in total</p>
            </div>
            <?php if ($appointmentStatusTotal === 0): ?>
                <p class="small text-muted mb-0 py-3">Request status data will appear here when a client books a viewing.</p>
            <?php else: ?>
                <div class="d-grid gap-3" role="list" aria-label="Viewing request counts by status">
                    <?php foreach ($appointmentStatusCounts as $status => $count):
                        $percentage = (int) round(((int) $count / $appointmentStatusMaximum) * 100);
                        $barClass = [
                            'Pending' => 'bg-warning',
                            'Approved' => 'bg-success',
                            'Cancelled' => 'bg-secondary',
                        ][$status] ?? 'bg-primary';
                    ?>
                        <div role="listitem">
                            <div class="d-flex justify-content-between gap-3 small mb-1">
                                <span class="text-break"><?= htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= number_format((int) $count) ?></strong>
                            </div>
                            <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') ?> viewing requests" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100" style="height: .65rem;">
                                <div class="progress-bar <?= $barClass ?>" style="width: <?= $percentage ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<section class="card p-4 p-lg-5 mb-4" id="locations">
    <div class="page-eyebrow mb-2">Location directory</div>
    <h2 class="h4 fw-bold mb-2">Manage locations</h2>
    <p class="text-muted small mb-4">Add verified regions, provinces, cities, or municipalities here. Active locations appear in property forms, public filters, and the estimator. Deactivating a location hides it from new selections but preserves existing property records.</p>
    <form method="POST" class="row g-3 align-items-end mb-4">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="col-sm-6 col-lg-2">
            <label class="form-label fw-semibold" for="location-region-code">Region code</label>
            <input id="location-region-code" type="text" name="regionCode" class="form-control" maxlength="10" pattern="(?:[A-Za-z0-9]|-){2,10}" placeholder="e.g. PH-40" required>
        </div>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label fw-semibold" for="location-region-name">Region</label>
            <input id="location-region-name" type="text" name="regionName" class="form-control" minlength="2" maxlength="120" placeholder="e.g. CALABARZON" required>
        </div>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label fw-semibold" for="location-province-name">Province</label>
            <input id="location-province-name" type="text" name="provinceName" class="form-control" minlength="2" maxlength="120" placeholder="e.g. Batangas" required>
        </div>
        <div class="col-sm-6 col-lg-3">
            <label class="form-label fw-semibold" for="location-municipality-name">City / municipality</label>
            <input id="location-municipality-name" type="text" name="municipalityName" class="form-control" minlength="2" maxlength="120" placeholder="e.g. Lipa City" required>
        </div>
        <div class="col-lg-1">
            <button type="submit" name="add_location" class="btn btn-primary w-100">Add</button>
        </div>
    </form>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>Region</th><th>Province</th><th>City / municipality</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($locationList as $location): ?>
                    <tr>
                        <td><?= htmlspecialchars($location['regionName'] . ' (' . $location['regionCode'] . ')', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($location['provinceName'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($location['municipalityName'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= (int) $location['isActive'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $location['isActive'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                        <td>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="locationID" value="<?= (int) $location['locationID'] ?>">
                                <input type="hidden" name="isActive" value="<?= (int) $location['isActive'] === 1 ? 0 : 1 ?>">
                                <button type="submit" name="set_location_status" class="btn btn-sm btn-outline-secondary"><?= (int) $location['isActive'] === 1 ? 'Deactivate' : 'Reactivate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($locationList === []): ?><tr><td colspan="5" class="text-center text-muted py-3">No locations in the directory yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card p-4 p-lg-5 mb-4">
    <div class="page-eyebrow mb-2">Comparable listing analysis</div>
    <h2 class="h4 fw-bold mb-2">Land price estimate</h2>
    <p class="text-muted small mb-4">An indicative range based on listed prices per square meter for the selected municipality and property type. It is not a formal appraisal or trained machine-learning valuation.</p>
    <form method="POST" class="row g-3 align-items-end">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="col-md-3">
            <label class="form-label fw-semibold" for="estimate-area">Land area (sq.m.)</label>
            <input id="estimate-area" type="number" min="1" max="1000000" step="any" name="sqm_input" class="form-control" required placeholder="e.g. 500" value="<?= htmlspecialchars((string) ($inputSqm ?: ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold" for="estimate-type">Category</label>
            <select id="estimate-type" name="property_type" class="form-select" required>
                <?php foreach (['Residential', 'Commercial', 'Farmlot', 'Beachlot'] as $propertyType): ?>
                    <option value="<?= $propertyType ?>" <?= $inputType === $propertyType ? 'selected' : '' ?>><?= $propertyType ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label fw-semibold" for="estimate-location">Municipality / city</label>
            <select id="estimate-location" name="locationID" class="form-select" required>
                <?= property_location_options($locations, $estimateLocationId > 0 ? (int) $estimateLocationId : null) ?>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" name="run_prediction" class="btn btn-primary w-100">Calculate estimate</button>
        </div>
    </form>

    <?php if ($estimate): ?>
        <div class="alert alert-success mt-4 mb-0" role="status">
            <h3 class="h6 fw-bold mb-2">Indicative estimate for <?= htmlspecialchars($estimate['location_name'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="mb-1"><strong><?= number_format((float) $inputSqm, 2) ?> sq.m.</strong>: <strong class="text-dark">₱<?= number_format($estimate['low_estimate'], 2) ?>–₱<?= number_format($estimate['high_estimate'], 2) ?></strong></p>
            <p class="small mb-0">Weighted rate: ₱<?= number_format($estimate['price_per_sqm'], 2) ?>/sq.m.; <?= $estimate['comparable_count'] ?> comparable listing(s), <?= $estimate['exact_match_count'] ?> exact match(es). Confidence is heuristic only.</p>
        </div>
    <?php endif; ?>
    <?php if ($estimateError !== ''): ?>
        <div class="alert alert-warning mt-4 mb-0" role="alert"><?= htmlspecialchars($estimateError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</div>

<section class="card p-4 p-lg-5 mb-4" id="properties">
    <div class="page-eyebrow mb-2">Inventory management</div>
    <h2 class="h4 fw-bold mb-4">Publish a property</h2>
    <p class="text-muted small">Choose a location from the admin-managed directory. If the verified location is missing, add it above first.</p>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold" for="property-title">Listing title</label><input id="property-title" type="text" name="title" class="form-control" required minlength="3" maxlength="150" placeholder="e.g. Residential lot near the town center"></div>
            <div class="col-md-4"><label class="form-label fw-semibold" for="property-area">Land area (sq.m.)</label><input id="property-area" type="number" min="0.01" max="1000000" step="0.01" name="areaSqm" class="form-control" required placeholder="e.g. 500"></div>
            <div class="col-md-4"><label class="form-label fw-semibold" for="property-price">Total asking price (PHP)</label><input id="property-price" type="number" min="0.01" max="9999999999.99" step="0.01" name="price" class="form-control" required placeholder="1500000"></div>
            <div class="col-md-4"><label class="form-label fw-semibold" for="property-type">Property category</label><select id="property-type" name="propertyType" class="form-select" required><option value="">Choose a category</option><option value="Residential">Residential</option><option value="Commercial">Commercial</option><option value="Farmlot">Farmlot</option><option value="Beachlot">Beachlot</option></select></div>
            <div class="col-md-6">            <label class="form-label fw-semibold" for="property-location">City / municipality</label><select id="property-location" name="locationID" class="form-select" required><?= property_location_options($locations) ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold" for="property-barangay">Barangay <span class="text-muted fw-normal">(optional)</span></label><input id="property-barangay" type="text" maxlength="120" name="barangay" class="form-control" placeholder="Enter verified barangay"></div>
            <div class="col-12"><label class="form-label fw-semibold" for="property-characteristics">Land characteristics <span class="text-muted fw-normal">(optional)</span></label><input id="property-characteristics" type="text" maxlength="2000" name="landCharacteristics" class="form-control" placeholder="Road access, terrain, utilities, lot dimensions"></div>
            <div class="col-12"><label class="form-label fw-semibold" for="property-image">Property image <span class="text-muted fw-normal">(optional, JPG/PNG/WebP up to 5 MB)</span></label><input id="property-image" type="file" name="propertyImage" class="form-control" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"></div>
            <div class="col-12"><label class="form-label fw-semibold" for="property-description">Description</label><textarea id="property-description" name="description" class="form-control" rows="4" required minlength="10" maxlength="10000" placeholder="Share accurate property details, terms, documents available, and viewing instructions."></textarea></div>
        </div>
        <button type="submit" name="add_property" class="btn btn-primary mt-3 px-4">Publish listing</button>
    </form>
</section>

<section class="card p-3 p-lg-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
        <div><div class="page-eyebrow">Current inventory</div><h2 class="h4 fw-bold mb-0">Manage properties</h2></div>
        <span class="small text-muted"><?= number_format($properties->num_rows) ?> total listings</span>
    </div>
    <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead><tr><th>Title</th><th>Category</th><th>Location</th><th>Asking price</th><th>Action</th></tr></thead>
            <tbody>
                <?php while($row = $properties->fetch_assoc()): ?>
                    <tr>
                        <td class="fw-bold"><?= htmlspecialchars($row['title']) ?></td>
                        <td><span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($row['propertyType']) ?></span></td>
                        <td><?= htmlspecialchars(property_location_label($row)) ?><?php if (!empty($row['barangay'])): ?><br><small class="text-muted"><?= htmlspecialchars($row['barangay']) ?></small><?php endif; ?></td>
                        <td>₱<?= number_format((float) $row['price'], 2) ?><br><small class="text-muted"><?= $row['areaSqm'] !== null ? number_format((float) $row['areaSqm'], 2) . ' sq.m. · ₱' . number_format((float) $row['pricePerSqm'], 2) . '/sq.m.' : 'Legacy area not recorded' ?></small></td>
                        <td>
                            <form method="POST" onsubmit="return confirm('Remove this listing? Related booking records may prevent deletion.');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="propertyID" value="<?= (int) $row['propertyID'] ?>">
                                <button type="submit" name="delete_property" class="btn btn-sm btn-outline-danger">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                <?php if ($properties->num_rows === 0): ?><tr><td colspan="5" class="text-center text-muted py-4">No property listings found.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include 'includes/footer.php'; ?>