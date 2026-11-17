<?php
/**
 * API endpoint: returns properties filtered by locationID (municipality).
 *
 * GET parameters:
 *   location_id  – (int) property_locations.locationID to filter by; 0 or omitted = all
 *   search       – (string) optional keyword search across title, description, municipality
 *   category     – (string) optional propertyType filter
 *
 * Returns JSON: { success: true, properties: [...], location: {...}|null }
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/location_directory.php';

$locationId = filter_var($_GET['location_id'] ?? 0, FILTER_VALIDATE_INT);
$locationId = $locationId === false ? 0 : max(0, $locationId);

$search = $_GET['search'] ?? '';
$search = is_string($search) ? trim(substr($search, 0, 120)) : '';

$category = $_GET['category'] ?? '';
$allowedCategories = ['Residential', 'Commercial', 'Farmlot', 'Beachlot', 'Condo'];
$category = is_string($category) && in_array($category, $allowedCategories, true) ? $category : '';

$conditions = ["p.status = 'Available'", "p.isDeleted = 0", "LEFT(p.title, 8) <> '[SAMPLE]'"];
$parameters = [];
$parameterTypes = '';

if ($locationId > 0) {
    $conditions[] = 'p.locationID = ?';
    $parameters[] = $locationId;
    $parameterTypes .= 'i';
}

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
$rows = $result->fetch_all(MYSQLI_ASSOC);

// Build property data with coordinates
$properties = [];
foreach ($rows as $row) {
    $municipality = trim((string) ($row['municipalityName'] ?? ''));
    $hasExactCoordinates = is_numeric($row['latitude'] ?? null)
        && is_numeric($row['longitude'] ?? null)
        && (float) $row['latitude'] >= -90
        && (float) $row['latitude'] <= 90
        && (float) $row['longitude'] >= -180
        && (float) $row['longitude'] <= 180;
    $coords = $hasExactCoordinates
        ? [(float) $row['latitude'], (float) $row['longitude']]
        : find_municipality_coordinates($municipality);

    $imageName = basename((string) ($row['image'] ?? ''));
    $hasImage = $imageName !== '' && $imageName !== 'default.jpg';

    $properties[] = [
        'id'            => (int) $row['propertyID'],
        'title'         => (string) $row['title'],
        'description'   => mb_strimwidth((string) $row['description'], 0, 120, '…'),
        'location'      => property_location_label($row),
        'municipality'  => $municipality,
        'barangay'      => (string) ($row['barangay'] ?? ''),
        'type'          => (string) $row['propertyType'],
        'price'         => (float) $row['price'],
        'pricePerSqm'   => (float) $row['pricePerSqm'],
        'areaSqm'       => $row['areaSqm'] !== null ? (float) $row['areaSqm'] : null,
        'characteristics' => (string) ($row['landCharacteristics'] ?? ''),
        'image'         => $hasImage ? 'assets/images/' . rawurlencode($imageName) : null,
        'link'          => 'property_detail.php?id=' . (int) $row['propertyID'],
        'lat'           => $coords ? (float) $coords[0] : null,
        'lng'           => $coords ? (float) $coords[1] : null,
        'locationPrecision' => $hasExactCoordinates ? 'property' : ($coords ? 'municipality' : null),
        'status'        => (string) $row['status'],
    ];
}

// Get location detail if filtering by a specific one
$locationInfo = null;
if ($locationId > 0) {
    $locations = get_property_locations($conn);
    $loc = find_property_location($locations, $locationId);
    if ($loc) {
        $municipality = $loc['municipalityName'];
        $coords = find_municipality_coordinates($municipality);
        $locationInfo = [
            'id'           => (int) $loc['locationID'],
            'municipality' => $municipality,
            'province'     => $loc['provinceName'],
            'lat'          => $coords ? (float) $coords[0] : null,
            'lng'          => $coords ? (float) $coords[1] : null,
        ];
    }
}

echo json_encode([
    'success'    => true,
    'count'      => count($properties),
    'properties' => $properties,
    'location'   => $locationInfo,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
