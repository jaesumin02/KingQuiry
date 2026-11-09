<?php
include 'config/db.php';
require_once 'includes/location_directory.php';
include 'includes/header.php';

$locations = get_property_locations($conn);

// Pre-selected location from URL (if any)
$selectedLocationId = filter_var($_GET['location'] ?? 0, FILTER_VALIDATE_INT);
$selectedLocationId = $selectedLocationId === false ? 0 : max(0, $selectedLocationId);

// Municipality center coordinates
$municipalityCoordinates = [
    'Agoncillo'        => [13.9379, 120.9282],
    'Alitagtag'        => [13.8633, 121.0032],
    'Balayan'          => [13.9392, 120.7231],
    'Balete'           => [13.7917, 121.1014],
    'Batangas City'    => [13.7565, 121.0583],
    'Bauan'            => [13.7917, 120.9458],
    'Calaca City'      => [13.9308, 120.8131],
    'Calatagan'        => [13.8319, 120.6340],
    'Cuenca'           => [13.8999, 121.0519],
    'Ibaan'            => [13.8170, 121.1319],
    'Laurel'           => [14.0481, 120.9184],
    'Lemery'           => [13.8814, 120.8458],
    'Lian'             => [13.9421, 120.6460],
    'Lipa City'        => [13.9413, 121.1632],
    'Lobo'             => [13.5845, 121.1956],
    'Mabini'           => [13.7154, 120.9502],
    'Malvar'           => [14.0471, 121.1572],
    'Mataasnakahoy'    => [13.9621, 121.0030],
    'Nasugbu'          => [14.0925, 120.6303],
    'Padre Garcia'     => [13.8738, 121.2136],
    'Rosario'          => [13.8446, 121.2134],
    'San Jose'         => [13.8718, 121.1057],
    'San Juan'         => [13.8248, 121.3963],
    'San Luis'         => [13.8345, 121.5181],
    'San Nicolas'      => [13.9282, 121.0759],
    'San Pascual'      => [13.8082, 121.0297],
    'Santa Teresita'   => [13.8559, 120.7007],
    'Santo Tomas City' => [14.0900, 121.1470],
    'Taal'             => [13.8814, 120.9327],
    'Talisay'          => [14.0922, 120.7752],
    'Tanauan City'     => [14.0862, 121.1513],
    'Taysan'           => [13.7329, 121.1877],
    'Tingloy'          => [13.6460, 120.8644],
    'Tuy'              => [13.9791, 120.7350],
];

$locationCoordinatesJson = json_encode($municipalityCoordinates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>

<style>
    /* ───── Map page specific styles ───── */
    .map-search-bar {
        position: relative;
        z-index: 10;
    }
    .map-search-bar .search-icon {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--kq-muted);
        font-size: 1.1rem;
        pointer-events: none;
    }
    .map-search-bar input {
        padding-left: 2.8rem;
        height: 52px;
        font-size: 1rem;
        border: 2px solid var(--kq-line);
        border-radius: .85rem;
        background: var(--kq-surface);
        transition: border-color .2s ease, box-shadow .2s ease;
    }
    .map-search-bar input:focus {
        border-color: var(--kq-primary);
        box-shadow: 0 0 0 .25rem rgba(23, 74, 112, .10);
    }

    /* Autocomplete dropdown */
    .location-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 100;
        max-height: 320px;
        overflow-y: auto;
        margin-top: 4px;
        border: 1px solid var(--kq-line);
        border-radius: .75rem;
        background: var(--kq-surface);
        box-shadow: 0 12px 40px rgba(22, 48, 76, .14);
        display: none;
    }
    .location-dropdown.show { display: block; }
    .location-dropdown .dropdown-item {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .75rem 1rem;
        color: var(--kq-ink);
        font-size: .92rem;
        cursor: pointer;
        border-bottom: 1px solid rgba(0,0,0,.04);
        transition: background .15s;
    }
    .location-dropdown .dropdown-item:last-child { border-bottom: 0; }
    .location-dropdown .dropdown-item:hover,
    .location-dropdown .dropdown-item.active {
        background: rgba(23, 74, 112, .06);
    }
    .location-dropdown .dropdown-item .loc-icon {
        flex-shrink: 0;
        width: 32px;
        height: 32px;
        display: grid;
        place-items: center;
        border-radius: .55rem;
        background: rgba(23, 74, 112, .08);
        color: var(--kq-primary);
        font-size: .82rem;
    }
    .location-dropdown .dropdown-item .loc-name {
        font-weight: 600;
    }
    .location-dropdown .dropdown-item .loc-province {
        font-size: .78rem;
        color: var(--kq-muted);
    }
    .location-dropdown .dropdown-item .loc-count {
        margin-left: auto;
        flex-shrink: 0;
        font-size: .72rem;
        font-weight: 700;
        color: var(--kq-primary);
        background: rgba(23, 74, 112, .08);
        padding: .2rem .55rem;
        border-radius: 999px;
    }
    .location-dropdown .no-results {
        padding: 1.2rem 1rem;
        text-align: center;
        color: var(--kq-muted);
        font-size: .88rem;
    }

    /* Map container */
    #property-map {
        height: 480px;
        width: 100%;
        border-radius: .9rem;
        overflow: hidden;
        background: #e8edf2;
        transition: opacity .3s ease;
    }
    #property-map.loading {
        opacity: .55;
        pointer-events: none;
    }
    @media (max-width: 767.98px) {
        #property-map { height: 350px; }
    }

    /* Active location bar */
    .active-location-bar {
        display: none;
        align-items: center;
        gap: .75rem;
        padding: .6rem 1rem;
        border-radius: .65rem;
        background: rgba(23, 74, 112, .06);
        border: 1px solid rgba(23, 74, 112, .1);
    }
    .active-location-bar.show { display: flex; }
    .active-location-bar .loc-label {
        font-weight: 700;
        color: var(--kq-primary);
        font-size: .9rem;
    }
    .active-location-bar .clear-btn {
        margin-left: auto;
        border: 0;
        background: transparent;
        color: var(--kq-muted);
        font-size: .82rem;
        font-weight: 600;
        cursor: pointer;
        padding: .25rem .6rem;
        border-radius: .4rem;
        transition: background .15s, color .15s;
    }
    .active-location-bar .clear-btn:hover {
        background: rgba(0, 0, 0, .06);
        color: var(--kq-ink);
    }

    /* Listing count badge */
    .listing-count-badge {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .35rem .7rem;
        border-radius: 999px;
        background: rgba(23, 74, 112, .07);
        color: var(--kq-primary);
        font-size: .78rem;
        font-weight: 700;
    }

    /* Property list cards */
    .property-list-card {
        overflow: hidden;
        transition: transform .22s ease, box-shadow .22s ease;
        border: 1px solid var(--kq-line);
        border-radius: var(--kq-radius);
    }
    .property-list-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 18px 38px rgba(22, 48, 76, .13);
    }
    .property-list-card .card-img-top {
        height: 210px;
        object-fit: cover;
        background: #e8edf2;
    }
    .property-type-badge {
        display: inline-flex;
        align-items: center;
        padding: .3rem .65rem;
        border-radius: 999px;
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .property-type-badge.residential { background: #e8f5e9; color: #2e7d32; }
    .property-type-badge.commercial  { background: #e3f2fd; color: #1565c0; }
    .property-type-badge.farmlot     { background: #fff8e1; color: #f57f17; }
    .property-type-badge.beachlot    { background: #e0f7fa; color: #00838f; }
    .property-type-badge.condo       { background: #ede7f6; color: #512da8; }

    /* Loading spinner overlay */
    .map-loading-overlay {
        position: absolute;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(255,255,255,.7);
        border-radius: .9rem;
        z-index: 50;
    }
    .map-loading-overlay.show { display: flex; }

    /* Property skeleton loading */
    .skeleton-card {
        border: 1px solid var(--kq-line);
        border-radius: var(--kq-radius);
        overflow: hidden;
    }
    .skeleton-img {
        height: 210px;
        background: linear-gradient(90deg, #e8edf2 25%, #f0f3f7 50%, #e8edf2 75%);
        background-size: 200% 100%;
        animation: shimmer 1.5s infinite;
    }
    .skeleton-line {
        height: 14px;
        border-radius: 7px;
        background: linear-gradient(90deg, #e8edf2 25%, #f0f3f7 50%, #e8edf2 75%);
        background-size: 200% 100%;
        animation: shimmer 1.5s infinite;
    }
    .skeleton-line.w-75 { width: 75%; }
    .skeleton-line.w-50 { width: 50%; }
    .skeleton-line.w-40 { width: 40%; }
    @keyframes shimmer {
        0% { background-position: 200% 0; }
        100% { background-position: -200% 0; }
    }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 2.5rem 1.5rem;
    }
    .empty-state-icon {
        width: 72px;
        height: 72px;
        display: inline-grid;
        place-items: center;
        border-radius: 50%;
        background: rgba(23, 74, 112, .07);
        color: var(--kq-primary);
        font-size: 1.6rem;
        margin-bottom: 1rem;
    }

    /* Filters row */
    .filter-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .4rem .85rem;
        border-radius: 999px;
        border: 1px solid var(--kq-line);
        background: var(--kq-surface);
        color: var(--kq-ink);
        font-size: .82rem;
        font-weight: 600;
        cursor: pointer;
        transition: background .15s, border-color .15s, color .15s;
    }
    .filter-pill:hover,
    .filter-pill.active {
        background: var(--kq-primary);
        border-color: var(--kq-primary);
        color: #fff;
    }
    .filter-pill.active:hover {
        background: var(--kq-primary-dark);
    }
</style>

<!-- ═══════════════════ SEARCH BAR ═══════════════════ -->
<section class="mb-4">
    <div class="d-flex flex-column flex-sm-row align-items-sm-end justify-content-between gap-2 mb-3">
        <div>
            <div class="page-eyebrow">Property collection</div>
            <h1 class="page-title h3 mb-0">Explore available listings</h1>
        </div>
        <div class="listing-count-badge" id="listing-count">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <span id="listing-count-number">0</span> listings found
        </div>
    </div>

    <div class="card p-3 p-lg-4 mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-lg-6">
                <label class="form-label fw-semibold small" for="location-search">Search location</label>
                <div class="map-search-bar">
                    <span class="search-icon">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                    </span>
                    <input type="text" id="location-search" class="form-control" placeholder="Search by municipality, city, or keyword…" autocomplete="off" maxlength="120">
                    <div class="location-dropdown" id="location-dropdown"></div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label fw-semibold small" for="location-select">City or municipality</label>
                <select id="location-select" class="form-select">
                    <option value="0">All locations</option>
                    <?php foreach ($locations as $location): ?>
                        <option value="<?= (int) $location['locationID'] ?>"
                                data-municipality="<?= htmlspecialchars($location['municipalityName'], ENT_QUOTES, 'UTF-8') ?>"
                                <?= $selectedLocationId === (int) $location['locationID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($location['municipalityName'] . ', ' . $location['provinceName'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label fw-semibold small" for="type-filter">Property type</label>
                <select id="type-filter" class="form-select">
                    <option value="">All property types</option>
                    <option value="Residential">Residential Lot</option>
                    <option value="Farmlot">Farm Lot</option>
                    <option value="Beachlot">Beach Lot</option>
                    <option value="Condo">Condo</option>
                    <option value="Commercial">Commercial</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Active location indicator -->
    <div class="active-location-bar mb-3" id="active-location-bar">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
        <span class="loc-label" id="active-location-label">Batangas City, Batangas</span>
        <button class="clear-btn" id="clear-location-btn" type="button">✕ Clear</button>
    </div>
</section>

<!-- ═══════════════════ MAP SECTION ═══════════════════ -->
<section class="card p-3 p-lg-4 mb-4" aria-labelledby="property-map-title">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
        <div>
            <div class="page-eyebrow">Interactive location view</div>
            <h2 class="h4 fw-bold mb-1" id="property-map-title">Batangas property map</h2>
        </div>
        <div class="small text-muted">Explore properties by municipality</div>
    </div>
    <div style="position: relative;">
        <div id="property-map" aria-label="Map of available properties"></div>
        <div class="map-loading-overlay" id="map-loading">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading…</span>
            </div>
        </div>
    </div>
</section>

<!-- ═══════════════════ PROPERTY LIST ═══════════════════ -->
<section class="mb-5" aria-labelledby="property-list-title">
    <div class="d-flex flex-column flex-sm-row align-items-sm-end justify-content-between gap-2 mb-3">
        <div>
            <div class="page-eyebrow">Available properties</div>
            <h2 class="h4 fw-bold mb-0" id="property-list-title">Properties in this area</h2>
        </div>
        <div class="d-flex flex-wrap gap-2" id="type-pills">
            <button class="filter-pill active" data-type="">All</button>
            <button class="filter-pill" data-type="Residential">Residential</button>
            <button class="filter-pill" data-type="Farmlot">Farm Lot</button>
            <button class="filter-pill" data-type="Beachlot">Beach Lot</button>
            <button class="filter-pill" data-type="Condo">Condo</button>
            <button class="filter-pill" data-type="Commercial">Commercial</button>
        </div>
    </div>

    <div class="row g-4" id="property-list">
        <!-- Properties loaded dynamically -->
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // ─── Configuration ───
    const BATANGAS_CENTER = [13.8, 120.85];
    const DEFAULT_ZOOM = 10;
    const LOCATION_ZOOM = 13;
    const MUNICIPALITY_COORDS = <?= $locationCoordinatesJson ?>;
    const INITIAL_LOCATION_ID = <?= (int) $selectedLocationId ?>;

    // ─── DOM references ───
    const searchInput = document.getElementById('location-search');
    const locationDropdown = document.getElementById('location-dropdown');
    const locationSelect = document.getElementById('location-select');
    const typeFilter = document.getElementById('type-filter');
    const listingCountNumber = document.getElementById('listing-count-number');
    const propertyList = document.getElementById('property-list');
    const activeLocationBar = document.getElementById('active-location-bar');
    const activeLocationLabel = document.getElementById('active-location-label');
    const clearLocationBtn = document.getElementById('clear-location-btn');
    const mapLoadingOverlay = document.getElementById('map-loading');
    const typePills = document.querySelectorAll('#type-pills .filter-pill');

    // ─── State ───
    let currentLocationId = INITIAL_LOCATION_ID;
    let currentCategory = '';
    let currentSearchText = '';
    let abortController = null;

    // ─── Initialize Leaflet Map ───
    const map = L.map('property-map', {
        scrollWheelZoom: true,
        zoomControl: true,
    }).setView(BATANGAS_CENTER, DEFAULT_ZOOM);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 18,
    }).addTo(map);

    // Custom marker icon
    const propertyIcon = L.divIcon({
        className: 'kq-map-marker',
        html: `<div style="
            width: 32px; height: 32px;
            background: var(--kq-primary, #174a70);
            border: 3px solid #fff;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            box-shadow: 0 3px 8px rgba(0,0,0,.3);
            display: grid; place-items: center;
        "><div style="
            transform: rotate(45deg);
            color: #fff;
            font-size: 12px;
            font-weight: 800;
        ">●</div></div>`,
        iconSize: [32, 32],
        iconAnchor: [16, 32],
        popupAnchor: [0, -32],
    });

    let markersLayer = L.layerGroup().addTo(map);

    // ─── Location Autocomplete ───
    const locationData = [];
    for (const option of locationSelect.options) {
        if (option.value === '0') continue;
        locationData.push({
            id: parseInt(option.value, 10),
            name: option.dataset.municipality || '',
            label: option.textContent.trim(),
        });
    }

    let dropdownActiveIndex = -1;

    function showDropdown(items) {
        if (items.length === 0) {
            locationDropdown.innerHTML = '<div class="no-results">No matching locations found</div>';
            locationDropdown.classList.add('show');
            return;
        }

        locationDropdown.innerHTML = items.map((item, idx) => `
            <div class="dropdown-item${idx === dropdownActiveIndex ? ' active' : ''}"
                 data-location-id="${item.id}" data-index="${idx}">
                <span class="loc-icon">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                </span>
                <div>
                    <div class="loc-name">${escapeHtml(item.name)}</div>
                    <div class="loc-province">Batangas</div>
                </div>
            </div>
        `).join('');
        locationDropdown.classList.add('show');

        locationDropdown.querySelectorAll('.dropdown-item').forEach(el => {
            el.addEventListener('click', () => {
                selectLocation(parseInt(el.dataset.locationId, 10));
                hideDropdown();
            });
            el.addEventListener('mouseenter', () => {
                dropdownActiveIndex = parseInt(el.dataset.index, 10);
                highlightDropdownItem();
            });
        });
    }

    function hideDropdown() {
        locationDropdown.classList.remove('show');
        dropdownActiveIndex = -1;
    }

    function highlightDropdownItem() {
        locationDropdown.querySelectorAll('.dropdown-item').forEach((el, idx) => {
            el.classList.toggle('active', idx === dropdownActiveIndex);
        });
    }

    searchInput.addEventListener('input', () => {
        const query = searchInput.value.trim().toLowerCase();
        if (query.length === 0) {
            hideDropdown();
            if (currentLocationId !== 0) {
                // Keep current location filter
            } else {
                currentSearchText = '';
                loadProperties();
            }
            return;
        }

        currentSearchText = query;
        const matches = locationData.filter(loc =>
            loc.name.toLowerCase().includes(query) || loc.label.toLowerCase().includes(query)
        );
        dropdownActiveIndex = -1;
        showDropdown(matches);
    });

    searchInput.addEventListener('keydown', (e) => {
        const items = locationDropdown.querySelectorAll('.dropdown-item[data-location-id]');
        if (!locationDropdown.classList.contains('show') || items.length === 0) {
            if (e.key === 'Enter') {
                e.preventDefault();
                currentSearchText = searchInput.value.trim();
                currentLocationId = 0;
                locationSelect.value = '0';
                updateActiveLocationBar();
                loadProperties();
            }
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            dropdownActiveIndex = Math.min(dropdownActiveIndex + 1, items.length - 1);
            highlightDropdownItem();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            dropdownActiveIndex = Math.max(dropdownActiveIndex - 1, 0);
            highlightDropdownItem();
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (dropdownActiveIndex >= 0 && items[dropdownActiveIndex]) {
                selectLocation(parseInt(items[dropdownActiveIndex].dataset.locationId, 10));
            }
            hideDropdown();
        } else if (e.key === 'Escape') {
            hideDropdown();
        }
    });

    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !locationDropdown.contains(e.target)) {
            hideDropdown();
        }
    });

    // ─── Location select (dropdown) ───
    locationSelect.addEventListener('change', () => {
        const val = parseInt(locationSelect.value, 10);
        selectLocation(val);
    });

    // ─── Type filter ───
    typeFilter.addEventListener('change', () => {
        currentCategory = typeFilter.value;
        syncTypePills();
        loadProperties();
    });

    // ─── Type pills ───
    typePills.forEach(pill => {
        pill.addEventListener('click', () => {
            currentCategory = pill.dataset.type;
            typeFilter.value = currentCategory;
            syncTypePills();
            loadProperties();
        });
    });

    function syncTypePills() {
        typePills.forEach(p => p.classList.toggle('active', p.dataset.type === currentCategory));
    }

    // ─── Clear location ───
    clearLocationBtn.addEventListener('click', () => {
        currentLocationId = 0;
        currentSearchText = '';
        searchInput.value = '';
        locationSelect.value = '0';
        updateActiveLocationBar();
        map.setView(BATANGAS_CENTER, DEFAULT_ZOOM);
        loadProperties();
    });

    // ─── Select a location ───
    function selectLocation(locationId) {
        currentLocationId = locationId;
        currentSearchText = '';

        if (locationId > 0) {
            locationSelect.value = String(locationId);
            const option = locationSelect.options[locationSelect.selectedIndex];
            const municipality = option?.dataset?.municipality || option?.textContent?.trim() || '';
            searchInput.value = municipality;

            // Pan map to municipality
            const coords = MUNICIPALITY_COORDS[municipality];
            if (coords) {
                map.setView(coords, LOCATION_ZOOM, { animate: true });
            }
        } else {
            locationSelect.value = '0';
            searchInput.value = '';
            map.setView(BATANGAS_CENTER, DEFAULT_ZOOM, { animate: true });
        }

        updateActiveLocationBar();
        loadProperties();
    }

    function updateActiveLocationBar() {
        if (currentLocationId > 0) {
            const option = locationSelect.options[locationSelect.selectedIndex];
            activeLocationLabel.textContent = option?.textContent?.trim() || '';
            activeLocationBar.classList.add('show');
        } else {
            activeLocationBar.classList.remove('show');
        }
    }

    // ─── Load properties via AJAX ───
    async function loadProperties() {
        if (abortController) abortController.abort();
        abortController = new AbortController();

        showLoading(true);
        showSkeletons();

        const params = new URLSearchParams();
        if (currentLocationId > 0) params.set('location_id', currentLocationId);
        if (currentCategory) params.set('category', currentCategory);
        if (currentSearchText) params.set('search', currentSearchText);

        try {
            const response = await fetch('api/map_properties.php?' + params.toString(), {
                signal: abortController.signal,
            });
            const data = await response.json();

            if (data.success) {
                renderMarkers(data.properties);
                renderPropertyList(data.properties);
                listingCountNumber.textContent = data.count;

                // If location data returned, center map
                if (data.location && currentLocationId > 0) {
                    map.setView([data.location.lat, data.location.lng], LOCATION_ZOOM, { animate: true });
                }
            }
        } catch (err) {
            if (err.name !== 'AbortError') {
                console.error('Failed to load properties:', err);
                propertyList.innerHTML = `
                    <div class="col-12">
                        <div class="card text-center px-4 py-5">
                            <h3 class="h5 fw-bold">Unable to load properties</h3>
                            <p class="text-muted mb-3">Please check your connection and try again.</p>
                            <button class="btn btn-outline-primary align-self-center" onclick="location.reload()">Retry</button>
                        </div>
                    </div>`;
            }
        } finally {
            showLoading(false);
        }
    }

    // ─── Map markers ───
    function renderMarkers(properties) {
        markersLayer.clearLayers();
        const bounds = [];

        properties.forEach(prop => {
            if (prop.lat === null || prop.lng === null) return;

            // Jitter to avoid exact overlap
            const jitter = () => (Math.random() - 0.5) * 0.003;
            const lat = prop.lat + jitter();
            const lng = prop.lng + jitter();

            const marker = L.marker([lat, lng], { icon: propertyIcon }).addTo(markersLayer);

            const priceFormatted = Number(prop.price).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });

            const imageHtml = prop.image
                ? `<img src="${prop.image}" style="width:100%;height:100px;object-fit:cover;border-radius:.4rem;margin-bottom:8px;" alt="${escapeHtml(prop.title)}">`
                : '';

            const categoryLabels = {
                'Residential': 'Residential Lot',
                'Farmlot': 'Farm Lot',
                'Beachlot': 'Beach Lot',
                'Condo': 'Condo',
                'Commercial': 'Commercial Property',
            };
            const displayType = categoryLabels[prop.type] || prop.type;

            marker.bindPopup(`
                <div style="min-width: 200px; max-width: 260px;">
                    ${imageHtml}
                    <div style="font-weight: 700; margin-bottom: 4px; font-size: 13px;">${escapeHtml(prop.title)}</div>
                    <div style="font-size: 11px; color: #5c6d7a; margin-bottom: 4px;">${escapeHtml(prop.location)}</div>
                    ${prop.areaSqm ? `<div style="font-size: 11px; color: #5c6d7a; margin-bottom: 4px;">${Number(prop.areaSqm).toLocaleString()} sq.m.</div>` : ''}
                    <div style="font-size: 13px; color: #174a70; font-weight: 700; margin-bottom: 6px;">₱${priceFormatted}</div>
                    <div style="font-size: 11px; color: #5c6d7a; margin-bottom: 8px;">${escapeHtml(displayType)}</div>
                    <a href="${prop.link}" style="display:inline-block;padding:6px 14px;background:#174a70;color:#fff;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;">View details</a>
                </div>
            `, { maxWidth: 280, className: 'kq-hover-popup' });

            let hoverTimer = null;
            let closeTimer = null;

            function bindPopupHoverEvents() {
                setTimeout(() => {
                    const popupEl = marker.getPopup()?.getElement();
                    if (!popupEl) return;
                    popupEl.onmouseenter = () => {
                        if (closeTimer) {
                            clearTimeout(closeTimer);
                            closeTimer = null;
                        }
                    };
                    popupEl.onmouseleave = () => {
                        closeTimer = setTimeout(() => {
                            marker.closePopup();
                        }, 250);
                    };
                }, 50);
            }

            // Mouse hover over pin: 0.5-second (500ms) delay to show information popup
            marker.on('mouseover', () => {
                if (closeTimer) {
                    clearTimeout(closeTimer);
                    closeTimer = null;
                }
                hoverTimer = setTimeout(() => {
                    marker.openPopup();
                    bindPopupHoverEvents();
                }, 500);
            });

            // Mouse leave pin: cancel pending open, or set grace period to close
            marker.on('mouseout', () => {
                if (hoverTimer) {
                    clearTimeout(hoverTimer);
                    hoverTimer = null;
                }
                closeTimer = setTimeout(() => {
                    marker.closePopup();
                }, 300);
            });

            // Click pin: immediately open popup without waiting 0.5s & scroll to card
            marker.on('click', () => {
                if (hoverTimer) {
                    clearTimeout(hoverTimer);
                    hoverTimer = null;
                }
                marker.openPopup();
                bindPopupHoverEvents();

                const card = document.getElementById('prop-card-' + prop.id);
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.style.boxShadow = '0 0 0 3px var(--kq-primary)';
                    setTimeout(() => { card.style.boxShadow = ''; }, 2000);
                }
            });

            bounds.push([lat, lng]);
        });

        if (bounds.length > 0 && currentLocationId === 0) {
            map.fitBounds(bounds, { padding: [40, 40], maxZoom: 14 });
        }
    }

    // ─── Property list ───
    function renderPropertyList(properties) {
        if (properties.length === 0) {
            propertyList.innerHTML = `
                <div class="col-12">
                    <div class="card empty-state">
                        <div class="empty-state-icon">
                            <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        </div>
                        <h3 class="h5 fw-bold">No matching properties yet</h3>
                        <p class="text-muted mb-3">Try another search or reset your filters to see all available listings.</p>
                        <div><button class="btn btn-outline-primary" id="clear-filters-btn" type="button">Clear filters</button></div>
                    </div>
                </div>`;

            document.getElementById('clear-filters-btn')?.addEventListener('click', () => {
                currentLocationId = 0;
                currentCategory = '';
                currentSearchText = '';
                searchInput.value = '';
                locationSelect.value = '0';
                typeFilter.value = '';
                syncTypePills();
                updateActiveLocationBar();
                map.setView(BATANGAS_CENTER, DEFAULT_ZOOM);
                loadProperties();
            });
            return;
        }

        propertyList.innerHTML = properties.map(prop => {
            const priceFormatted = Number(prop.price).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
            const pricePerSqm = prop.pricePerSqm > 0
                ? `₱${Number(prop.pricePerSqm).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} per sq.m.`
                : 'Contact us for price per sq.m.';
            const typeLower = (prop.type || '').toLowerCase();
            const typeClass = ['residential', 'commercial', 'farmlot', 'beachlot', 'condo'].includes(typeLower) ? typeLower : '';

            const imageHtml = prop.image
                ? `<img src="${prop.image}" class="card-img-top" style="height:210px;object-fit:cover;" alt="${escapeHtml(prop.title)}">`
                : `<div class="property-placeholder d-flex align-items-center justify-content-center" style="height:210px;">
                       <span class="property-placeholder-mark" aria-hidden="true">KQ</span>
                   </div>`;

            const categoryLabels = {
                'Residential': 'Residential Lot',
                'Farmlot': 'Farm Lot',
                'Beachlot': 'Beach Lot',
                'Condo': 'Condo',
                'Commercial': 'Commercial Property',
            };
            const displayType = categoryLabels[prop.type] || prop.type;

            return `
                <div class="col-md-6 col-xl-4">
                    <article class="card property-list-card h-100" id="prop-card-${prop.id}">
                        ${imageHtml}
                        <div class="card-body p-4 d-flex flex-column">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                                <span class="property-type-badge ${typeClass}">${escapeHtml(displayType)}</span>
                                <span class="small text-muted">${escapeHtml(prop.location)}</span>
                            </div>
                            <h3 class="h5 fw-bold mb-2">${escapeHtml(prop.title)}</h3>
                            ${prop.areaSqm ? `<div class="small text-muted mb-2">${Number(prop.areaSqm).toLocaleString()} sq.m. lot</div>` : ''}
                            <p class="text-muted small mb-3">${escapeHtml(prop.characteristics || 'Contact our team for property characteristics.')}</p>
                            <div class="border-top pt-3 mt-auto">
                                <div class="property-price">₱${priceFormatted}</div>
                                <div class="small text-muted mb-3">${pricePerSqm}</div>
                                <a href="${prop.link}" class="btn btn-outline-primary w-100">View property details</a>
                            </div>
                        </div>
                    </article>
                </div>`;
        }).join('');
    }

    // ─── Loading states ───
    function showLoading(isLoading) {
        const mapEl = document.getElementById('property-map');
        mapEl.classList.toggle('loading', isLoading);
        mapLoadingOverlay.classList.toggle('show', isLoading);
    }

    function showSkeletons() {
        propertyList.innerHTML = Array.from({ length: 6 }, () => `
            <div class="col-md-6 col-xl-4">
                <div class="skeleton-card">
                    <div class="skeleton-img"></div>
                    <div class="p-4">
                        <div class="skeleton-line w-40 mb-2"></div>
                        <div class="skeleton-line w-75 mb-2" style="height:18px;"></div>
                        <div class="skeleton-line w-50 mb-3"></div>
                        <div class="skeleton-line w-50 mb-2" style="height:20px;"></div>
                        <div class="skeleton-line w-40 mb-3"></div>
                        <div class="skeleton-line" style="height:40px;border-radius:.65rem;"></div>
                    </div>
                </div>
            </div>
        `).join('');
    }

    // ─── Helpers ───
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    // ─── Initial load ───
    updateActiveLocationBar();
    loadProperties();
});
</script>

<?php include 'includes/footer.php'; ?>
