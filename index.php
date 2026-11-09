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
$visibleProperties = $result->fetch_all(MYSQLI_ASSOC);
$result->data_seek(0);
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

$isFiltered = ($search !== '' || $category !== '' || $locationFilter > 0);
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

<!-- Content container for stats and property collection -->
<div class="container py-4 py-lg-5">

<!-- ═══════════════════ PROPERTY HIGHLIGHTS (STAT CARDS) ═══════════════════ -->
<section class="row g-3 mb-4" aria-label="Property highlights">
    <div class="col-md-4">
        <article class="kq-stat-card">
            <div class="kq-stat-label">Verified listings</div>
            <div class="kq-stat-value"><?= number_format((int) $result->num_rows) ?></div>
            <div class="kq-stat-sub">Curated properties &amp; land opportunities across the Philippines.</div>
        </article>
    </div>
    <div class="col-md-4">
        <article class="kq-stat-card">
            <div class="kq-stat-label">Property portfolio</div>
            <div class="kq-stat-value">5 categories</div>
            <div class="kq-stat-sub">Residential lots, farm lots, beach lots, condos &amp; commercial.</div>
        </article>
    </div>
    <div class="col-md-4">
        <article class="kq-stat-card">
            <div class="kq-stat-label">Client process</div>
            <div class="kq-stat-value">Fast & clear</div>
            <div class="kq-stat-sub">Book visits, track updates, and review completed sales with confidence.</div>
        </article>
    </div>
</section>

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
        <div class="text-muted small"><?= number_format($result->num_rows) ?> listing<?= $result->num_rows === 1 ? '' : 's' ?> found</div>
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
