<?php
include 'config/db.php';
require_once 'includes/location_directory.php';
include 'includes/header.php';

$allowedCategories = ['Residential', 'Commercial', 'Farmlot', 'Beachlot', 'Condo'];
$categoryLabels = [
    'Residential' => 'Residential Lot',
    'Farmlot'     => 'Farm Lot',
    'Beachlot'    => 'Beach Lot',
    'Condo'       => 'Condo',
    'Commercial'  => 'Commercial Property',
];
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

$allowedSorts = [
    'newest'     => ['label' => 'Newest First',        'order' => 'p.createdAt DESC, p.propertyID DESC'],
    'price_asc'  => ['label' => 'Price: Low to High',   'order' => 'p.price ASC, p.propertyID ASC'],
    'price_desc' => ['label' => 'Price: High to Low',  'order' => 'p.price DESC, p.propertyID DESC'],
    'name_asc'   => ['label' => 'Title: A to Z',       'order' => 'p.title ASC, p.propertyID ASC'],
    'name_desc'  => ['label' => 'Title: Z to A',       'order' => 'p.title DESC, p.propertyID DESC'],
];
$sort = $_GET['sort'] ?? 'newest';
if (!array_key_exists($sort, $allowedSorts)) {
    $sort = 'newest';
}
$orderBySql = $allowedSorts[$sort]['order'];

$perPageInput = $_GET['per_page'] ?? '10';
if ($perPageInput === 'all' || $perPageInput === '0' || $perPageInput === 0) {
    $perPage = 0;
} else {
    $perPage = filter_var($perPageInput, FILTER_VALIDATE_INT);
    if ($perPage === false || !in_array($perPage, [6, 10, 15, 24], true)) {
        $perPage = 10;
    }
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = ($page === false || $page < 1) ? 1 : $page;

// Count total matching properties before applying LIMIT / OFFSET
$countSql = "SELECT COUNT(*) AS total
             FROM properties p
             LEFT JOIN property_locations l ON l.locationID = p.locationID
             WHERE " . implode(' AND ', $conditions);
$countStmt = $conn->prepare($countSql);
if (!empty($parameters)) {
    $bindParameters = [$parameterTypes];
    foreach ($parameters as $index => &$paramRef) {
        $bindParameters[] = &$paramRef;
    }
    call_user_func_array([$countStmt, 'bind_param'], $bindParameters);
    unset($paramRef);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalProperties = (int) ($countResult->fetch_assoc()['total'] ?? 0);
$countStmt->close();

if ($perPage > 0) {
    $totalPages = max(1, (int) ceil($totalProperties / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
} else {
    $totalPages = 1;
    $page = 1;
    $offset = 0;
}

$sql = "SELECT p.*, l.municipalityName, l.provinceName, l.regionName
        FROM properties p
        LEFT JOIN property_locations l ON l.locationID = p.locationID
        WHERE " . implode(' AND ', $conditions) . "
        ORDER BY " . $orderBySql;

$parametersWithLimit = $parameters;
$parameterTypesWithLimit = $parameterTypes;
if ($perPage > 0) {
    $sql .= " LIMIT ? OFFSET ?";
    $parametersWithLimit[] = $perPage;
    $parametersWithLimit[] = $offset;
    $parameterTypesWithLimit .= 'ii';
}

$stmt = $conn->prepare($sql);
if (!empty($parametersWithLimit)) {
    $bindParameters = [$parameterTypesWithLimit];
    foreach ($parametersWithLimit as $index => &$paramRef) {
        $bindParameters[] = &$paramRef;
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParameters);
    unset($paramRef);
}
$stmt->execute();
$result = $stmt->get_result();
$visibleProperties = $result->fetch_all(MYSQLI_ASSOC);
$result->data_seek(0);
$locations = get_property_locations($conn);

$startItem = $totalProperties > 0 ? ($perPage > 0 ? $offset + 1 : 1) : 0;
$endItem = $totalProperties > 0 ? ($perPage > 0 ? min($totalProperties, $offset + count($visibleProperties)) : $totalProperties) : 0;

function kq_build_catalog_url(array $changes = []): string
{
    $params = $_GET;
    foreach ($changes as $key => $val) {
        if ($val === null || $val === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $val;
        }
    }
    if (!isset($changes['page']) && (isset($changes['sort']) || isset($changes['per_page']) || isset($changes['search']) || isset($changes['category']) || isset($changes['location_filter']))) {
        unset($params['page']);
    }
    if (isset($params['page']) && (int) $params['page'] <= 1) {
        unset($params['page']);
    }
    if (isset($params['sort']) && $params['sort'] === 'newest') {
        unset($params['sort']);
    }
    if (isset($params['per_page']) && (int) $params['per_page'] === 10) {
        unset($params['per_page']);
    }
    $query = http_build_query($params);
    return 'index.php' . ($query !== '' ? '?' . $query : '');
}

function kq_pagination_pages(int $currentPage, int $totalPages): array
{
    if ($totalPages <= 7) {
        return range(1, $totalPages);
    }
    $pages = [1];
    if ($currentPage > 3) {
        $pages[] = '...';
    }
    $start = max(2, $currentPage - 1);
    $end = min($totalPages - 1, $currentPage + 1);
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($currentPage < $totalPages - 2) {
        $pages[] = '...';
    }
    $pages[] = $totalPages;
    return $pages;
}

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

$isFiltered = ($search !== '' || $category !== '' || $locationFilter > 0 || $sort !== 'newest' || $perPage !== 10);
?>

<style>
    /* ══════════ FULL-WIDTH, BRIGHTER HERO SECTION ══════════ */
    .hero-banner {
        position: relative;
        width: 100%;
        min-height: 480px;
        background: linear-gradient(180deg, rgba(12, 32, 60, 0.28) 0%, rgba(14, 38, 70, 0.42) 100%),
                    url('assets/images/background.jpg') center 42% / cover no-repeat;
        box-shadow: 0 12px 36px rgba(10, 24, 44, 0.1);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 5.5rem 1.5rem;
    }

    .hero-content {
        width: 100%;
        max-width: 1180px;
        margin: 0 auto;
        text-align: center;
    }

    .hero-title {
        color: #ffffff;
        font-size: clamp(2.3rem, 4.4vw, 3.5rem);
        font-weight: 800;
        letter-spacing: -0.035em;
        line-height: 1.15;
        margin-bottom: 0.85rem;
        text-shadow: 0 3px 18px rgba(0, 0, 0, 0.75), 0 1px 4px rgba(0, 0, 0, 0.9);
    }

    .hero-subtitle {
        color: rgba(255, 255, 255, 0.96);
        font-size: clamp(1.05rem, 1.45vw, 1.25rem);
        font-weight: 500;
        max-width: 860px;
        margin: 0 auto 2.5rem auto;
        text-shadow: 0 2px 12px rgba(0, 0, 0, 0.7), 0 1px 3px rgba(0, 0, 0, 0.85);
    }

    /* ══════════ WIDER UNIFIED SEARCH BAR ══════════ */
    .hero-search-wrapper {
        position: relative;
        background: rgba(10, 26, 48, 0.65);
        backdrop-filter: blur(14px);
        -webkit-backdrop-filter: blur(14px);
        border: 1px solid rgba(255, 255, 255, 0.32);
        border-radius: 1rem;
        padding: 0.65rem;
        box-shadow: 0 24px 50px rgba(0, 0, 0, 0.42);
        max-width: 1080px;
        margin: 0 auto;
        z-index: 20;
    }

    .hero-search-bar {
        display: flex;
        align-items: stretch;
        background: #ffffff;
        border-radius: 0.75rem;
        overflow: visible;
        position: relative;
    }

    .search-input-box {
        flex: 1 1 400px;
        position: relative;
    }

    .search-input-box input {
        width: 100%;
        height: 56px;
        border: 0;
        outline: none;
        padding: 0 1rem 0 3rem;
        font-size: 1rem;
        color: var(--kq-ink);
        background: transparent;
        border-radius: 0.75rem 0 0 0.75rem;
    }

    .search-input-box input:focus {
        box-shadow: inset 0 0 0 2px var(--kq-primary);
    }

    .search-input-box .search-icon {
        position: absolute;
        left: 1.15rem;
        top: 50%;
        transform: translateY(-50%);
        color: #728498;
        pointer-events: none;
    }

    .search-select-box {
        flex: 1 1 240px;
        border-left: 1px solid #e3eaf0;
        position: relative;
    }

    .search-select-box select {
        width: 100%;
        height: 56px;
        border: 0;
        outline: none;
        padding: 0 2rem 0 1.15rem;
        font-size: 0.95rem;
        color: var(--kq-ink);
        background: transparent;
        cursor: pointer;
    }

    .search-select-box select:focus {
        box-shadow: inset 0 0 0 2px var(--kq-primary);
    }

    .search-btn-box {
        flex: 0 0 auto;
    }

    .hero-search-submit {
        height: 56px;
        padding: 0 2.5rem;
        font-size: 1.05rem;
        font-weight: 750;
        letter-spacing: -0.01em;
        background: linear-gradient(135deg, #f26522 0%, #e04e0b 100%);
        color: #ffffff;
        border: 0;
        border-radius: 0 0.75rem 0.75rem 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.45rem;
        transition: background 0.18s ease, transform 0.15s ease;
        white-space: nowrap;
        text-shadow: 0 1px 2px rgba(0,0,0,0.25);
    }

    .hero-search-submit:hover {
        background: linear-gradient(135deg, #e04e0b 0%, #c43e04 100%);
        color: #ffffff;
    }

    /* Mobile Responsive Search Bar */
    @media (max-width: 880px) {
        .hero-search-bar {
            flex-direction: column;
        }
        .search-input-box input {
            border-radius: 0.75rem 0.75rem 0 0;
        }
        .search-select-box {
            border-left: 0;
            border-top: 1px solid #e3eaf0;
        }
        .hero-search-submit {
            border-radius: 0 0 0.75rem 0.75rem;
            width: 100%;
        }
    }

    /* Autocomplete dropdown */
    .search-autocomplete-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 1050;
        max-height: 280px;
        overflow-y: auto;
        margin-top: 6px;
        border: 1px solid var(--kq-line);
        border-radius: 0.75rem;
        background: #ffffff;
        box-shadow: 0 16px 36px rgba(18, 38, 64, 0.18);
        display: none;
        text-align: left;
    }
    .search-autocomplete-dropdown.show { display: block; }
    .search-autocomplete-item {
        padding: 0.7rem 1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 0.65rem;
        font-size: 0.92rem;
        color: var(--kq-ink);
        border-bottom: 1px solid rgba(0,0,0,0.04);
        transition: background 0.15s ease;
    }
    .search-autocomplete-item:last-child { border-bottom: 0; }
    .search-autocomplete-item:hover,
    .search-autocomplete-item.active {
        background: rgba(23, 74, 112, 0.08);
        color: var(--kq-primary);
    }

    /* Active filter pill in catalog header */
    .filter-active-indicator {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.35rem 0.75rem;
        border-radius: 999px;
        background: rgba(23, 74, 112, 0.08);
        color: var(--kq-primary);
        font-size: 0.82rem;
        font-weight: 600;
    }
</style>

<!-- ═══════════════════ FULL-WIDTH HERO BANNER (WIDER & BRIGHTER) ═══════════════════ -->
<section class="hero-banner">
    <div class="hero-content">
        <!-- Replaced Title -->
        <h1 class="hero-title">Find the right land for what comes next.</h1>
        <p class="hero-subtitle">Kings Land Properties (Philippines) — Your trusted real estate service catering to buyers looking for residential lots, farm lots, beach lots, condos, and commercial properties.</p>

        <!-- Centered Search Bar at the bottom of hero text -->
        <div class="hero-search-wrapper">
            <form method="GET" action="index.php" id="property-search-form">
                <?php if ($sort !== 'newest'): ?>
                    <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <?php if ($perPage !== 10): ?>
                    <input type="hidden" name="per_page" value="<?= htmlspecialchars((string) ($perPage === 0 ? 'all' : $perPage), ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <div class="hero-search-bar">
                    <!-- 1. Text input -->
                    <div class="search-input-box">
                        <span class="search-icon">
                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        </span>
                        <input id="property-search" type="search" name="search" maxlength="120" placeholder="Search by location, keyword, or municipality" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                        <div id="search-autocomplete" class="search-autocomplete-dropdown"></div>
                    </div>

                    <!-- 2. City or municipality dropdown -->
                    <div class="search-select-box">
                        <select id="location-filter" name="location_filter" aria-label="City or municipality">
                            <option value="0">All municipalities</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= (int) $location['locationID'] ?>" data-municipality="<?= htmlspecialchars($location['municipalityName'], ENT_QUOTES, 'UTF-8') ?>" <?= $locationFilter === (int) $location['locationID'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($location['municipalityName'] . ', ' . $location['provinceName'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 3. Property type dropdown -->
                    <div class="search-select-box">
                        <select id="property-category" name="category" aria-label="Property type">
                            <option value="">All property types</option>
                            <?php foreach ($allowedCategories as $propertyCategory): ?>
                                <option value="<?= $propertyCategory ?>" <?= $category === $propertyCategory ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($categoryLabels[$propertyCategory] ?? $propertyCategory, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- 4. Vibrant Search button -->
                    <div class="search-btn-box">
                        <button class="hero-search-submit" type="submit">
                            Search &rsaquo;
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($isFiltered): ?>
            <div class="mt-3">
                <a href="index.php" class="text-white text-decoration-underline small fw-bold" style="text-shadow: 0 1px 4px rgba(0,0,0,.85);">
                    Reset search filters &times;
                </a>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Content container for property collection -->
<div class="container py-4 py-lg-5">

<!-- ═══════════════════ PROPERTY COLLECTION HEADER ═══════════════════ -->
<div class="d-flex flex-column flex-sm-row align-items-sm-end justify-content-between gap-2 mb-3">
    <div>
        <div class="page-eyebrow">Property collection</div>
        <h2 class="page-title h3 mb-0">Explore available listings</h2>
    </div>
    <div class="d-flex align-items-center gap-2">
        <?php if ($isFiltered): ?>
            <div class="filter-active-indicator">
                <span>Filtered results</span>
                <a href="index.php" class="text-danger ms-1 text-decoration-none fw-bold" title="Clear all filters">&times;</a>
            </div>
        <?php endif; ?>
        <div class="text-muted small"><?= number_format($totalProperties) ?> listing<?= $totalProperties === 1 ? '' : 's' ?> found</div>
    </div>
</div>

<?php if (normalized_user_role($_SESSION['user'] ?? null) === 'Client'): ?>
    <section class="card p-3 p-lg-4 mb-4">
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

<!-- ═══════════════════ CATALOG TOOLBAR (SORTING & PER-PAGE) ═══════════════════ -->
<div class="card kq-catalog-toolbar p-3 mb-4 shadow-sm border">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
        <div class="d-flex align-items-center flex-wrap gap-2">
            <span class="badge rounded-pill text-bg-light border px-3 py-2 text-dark fw-semibold">
                <?php if ($totalProperties > 0): ?>
                    Showing <strong class="text-primary"><?= number_format($startItem) ?>–<?= number_format($endItem) ?></strong> of <strong class="text-primary"><?= number_format($totalProperties) ?></strong> <?= $totalProperties === 1 ? 'property' : 'properties' ?>
                <?php else: ?>
                    0 properties found
                <?php endif; ?>
            </span>
            <?php if ($totalPages > 1): ?>
                <span class="text-muted small">Page <?= number_format($page) ?> of <?= number_format($totalPages) ?></span>
            <?php endif; ?>
        </div>

        <form method="GET" action="index.php" class="d-flex flex-wrap align-items-center justify-content-start justify-content-md-end gap-2.5 m-0" id="catalog-controls-form">
            <?php if ($search !== ''): ?>
                <input type="hidden" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <?php if ($locationFilter > 0): ?>
                <input type="hidden" name="location_filter" value="<?= (int) $locationFilter ?>">
            <?php endif; ?>
            <?php if ($category !== ''): ?>
                <input type="hidden" name="category" value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <div class="d-flex align-items-center gap-2">
                <label for="catalog-sort" class="form-label mb-0 small text-muted text-nowrap fw-semibold">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="me-1 align-text-bottom"><path d="m3 16 4 4 4-4"/><path d="M7 20V4"/><path d="m21 8-4-4-4 4"/><path d="M17 4v16"/></svg>Sort by:
                </label>
                <select id="catalog-sort" name="sort" class="form-select form-select-sm kq-toolbar-select" onchange="this.form.submit()">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                    <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High (Ascending)</option>
                    <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low (Descending)</option>
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Title: A to Z</option>
                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Title: Z to A</option>
                </select>
            </div>

            <div class="d-flex align-items-center gap-2">
                <label for="catalog-per-page" class="form-label mb-0 small text-muted text-nowrap fw-semibold">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="me-1 align-text-bottom"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>Show:
                </label>
                <select id="catalog-per-page" name="per_page" class="form-select form-select-sm kq-toolbar-select" onchange="this.form.submit()">
                    <option value="10" <?= $perPage === 10 ? 'selected' : '' ?>>10 properties</option>
                    <option value="6" <?= $perPage === 6 ? 'selected' : '' ?>>6 properties</option>
                    <option value="15" <?= $perPage === 15 ? 'selected' : '' ?>>15 properties</option>
                    <option value="24" <?= $perPage === 24 ? 'selected' : '' ?>>24 properties</option>
                    <option value="all" <?= $perPage === 0 ? 'selected' : '' ?>>Show all</option>
                </select>
            </div>
            <noscript>
                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            </noscript>
        </form>
    </div>
</div>

<!-- ═══════════════════ ALL AVAILABLE LAND & PROPERTIES ═══════════════════ -->
<div class="row g-4 mb-5">
    <?php if ($visibleProperties !== []): ?>
        <?php foreach ($visibleProperties as $row): ?>
            <?php
            $imageName = basename((string) ($row['image'] ?? ''));
            $imagePath = __DIR__ . '/assets/images/' . $imageName;
            $hasImage = $imageName !== '' && $imageName !== 'default.jpg' && is_file($imagePath);
            ?>
            <div class="col-md-6 col-xl-4">
                <article class="card property-card h-100" id="prop-card-<?= (int) $row['propertyID'] ?>">
                    <?php if ($hasImage): ?>
                        <img src="assets/images/<?= rawurlencode($imageName) ?>" class="card-img-top property-card-image" alt="<?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <div class="property-placeholder property-card-image d-flex align-items-center justify-content-center" aria-label="No property image available">
                            <span class="property-placeholder-mark" aria-hidden="true">KQ</span>
                        </div>
                    <?php endif; ?>
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <span class="badge rounded-pill text-bg-light"><?= htmlspecialchars($categoryLabels[$row['propertyType']] ?? $row['propertyType'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="small text-muted"><?= htmlspecialchars(property_location_label($row), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <h3 class="h5 fw-bold mb-2"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <?php if (!empty($row['areaSqm'])): ?>
                            <div class="small text-muted mb-2"><?= number_format((float) $row['areaSqm'], 2) ?> sq.m. lot</div>
                        <?php endif; ?>
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
        <?php endforeach; ?>
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

<?php if ($totalPages > 1): ?>
    <nav aria-label="Properties pagination" class="d-flex justify-content-center my-4">
        <ul class="pagination kq-pagination justify-content-center flex-wrap gap-1">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page <= 1 ? '#' : htmlspecialchars(kq_build_catalog_url(['page' => $page - 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous" <?= $page <= 1 ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    &lsaquo; Previous
                </a>
            </li>

            <?php foreach (kq_pagination_pages($page, $totalPages) as $pageItem): ?>
                <?php if ($pageItem === '...'): ?>
                    <li class="page-item disabled" aria-hidden="true">
                        <span class="page-link">&hellip;</span>
                    </li>
                <?php else: ?>
                    <li class="page-item <?= (int) $pageItem === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(kq_build_catalog_url(['page' => $pageItem]), ENT_QUOTES, 'UTF-8') ?>" <?= (int) $pageItem === $page ? 'aria-current="page"' : '' ?>>
                            <?= $pageItem ?>
                        </a>
                    </li>
                <?php endif; ?>
            <?php endforeach; ?>

            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="<?= $page >= $totalPages ? '#' : htmlspecialchars(kq_build_catalog_url(['page' => $page + 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next" <?= $page >= $totalPages ? 'tabindex="-1" aria-disabled="true"' : '' ?>>
                    Next &rsaquo;
                </a>
            </li>
        </ul>
    </nav>
<?php endif; ?>
</div> <!-- /container -->

<script>
document.addEventListener('DOMContentLoaded', () => {
    // ─── Search input autocomplete for municipalities ───
    const searchInput = document.getElementById('property-search');
    const autocompleteDropdown = document.getElementById('search-autocomplete');
    const locationSelect = document.getElementById('location-filter');
    const searchForm = document.getElementById('property-search-form');

    const municipalities = [];
    if (locationSelect) {
        for (const opt of locationSelect.options) {
            if (opt.value === '0') continue;
            municipalities.push({
                id: opt.value,
                name: opt.dataset.municipality || opt.textContent.split(',')[0].trim(),
                label: opt.textContent.trim(),
            });
        }
    }

    if (searchInput && autocompleteDropdown) {
        searchInput.addEventListener('input', () => {
            const val = searchInput.value.trim().toLowerCase();
            if (val.length === 0) {
                autocompleteDropdown.classList.remove('show');
                return;
            }

            const matches = municipalities.filter(m =>
                m.name.toLowerCase().includes(val) || m.label.toLowerCase().includes(val)
            ).slice(0, 6);

            if (matches.length === 0) {
                autocompleteDropdown.classList.remove('show');
                return;
            }

            autocompleteDropdown.innerHTML = matches.map(m => `
                <div class="search-autocomplete-item" data-id="${m.id}" data-name="${m.name}">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span>${m.label}</span>
                </div>
            `).join('');
            autocompleteDropdown.classList.add('show');

            autocompleteDropdown.querySelectorAll('.search-autocomplete-item').forEach(item => {
                item.addEventListener('click', () => {
                    locationSelect.value = item.dataset.id;
                    searchInput.value = item.dataset.name;
                    autocompleteDropdown.classList.remove('show');
                    searchForm.submit();
                });
            });
        });

        document.addEventListener('click', (e) => {
            if (!searchInput.contains(e.target) && !autocompleteDropdown.contains(e.target)) {
                autocompleteDropdown.classList.remove('show');
            }
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>
