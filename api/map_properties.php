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

$conditions = ["p.status = 'Available'"];
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

// Municipality center coordinates for the map
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

// Build property data with coordinates
$properties = [];
foreach ($rows as $row) {
    $municipality = trim((string) ($row['municipalityName'] ?? ''));
    $coords = $municipalityCoordinates[$municipality] ?? null;

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
        $coords = $municipalityCoordinates[$municipality] ?? [13.7565, 121.0583];
        $locationInfo = [
            'id'           => (int) $loc['locationID'],
            'municipality' => $municipality,
            'province'     => $loc['provinceName'],
            'lat'          => (float) $coords[0],
            'lng'          => (float) $coords[1],
        ];
    }
}

echo json_encode([
    'success'    => true,
    'count'      => count($properties),
    'properties' => $properties,
    'location'   => $locationInfo,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
