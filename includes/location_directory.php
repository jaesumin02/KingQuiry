<?php
function get_property_locations(mysqli $conn, bool $includeInactive = false): array
{
    $activeCondition = $includeInactive ? '' : 'WHERE isActive = 1';
    $result = $conn->query(
        "SELECT locationID, regionCode, regionName, provinceName, municipalityName, isActive
         FROM property_locations
         $activeCondition
         ORDER BY regionName, provinceName, municipalityName"
    );

    return $result->fetch_all(MYSQLI_ASSOC);
}

function property_location_label(array $property): string
{
    if (!empty($property['municipalityName'])) {
        return $property['municipalityName'] . ', ' . $property['provinceName'];
    }

    return (string) ($property['location'] ?? '');
}

function find_property_location(array $locations, int $locationId): ?array
{
    foreach ($locations as $location) {
        if ((int) $location['locationID'] === $locationId) {
            return $location;
        }
    }

    return null;
}

function get_batangas_municipality_coordinates(): array
{
    return [
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
}

function find_municipality_center_coordinates(string $municipalityName): array
{
    $coords = get_batangas_municipality_coordinates();
    if (isset($coords[$municipalityName])) {
        return $coords[$municipalityName];
    }
    foreach ($coords as $name => $pair) {
        if (strcasecmp($name, $municipalityName) === 0 || stripos($municipalityName, $name) !== false) {
            return $pair;
        }
    }
    return [13.7565, 121.0583];
}

function property_location_options(array $locations, ?int $selectedLocationId = null): string
{
    $html = '<option value="">Choose a municipality or city</option>';
    $coords = get_batangas_municipality_coordinates();
    foreach ($locations as $location) {
        $locationId = (int) $location['locationID'];
        $mName = (string) $location['municipalityName'];
        $label = $mName . ', ' . $location['provinceName'];
        $selected = $selectedLocationId === $locationId ? ' selected' : '';
        $lat = $coords[$mName][0] ?? 13.7565;
        $lng = $coords[$mName][1] ?? 121.0583;
        $html .= '<option value="' . $locationId . '" data-municipality="' . htmlspecialchars($mName, ENT_QUOTES, 'UTF-8') . '" data-lat="' . $lat . '" data-lng="' . $lng . '"' . $selected . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</option>';
    }
    return $html;
}
