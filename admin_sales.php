<?php
include 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/admin_management.php';
require_once 'includes/transaction_tracking.php';
require_once 'includes/admin_analytics.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

apply_secure_headers();
require_role('Admin');
ensure_admin_management_schema($conn);

$stageLabels = property_transaction_stages();
$adminMetrics = get_admin_summary_metrics($conn);

$salesSummary = $conn->query(
    "SELECT COALESCE(AVG(salePrice), 0) AS averageSale
     FROM property_transactions
     WHERE currentStage = 'completed' AND salePrice IS NOT NULL"
)->fetch_assoc();
$completedSalesCount = $adminMetrics['completed_sales'];
$actualRevenue = $adminMetrics['actual_revenue'];
$averageSale = (float) $salesSummary['averageSale'];

$pipeline = $conn->query(
    "SELECT COUNT(*) AS activeCases, COALESCE(SUM(p.price), 0) AS askingPriceValue
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     WHERE t.currentStage NOT IN ('completed', 'cancelled')"
)->fetch_assoc();
$activePipelineCount = $adminMetrics['active_pipeline'];
$activePipelineAskingValue = (float) $pipeline['askingPriceValue'];

$trackedSummary = $conn->query(
    "SELECT COUNT(*) AS totalTracked,
            COALESCE(SUM(currentStage = 'cancelled'), 0) AS cancelledCases
     FROM property_transactions"
)->fetch_assoc();
$totalTracked = (int) $trackedSummary['totalTracked'];
$cancelledCases = (int) $trackedSummary['cancelledCases'];
$conversionRate = $totalTracked > 0 ? ($completedSalesCount / $totalTracked) * 100 : 0.0;

$verifiedTransactionIds = $conn->query(
    "SELECT transactionID FROM payments WHERE status = 'Verified' AND transactionID IS NOT NULL
     UNION
     SELECT transactionID FROM property_transaction_payments WHERE status = 'confirmed'"
);
$verifiedCollected = 0.0;
while ($row = $verifiedTransactionIds->fetch_assoc()) {
    $verifiedCollected += get_verified_transaction_payment_total($conn, (int) $row['transactionID']);
}

$pendingSummary = $conn->query(
    "SELECT
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'Pending')
        + (SELECT COALESCE(SUM(amount), 0) FROM property_transaction_payments WHERE status IN ('pending', 'in_process'))
        AS pendingTotal"
)->fetch_assoc();
$pendingCollected = (float) $pendingSummary['pendingTotal'];

$completedTransactionRows = $conn->query(
    "SELECT transactionID, salePrice
     FROM property_transactions
     WHERE currentStage = 'completed' AND salePrice IS NOT NULL"
);
$outstandingBalance = 0.0;
while ($row = $completedTransactionRows->fetch_assoc()) {
    $paidTotal = get_verified_transaction_payment_total($conn, (int) $row['transactionID']);
    $outstandingBalance += max((float) $row['salePrice'] - $paidTotal, 0.0);
}

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

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow" style="font-size: 0.68rem;">Tracked Cases</div>
                <h3 class="h3 mb-0"><?= number_format($totalTracked) ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Total requests</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-primary" style="font-size: 0.68rem;">Active Pipeline</div>
                <h3 class="h3 mb-0 text-primary"><?= number_format($activePipelineCount) ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">In progress</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-success" style="font-size: 0.68rem;">Completed Sales</div>
                <h3 class="h3 mb-0 text-success"><?= number_format($completedSalesCount) ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">All completed transactions</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-info" style="font-size: 0.68rem;">Conversion</div>
                <h3 class="h3 mb-0 text-info"><?= number_format($conversionRate, 1) ?>%</h3>
                <small class="text-muted" style="font-size: 0.72rem;">Success rate</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-success" style="font-size: 0.68rem;">Actual Revenue</div>
                <h3 class="h3 mb-0 text-success"><?= kq_format_compact_peso($actualRevenue) ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Verified collected</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4 col-xl-2">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="page-eyebrow text-warning" style="font-size: 0.68rem;">Outstanding</div>
                <h3 class="h3 mb-0 text-warning"><?= kq_format_compact_peso($outstandingBalance) ?></h3>
                <small class="text-muted" style="font-size: 0.72rem;">Awaiting collection</small>
            </div>
        </div>
    </div>
</div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <section class="card p-3 p-lg-4 h-100" aria-labelledby="collections-summary-title">
                <div class="mb-3">
                    <div class="page-eyebrow">Collections</div>
                    <h2 class="h5 fw-bold mb-1" id="collections-summary-title">Payment reconciliation</h2>
                    <p class="small text-muted mb-0">Client-submitted reports and Admin-register payments across all transactions.</p>
                </div>
                <div class="d-grid gap-3">
                    <div class="border rounded-3 p-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-semibold">Verified collected</span>
                            <span class="fw-bold text-success"><?= kq_format_peso($verifiedCollected) ?></span>
                        </div>
                        <div class="small text-muted">Confirmed reports and verified Admin records, reconciled by transaction.</div>
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
                    <a href="admin_payments.php" class="btn btn-outline-primary">Open payments</a>
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
            <form method="GET" action="admin_sales.php#sales-results" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch">
                <label class="visually-hidden" for="sales-search">Search transactions</label>
                <input id="sales-search" type="search" name="q" class="form-control form-control-sm" value="<?= kq_e($search) ?>" placeholder="Search client, property, or ref">
                <label class="visually-hidden" for="sales-stage-filter">Filter by stage</label>
                <select id="sales-stage-filter" name="stage" class="form-select form-select-sm">
                    <option value="">All stages</option>
                    <?php foreach ($stageLabels as $stageKey => $stageLabel): ?>
                        <option value="<?= kq_e($stageKey) ?>" <?= $stageFilter === $stageKey ? 'selected' : '' ?>><?= kq_e($stageLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Apply filters</button>
                <?php if ($search !== '' || $stageFilter !== ''): ?><a href="admin_sales.php#sales-results" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
            </form>
        </div>

        <div id="sales-results" class="table-responsive">
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
                                <?php
                                $stageBadgeClass = match ($currentStage) {
                                    'completed' => 'text-bg-success',
                                    'cancelled' => 'text-bg-secondary',
                                    'payment_in_process' => 'text-bg-warning text-dark',
                                    'payment_confirmation' => 'text-bg-info text-dark',
                                    default => 'text-bg-primary',
                                };
                                ?>
                                <span class="badge rounded-pill <?= $stageBadgeClass ?>"><?= kq_e($stageLabels[$currentStage] ?? 'Status unavailable') ?></span>
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
                                    <a href="transaction_tracking.php#transactions" class="btn btn-sm btn-outline-primary">Transaction tracker</a>
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
<?php include 'includes/footer.php'; ?>
