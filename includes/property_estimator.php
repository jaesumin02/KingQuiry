<?php
function estimate_property_value(mysqli $conn, float $area, string $propertyType, int $locationId): array
{
    $allowedTypes = ['Residential', 'Commercial', 'Farmlot', 'Beachlot'];

    if ($area <= 0 || $area > 1000000 || !in_array($propertyType, $allowedTypes, true) || $locationId <= 0) {
        throw new InvalidArgumentException('Enter a valid land area, category, and location.');
    }

    $locationStmt = $conn->prepare("SELECT municipalityName, provinceName FROM property_locations WHERE locationID = ? AND isActive = 1");
    $locationStmt->bind_param('i', $locationId);
    $locationStmt->execute();
    $selectedLocation = $locationStmt->get_result()->fetch_assoc();
    if (!$selectedLocation) {
        throw new InvalidArgumentException('Choose a valid location from the list.');
    }

    $result = $conn->query(
        "SELECT p.pricePerSqm, p.propertyType, p.locationID
         FROM properties p
         WHERE p.pricePerSqm > 0 AND p.status = 'Available'"
    );
    $totalWeight = 0.0;
    $comparableCount = 0;
    $exactMatchCount = 0;
    $comparables = [];

    while ($property = $result->fetch_assoc()) {
        $sameType = $property['propertyType'] === $propertyType;
        $sameLocation = $property['locationID'] !== null && (int) $property['locationID'] === $locationId;
        $weight = $sameType && $sameLocation ? 4.0 : ($sameType ? 2.0 : ($sameLocation ? 1.0 : 0.25));
        $pricePerSqm = (float) $property['pricePerSqm'];

        if ($pricePerSqm <= 0) {
            continue;
        }

        $comparables[] = ['rate' => $pricePerSqm, 'weight' => $weight];
        $totalWeight += $weight;
        $comparableCount++;

        if ($sameType && $sameLocation) {
            $exactMatchCount++;
        }
    }

    if ($totalWeight === 0.0) {
        throw new RuntimeException('No property price data is available yet. Add listings with a price per square meter first.');
    }

    usort($comparables, static fn(array $left, array $right): int => $left['rate'] <=> $right['rate']);
    $cumulativeWeight = 0.0;
    $pricePerSqm = 0.0;
    foreach ($comparables as $comparable) {
        $cumulativeWeight += $comparable['weight'];
        if ($cumulativeWeight >= $totalWeight / 2) {
            $pricePerSqm = $comparable['rate'];
            break;
        }
    }
    $estimatedValue = $pricePerSqm * $area;

    return [
        'price_per_sqm' => $pricePerSqm,
        'estimated_value' => $estimatedValue,
        'low_estimate' => $estimatedValue * 0.9,
        'high_estimate' => $estimatedValue * 1.1,
        'location_name' => $selectedLocation['municipalityName'] . ', ' . $selectedLocation['provinceName'],
        'comparable_count' => $comparableCount,
        'exact_match_count' => $exactMatchCount,
        'confidence' => $exactMatchCount >= 3 ? 'High' : ($exactMatchCount > 0 ? 'Medium' : 'Low'),
    ];
}
