<?php
require_once __DIR__ . '/property_categories.php';

function get_admin_summary_metrics(mysqli $conn): array
{
    $result = $conn->query(
        "SELECT
            (SELECT COUNT(*) FROM properties WHERE status = 'Available' AND isDeleted = 0) AS availableListings,
            (SELECT COUNT(*) FROM appointments WHERE status = 'Pending') AS pendingViewingRequests,
            (SELECT COUNT(*) FROM feedbackinquiries WHERE createdAt >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)) AS recentInquiries,
            (SELECT COUNT(*) FROM property_transactions WHERE currentStage NOT IN ('completed', 'cancelled', 'inquiry_received', 'contacting_client')) AS activePipeline,
            (SELECT COUNT(*) FROM property_transactions WHERE currentStage = 'completed') AS completedSales,
            (SELECT COALESCE(SUM(salePrice), 0) FROM property_transactions WHERE currentStage = 'completed' AND salePrice IS NOT NULL) AS actualRevenue"
    );
    $row = $result->fetch_assoc();

    return [
        'available_listings' => (int) $row['availableListings'],
        'pending_viewing_requests' => (int) $row['pendingViewingRequests'],
        'recent_inquiries' => (int) $row['recentInquiries'],
        'active_pipeline' => (int) $row['activePipeline'],
        'completed_sales' => (int) $row['completedSales'],
        'actual_revenue' => (float) $row['actualRevenue'],
    ];
}

function admin_report_year_options(array $recordedYears, int $currentYear): array
{
    $years = [$currentYear];
    foreach ($recordedYears as $recordedYear) {
        $year = (int) $recordedYear;
        if ($year > 0 && !in_array($year, $years, true)) {
            $years[] = $year;
        }
    }
    for ($yearOffset = 1; $yearOffset <= 5; $yearOffset++) {
        $years[] = $currentYear + $yearOffset;
    }
    rsort($years, SORT_NUMERIC);
    return $years;
}

function get_admin_report_data(mysqli $conn, ?int $requestedYear = null): array
{
    $standardCategories = property_type_values();
    $propertyCategoryCounts = array_fill_keys($standardCategories, 0);
    foreach ($conn->query('SELECT propertyType, COUNT(*) AS total FROM properties WHERE isDeleted = 0 GROUP BY propertyType') as $row) {
        $category = trim((string) $row['propertyType']);
        if ($category === '') {
            $category = 'Uncategorized';
        }
        if (!in_array($category, $standardCategories, true)) {
            $category = 'Other / legacy';
        }
        $propertyCategoryCounts[$category] = ($propertyCategoryCounts[$category] ?? 0) + (int) $row['total'];
    }

    $appointmentStatusCounts = [
        'Pending' => 0,
        'Approved' => 0,
        'Cancelled' => 0,
    ];
    foreach ($conn->query('SELECT status, COUNT(*) AS total FROM appointments GROUP BY status') as $row) {
        $status = trim((string) $row['status']);
        if ($status === '') {
            $status = 'Unspecified';
        }
        $appointmentStatusCounts[$status] = ($appointmentStatusCounts[$status] ?? 0) + (int) $row['total'];
    }

    $timeZone = new DateTimeZone('Asia/Manila');
    $asOf = new DateTimeImmutable('now', $timeZone);
    $currentYear = (int) $asOf->format('Y');
    $recordedYears = [];
    $yearResult = $conn->query(
        "SELECT DISTINCT YEAR(completions.completedAt) AS reportYear
         FROM property_transactions t
         JOIN (
             SELECT transactionID, MAX(createdAt) AS completedAt
             FROM property_transaction_events
             WHERE stage = 'completed'
             GROUP BY transactionID
         ) completions ON completions.transactionID = t.transactionID
         WHERE t.currentStage = 'completed' AND t.salePrice IS NOT NULL
         ORDER BY reportYear DESC"
    );
    foreach ($yearResult as $row) {
        $recordedYears[] = (int) $row['reportYear'];
    }
    $availableYears = admin_report_year_options($recordedYears, $currentYear);
    $selectedYear = $requestedYear !== null && in_array($requestedYear, $availableYears, true)
        ? $requestedYear
        : $currentYear;
    $yearStart = $asOf->setDate($selectedYear, 1, 1)->setTime(0, 0);
    $yearEnd = $yearStart->modify('+1 year');
    $isFutureYear = $selectedYear > $currentYear;
    $isCurrentYear = $selectedYear === $currentYear;
    $currentMonthKey = $isCurrentYear ? $asOf->format('Y-m') : $yearEnd->modify('-1 month')->format('Y-m');
    $monthlySales = [];
    for ($monthOffset = 0; $monthOffset < 12; $monthOffset++) {
        $month = $yearStart->modify('+' . $monthOffset . ' months');
        $monthKey = $month->format('Y-m');
        $isFutureMonth = $isCurrentYear && $monthKey > $currentMonthKey;
        $monthlySales[$monthKey] = [
            'label' => $month->format('M'),
            'sales' => $isFutureMonth ? null : 0,
            'revenue' => $isFutureMonth ? null : 0.0,
        ];
    }

    $monthlySalesStmt = $conn->prepare(
        "SELECT DATE_FORMAT(completions.completedAt, '%Y-%m') AS monthKey,
                COUNT(*) AS sales,
                COALESCE(SUM(t.salePrice), 0) AS revenue
         FROM property_transactions t
         JOIN (
             SELECT transactionID, MAX(createdAt) AS completedAt
             FROM property_transaction_events
             WHERE stage = 'completed'
             GROUP BY transactionID
         ) completions ON completions.transactionID = t.transactionID
         WHERE t.currentStage = 'completed'
           AND t.salePrice IS NOT NULL
           AND completions.completedAt >= ?
           AND completions.completedAt < ?
         GROUP BY monthKey"
    );
    $rangeStart = $yearStart->format('Y-m-d H:i:s');
    $rangeEnd = ($isCurrentYear ? $asOf->modify('+1 second') : $yearEnd)->format('Y-m-d H:i:s');
    $monthlySalesStmt->bind_param('ss', $rangeStart, $rangeEnd);
    $monthlySalesStmt->execute();
    foreach ($monthlySalesStmt->get_result() as $row) {
        $monthKey = (string) $row['monthKey'];
        if (isset($monthlySales[$monthKey])) {
            $monthlySales[$monthKey]['sales'] = (int) $row['sales'];
            $monthlySales[$monthKey]['revenue'] = (float) $row['revenue'];
        }
    }

    $recordedMonthlyRevenue = array_values(array_filter(
        array_column($monthlySales, 'revenue'),
        static fn(?float $revenue): bool => $revenue !== null
    ));
    $monthlyRevenueMaximum = max(1.0, ...$recordedMonthlyRevenue) * 1.12;
    $yearToDateRevenue = array_sum($recordedMonthlyRevenue);
    $yearToDateSales = array_sum(array_map(
        static fn(array $month): int => (int) ($month['sales'] ?? 0),
        array_values($monthlySales)
    ));

    $topSalesProperties = $conn->query(
        "SELECT p.title, COUNT(*) AS sales, SUM(t.salePrice) AS revenue
         FROM property_transactions t
         JOIN properties p ON p.propertyID = t.propertyID
         WHERE t.currentStage = 'completed' AND t.salePrice IS NOT NULL
         GROUP BY p.propertyID, p.title
         ORDER BY revenue DESC, sales DESC, p.title ASC
         LIMIT 5"
    )->fetch_all(MYSQLI_ASSOC);

    $demandResult = $conn->query(
        "SELECT l.municipalityName,
                COUNT(DISTINCT i.inquiryID) AS totalInquiries,
                COUNT(DISTINCT p.propertyID) AS availableListings
         FROM property_locations l
         LEFT JOIN properties p ON p.locationID = l.locationID AND p.status = 'Available' AND p.isDeleted = 0
         LEFT JOIN feedbackinquiries i
             ON i.propertyID = p.propertyID
            AND i.createdAt >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)
         WHERE l.isActive = 1
         GROUP BY l.locationID, l.municipalityName
         HAVING totalInquiries > 0 OR availableListings > 0
         ORDER BY totalInquiries DESC, availableListings DESC
         LIMIT 6"
    );

    return [
        'property_category_counts' => $propertyCategoryCounts,
        'property_category_total' => array_sum($propertyCategoryCounts),
        'appointment_status_counts' => $appointmentStatusCounts,
        'appointment_status_total' => array_sum($appointmentStatusCounts),
        'monthly_sales' => $monthlySales,
        'monthly_revenue_maximum' => $monthlyRevenueMaximum,
        'year_to_date_revenue' => $yearToDateRevenue,
        'year_to_date_sales' => $yearToDateSales,
        'has_monthly_sales' => $yearToDateSales > 0,
        'current_year' => (string) $currentYear,
        'selected_year' => $selectedYear,
        'available_years' => $availableYears,
        'is_current_year' => $isCurrentYear,
        'is_future_year' => $isFutureYear,
        'as_of_label' => $isFutureYear
            ? 'No data yet'
            : ($isCurrentYear ? $asOf->format('M j, Y') : $yearEnd->modify('-1 day')->format('M j, Y')),
        'top_sales_properties' => $topSalesProperties,
        'top_sales_maximum' => $topSalesProperties === []
            ? 1.0
            : max(1.0, ...array_map(static fn(array $row): float => (float) $row['revenue'], $topSalesProperties)),
        'demand_prescription' => $demandResult->fetch_all(MYSQLI_ASSOC),
    ];
}

function format_admin_compact_peso(float $amount): string
{
    if ($amount >= 1000000) {
        return '₱' . number_format($amount / 1000000, 1) . 'M';
    }
    if ($amount >= 1000) {
        return '₱' . number_format($amount / 1000, 1) . 'K';
    }
    return '₱' . number_format($amount, 0);
}
