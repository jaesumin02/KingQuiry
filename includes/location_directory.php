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

function property_location_options(array $locations, ?int $selectedLocationId = null): string
{
    $html = '<option value="">Choose a municipality or city</option>';
    foreach ($locations as $location) {
        $locationId = (int) $location['locationID'];
        $label = $location['municipalityName'] . ', ' . $location['provinceName'];
        $selected = $selectedLocationId === $locationId ? ' selected' : '';
        $html .= '<option value="' . $locationId . '"' . $selected . '>'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '</option>';
    }
    return $html;
}
