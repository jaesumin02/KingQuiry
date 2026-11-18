<?php
/**
 * ALGORITHM: Weighted Property Similarity Ranking
 * Rank up to 50 available listings using 40% price proximity, 25% exact category,
 * 20% exact municipality, and 15% area proximity; return the highest-scoring results.
 * The score is deterministic and rule-based, not a trained machine-learning model.
 * Without a client/search profile, return newest listings without a match score.
 */

if (!function_exists('record_user_search_preference')) {
    function record_user_search_preference(string $category = '', int $locationId = 0, float $price = 0.0): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            require_once __DIR__ . '/auth.php';
            kq_start_session();
        }

        if (!isset($_SESSION['kq_search_history'])) {
            $_SESSION['kq_search_history'] = [];
        }

        if ($category !== '' || $locationId > 0 || $price > 0.0) {
            $_SESSION['kq_search_history'][] = [
                'category' => $category,
                'locationId' => $locationId,
                'price' => $price,
                'timestamp' => time()
            ];

            // Keep only trailing 20 search activities
            if (count($_SESSION['kq_search_history']) > 20) {
                array_shift($_SESSION['kq_search_history']);
            }
        }
    }
}

if (!function_exists('kq_property_similarity_score')) {
    function kq_property_similarity_score(
        float $price,
        float $referencePrice,
        float $area,
        float $referenceArea,
        bool $categoryMatch,
        bool $locationMatch
    ): float {
        $priceProximity = 1.0 - (abs($price - $referencePrice) / max($price, $referencePrice, 1.0));
        $areaProximity = 1.0 - (abs($area - $referenceArea) / max($area, $referenceArea, 1.0));

        return (0.40 * max(0.0, $priceProximity))
            + (0.25 * (int) $categoryMatch)
            + (0.20 * (int) $locationMatch)
            + (0.15 * max(0.0, $areaProximity));
    }
}

if (!function_exists('kq_property_match_percentage')) {
    function kq_property_match_percentage(float $score): int
    {
        return (int) round(min(1.0, max(0.0, $score)) * 100);
    }
}

if (!function_exists('get_personalized_recommendations')) {
    function get_personalized_recommendations(mysqli $conn, ?int $clientID = null, int $excludePropertyID = 0, int $limit = 4): array
    {
        $targetPrice = null;
        $targetArea = null;
        $preferredCategory = '';
        $preferredLocationId = 0;
        $reasonHint = '';

        // 1. Extract Profile from Database if Client is logged in
        if ($clientID !== null && $clientID > 0) {
            $stmt = $conn->prepare("
                SELECT AVG(p.price) AS avgPrice,
                       AVG(p.areaSqm) AS avgArea,
                       p.propertyType,
                       p.locationID,
                       COUNT(*) AS interactionCount
                FROM (
                    SELECT propertyID FROM feedbackinquiries WHERE clientID = ?
                    UNION ALL
                    SELECT propertyID FROM appointments WHERE clientID = ?
                ) user_actions
                JOIN properties p ON p.propertyID = user_actions.propertyID
                GROUP BY p.propertyType, p.locationID
                ORDER BY interactionCount DESC
                LIMIT 1
            ");
            $stmt->bind_param('ii', $clientID, $clientID);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            if ($res && !empty($res['avgPrice'])) {
                $targetPrice = (float) $res['avgPrice'];
                $targetArea = (float) ($res['avgArea'] ?? 500.0);
                $preferredCategory = (string) $res['propertyType'];
                $preferredLocationId = (int) $res['locationID'];
                $reasonHint = "Based on your inquiry and viewing history";
            }
        }

        // 2. If no DB history, extract from session search memory
        if ($targetPrice === null && !empty($_SESSION['kq_search_history'])) {
            $recent = end($_SESSION['kq_search_history']);
            if (!empty($recent['category'])) {
                $preferredCategory = (string) $recent['category'];
            }
            if (!empty($recent['locationId'])) {
                $preferredLocationId = (int) $recent['locationId'];
            }
            if (!empty($recent['price']) && (float) $recent['price'] > 0) {
                $targetPrice = (float) $recent['price'];
            }
            $reasonHint = "Based on your recent property searches";
        }

        // 3. Query Active Candidate Properties
        $excludeSql = $excludePropertyID > 0 ? "AND p.propertyID <> $excludePropertyID" : "";
        $candidates = $conn->query("
            SELECT p.propertyID, p.title, p.price, p.pricePerSqm, p.areaSqm, p.propertyType, 
                   p.locationID, p.location, p.barangay, p.image,
                   l.municipalityName, l.provinceName
            FROM properties p
            LEFT JOIN property_locations l ON l.locationID = p.locationID
            WHERE p.status = 'Available'
              AND p.isDeleted = 0
              AND LEFT(p.title, 8) <> '[SAMPLE]'
              $excludeSql
            ORDER BY p.propertyID DESC
            LIMIT 50
        ")->fetch_all(MYSQLI_ASSOC);

        if ($candidates === []) {
            return [];
        }

        // If completely cold start (no profile available), return newest popular listings
        if ($targetPrice === null && $preferredCategory === '' && $preferredLocationId === 0) {
            $defaultSample = array_slice($candidates, 0, $limit);
            foreach ($defaultSample as &$item) {
                $item['match_percentage'] = null;
                $item['match_reason'] = 'Recently added property';
            }
            unset($item);
            return $defaultSample;
        }

        // Default assumptions if partially filled
        $refPrice = $targetPrice ?? 2500000.0;
        $refArea = $targetArea ?? 450.0;

        // 4. Multi-Attribute Vector Scoring
        foreach ($candidates as &$prop) {
            $price = (float) $prop['price'];
            $area = (float) ($prop['areaSqm'] ?? 500.0);

            $categoryMatch = (!empty($preferredCategory) && $prop['propertyType'] === $preferredCategory) ? 1.0 : 0.0;
            $locationMatch = ($preferredLocationId > 0 && (int)$prop['locationID'] === $preferredLocationId) ? 1.0 : 0.0;

            $score = kq_property_similarity_score(
                $price,
                $refPrice,
                $area,
                $refArea,
                $categoryMatch > 0,
                $locationMatch > 0
            );
            $matchPct = kq_property_match_percentage($score);
            $prop['similarity_score'] = $score;
            $prop['match_percentage'] = $matchPct;

            $reasons = [];
            if ($locationMatch > 0) $reasons[] = 'same municipality';
            if ($categoryMatch > 0) $reasons[] = 'same category';
            $priceProximity = 1.0 - (abs($price - $refPrice) / max($price, $refPrice, 1.0));
            if ($priceProximity > 0.8) $reasons[] = 'similar price range';

            $prop['match_reason'] = $reasons !== []
                ? 'Suggested for ' . implode(', ', $reasons)
                : ($reasonHint ?: 'Suggested based on property details');
        }
        unset($prop);

        // 5. Rank descending by similarity score
        usort($candidates, static fn(array $a, array $b): int => $b['similarity_score'] <=> $a['similarity_score']);

        return array_slice($candidates, 0, $limit);
    }
}
