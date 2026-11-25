<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/admin_analytics.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

apply_secure_headers();
require_role('Admin');

$requestedYear = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT);
$requestedYear = is_int($requestedYear) ? $requestedYear : null;
$metrics = get_admin_summary_metrics($conn);
$reports = get_admin_report_data($conn, $requestedYear);
$invalidYearFilter = isset($_GET['year'])
    && ($requestedYear === null || $requestedYear !== $reports['selected_year']);
$propertyCategoryMaximum = max(1, ...array_values($reports['property_category_counts']));
$appointmentStatusMaximum = max(1, ...array_values($reports['appointment_status_counts']));

$chartLeft = 88;
$chartRight = 988;
$chartTop = 52;
$chartBottom = 350;
$chartWidth = $chartRight - $chartLeft;
$chartHeight = $chartBottom - $chartTop;
$monthlyRevenuePoints = [];
$monthlyRevenuePath = '';
$monthlyRevenueAreaPath = '';
foreach (array_values($reports['monthly_sales']) as $index => $month) {
    if ($month['revenue'] === null) {
        continue;
    }

    $x = $chartLeft + ($index * $chartWidth / 11);
    $y = $chartBottom - (((float) $month['revenue'] / $reports['monthly_revenue_maximum']) * $chartHeight);
    $monthlyRevenuePoints[] = [
        'x' => $x,
        'y' => $y,
        'label' => $month['label'],
        'revenue' => (float) $month['revenue'],
        'sales' => (int) $month['sales'],
    ];
}
if ($monthlyRevenuePoints !== []) {
    $pathCommands = array_map(
        static fn(array $point, int $index): string => ($index === 0 ? 'M ' : 'L ')
            . number_format($point['x'], 2, '.', '') . ' '
            . number_format($point['y'], 2, '.', ''),
        $monthlyRevenuePoints,
        array_keys($monthlyRevenuePoints)
    );
    $monthlyRevenuePath = implode(' ', $pathCommands);
    $firstPoint = $monthlyRevenuePoints[0];
    $lastPoint = $monthlyRevenuePoints[count($monthlyRevenuePoints) - 1];
    $monthlyRevenueAreaPath = $monthlyRevenuePath
        . ' L ' . number_format($lastPoint['x'], 2, '.', '') . ' ' . $chartBottom
        . ' L ' . number_format($firstPoint['x'], 2, '.', '') . ' ' . $chartBottom
        . ' Z';
}

include 'includes/header.php';
?>

<section class="mb-4" aria-label="Report totals">
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow">Available listings</div><h2 class="h3 mb-0"><?= number_format($metrics['available_listings']) ?></h2><small class="text-muted">Not deleted</small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow text-warning">Pending viewing requests</div><h2 class="h3 mb-0 text-warning"><?= number_format($metrics['pending_viewing_requests']) ?></h2><small class="text-muted">Awaiting review</small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow text-info">Inquiries (30 days)</div><h2 class="h3 mb-0 text-info"><?= number_format($metrics['recent_inquiries']) ?></h2><small class="text-muted">All client inquiries</small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow text-primary">Active pipeline</div><h2 class="h3 mb-0 text-primary"><?= number_format($metrics['active_pipeline']) ?></h2><small class="text-muted">Excludes completed/cancelled</small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow text-success">Completed sales</div><h2 class="h3 mb-0 text-success"><?= number_format($metrics['completed_sales']) ?></h2><small class="text-muted">All completed transactions</small></div></div></div>
        <div class="col-6 col-lg-4 col-xl-2"><div class="card h-100"><div class="card-body p-3"><div class="page-eyebrow text-success">Actual revenue</div><h2 class="h3 mb-0 text-success"><?= htmlspecialchars(kq_format_compact_peso($metrics['actual_revenue']), ENT_QUOTES, 'UTF-8') ?></h2><small class="text-muted">Recorded sale prices</small></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <section class="card p-3 p-lg-4" id="monthly-sales-chart" aria-labelledby="monthly-sales-title">
                <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
                    <div>
                        <div class="page-eyebrow">Sales revenue report</div>
                        <h2 class="h4 fw-bold mb-1" id="monthly-sales-title">Actual sales revenue by month — <?= (int) $reports['selected_year'] ?></h2>
                        <p class="small text-muted mb-0">Completed transactions with a recorded sale price, grouped by completion month. <?= $reports['is_future_year'] ? 'Future years show actuals only when sales are completed; no projections are included.' : 'Data through ' . htmlspecialchars($reports['as_of_label'], ENT_QUOTES, 'UTF-8') . '.' ?></p>
                    </div>
                    <div class="d-flex flex-wrap align-items-end gap-3">
                        <form method="get" action="admin_reports.php#monthly-sales-chart" class="d-flex align-items-end gap-2">
                            <div>
                                <label class="form-label small fw-semibold mb-1" for="sales-report-year">Report year</label>
                                <select id="sales-report-year" name="year" class="form-select form-select-sm">
                                    <?php foreach ($reports['available_years'] as $year): ?>
                                        <option value="<?= (int) $year ?>" <?= (int) $reports['selected_year'] === (int) $year ? 'selected' : '' ?>><?= (int) $year ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary">Show report</button>
                        </form>
                        <div class="small text-muted"><span class="d-inline-block rounded-pill me-2" style="width: 1.4rem; height: .2rem; background: #167a9c; vertical-align: middle;"></span>Actual sale revenue (PHP)</div>
                    </div>
                </div>
                <?php if ($invalidYearFilter): ?>
                    <p class="small text-warning-emphasis mb-2" role="status">That year is not available. Showing <?= (int) $reports['selected_year'] ?> instead.</p>
                <?php endif; ?>
                <div class="overflow-auto">
                    <svg class="w-100" style="min-width: 760px;" viewBox="0 0 1020 420" role="img" aria-labelledby="monthly-sales-title monthly-sales-chart-description">
                        <desc id="monthly-sales-chart-description">Labeled line chart of actual completed-sale revenue from January through December <?= (int) $reports['selected_year'] ?>.</desc>
                        <defs>
                            <linearGradient id="revenue-area-gradient" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#167a9c" stop-opacity=".22"/>
                                <stop offset="100%" stop-color="#167a9c" stop-opacity=".01"/>
                            </linearGradient>
                        </defs>
                        <?php for ($gridLine = 0; $gridLine <= 4; $gridLine++):
                            $gridY = $chartBottom - ($gridLine * ($chartHeight / 4));
                            $gridValue = $reports['monthly_revenue_maximum'] * $gridLine / 4;
                        ?>
                            <line class="kq-chart-grid" x1="<?= $chartLeft ?>" y1="<?= number_format($gridY, 2, '.', '') ?>" x2="<?= $chartRight ?>" y2="<?= number_format($gridY, 2, '.', '') ?>" stroke-width="1"/>
                            <text class="kq-chart-text" x="<?= $chartLeft - 10 ?>" y="<?= number_format($gridY + 4, 2, '.', '') ?>" text-anchor="end" font-size="12"><?= htmlspecialchars(kq_format_compact_peso($gridValue), ENT_QUOTES, 'UTF-8') ?></text>
                        <?php endfor; ?>
                        <line class="kq-chart-axis" x1="<?= $chartLeft ?>" y1="<?= $chartTop ?>" x2="<?= $chartLeft ?>" y2="<?= $chartBottom ?>" stroke-width="1"/>
                        <line class="kq-chart-axis" x1="<?= $chartLeft ?>" y1="<?= $chartBottom ?>" x2="<?= $chartRight ?>" y2="<?= $chartBottom ?>" stroke-width="1"/>
                        <?php if ($reports['has_monthly_sales']): ?>
                            <path d="<?= htmlspecialchars($monthlyRevenueAreaPath, ENT_QUOTES, 'UTF-8') ?>" fill="url(#revenue-area-gradient)"/>
                            <path d="<?= htmlspecialchars($monthlyRevenuePath, ENT_QUOTES, 'UTF-8') ?>" fill="none" stroke="#167a9c" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        <?php endif; ?>
                        <?php foreach (array_values($reports['monthly_sales']) as $index => $month):
                            $monthX = $chartLeft + ($index * $chartWidth / 11);
                        ?>
                            <text class="kq-chart-text" x="<?= number_format($monthX, 2, '.', '') ?>" y="382" text-anchor="middle" font-size="12"><?= htmlspecialchars($month['label'], ENT_QUOTES, 'UTF-8') ?></text>
                        <?php endforeach; ?>
                        <?php if ($reports['has_monthly_sales']): ?>
                            <?php foreach ($monthlyRevenuePoints as $point): ?>
                                <circle cx="<?= number_format($point['x'], 2, '.', '') ?>" cy="<?= number_format($point['y'], 2, '.', '') ?>" r="5" fill="#fff" stroke="#167a9c" stroke-width="3">
                                    <title><?= htmlspecialchars($point['label'] . ': ' . kq_format_compact_peso($point['revenue']) . ' (' . number_format($point['sales']) . ' sale(s) with price recorded)', ENT_QUOTES, 'UTF-8') ?></title>
                                </circle>
                                <?php if ($point['revenue'] > 0): ?>
                                    <text x="<?= number_format($point['x'], 2, '.', '') ?>" y="<?= number_format($point['y'] - 12, 2, '.', '') ?>" text-anchor="middle" fill="#126481" font-size="11" font-weight="700"><?= htmlspecialchars(kq_format_compact_peso($point['revenue']), ENT_QUOTES, 'UTF-8') ?></text>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </svg>
                </div>
                <div class="d-flex flex-wrap justify-content-between gap-2 mt-2 small text-muted">
                    <span><?= $reports['is_future_year'] ? 'Future year' : ($reports['is_current_year'] ? 'Year-to-date' : 'Full year') ?>: <strong class="text-body"><?= htmlspecialchars(kq_format_compact_peso($reports['year_to_date_revenue']), ENT_QUOTES, 'UTF-8') ?></strong> across <?= number_format($reports['year_to_date_sales']) ?> sale(s) with a recorded price.</span>
                    <?php if ($reports['is_current_year']): ?><span>Months after the current month are intentionally left blank.</span><?php endif; ?>
                </div>
                <?php if (!$reports['has_monthly_sales']): ?>
                    <p class="small text-muted mb-0 mt-3" role="status"><?= $reports['is_future_year'] ? 'This future year has no sales data yet. The chart will populate as sales are completed.' : 'No completed sales with a recorded sale price were recorded in ' . (int) $reports['selected_year'] . '.' ?></p>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <section class="card p-3 p-lg-4 h-100" aria-labelledby="property-category-chart-title">
                <h2 class="h5 fw-bold mb-1" id="property-category-chart-title">Listings by property type</h2>
                <p class="small text-muted mb-3"><?= number_format((int) $reports['property_category_total']) ?> current listing(s); deleted listings excluded.</p>
                <?php if ($reports['property_category_total'] === 0): ?>
                    <p class="small text-muted mb-0 py-3">Category data will appear when listings are added.</p>
                <?php else: ?>
                    <div class="d-grid gap-3" role="list" aria-label="Current property listings by category">
                        <?php foreach ($reports['property_category_counts'] as $category => $count):
                            $percentage = (int) round(((int) $count / $propertyCategoryMaximum) * 100);
                            $barClass = [
                                'Residential' => 'bg-primary',
                                'Commercial' => 'bg-success',
                                'Farmlot' => 'bg-warning',
                                'Beachlot' => 'bg-info',
                                'Condo' => 'bg-purple text-white',
                                'House and Lot' => 'bg-primary',
                                'Townhouse' => 'bg-info',
                                'Apartment' => 'bg-purple text-white',
                                'Warehouse' => 'bg-warning',
                                'Office Space' => 'bg-success',
                                'Resort' => 'bg-info',
                                'Other / legacy' => 'bg-secondary',
                            ][$category] ?? 'bg-secondary';
                        ?>
                            <div role="listitem">
                                <div class="d-flex justify-content-between gap-3 small mb-1"><span><?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format((int) $count) ?></strong></div>
                                <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars((string) $category, ENT_QUOTES, 'UTF-8') ?> listings" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100" style="height: .65rem;"><div class="progress-bar <?= $barClass ?>" style="width: <?= $percentage ?>%"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="card p-3 p-lg-4 h-100" aria-labelledby="appointment-status-chart-title">
                <h2 class="h5 fw-bold mb-1" id="appointment-status-chart-title">Viewing request status</h2>
                <p class="small text-muted mb-3"><?= number_format((int) $reports['appointment_status_total']) ?> all-time request(s)</p>
                <?php if ($reports['appointment_status_total'] === 0): ?>
                    <p class="small text-muted mb-0 py-3">Request status data will appear when a client books a viewing.</p>
                <?php else: ?>
                    <div class="d-grid gap-3" role="list" aria-label="All viewing requests by status">
                        <?php foreach ($reports['appointment_status_counts'] as $status => $count):
                            $percentage = (int) round(((int) $count / $appointmentStatusMaximum) * 100);
                            $barClass = ['Pending' => 'bg-warning', 'Approved' => 'bg-success', 'Cancelled' => 'bg-secondary'][$status] ?? 'bg-primary';
                        ?>
                            <div role="listitem">
                                <div class="d-flex justify-content-between gap-3 small mb-1"><span><?= htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') ?></span><strong><?= number_format((int) $count) ?></strong></div>
                                <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars((string) $status, ENT_QUOTES, 'UTF-8') ?> viewing requests" aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100" style="height: .65rem;"><div class="progress-bar <?= $barClass ?>" style="width: <?= $percentage ?>%"></div></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <section class="card p-3 p-lg-4 h-100" aria-labelledby="top-sales-title">
                <h2 class="h5 fw-bold mb-1" id="top-sales-title">Top properties by actual sale revenue</h2>
                <p class="small text-muted mb-3">Only completed transactions with a recorded sale price are included.</p>
                <?php if ($reports['top_sales_properties'] === []): ?>
                    <p class="small text-muted mb-0">No completed sales with a recorded price are available yet.</p>
                <?php else: ?>
                    <ol class="list-unstyled d-grid gap-3 mb-0">
                        <?php foreach ($reports['top_sales_properties'] as $rank => $propertySale):
                            $revenue = (float) $propertySale['revenue'];
                            $barPercent = (int) round(($revenue / $reports['top_sales_maximum']) * 100);
                        ?>
                            <li>
                                <div class="d-flex justify-content-between align-items-start gap-3 small mb-1">
                                    <span class="fw-semibold text-break"><?= ($rank + 1) ?>. <?= htmlspecialchars($propertySale['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-end text-nowrap"><?= htmlspecialchars(kq_format_compact_peso($revenue), ENT_QUOTES, 'UTF-8') ?><span class="d-block text-muted"><?= number_format((int) $propertySale['sales']) ?> sale(s) with price recorded</span></span>
                                </div>
                                <div class="progress" role="progressbar" aria-label="<?= htmlspecialchars($propertySale['title'], ENT_QUOTES, 'UTF-8') ?> actual sale revenue" aria-valuenow="<?= $barPercent ?>" aria-valuemin="0" aria-valuemax="100" style="height: .55rem;"><div class="progress-bar bg-success" style="width: <?= $barPercent ?>%"></div></div>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card p-3 p-lg-4 h-100" aria-labelledby="municipal-activity-title">
                <h2 class="h5 fw-bold mb-1" id="municipal-activity-title">Listing activity by municipality</h2>
                <p class="small text-muted mb-3">Compare inquiries from the last 30 days with currently available listings.</p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr class="table-light"><th>Municipality</th><th class="text-center">Inquiries (30d)</th><th class="text-center">Available listings</th></tr></thead>
                        <tbody>
                            <?php if ($reports['demand_prescription'] !== []): ?>
                                <?php foreach ($reports['demand_prescription'] as $municipality): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= htmlspecialchars($municipality['municipalityName'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="text-center"><?= number_format((int) $municipality['totalInquiries']) ?></td>
                                        <td class="text-center"><?= number_format((int) $municipality['availableListings']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No current listing activity to report.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
