<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/admin_management.php';
require_once 'includes/transaction_tracking.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

apply_secure_headers();
require_role('Admin');
ensure_admin_management_schema($conn);

$stageLabels = property_transaction_stages();

$salesSummary = $conn->query(
    "SELECT COUNT(*) AS verifiedSales,
            COALESCE(SUM(salePrice), 0) AS actualRevenue,
            COALESCE(AVG(salePrice), 0) AS averageSale
     FROM property_transactions
     WHERE currentStage = 'completed' AND salePrice IS NOT NULL"
)->fetch_assoc();
$verifiedSalesCount = (int) $salesSummary['verifiedSales'];
$actualRevenue = (float) $salesSummary['actualRevenue'];
$averageSale = (float) $salesSummary['averageSale'];

$pipeline = $conn->query(
    "SELECT COUNT(*) AS activeCases, COALESCE(SUM(p.price), 0) AS askingPriceValue
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     WHERE t.currentStage NOT IN ('completed', 'cancelled')"
)->fetch_assoc();
$activePipelineCount = (int) $pipeline['activeCases'];
$activePipelineAskingValue = (float) $pipeline['askingPriceValue'];

$trackedSummary = $conn->query(
    "SELECT COUNT(*) AS totalTracked,
            COALESCE(SUM(currentStage = 'cancelled'), 0) AS cancelledCases
     FROM property_transactions"
)->fetch_assoc();
$totalTracked = (int) $trackedSummary['totalTracked'];
$cancelledCases = (int) $trackedSummary['cancelledCases'];
$conversionRate = $totalTracked > 0 ? ($verifiedSalesCount / $totalTracked) * 100 : 0.0;

$paymentSummary = $conn->query(
    "SELECT COALESCE(SUM(CASE WHEN status = 'Verified' THEN amount ELSE 0 END), 0) AS verifiedTotal,
            COALESCE(SUM(CASE WHEN status = 'Pending' THEN amount ELSE 0 END), 0) AS pendingTotal
     FROM payments"
)->fetch_assoc();
$verifiedCollected = (float) $paymentSummary['verifiedTotal'];
$pendingCollected = (float) $paymentSummary['pendingTotal'];

$outstandingSummary = $conn->query(
    "SELECT COALESCE(SUM(GREATEST(t.salePrice - COALESCE(pay.paidTotal, 0), 0)), 0) AS outstanding
     FROM property_transactions t
     LEFT JOIN (
        SELECT transactionID, SUM(amount) AS paidTotal
        FROM payments
        WHERE status = 'Verified'
        GROUP BY transactionID
     ) pay ON pay.transactionID = t.transactionID
     WHERE t.currentStage = 'completed' AND t.salePrice IS NOT NULL"
)->fetch_assoc();
$outstandingBalance = (float) $outstandingSummary['outstanding'];

$salesMonthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$salesRangeStart = $salesMonthStart->format('Y-m-d');
$salesRangeEnd = $salesMonthStart->modify('+6 months')->format('Y-m-d');
$monthlySales = [];
for ($monthOffset = 0; $monthOffset < 6; $monthOffset++) {
    $month = $salesMonthStart->modify('+' . $monthOffset . ' months');
    $monthlySales[$month->format('Y-m')] = [
        'label' => $month->format('M Y'),
        'sales' => 0,
        'revenue' => 0.0,
    ];
}
$monthlySalesStmt = $conn->prepare(
    "SELECT DATE_FORMAT(e.createdAt, '%Y-%m') AS monthKey,
            COUNT(DISTINCT t.transactionID) AS sales,
            COALESCE(SUM(t.salePrice), 0) AS revenue
     FROM property_transaction_events e
     JOIN property_transactions t ON t.transactionID = e.transactionID
     WHERE e.stage = 'completed'
       AND t.currentStage = 'completed'
       AND t.salePrice IS NOT NULL
       AND e.createdAt >= ?
       AND e.createdAt < ?
     GROUP BY monthKey"
);
$monthlySalesStmt->bind_param('ss', $salesRangeStart, $salesRangeEnd);
$monthlySalesStmt->execute();
foreach ($monthlySalesStmt->get_result() as $row) {
    $monthKey = (string) $row['monthKey'];
    if (isset($monthlySales[$monthKey])) {
        $monthlySales[$monthKey]['sales'] = (int) $row['sales'];
        $monthlySales[$monthKey]['revenue'] = (float) $row['revenue'];
    }
}
$revenueMaximum = max(1.0, ...array_column($monthlySales, 'revenue'));
$salesBars = [];
$plotLeft = 76;
$plotWidth = 620;
$plotBottom = 218;
$plotHeight = 150;
$slotWidth = $plotWidth / 6;
foreach (array_values($monthlySales) as $index => $month) {
    $barHeight = ($month['revenue'] / $revenueMaximum) * $plotHeight;
    $salesBars[] = [
        'x' => $plotLeft + ($index * $slotWidth) + 14,
        'y' => $plotBottom - $barHeight,
        'width' => $slotWidth - 28,
        'height' => $barHeight,
        'label' => $month['label'],
        'revenue' => $month['revenue'],
        'sales' => $month['sales'],
        'center' => $plotLeft + ($index * $slotWidth) + ($slotWidth / 2),
    ];
}
$hasMonthlySales = array_sum(array_column($monthlySales, 'sales')) > 0;

$stageBreakdown = [];
$stageResult = $conn->query(
    "SELECT currentStage, COUNT(*) AS caseCount
     FROM property_transactions
     GROUP BY currentStage
     ORDER BY caseCount DESC, currentStage ASC"
);
while ($row = $stageResult->fetch_assoc()) {
    $stageBreakdown[] = [
        'stage' => (string) $row['currentStage'],
        'label' => $stageLabels[$row['currentStage']] ?? ucfirst(str_replace('_', ' ', (string) $row['currentStage'])),
        'count' => (int) $row['caseCount'],
    ];
}
$stageMaximum = $stageBreakdown === [] ? 1 : max(1, ...array_column($stageBreakdown, 'count'));

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$stageFilter = is_string($_GET['stage'] ?? null) ? $_GET['stage'] : '';
if (!isset($stageLabels[$stageFilter])) {
    $stageFilter = '';
}

$where = ['1 = 1'];
$bindTypes = '';
$bindValues = [];
if ($search !== '') {
    $where[] = '(u.fullname LIKE ? OR u.email LIKE ? OR p.title LIKE ? OR CAST(t.transactionID AS CHAR) LIKE ?)';
    $like = '%' . $search . '%';
    $bindTypes .= 'ssss';
    array_push($bindValues, $like, $like, $like, $like);
}
if ($stageFilter !== '') {
    $where[] = 't.currentStage = ?';
    $bindTypes .= 's';
    $bindValues[] = $stageFilter;
}

$transactions = [];
$transactionQuery = $conn->prepare(
    "SELECT t.transactionID, t.propertyID, t.clientID, t.currentStage, t.salePrice,
            t.createdAt, t.updatedAt,
            p.title, p.location, l.municipalityName, l.provinceName,
            u.fullname AS clientName, u.email AS clientEmail,
            COALESCE(pay.paidTotal, 0) AS verifiedPaid,
            COALESCE(pay.paymentCount, 0) AS verifiedPaymentCount
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     JOIN users u ON u.userID = t.clientID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     LEFT JOIN (
        SELECT transactionID, SUM(amount) AS paidTotal, COUNT(*) AS paymentCount
        FROM payments
        WHERE status = 'Verified'
        GROUP BY transactionID
     ) pay ON pay.transactionID = t.transactionID
     WHERE " . implode(' AND ', $where) . "
     ORDER BY (t.currentStage IN ('completed', 'cancelled')) ASC, t.updatedAt DESC, t.transactionID DESC
     LIMIT 300"
);
if ($bindTypes !== '') {
    kq_bind_dynamic($transactionQuery, $bindTypes, $bindValues);
}
$transactionQuery->execute();
$transactionResult = $transactionQuery->get_result();
while ($row = $transactionResult->fetch_assoc()) {
    $transactions[] = $row;
}

include 'includes/header.php';
?>

<style>
    body {
        background: radial-gradient(circle at top, rgba(13, 37, 43, 0.85), rgba(6, 15, 19, 1) 52%);
    }

    .kq-admin-shell {
        max-width: 1280px;
        margin: 0 auto;
        padding: 1.2rem 0 2rem;
        color: #f7f5f0;
    }
    .kq-admin-shell .page-title { color: #f7f5f0; }
    .kq-admin-shell .text-muted { color: rgba(245, 238, 227, 0.74) !important; }
    .kq-admin-shell .card {
        border: 1px solid rgba(20, 34, 41, 0.08) !important;
        border-radius: 1.2rem !important;
        background: rgba(255, 255, 255, 0.98);
        color: var(--kq-ink);
        box-shadow: 0 18px 40px rgba(0, 0, 0, 0.12);
    }
    .kq-admin-shell .card .text-muted { color: #536579 !important; }
    .kq-admin-shell .card .page-eyebrow { color: #805a20; }
    .kq-admin-shell .kq-stat-card {
        background: linear-gradient(180deg, rgba(255,255,255,1), rgba(243,247,250,1));
        border: 1px solid rgba(19, 45, 66, 0.08);
        border-radius: 1rem;
        box-shadow: 0 12px 26px rgba(17, 43, 69, 0.07);
        height: 100%;
        padding: 1.1rem 1.2rem;
    }
    .kq-admin-shell .table { --bs-table-color: var(--kq-ink); --bs-table-border-color: var(--kq-line); }
    .kq-admin-shell .form-label { color: #33475c; }
    .kq-admin-shell .kq-panel-header {
        position: relative;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1.1rem;
        padding-bottom: .75rem;
        border-bottom: 1px solid rgba(19, 45, 66, 0.08);
    }
    .kq-admin-shell .kq-badge-soft {
        display: inline-flex;
        align-items: center;
        padding: .38rem .7rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.12);
        color: #f7f5f0;
        font-size: .75rem;
        font-weight: 700;
    }
    .kq-admin-shell .kq-dashboard-hero {
        display: flex;
        align-items: center;
        gap: .9rem;
        padding: 1rem 1.2rem;
        margin-bottom: 1.5rem;
        border: 1px solid rgba(212, 175, 110, 0.28);
        border-radius: 1.1rem;
        background: linear-gradient(135deg, rgba(14, 29, 39, 0.92), rgba(25, 47, 58, 0.82));
        box-shadow: 0 14px 32px rgba(0, 0, 0, 0.14);
    }
    .kq-admin-shell .kq-dashboard-brand {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 86px;
        padding: .5rem .8rem;
        border: 1px solid rgba(212, 175, 110, 0.4);
        border-radius: 999px;
        background: rgba(212, 175, 110, 0.12);
        color: #f2d79f;
        font-size: .7rem;
        font-weight: 800;
        letter-spacing: .2em;
        text-transform: uppercase;
    }
</style>

<div class="kq-admin-shell">
    <div class="kq-dashboard-hero">
        <span class="kq-dashboard-brand">4 KINGS</span>
        <div>
            <div class="page-eyebrow">Sales operations</div>
            <div class="text-white fw-semibold mb-0">KingQuiry sales command center</div>
        </div>
    </div>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
        <div>
            <div class="page-eyebrow">KingQuiry management</div>
            <h1 class="page-title h2 mb-1">Sales &amp; transactions</h1>
            <p class="text-muted mb-0">Monitor the pipeline, verified revenue, collections, and every tracked client case.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="admin_dashboard.php" class="btn btn-outline-primary">Admin dashboard</a>
            <a href="admin_clients.php" class="btn btn-outline-primary">Clients</a>
            <a href="admin_payments.php" class="btn btn-outline-primary">Payments</a>
            <a href="transaction_tracking.php" class="btn btn-primary">Open tracker</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Verified sales</div>
                <div class="kq-stat-value"><?= number_format($verifiedSalesCount) ?></div>
                <div class="kq-stat-sub"><?= number_format($conversionRate, 1) ?>% of <?= number_format($totalTracked) ?> tracked case(s).</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Actual revenue</div>
                <div class="kq-stat-value"><?= kq_format_compact_peso($actualRevenue) ?></div>
                <div class="kq-stat-sub">Average verified sale <?= kq_format_compact_peso($averageSale) ?>.</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Active pipeline</div>
                <div class="kq-stat-value"><?= number_format($activePipelineCount) ?></div>
                <div class="kq-stat-sub"><?= kq_format_compact_peso($activePipelineAskingValue) ?> in asking-price value.</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Outstanding balances</div>
                <div class="kq-stat-value"><?= kq_format_compact_peso($outstandingBalance) ?></div>
                <div class="kq-stat-sub">Completed sale value not yet verified as collected.</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-7">
            <section class="card p-3 p-lg-4 h-100" aria-labelledby="sales-revenue-chart-title">
                <div class="mb-3">
                    <div class="page-eyebrow">Analytics</div>
                    <h2 class="h5 fw-bold mb-1" id="sales-revenue-chart-title">Actual sales revenue — last six months</h2>
                    <p class="small text-muted mb-0">Based on staff-recorded sale prices at successful completion.</p>
                </div>
                <div class="overflow-auto">
                    <svg class="w-100" style="min-width: 620px;" viewBox="0 0 720 260" role="img" aria-labelledby="sales-revenue-chart-title sales-revenue-chart-description">
                        <desc id="sales-revenue-chart-description">Bar chart of verified actual sale revenue for the last six months.</desc>
                        <?php for ($gridLine = 0; $gridLine <= 4; $gridLine++):
                            $gridY = $plotBottom - ($gridLine * ($plotHeight / 4));
                            $gridValue = $revenueMaximum * $gridLine / 4;
                        ?>
                            <line x1="<?= $plotLeft ?>" y1="<?= $gridY ?>" x2="<?= $plotLeft + $plotWidth ?>" y2="<?= $gridY ?>" stroke="#e5ebf0" stroke-width="1"/>
                            <text x="<?= $plotLeft - 8 ?>" y="<?= $gridY + 4 ?>" text-anchor="end" fill="#687789" font-size="11"><?= kq_e(kq_format_compact_peso($gridValue)) ?></text>
                        <?php endfor; ?>
                        <line x1="<?= $plotLeft ?>" y1="<?= $plotBottom - $plotHeight ?>" x2="<?= $plotLeft ?>" y2="<?= $plotBottom ?>" stroke="#aab6c2" stroke-width="1"/>
                        <line x1="<?= $plotLeft ?>" y1="<?= $plotBottom ?>" x2="<?= $plotLeft + $plotWidth ?>" y2="<?= $plotBottom ?>" stroke="#aab6c2" stroke-width="1"/>
                        <?php foreach ($salesBars as $bar): ?>
                            <rect x="<?= number_format($bar['x'], 2, '.', '') ?>" y="<?= number_format($bar['y'], 2, '.', '') ?>" width="<?= number_format($bar['width'], 2, '.', '') ?>" height="<?= number_format($bar['height'], 2, '.', '') ?>" rx="6" fill="#17865b">
                                <title><?= kq_e($bar['label'] . ': ' . kq_format_peso($bar['revenue']) . ' (' . $bar['sales'] . ' sale(s))') ?></title>
                            </rect>
                            <text x="<?= number_format($bar['center'], 2, '.', '') ?>" y="<?= number_format($bar['y'] - 8, 2, '.', '') ?>" text-anchor="middle" fill="#17865b" font-size="11" font-weight="700"><?= $bar['revenue'] > 0 ? kq_e(kq_format_compact_peso($bar['revenue'])) : '' ?></text>
                            <text x="<?= number_format($bar['center'], 2, '.', '') ?>" y="245" text-anchor="middle" fill="#687789" font-size="11"><?= kq_e($bar['label']) ?></text>
                        <?php endforeach; ?>
                    </svg>
                </div>
                <?php if (!$hasMonthlySales): ?>
                    <p class="small text-muted mb-0 mt-2">No verified sales in this period. The chart updates automatically after a transaction is completed with a sale price.</p>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-5">
            <section class="card p-3 p-lg-4 h-100" aria-labelledby="collections-summary-title">
                <div class="mb-3">
                    <div class="page-eyebrow">Collections</div>
                    <h2 class="h5 fw-bold mb-1" id="collections-summary-title">Payment reconciliation</h2>
                    <p class="small text-muted mb-0">Verified and pending payment records across all transactions.</p>
                </div>
                <div class="d-grid gap-3">
                    <div class="border rounded-3 p-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold">Verified collected</span>
                            <span class="fw-bold text-success"><?= kq_format_peso($verifiedCollected) ?></span>
                        </div>
                        <div class="small text-muted">Confirmed collections on file.</div>
                    </div>
                    <div class="border rounded-3 p-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold">Pending verification</span>
                            <span class="fw-bold text-warning"><?= kq_format_peso($pendingCollected) ?></span>
                        </div>
                        <div class="small text-muted">Recorded payments that still need staff review.</div>
                    </div>
                    <div class="border rounded-3 p-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold">Outstanding on completed sales</span>
                            <span class="fw-bold"><?= kq_format_peso($outstandingBalance) ?></span>
                        </div>
                        <div class="small text-muted">Recorded sale value minus verified payments.</div>
                    </div>
                    <a href="admin_payments.php" class="btn btn-outline-primary">Open payment register</a>
                </div>
            </section>
        </div>
    </div>

    <section class="card p-3 p-lg-4 mb-4" aria-labelledby="stage-breakdown-title">
        <div class="mb-3">
            <div class="page-eyebrow">Pipeline health</div>
            <h2 class="h5 fw-bold mb-1" id="stage-breakdown-title">Transactions by current stage</h2>
            <p class="small text-muted mb-0"><?= number_format($totalTracked) ?> tracked case(s), including <?= number_format($cancelledCases) ?> cancelled.</p>
        </div>
        <?php if ($stageBreakdown === []): ?>
            <p class="small text-muted mb-0">No tracked transactions yet.</p>
        <?php else: ?>
            <div class="d-grid gap-3" role="list" aria-label="Transaction counts by current stage">
                <?php foreach ($stageBreakdown as $stage): ?>
                    <div role="listitem">
                        <div class="d-flex justify-content-between gap-3 small mb-1">
                            <span class="text-break"><?= kq_e($stage['label']) ?></span>
                            <strong><?= number_format($stage['count']) ?></strong>
                        </div>
                        <div class="progress" role="progressbar" aria-label="<?= kq_e($stage['label']) ?> transactions" aria-valuenow="<?= (int) round(($stage['count'] / $stageMaximum) * 100) ?>" aria-valuemin="0" aria-valuemax="100" style="height: .6rem;">
                            <div class="progress-bar <?= in_array($stage['stage'], ['completed'], true) ? 'bg-success' : (in_array($stage['stage'], ['cancelled'], true) ? 'bg-secondary' : 'bg-primary') ?>" style="width: <?= (int) round(($stage['count'] / $stageMaximum) * 100) ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card p-3 p-lg-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-3">
            <div>
                <div class="page-eyebrow">Transaction register</div>
                <h2 class="h4 fw-bold mb-0"><?= number_format(count($transactions)) ?> transaction<?= count($transactions) === 1 ? '' : 's' ?> shown</h2>
            </div>
            <form method="GET" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch">
                <label class="visually-hidden" for="sales-search">Search transactions</label>
                <input id="sales-search" type="search" name="q" class="form-control form-control-sm" value="<?= kq_e($search) ?>" placeholder="Search client, property, or ref">
                <label class="visually-hidden" for="sales-stage-filter">Filter by stage</label>
                <select id="sales-stage-filter" name="stage" class="form-select form-select-sm">
                    <option value="">All stages</option>
                    <?php foreach ($stageLabels as $stageKey => $stageLabel): ?>
                        <option value="<?= kq_e($stageKey) ?>" <?= $stageFilter === $stageKey ? 'selected' : '' ?>><?= kq_e($stageLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <?php if ($search !== '' || $stageFilter !== ''): ?><a href="admin_sales.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Tracking ref</th>
                        <th>Client / property</th>
                        <th>Stage</th>
                        <th>Sale price</th>
                        <th>Verified collected</th>
                        <th>Last updated</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $transaction):
                        $currentStage = (string) $transaction['currentStage'];
                        $location = !empty($transaction['municipalityName'])
                            ? $transaction['municipalityName'] . ', ' . $transaction['provinceName']
                            : $transaction['location'];
                        $verifiedPaid = (float) $transaction['verifiedPaid'];
                        $salePrice = $transaction['salePrice'] !== null ? (float) $transaction['salePrice'] : null;
                        $paidPercent = $salePrice !== null && $salePrice > 0
                            ? min(100, (int) round(($verifiedPaid / $salePrice) * 100))
                            : 0;
                    ?>
                        <tr>
                            <td class="fw-semibold text-nowrap">#KQT-<?= (int) $transaction['transactionID'] ?></td>
                            <td>
                                <strong><?= kq_e($transaction['clientName']) ?></strong>
                                <div class="small text-muted"><?= kq_e($transaction['clientEmail']) ?></div>
                                <div class="fw-semibold mt-2"><?= kq_e($transaction['title']) ?></div>
                                <div class="small text-muted"><?= kq_e($location) ?></div>
                            </td>
                            <td>
                                <span class="badge rounded-pill <?= $currentStage === 'completed' ? 'text-bg-success' : ($currentStage === 'cancelled' ? 'text-bg-secondary' : 'text-bg-primary') ?>"><?= kq_e($stageLabels[$currentStage] ?? 'Status unavailable') ?></span>
                            </td>
                            <td class="small text-nowrap">
                                <?php if ($salePrice !== null): ?>
                                    <span class="fw-semibold"><?= kq_format_peso($salePrice) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Not recorded</span>
                                <?php endif; ?>
                            </td>
                            <td class="small" style="min-width: 11rem;">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="fw-semibold"><?= kq_format_peso($verifiedPaid) ?></span>
                                    <?php if ($salePrice !== null): ?><span class="text-muted"><?= $paidPercent ?>%</span><?php endif; ?>
                                </div>
                                <div class="progress" role="progressbar" aria-label="Verified payment progress for transaction #KQT-<?= (int) $transaction['transactionID'] ?>" aria-valuenow="<?= $paidPercent ?>" aria-valuemin="0" aria-valuemax="100" style="height: .5rem;">
                                    <div class="progress-bar bg-success" style="width: <?= $paidPercent ?>%"></div>
                                </div>
                                <div class="text-muted mt-1"><?= (int) $transaction['verifiedPaymentCount'] ?> verified payment(s)</div>
                            </td>
                            <td class="small text-nowrap"><?= kq_e($transaction['updatedAt']) ?></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="transaction_tracking.php#transactions" class="btn btn-sm btn-outline-primary">Tracker</a>
                                    <a href="admin_payments.php?transaction=<?= (int) $transaction['transactionID'] ?>#record-payment" class="btn btn-sm btn-outline-success">Payment</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($transactions === []): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No transactions match this view. New client inquiries and viewing requests create tracked cases automatically.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>
