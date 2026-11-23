<?php
require_once __DIR__ . '/property_categories.php';

/**
 * ALGORITHM: Batch Gradient Descent Linear Regression
 * Normalize area and asking-price values, encode category/location as one-hot features,
 * then train the weights for 2,200 full-batch passes at a 0.03 learning rate.
 * If regression fails, estimate from the weighted median comparable price per sq.m.
 */

function build_regression_feature_vector(
    float $area,
    string $propertyType,
    int $locationId,
    array $typeOrder,
    array $locationOrder,
    float $areaMean,
    float $areaStd
): array {
    $areaNorm = $areaStd > 0.0 ? (($area - $areaMean) / $areaStd) : 0.0;
    $features = [1.0, $areaNorm];

    foreach ($typeOrder as $typeName) {
        $features[] = $propertyType === $typeName ? 1.0 : 0.0;
    }

    foreach ($locationOrder as $locationValue) {
        $features[] = $locationId === (int) $locationValue ? 1.0 : 0.0;
    }

    return $features;
}

function train_property_price_regression(array $records): array
{
    if (count($records) < 3) {
        throw new RuntimeException('Not enough property data for regression training.');
    }

    $typeOrder = array_values(array_unique(array_map(static fn(array $record): string => (string) $record['propertyType'], $records)));
    sort($typeOrder, SORT_STRING);

    $locationOrder = array_values(array_unique(array_map(static fn(array $record): int => (int) $record['locationID'], $records)));
    sort($locationOrder, SORT_NUMERIC);

    $areaValues = array_map(static fn(array $record): float => (float) $record['areaSqm'], $records);
    $priceValues = array_map(static fn(array $record): float => (float) $record['price'], $records);

    $areaMean = array_sum($areaValues) / count($areaValues);
    $areaVariance = 0.0;
    foreach ($areaValues as $areaValue) {
        $areaVariance += ($areaValue - $areaMean) ** 2;
    }
    $areaStd = $areaVariance > 0.0 ? sqrt($areaVariance / count($areaValues)) : 1.0;

    $priceMean = array_sum($priceValues) / count($priceValues);
    $priceVariance = 0.0;
    foreach ($priceValues as $priceValue) {
        $priceVariance += ($priceValue - $priceMean) ** 2;
    }
    $priceStd = $priceVariance > 0.0 ? sqrt($priceVariance / count($priceValues)) : 1.0;

    $featureCount = 2 + count($typeOrder) + count($locationOrder);
    $weights = array_fill(0, $featureCount, 0.0);
    $bias = 0.0;
    $learningRate = 0.03;
    $iterations = 2200;

    for ($iteration = 0; $iteration < $iterations; $iteration++) {
        $gradientBias = 0.0;
        $gradient = array_fill(0, $featureCount, 0.0);

        foreach ($records as $record) {
            $featureVector = build_regression_feature_vector(
                (float) $record['areaSqm'],
                (string) $record['propertyType'],
                (int) $record['locationID'],
                $typeOrder,
                $locationOrder,
                $areaMean,
                $areaStd
            );

            $target = ($record['price'] - $priceMean) / $priceStd;
            $prediction = $bias;
            foreach ($featureVector as $index => $value) {
                $prediction += $weights[$index] * $value;
            }

            $error = $prediction - $target;
            $gradientBias += $error / count($records);
            foreach ($featureVector as $index => $value) {
                $gradient[$index] += ($error * $value) / count($records);
            }
        }

        $bias -= $learningRate * $gradientBias;
        foreach ($weights as $index => $currentWeight) {
            $weights[$index] -= $learningRate * $gradient[$index];
        }
    }

    return [
        'weights' => $weights,
        'bias' => $bias,
        'areaMean' => $areaMean,
        'areaStd' => $areaStd,
        'priceMean' => $priceMean,
        'priceStd' => $priceStd,
        'typeOrder' => $typeOrder,
        'locationOrder' => $locationOrder,
    ];
}

function fallback_weighted_median_estimate(array $comparables, float $area): array
{
    $totalWeight = 0.0;
    $weightedRates = [];

    foreach ($comparables as $comparable) {
        $weight = (float) $comparable['weight'];
        $totalWeight += $weight;
        $weightedRates[] = ['rate' => (float) $comparable['rate'], 'weight' => $weight];
    }

    if ($totalWeight <= 0.0) {
        throw new RuntimeException('No comparable property rates are available.');
    }

    usort($weightedRates, static fn(array $left, array $right): int => $left['rate'] <=> $right['rate']);

    $cumulativeWeight = 0.0;
    $pricePerSqm = 0.0;
    foreach ($weightedRates as $comparable) {
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
    ];
}

function estimate_property_value(
    mysqli $conn,
    float $area,
    string $propertyType,
    int $locationId,
    ?int $excludePropertyId = null
): array
{
    $allowedTypes = property_type_values();

    if (
        $area <= 0
        || $area > 1000000
        || !in_array($propertyType, $allowedTypes, true)
        || $locationId <= 0
        || ($excludePropertyId !== null && $excludePropertyId <= 0)
    ) {
        throw new InvalidArgumentException('Enter a valid land area, category, and location.');
    }

    $locationStmt = $conn->prepare("SELECT municipalityName, provinceName FROM property_locations WHERE locationID = ? AND isActive = 1");
    $locationStmt->bind_param('i', $locationId);
    $locationStmt->execute();
    $selectedLocation = $locationStmt->get_result()->fetch_assoc();
    if (!$selectedLocation) {
        throw new InvalidArgumentException('Choose a valid location from the list.');
    }

    $comparableSql =
        "SELECT p.price, p.areaSqm, p.propertyType, p.locationID
         FROM properties p
         WHERE p.price > 0 AND p.areaSqm > 0 AND p.status = 'Available'
           AND p.isDeleted = 0
           AND LEFT(p.title, 8) <> '[SAMPLE]'";
    if ($excludePropertyId === null) {
        $result = $conn->query($comparableSql);
    } else {
        $comparableStmt = $conn->prepare($comparableSql . ' AND p.propertyID <> ?');
        $comparableStmt->bind_param('i', $excludePropertyId);
        $comparableStmt->execute();
        $result = $comparableStmt->get_result();
    }

    $comparables = [];
    $exactMatchCount = 0;
    $comparableCount = 0;

    while ($property = $result->fetch_assoc()) {
        $propertyTypeName = (string) $property['propertyType'];
        $sameType = $propertyTypeName === $propertyType;
        $sameLocation = ((int) $property['locationID']) === $locationId;
        $weight = $sameType && $sameLocation ? 5.0 : ($sameType ? 2.5 : ($sameLocation ? 1.5 : 0.5));
        $price = (float) $property['price'];
        $areaSqm = (float) $property['areaSqm'];

        if ($price <= 0 || $areaSqm <= 0) {
            continue;
        }

        $comparables[] = [
            'propertyType' => $propertyTypeName,
            'locationID' => (int) $property['locationID'],
            'areaSqm' => $areaSqm,
            'price' => $price,
            'weight' => $weight,
            'rate' => $price / $areaSqm,
        ];
        $comparableCount++;

        if ($sameType && $sameLocation) {
            $exactMatchCount++;
        }
    }

    if ($comparables === []) {
        throw new RuntimeException('No property price data is available yet. Add listings with a price per square meter first.');
    }

    try {
        $model = train_property_price_regression($comparables);
        $typeOrder = $model['typeOrder'];
        $locationOrder = $model['locationOrder'];
        $featureVector = build_regression_feature_vector(
            $area,
            $propertyType,
            $locationId,
            $typeOrder,
            $locationOrder,
            $model['areaMean'],
            $model['areaStd']
        );

        $prediction = $model['bias'];
        foreach ($featureVector as $index => $value) {
            $prediction += $model['weights'][$index] * $value;
        }

        $predictedPrice = round($prediction * $model['priceStd'] + $model['priceMean']);
        $pricePerSqm = round(max(1.0, $predictedPrice / max(1.0, $area)));
        $estimatedValue = $predictedPrice;

        return [
            'price_per_sqm' => (float) $pricePerSqm,
            'estimated_value' => (float) $estimatedValue,
            'low_estimate' => (float) round($estimatedValue * 0.93),
            'high_estimate' => (float) round($estimatedValue * 1.07),
            'location_name' => $selectedLocation['municipalityName'] . ', ' . $selectedLocation['provinceName'],
            'comparable_count' => $comparableCount,
            'exact_match_count' => $exactMatchCount,
            'confidence' => $exactMatchCount >= 3 ? 'High' : ($exactMatchCount > 0 ? 'Medium' : 'Low'),
            'model' => 'Linear regression (batch gradient descent)',
        ];
    } catch (Throwable $exception) {
        $fallback = fallback_weighted_median_estimate($comparables, $area);
        $estVal = round($fallback['estimated_value']);
        return [
            'price_per_sqm' => (float) round($fallback['price_per_sqm']),
            'estimated_value' => (float) $estVal,
            'low_estimate' => (float) round($estVal * 0.93),
            'high_estimate' => (float) round($estVal * 1.07),
            'location_name' => $selectedLocation['municipalityName'] . ', ' . $selectedLocation['provinceName'],
            'comparable_count' => $comparableCount,
            'exact_match_count' => $exactMatchCount,
            'confidence' => $exactMatchCount >= 3 ? 'High' : ($exactMatchCount > 0 ? 'Medium' : 'Low'),
            'model' => 'Weighted median of comparable price per sq.m.',
        ];
    }
}
