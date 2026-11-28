<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/location_directory.php';
require_once __DIR__ . '/includes/property_categories.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include __DIR__ . '/includes/header.php';
?>

<section class="container py-4 py-lg-5">
    <div class="mb-4">
        <div class="page-eyebrow">PROPERTY LOCATIONS</div>
        <h1 class="page-title h2 mb-1">Map Explorer</h1>
        <p class="text-muted mb-0">Select a property pin or listing to view its details. Map locations without exact pins are approximate municipality centers.</p>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div id="property-map" class="kq-property-map rounded-4 border shadow-sm" role="application" aria-label="Map of available properties" style="height: min(68vh, 680px); min-height: 360px;">
                <span class="kq-map-fallback">Loading property map…</span>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white border-bottom py-3">
                    <h2 class="h5 fw-bold mb-0">Available properties <span id="map-property-count" class="badge text-bg-light border text-muted">…</span></h2>
                </div>
                <div id="map-property-list" class="list-group list-group-flush overflow-auto" style="max-height: min(68vh, 680px);">
                    <div class="list-group-item text-muted py-4">Loading available property listings…</div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', async () => {
    const mapElement = document.getElementById('property-map');
    const listElement = document.getElementById('map-property-list');
    const countElement = document.getElementById('map-property-count');
    if (!mapElement || !listElement || !countElement) return;
    if (typeof L === 'undefined') {
        const fallback = mapElement.querySelector('.kq-map-fallback');
        if (fallback) fallback.textContent = 'The map library could not be loaded.';
        countElement.textContent = '0';
        listElement.innerHTML = '<div class="list-group-item text-danger py-4">The map could not be loaded. Please try again later.</div>';
        return;
    }

    mapElement.replaceChildren();
    const map = L.map(mapElement).setView([13.935, 120.8], 9);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    try {
        const response = await fetch('api/map_properties.php', { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error('Property locations could not be loaded.');
        const data = await response.json();
        if (!data.success || !Array.isArray(data.properties)) throw new Error('Property locations could not be loaded.');

        const properties = data.properties.filter((property) =>
            Number.isInteger(Number(property.id))
            && Number(property.id) > 0
            && property.lat !== null
            && property.lng !== null
            && Number.isFinite(Number(property.lat))
            && Number.isFinite(Number(property.lng))
        );
        countElement.textContent = String(properties.length);
        listElement.replaceChildren();

        if (properties.length === 0) {
            listElement.innerHTML = '<div class="list-group-item text-muted py-4">No available properties with map locations are listed.</div>';
            return;
        }

        const markers = [];
        properties.forEach((property) => {
            const latitude = Number(property.lat);
            const longitude = Number(property.lng);
            const marker = L.marker([latitude, longitude]).addTo(map);
            const popup = document.createElement('div');
            popup.className = 'p-1';
            const title = document.createElement('strong');
            title.textContent = String(property.title || 'Property listing');
            const location = document.createElement('div');
            location.className = 'small text-muted mt-1';
            location.textContent = String(property.location || '');
            const link = document.createElement('a');
            link.className = 'btn btn-sm btn-primary mt-2';
            link.href = 'property_detail.php?id=' + encodeURIComponent(String(property.id));
            link.textContent = 'View property';
            popup.append(title, location, link);
            marker.bindPopup(popup);
            markers.push(marker);

            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action py-3 text-start';
            const itemTitle = document.createElement('span');
            itemTitle.className = 'd-block fw-semibold';
            itemTitle.textContent = String(property.title || 'Property listing');
            const itemLocation = document.createElement('span');
            itemLocation.className = 'd-block small text-muted mt-1';
            itemLocation.textContent = String(property.location || '');
            const itemPrice = document.createElement('span');
            itemPrice.className = 'd-block small text-primary fw-semibold mt-1';
            itemPrice.textContent = Number.isFinite(Number(property.price))
                ? '₱' + Number(property.price).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                : '';
            item.append(itemTitle, itemLocation, itemPrice);
            item.addEventListener('click', () => {
                map.setView([latitude, longitude], 15);
                marker.openPopup();
            });
            listElement.append(item);
        });

        map.fitBounds(L.featureGroup(markers).getBounds().pad(0.15), { maxZoom: 13 });
        window.setTimeout(() => map.invalidateSize(), 50);
    } catch (error) {
        console.error(error);
        countElement.textContent = '0';
        listElement.innerHTML = '<div class="list-group-item text-danger py-4">Property locations could not be loaded. Please try again later.</div>';
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
