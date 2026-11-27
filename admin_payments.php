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

$actorId = (int) ($_SESSION['user']['userID'] ?? 0);
$formError = '';
$formSuccess = '';

$flashMessages = [
    'payment_recorded' => 'The payment record has been saved.',
    'payment_verified' => 'The payment has been verified and the client has been notified.',
    'payment_rejected' => 'The payment has been marked as rejected.',
    'payment_pending' => 'The payment has been returned to pending verification.',
    'payment_deleted' => 'The payment record has been removed.',
];
foreach ($flashMessages as $flashKey => $flashMessage) {
    if (isset($_GET[$flashKey])) {
        $formSuccess = $flashMessage;
        break;
    }
}

$stageLabels = property_transaction_stages();
$paymentForm = [
    'transactionID' => 0,
    'amount' => '',
    'paymentType' => 'Reservation',
    'paymentMethod' => 'Cash',
    'referenceNo' => '',
    'paidAt' => date('Y-m-d'),
    'status' => 'Pending',
    'note' => '',
];
$prefillTransactionId = filter_var($_GET['transaction'] ?? null, FILTER_VALIDATE_INT);
if ($prefillTransactionId !== false && $prefillTransactionId > 0) {
    $paymentForm['transactionID'] = (int) $prefillTransactionId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_action'])) {
    $action = is_string($_POST['payment_action'] ?? null) ? $_POST['payment_action'] : '';

    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } else {
        try {
            if ($action === 'record_payment') {
                $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
                $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
                $paymentType = is_string($_POST['paymentType'] ?? null) ? $_POST['paymentType'] : '';
                $paymentMethod = is_string($_POST['paymentMethod'] ?? null) ? $_POST['paymentMethod'] : '';
                $referenceNo = is_string($_POST['referenceNo'] ?? null) ? trim($_POST['referenceNo']) : '';
                $paidAt = is_string($_POST['paidAt'] ?? null) ? trim($_POST['paidAt']) : '';
                $paymentStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : 'Pending';
                $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';

                $paymentForm = [
                    'transactionID' => $transactionId === false ? 0 : (int) $transactionId,
                    'amount' => is_string($_POST['amount'] ?? null) ? $_POST['amount'] : '',
                    'paymentType' => $paymentType,
                    'paymentMethod' => $paymentMethod,
                    'referenceNo' => $referenceNo,
                    'paidAt' => $paidAt,
                    'status' => $paymentStatus,
                    'note' => $note,
                ];

                if ($transactionId === false || $transactionId <= 0) {
                    throw new InvalidArgumentException('Choose the transaction this payment belongs to.');
                }
                if ($amount === false || $amount <= 0 || $amount > 9999999999.99) {
                    throw new InvalidArgumentException('Enter a valid payment amount greater than zero.');
                }
                if (!in_array($paymentType, payment_type_options(), true)) {
                    throw new InvalidArgumentException('Choose a valid payment type.');
                }
                if (!in_array($paymentMethod, payment_method_options(), true)) {
                    throw new InvalidArgumentException('Choose a valid payment method.');
                }
                if (strlen($referenceNo) > 100) {
                    throw new InvalidArgumentException('The reference number must be 100 characters or fewer.');
                }
                $paidDate = DateTimeImmutable::createFromFormat('!Y-m-d', $paidAt);
                if ($paidDate === false || $paidDate->format('Y-m-d') !== $paidAt) {
                    throw new InvalidArgumentException('Choose a valid payment date.');
                }
                if (!in_array($paymentStatus, ['Pending', 'Verified'], true)) {
                    throw new InvalidArgumentException('New payments can be recorded as Pending or Verified.');
                }
                if (strlen($note) > 1000) {
                    throw new InvalidArgumentException('Payment notes must be 1,000 characters or fewer.');
                }

                $transactionStmt = $conn->prepare(
                    'SELECT t.transactionID, t.clientID, t.currentStage, t.salePrice, p.title, u.fullname, u.email
                     FROM property_transactions t
                     JOIN properties p ON p.propertyID = t.propertyID
                     JOIN users u ON u.userID = t.clientID
                     WHERE t.transactionID = ?
                     LIMIT 1'
                );
                $transactionStmt->bind_param('i', $transactionId);
                $transactionStmt->execute();
                $transaction = $transactionStmt->get_result()->fetch_assoc();
                if (!$transaction) {
                    throw new InvalidArgumentException('The selected transaction was not found.');
                }

                if ($paymentStatus === 'Verified' && $transaction['salePrice'] !== null) {
                    $paidTotal = get_verified_transaction_payment_total($conn, $transactionId);
                    if ($paidTotal + (float) $amount > (float) $transaction['salePrice'] + 0.0001) {
                        throw new InvalidArgumentException(
                            'This verified payment would exceed the recorded sale price for the transaction.'
                        );
                    }
                }

                $clientId = (int) $transaction['clientID'];
                $stmt = $conn->prepare(
                    'INSERT INTO payments
                        (transactionID, clientID, amount, paymentType, paymentMethod, referenceNo, status, paidAt, note, recordedBy)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $amountValue = (float) $amount;
                $stmt->bind_param(
                    'iidssssssi',
                    $transactionId,
                    $clientId,
                    $amountValue,
                    $paymentType,
                    $paymentMethod,
                    $referenceNo,
                    $paymentStatus,
                    $paidAt,
                    $note,
                    $actorId
                );
                $stmt->execute();
                $paymentId = (int) $conn->insert_id;

                kq_log_admin_action(
                    $conn,
                    $actorId,
                    'Recorded payment #' . $paymentId . ' for transaction #' . $transactionId . ' (' . kq_format_peso($amountValue) . ').'
                );

                if ($paymentStatus === 'Verified') {
                    send_property_notification(
                        $conn,
                        $clientId,
                        'Payment verified',
                        sprintf(
                            "Hello %s,\n\nA payment of %s for '%s' has been recorded and verified.\n\nReference: %s\nPayment type: %s\nPayment date: %s\n\nPlease check your dashboard for the latest transaction details.",
                            (string) $transaction['fullname'],
                            kq_format_peso($amountValue),
                            (string) $transaction['title'],
                            $referenceNo !== '' ? $referenceNo : 'Not provided',
                            $paymentType,
                            $paidAt
                        )
                    );
                }

                header('Location: admin_payments.php?payment_recorded=1');
                exit();
            }

            if ($action === 'update_status') {
                $paymentId = filter_var($_POST['paymentID'] ?? null, FILTER_VALIDATE_INT);
                $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';

                if ($paymentId === false || $paymentId <= 0 || !in_array($newStatus, payment_status_options(), true)) {
                    throw new InvalidArgumentException('Choose a valid payment and status.');
                }

                $detailsStmt = $conn->prepare(
                    'SELECT p.paymentID, p.transactionID, p.clientID, p.amount, p.status, p.paidAt,
                            u.fullname, p.referenceNo, pr.title
                     FROM payments p
                     JOIN users u ON u.userID = p.clientID
                     LEFT JOIN property_transactions t ON t.transactionID = p.transactionID
                     LEFT JOIN properties pr ON pr.propertyID = t.propertyID
                     WHERE p.paymentID = ?
                     LIMIT 1'
                );
                $detailsStmt->bind_param('i', $paymentId);
                $detailsStmt->execute();
                $payment = $detailsStmt->get_result()->fetch_assoc();
                if (!$payment) {
                    throw new InvalidArgumentException('The selected payment record was not found.');
                }
                if ($payment['status'] === $newStatus) {
                    throw new InvalidArgumentException('The payment already has that status.');
                }

                if ($newStatus === 'Verified' && $payment['transactionID'] !== null) {
                    $transactionStmt = $conn->prepare(
                        'SELECT salePrice FROM property_transactions WHERE transactionID = ? LIMIT 1'
                    );
                    $transactionStmt->bind_param('i', $payment['transactionID']);
                    $transactionStmt->execute();
                    $transactionRow = $transactionStmt->get_result()->fetch_assoc();
                    if ($transactionRow && $transactionRow['salePrice'] !== null) {
                        $paidTotal = get_verified_transaction_payment_total($conn, (int) $payment['transactionID']);
                        if ($paidTotal + (float) $payment['amount'] > (float) $transactionRow['salePrice'] + 0.0001) {
                            throw new InvalidArgumentException(
                                'Verifying this payment would exceed the recorded sale price for the transaction.'
                            );
                        }
                    }
                }

                $stmt = $conn->prepare('UPDATE payments SET status = ? WHERE paymentID = ?');
                $stmt->bind_param('si', $newStatus, $paymentId);
                $stmt->execute();

                kq_log_admin_action(
                    $conn,
                    $actorId,
                    'Changed payment #' . $paymentId . ' status from ' . $payment['status'] . ' to ' . $newStatus . '.'
                );

                if ($newStatus === 'Verified') {
                    send_property_notification(
                        $conn,
                        (int) $payment['clientID'],
                        'Payment verified',
                        sprintf(
                            "Hello %s,\n\nYour payment of %s for '%s' has been verified.\n\nReference: %s\nPayment date: %s\n\nPlease check your dashboard for the latest transaction details.",
                            (string) $payment['fullname'],
                            kq_format_peso((float) $payment['amount']),
                            (string) ($payment['title'] ?? 'your property'),
                            (string) ($payment['referenceNo'] ?: 'Not provided'),
                            (string) $payment['paidAt']
                        )
                    );
                }

                $statusFlash = $newStatus === 'Verified'
                    ? 'payment_verified'
                    : ($newStatus === 'Rejected' ? 'payment_rejected' : 'payment_pending');
                header('Location: admin_payments.php?' . $statusFlash . '=1');
                exit();
            }

            if ($action === 'review_client_report') {
                $paymentId = filter_var($_POST['paymentID'] ?? null, FILTER_VALIDATE_INT);
                $newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
                $reviewNote = is_string($_POST['reviewNote'] ?? null) ? trim($_POST['reviewNote']) : '';
                if ($paymentId === false || $paymentId <= 0 || !in_array($newStatus, ['confirmed', 'rejected'], true)) {
                    throw new InvalidArgumentException('Choose a valid client payment report and review status.');
                }

                review_property_transaction_payment($conn, (int) $paymentId, $newStatus, $reviewNote, $actorId);
                $statusFlash = $newStatus === 'confirmed' ? 'payment_verified' : 'payment_rejected';
                header('Location: admin_payments.php?' . $statusFlash . '=1');
                exit();
            }

            if ($action === 'delete_payment') {
                $paymentId = filter_var($_POST['paymentID'] ?? null, FILTER_VALIDATE_INT);
                if ($paymentId === false || $paymentId <= 0) {
                    throw new InvalidArgumentException('Choose a valid payment record to remove.');
                }

                $deleteStmt = $conn->prepare('DELETE FROM payments WHERE paymentID = ?');
                $deleteStmt->bind_param('i', $paymentId);
                $deleteStmt->execute();
                if ($deleteStmt->affected_rows === 0) {
                    throw new InvalidArgumentException('The selected payment record was not found.');
                }

                kq_log_admin_action($conn, $actorId, 'Removed payment record #' . $paymentId . '.');
                header('Location: admin_payments.php?payment_deleted=1');
                exit();
            }

            throw new InvalidArgumentException('Choose a valid payment action.');
        } catch (InvalidArgumentException $exception) {
            $formError = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            error_log('KingQuiry payment management failed: ' . $exception->getMessage());
            $formError = 'The payment record could not be saved. Confirm MySQL is running and try again.';
        } catch (Throwable $exception) {
            error_log('KingQuiry payment management failed: ' . $exception->getMessage());
            $formError = 'The payment record could not be saved. Please try again.';
        }
    }
}

$paymentStats = $conn->query(
    "SELECT COUNT(*) AS totalPayments,
            COALESCE(SUM(CASE WHEN status = 'Verified' THEN amount ELSE 0 END), 0) AS verifiedTotal,
            COALESCE(SUM(CASE WHEN status = 'Verified' THEN 1 ELSE 0 END), 0) AS verifiedCount,
            COALESCE(SUM(CASE WHEN status = 'Pending' THEN amount ELSE 0 END), 0) AS pendingTotal,
            COALESCE(SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END), 0) AS pendingCount,
            COALESCE(SUM(CASE WHEN status = 'Rejected' THEN amount ELSE 0 END), 0) AS rejectedTotal,
            COALESCE(SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END), 0) AS rejectedCount,
            COALESCE(SUM(CASE WHEN status = 'Verified' AND paidAt >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN amount ELSE 0 END), 0) AS monthVerifiedTotal,
            COALESCE(SUM(CASE WHEN status = 'Verified' AND paidAt >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN 1 ELSE 0 END), 0) AS monthVerifiedCount
     FROM payments"
)->fetch_assoc();
$clientReportStats = $conn->query(
    "SELECT COUNT(*) AS totalReports,
            COALESCE(SUM(CASE WHEN status = 'confirmed' THEN amount ELSE 0 END), 0) AS confirmedTotal,
            COALESCE(SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmedCount,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'in_process') THEN amount ELSE 0 END), 0) AS pendingTotal,
            COALESCE(SUM(CASE WHEN status IN ('pending', 'in_process') THEN 1 ELSE 0 END), 0) AS pendingCount,
            COALESCE(SUM(CASE WHEN status = 'rejected' THEN amount ELSE 0 END), 0) AS rejectedTotal,
            COALESCE(SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END), 0) AS rejectedCount,
            COALESCE(SUM(CASE WHEN status = 'confirmed' AND COALESCE(reviewedAt, submittedAt) >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN amount ELSE 0 END), 0) AS monthConfirmedTotal,
            COALESCE(SUM(CASE WHEN status = 'confirmed' AND COALESCE(reviewedAt, submittedAt) >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01') THEN 1 ELSE 0 END), 0) AS monthConfirmedCount
     FROM property_transaction_payments"
)->fetch_assoc();

$unlinkedVerifiedRow = $conn->query(
    "SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS cnt
     FROM payments
     WHERE status = 'Verified' AND transactionID IS NULL"
)->fetch_assoc();
$verifiedTxIdsRes = $conn->query(
    "SELECT transactionID FROM payments WHERE status = 'Verified' AND transactionID IS NOT NULL
     UNION
     SELECT transactionID FROM property_transaction_payments WHERE status = 'confirmed'"
);
$verifiedTxIds = [];
while ($txIdRow = $verifiedTxIdsRes->fetch_assoc()) {
    $verifiedTxIds[] = (int) $txIdRow['transactionID'];
}
$verifiedTxSummaries = $verifiedTxIds !== [] ? get_transaction_payment_summaries($conn, $verifiedTxIds) : [];
$totalVerifiedCollected = (float) ($unlinkedVerifiedRow['total'] ?? 0)
    + array_sum(array_column($verifiedTxSummaries, 'verifiedTotal'));
$totalVerifiedCount = (int) ($unlinkedVerifiedRow['cnt'] ?? 0)
    + array_sum(array_column($verifiedTxSummaries, 'verifiedCount'));
$totalPendingAmount = (float) $paymentStats['pendingTotal'] + (float) $clientReportStats['pendingTotal'];
$totalPendingCount = (int) $paymentStats['pendingCount'] + (int) $clientReportStats['pendingCount'];
$totalMonthVerifiedAmount = (float) $paymentStats['monthVerifiedTotal'] + (float) $clientReportStats['monthConfirmedTotal'];
$totalMonthVerifiedCount = (int) $paymentStats['monthVerifiedCount'] + (int) $clientReportStats['monthConfirmedCount'];
$totalRejectedAmount = (float) $paymentStats['rejectedTotal'] + (float) $clientReportStats['rejectedTotal'];
$totalRejectedCount = (int) $paymentStats['rejectedCount'] + (int) $clientReportStats['rejectedCount'];

$collectionMonthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$collectionRangeStart = $collectionMonthStart->format('Y-m-d');
$collectionRangeEnd = $collectionMonthStart->modify('+6 months')->format('Y-m-d');
$monthlyCollections = [];
for ($monthOffset = 0; $monthOffset < 6; $monthOffset++) {
    $month = $collectionMonthStart->modify('+' . $monthOffset . ' months');
    $monthlyCollections[$month->format('Y-m')] = [
        'label' => $month->format('M Y'),
        'total' => 0.0,
        'count' => 0,
    ];
}
$monthlyCollectionStmt = $conn->prepare(
    "SELECT monthKey, SUM(amount) AS total, SUM(paymentCount) AS paymentCount
     FROM (
         SELECT DATE_FORMAT(paidAt, '%Y-%m') AS monthKey, amount, 1 AS paymentCount
         FROM payments
         WHERE status = 'Verified' AND paidAt >= ? AND paidAt < ?
         UNION ALL
         SELECT DATE_FORMAT(COALESCE(reviewedAt, submittedAt), '%Y-%m') AS monthKey,
                amount, 1 AS paymentCount
         FROM property_transaction_payments
         WHERE status = 'confirmed'
           AND COALESCE(reviewedAt, submittedAt) >= ?
           AND COALESCE(reviewedAt, submittedAt) < ?
     ) verified_collections
     GROUP BY monthKey"
);
$monthlyCollectionStmt->bind_param(
    'ssss',
    $collectionRangeStart,
    $collectionRangeEnd,
    $collectionRangeStart,
    $collectionRangeEnd
);
$monthlyCollectionStmt->execute();
foreach ($monthlyCollectionStmt->get_result() as $row) {
    $monthKey = (string) $row['monthKey'];
    if (isset($monthlyCollections[$monthKey])) {
        $monthlyCollections[$monthKey]['total'] = (float) $row['total'];
        $monthlyCollections[$monthKey]['count'] = (int) $row['paymentCount'];
    }
}
$collectionMaximum = max(1.0, ...array_column($monthlyCollections, 'total'));
$collectionPoints = [];
$collectionPath = '';
$plotLeft = 76;
$plotWidth = 620;
$plotBottom = 218;
$plotHeight = 150;
$slotWidth = $plotWidth / 6;
foreach (array_values($monthlyCollections) as $index => $month) {
    $collectionPoints[] = [
        'x' => $plotLeft + ($index * $slotWidth) + ($slotWidth / 2),
        'y' => $plotBottom - (($month['total'] / $collectionMaximum) * $plotHeight),
        'label' => $month['label'],
        'total' => $month['total'],
        'count' => $month['count'],
    ];
}
if ($collectionPoints !== []) {
    $collectionPath = implode(' ', array_map(
        static fn(array $point, int $index): string => ($index === 0 ? 'M ' : 'L ')
            . number_format($point['x'], 2, '.', '') . ' '
            . number_format($point['y'], 2, '.', ''),
        $collectionPoints,
        array_keys($collectionPoints)
    ));
}

$transactionsForSelect = [];
$transactionSelectResult = $conn->query(
    "SELECT t.transactionID, t.clientID, t.currentStage, t.salePrice, p.title, p.price AS listingPrice, u.fullname
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     JOIN users u ON u.userID = t.clientID
     WHERE t.currentStage <> 'cancelled'
     ORDER BY (t.currentStage IN ('completed')) ASC, t.updatedAt DESC, t.transactionID DESC
     LIMIT 300"
);
while ($row = $transactionSelectResult->fetch_assoc()) {
    $transactionsForSelect[] = $row;
}
$selectPaymentSummaries = $transactionsForSelect !== []
    ? get_transaction_payment_summaries($conn, array_column($transactionsForSelect, 'transactionID'))
    : [];

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!in_array($statusFilter, payment_status_options(), true)) {
    $statusFilter = '';
}

$where = ['1 = 1'];
$bindTypes = '';
$bindValues = [];
if ($search !== '') {
    $where[] = '(u.fullname LIKE ? OR u.email LIKE ? OR pay.referenceNo LIKE ? OR p.title LIKE ?)';
    $like = '%' . $search . '%';
    $bindTypes .= 'ssss';
    array_push($bindValues, $like, $like, $like, $like);
}
if ($statusFilter !== '') {
    $where[] = 'pay.status = ?';
    $bindTypes .= 's';
    $bindValues[] = $statusFilter;
}

$payments = [];
$paymentQuery = $conn->prepare(
    "SELECT pay.paymentID, pay.transactionID, pay.clientID, pay.amount, pay.paymentType,
            pay.paymentMethod, pay.referenceNo, pay.status, pay.paidAt, pay.note, pay.createdAt,
            u.fullname AS clientName, u.email AS clientEmail,
            p.title AS propertyTitle, t.currentStage, t.salePrice
     FROM payments pay
     JOIN users u ON u.userID = pay.clientID
     LEFT JOIN property_transactions t ON t.transactionID = pay.transactionID
     LEFT JOIN properties p ON p.propertyID = t.propertyID
     WHERE " . implode(' AND ', $where) . "
     ORDER BY pay.paidAt DESC, pay.paymentID DESC
     LIMIT 300"
);
if ($bindTypes !== '') {
    kq_bind_dynamic($paymentQuery, $bindTypes, $bindValues);
}
$paymentQuery->execute();
$paymentResult = $paymentQuery->get_result();
while ($row = $paymentResult->fetch_assoc()) {
    $row['paymentSource'] = 'admin_register';
    $payments[] = $row;
}

$clientReportWhere = ['1 = 1'];
$clientReportBindTypes = '';
$clientReportBindValues = [];
if ($search !== '') {
    $clientReportWhere[] = '(u.fullname LIKE ? OR u.email LIKE ? OR pay.referenceNumber LIKE ? OR p.title LIKE ?)';
    $like = '%' . $search . '%';
    $clientReportBindTypes .= 'ssss';
    array_push($clientReportBindValues, $like, $like, $like, $like);
}
if ($statusFilter !== '') {
    $clientReportStatuses = match ($statusFilter) {
        'Verified' => ['confirmed'],
        'Rejected' => ['rejected'],
        default => ['pending', 'in_process'],
    };
    $statusPlaceholders = implode(', ', array_fill(0, count($clientReportStatuses), '?'));
    $clientReportWhere[] = "pay.status IN ($statusPlaceholders)";
    $clientReportBindTypes .= str_repeat('s', count($clientReportStatuses));
    array_push($clientReportBindValues, ...$clientReportStatuses);
}
$clientReportQuery = $conn->prepare(
    "SELECT pay.paymentID, pay.transactionID, pay.clientID, pay.amount,
            pay.paymentMethod, pay.referenceNumber AS referenceNo, pay.status,
            pay.submittedAt AS paidAt, pay.reviewNote AS note,
            u.fullname AS clientName, u.email AS clientEmail,
            p.title AS propertyTitle, t.currentStage, t.salePrice
     FROM property_transaction_payments pay
     JOIN users u ON u.userID = pay.clientID
     LEFT JOIN property_transactions t ON t.transactionID = pay.transactionID
     LEFT JOIN properties p ON p.propertyID = t.propertyID
     WHERE " . implode(' AND ', $clientReportWhere) . "
     ORDER BY pay.submittedAt DESC, pay.paymentID DESC
     LIMIT 300"
);
if ($clientReportBindTypes !== '') {
    kq_bind_dynamic($clientReportQuery, $clientReportBindTypes, $clientReportBindValues);
}
$clientReportQuery->execute();
$clientReportResult = $clientReportQuery->get_result();
while ($row = $clientReportResult->fetch_assoc()) {
    $row['paymentType'] = 'Client report';
    $row['paymentSource'] = 'client_report';
    $row['reviewable'] = $row['status'] === 'pending';
    $row['status'] = match ((string) $row['status']) {
        'confirmed' => 'Verified',
        'rejected' => 'Rejected',
        default => 'Pending',
    };
    $payments[] = $row;
}
usort(
    $payments,
    static fn(array $left, array $right): int => strcmp(
        (string) $right['paidAt'],
        (string) $left['paidAt']
    ) ?: ((int) $right['paymentID'] <=> (int) $left['paymentID'])
);
$payments = array_slice($payments, 0, 300);

$statusBadgeClasses = [
    'Pending' => 'text-bg-warning',
    'Verified' => 'text-bg-success',
    'Rejected' => 'text-bg-secondary',
];

include 'includes/header.php';
?>

<?php if ($formSuccess !== ''): ?><div class="alert alert-success" role="status"><?= kq_e($formSuccess) ?></div><?php endif; ?>
    <?php if ($formError !== ''): ?><div class="alert alert-danger" role="alert"><?= kq_e($formError) ?></div><?php endif; ?>

    <div class="alert alert-info small mb-4" role="note">
        <strong>How payments work here:</strong> KingQuiry tracks offline payments (cash, bank transfer, or checks) rather than transferring money online.
        <strong>1)</strong> Choose a transaction and record the payment below (or verify a pending entry).
        <strong>2)</strong> Verified payments automatically update the client's balance and allow the Sales Agent to complete the sale once the full price is covered.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Total verified collected</div>
                <div class="kq-stat-value text-success"><?= kq_format_compact_peso($totalVerifiedCollected) ?></div>
                <div class="kq-stat-sub"><?= number_format($totalVerifiedCount) ?> verified payment(s) across all transactions.</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Pending verification</div>
                <div class="kq-stat-value text-warning-emphasis"><?= kq_format_compact_peso($totalPendingAmount) ?></div>
                <div class="kq-stat-sub"><?= number_format($totalPendingCount) ?> payment(s) waiting for review.</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Verified this month</div>
                <div class="kq-stat-value"><?= kq_format_compact_peso($totalMonthVerifiedAmount) ?></div>
                <div class="kq-stat-sub"><?= number_format($totalMonthVerifiedCount) ?> payment(s) verified this month.</div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="kq-stat-card">
                <div class="kq-stat-label">Rejected</div>
                <div class="kq-stat-value"><?= kq_format_compact_peso($totalRejectedAmount) ?></div>
                <div class="kq-stat-sub"><?= number_format($totalRejectedCount) ?> rejected payment(s).</div>
            </div>
        </div>
    </div>

    <section class="card p-3 p-lg-4 mb-4" aria-labelledby="collections-chart-title">
        <div class="mb-3">
            <div class="page-eyebrow">Analytics</div>
            <h2 class="h5 fw-bold mb-1" id="collections-chart-title">Verified collections — last six months</h2>
            <p class="small text-muted mb-0">Includes verified Admin-register payments and confirmed client-submitted reports.</p>
        </div>
        <div class="overflow-auto">
            <svg class="w-100" style="min-width: 620px;" viewBox="0 0 720 260" role="img" aria-labelledby="collections-chart-title collections-chart-description">
                <desc id="collections-chart-description">Line chart of verified collections from both payment sources for the last six months.</desc>
                <?php for ($gridLine = 0; $gridLine <= 4; $gridLine++):
                    $gridY = $plotBottom - ($gridLine * ($plotHeight / 4));
                    $gridValue = $collectionMaximum * $gridLine / 4;
                ?>
                    <line x1="<?= $plotLeft ?>" y1="<?= $gridY ?>" x2="<?= $plotLeft + $plotWidth ?>" y2="<?= $gridY ?>" stroke="#e5ebf0" stroke-width="1"/>
                    <text x="<?= $plotLeft - 8 ?>" y="<?= $gridY + 4 ?>" text-anchor="end" fill="#687789" font-size="11"><?= kq_e(kq_format_compact_peso($gridValue)) ?></text>
                <?php endfor; ?>
                <line x1="<?= $plotLeft ?>" y1="<?= $plotBottom - $plotHeight ?>" x2="<?= $plotLeft ?>" y2="<?= $plotBottom ?>" stroke="#aab6c2" stroke-width="1"/>
                <line x1="<?= $plotLeft ?>" y1="<?= $plotBottom ?>" x2="<?= $plotLeft + $plotWidth ?>" y2="<?= $plotBottom ?>" stroke="#aab6c2" stroke-width="1"/>
                <path d="<?= kq_e($collectionPath) ?>" fill="none" stroke="#174a70" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                <?php foreach ($collectionPoints as $point): ?>
                    <circle cx="<?= number_format($point['x'], 2, '.', '') ?>" cy="<?= number_format($point['y'], 2, '.', '') ?>" r="5" fill="#fff" stroke="#174a70" stroke-width="3">
                        <title><?= kq_e($point['label'] . ': ' . kq_format_peso($point['total']) . ' (' . $point['count'] . ' payment(s))') ?></title>
                    </circle>
                    <text x="<?= number_format($point['x'], 2, '.', '') ?>" y="<?= number_format($point['y'] - 8, 2, '.', '') ?>" text-anchor="middle" fill="#174a70" font-size="11" font-weight="700"><?= $point['total'] > 0 ? kq_e(kq_format_compact_peso($point['total'])) : '' ?></text>
                    <text x="<?= number_format($point['x'], 2, '.', '') ?>" y="245" text-anchor="middle" fill="#687789" font-size="11"><?= kq_e($point['label']) ?></text>
                <?php endforeach; ?>
            </svg>
        </div>
        <?php if (array_sum(array_column($monthlyCollections, 'count')) === 0): ?>
            <p class="small text-muted mb-0 mt-2">No verified collections in this period. The chart will update as payments are verified.</p>
        <?php endif; ?>
    </section>

    <section class="card p-3 p-lg-4 mb-4" id="record-payment">
        <div class="mb-3">
            <div class="page-eyebrow">New collection</div>
            <h2 class="h4 fw-bold mb-1">Record a payment</h2>
            <p class="small text-muted mb-0">Link the payment to a tracked transaction so the client and sales team see the same record.</p>
        </div>

        <form method="POST" class="row g-3" data-confirm="Save this payment record? This records payment information but does not transfer money.">
            <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
            <input type="hidden" name="payment_action" value="record_payment">

            <div class="col-12">
                <label class="form-label fw-semibold" for="payment-transaction">Transaction</label>
                <select id="payment-transaction" name="transactionID" class="form-select" required>
                    <option value="">Choose a transaction</option>
                    <?php foreach ($transactionsForSelect as $transactionOption):
                        $optTxId = (int) $transactionOption['transactionID'];
                        $optPaid = (float) ($selectPaymentSummaries[$optTxId]['verifiedTotal'] ?? 0.0);
                        $optTarget = $transactionOption['salePrice'] !== null
                            ? (float) $transactionOption['salePrice']
                            : (float) ($transactionOption['listingPrice'] ?? 0.0);
                        $optRemaining = $optTarget > 0 ? max($optTarget - $optPaid, 0.0) : 0.0;
                        $optionStage = $stageLabels[$transactionOption['currentStage']] ?? $transactionOption['currentStage'];
                        $optionLabel = '#KQT-' . $optTxId
                            . ' · ' . $transactionOption['fullname']
                            . ' — ' . $transactionOption['title']
                            . ' (' . $optionStage . ')';
                    ?>
                        <option value="<?= $optTxId ?>"
                                data-target-price="<?= number_format($optTarget, 2, '.', '') ?>"
                                data-paid="<?= number_format($optPaid, 2, '.', '') ?>"
                                data-remaining="<?= number_format($optRemaining, 2, '.', '') ?>"
                                data-price-label="<?= $transactionOption['salePrice'] !== null ? 'Agreed price' : 'Listing price' ?>"
                                <?= (int) $paymentForm['transactionID'] === $optTxId ? 'selected' : '' ?>><?= kq_e($optionLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="selected-tx-balance-box" class="alert alert-light border small mt-2 mb-0 d-none align-items-center justify-content-between flex-wrap gap-2">
                    <div id="selected-tx-balance-text"></div>
                    <button type="button" id="fill-remaining-btn" class="btn btn-sm btn-outline-primary py-0 px-2 d-none">Fill remaining balance</button>
                </div>
                <?php if ($transactionsForSelect === []): ?><div class="form-text text-danger">No tracked transactions are available yet. Create one from a client inquiry or viewing request first.</div><?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="payment-amount">Amount (PHP)</label>
                <div class="input-group">
                    <span class="input-group-text">₱</span>
                    <input id="payment-amount" type="number" name="amount" class="form-control" min="0.01" max="9999999999.99" step="0.01" required value="<?= kq_e($paymentForm['amount']) ?>" placeholder="0.00">
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="payment-type">Payment type</label>
                <select id="payment-type" name="paymentType" class="form-select" required>
                    <?php foreach (payment_type_options() as $option): ?>
                        <option value="<?= kq_e($option) ?>" <?= $paymentForm['paymentType'] === $option ? 'selected' : '' ?>><?= kq_e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="payment-method">Payment method</label>
                <select id="payment-method" name="paymentMethod" class="form-select" required>
                    <?php foreach (payment_method_options() as $option): ?>
                        <option value="<?= kq_e($option) ?>" <?= $paymentForm['paymentMethod'] === $option ? 'selected' : '' ?>><?= kq_e($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="payment-reference">Reference number <span class="text-muted fw-normal">(optional)</span></label>
                <input id="payment-reference" type="text" name="referenceNo" class="form-control" maxlength="100" value="<?= kq_e($paymentForm['referenceNo']) ?>" placeholder="Bank reference">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="payment-date">Payment date</label>
                <input id="payment-date" type="date" name="paidAt" class="form-control" required value="<?= kq_e($paymentForm['paidAt']) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold" for="payment-status">Initial status</label>
                <select id="payment-status" name="status" class="form-select" required>
                    <option value="Pending" <?= $paymentForm['status'] === 'Pending' ? 'selected' : '' ?>>Pending verification</option>
                    <option value="Verified" <?= $paymentForm['status'] === 'Verified' ? 'selected' : '' ?>>Verified</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label fw-semibold" for="payment-note">Note <span class="text-muted fw-normal">(optional, client-visible)</span></label>
                <input id="payment-note" type="text" name="note" class="form-control" maxlength="1000" value="<?= kq_e($paymentForm['note']) ?>" placeholder="Deposit received at the branch, OR number, or verification details">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary px-4" <?= $transactionsForSelect === [] ? 'disabled' : '' ?>>Save payment</button>
            </div>
        </form>
    </section>

    <section class="card p-3 p-lg-4">
        <div class="mb-3">
            <div class="page-eyebrow">Shared payment activity</div>
            <h2 class="h5 fw-bold mb-1">Payment records &amp; client reports</h2>
            <p class="small text-muted mb-0"><?= number_format((int) $clientReportStats['totalReports']) ?> client-submitted report(s) in the system. Reports appear here for staff review and in Client and assigned Agent transaction views.</p>
        </div>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-3">
            <div>
                <div class="page-eyebrow">Payment register</div>
                <h2 class="h4 fw-bold mb-0"><?= number_format(count($payments)) ?> payment<?= count($payments) === 1 ? '' : 's' ?> shown</h2>
            </div>
            <form method="GET" action="admin_payments.php#payment-results" class="d-flex flex-column flex-sm-row gap-2 align-items-stretch">
                <label class="visually-hidden" for="payment-search">Search payments</label>
                <input id="payment-search" type="search" name="q" class="form-control form-control-sm" value="<?= kq_e($search) ?>" placeholder="Search client, reference, or property">
                <label class="visually-hidden" for="payment-filter-status">Filter by payment status</label>
                <select id="payment-filter-status" name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    <?php foreach (payment_status_options() as $option): ?>
                        <option value="<?= kq_e($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= kq_e($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                <?php if ($search !== '' || $statusFilter !== ''): ?><a href="admin_payments.php#payment-results" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
            </form>
        </div>

        <div class="table-responsive" id="payment-results">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Payment</th>
                        <th>Client</th>
                        <th>Property / transaction</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td class="small">
                                <div class="fw-semibold"><?= $payment['paymentSource'] === 'client_report' ? '#KQCR-' : '#KQP-' ?><?= (int) $payment['paymentID'] ?></div>
                                <div class="text-muted"><?= $payment['paymentSource'] === 'client_report' ? 'Transaction entry' : 'Staff record' ?></div>
                                <div class="text-muted"><?= kq_e(date('M j, Y', strtotime((string) $payment['paidAt']))) ?></div>
                                <div class="text-muted"><?= kq_e($payment['paymentType']) ?> · <?= kq_e($payment['paymentMethod']) ?></div>
                                <?php if (!empty($payment['referenceNo'])): ?><div class="text-muted">Ref: <?= kq_e($payment['referenceNo']) ?></div><?php endif; ?>
                            </td>
                            <td class="small">
                                <div class="fw-semibold"><?= kq_e($payment['clientName']) ?></div>
                                <div class="text-muted"><?= kq_e($payment['clientEmail']) ?></div>
                            </td>
                            <td class="small">
                                <?php if ($payment['transactionID'] !== null): ?>
                                    <div class="fw-semibold"><?= kq_e($payment['propertyTitle'] ?? 'Property record unavailable') ?></div>
                                    <div class="text-muted">#KQT-<?= (int) $payment['transactionID'] ?> · <?= kq_e(property_transaction_stage_label((string) $payment['currentStage'])) ?></div>
                                    <?php if ($payment['salePrice'] !== null): ?><div class="text-muted">Recorded sale: <?= kq_format_peso((float) $payment['salePrice']) ?></div><?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Not linked to a transaction</span>
                                <?php endif; ?>
                                <?php if (!empty($payment['note'])): ?><div class="kq-payment-note text-muted mt-1"><?= kq_e($payment['note']) ?></div><?php endif; ?>
                            </td>
                            <td class="fw-semibold text-nowrap"><?= kq_format_peso((float) $payment['amount']) ?></td>
                            <td><span class="badge <?= $statusBadgeClasses[$payment['status']] ?? 'text-bg-secondary' ?>"><?= kq_e($payment['status']) ?></span></td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if ($payment['paymentSource'] === 'client_report' && !empty($payment['reviewable'])): ?>
                                        <form method="POST" class="d-flex flex-wrap gap-2" data-confirm="Review this client-submitted payment report?">
                                            <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
                                            <input type="hidden" name="payment_action" value="review_client_report">
                                            <input type="hidden" name="paymentID" value="<?= (int) $payment['paymentID'] ?>">
                                            <input type="text" name="reviewNote" class="form-control form-control-sm" maxlength="1000" placeholder="Optional note for client" aria-label="Optional payment review note">
                                            <button type="submit" name="status" value="confirmed" class="btn btn-sm btn-success">Confirm payment</button>
                                            <button type="submit" name="status" value="rejected" class="btn btn-sm btn-outline-danger">Reject payment</button>
                                        </form>
                                    <?php elseif ($payment['paymentSource'] === 'client_report'): ?>
                                        <?php if ($payment['transactionID'] !== null): ?>
                                            <a href="transaction_tracking.php#transactions" class="btn btn-sm btn-outline-primary">View transaction</a>
                                        <?php else: ?>
                                            <span class="small text-muted">Reviewed — no action needed</span>
                                        <?php endif; ?>
                                    <?php elseif ($payment['paymentSource'] === 'admin_register' && $payment['status'] !== 'Verified'): ?>
                                        <form method="POST" class="d-inline" data-confirm="Verify this payment record? The client will be notified.">
                                            <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
                                            <input type="hidden" name="payment_action" value="update_status">
                                            <input type="hidden" name="paymentID" value="<?= (int) $payment['paymentID'] ?>">
                                            <input type="hidden" name="status" value="Verified">
                                            <button type="submit" class="btn btn-sm btn-success">Verify payment</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($payment['paymentSource'] === 'admin_register' && $payment['status'] !== 'Pending'): ?>
                                        <form method="POST" class="d-inline" data-confirm="Return this payment record to pending verification?">
                                            <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
                                            <input type="hidden" name="payment_action" value="update_status">
                                            <input type="hidden" name="paymentID" value="<?= (int) $payment['paymentID'] ?>">
                                            <input type="hidden" name="status" value="Pending">
                                            <button type="submit" class="btn btn-sm btn-outline-warning">Mark as pending</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($payment['paymentSource'] === 'admin_register' && $payment['status'] !== 'Rejected'): ?>
                                        <form method="POST" class="d-inline" data-confirm="Reject this payment record?">
                                            <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
                                            <input type="hidden" name="payment_action" value="update_status">
                                            <input type="hidden" name="paymentID" value="<?= (int) $payment['paymentID'] ?>">
                                            <input type="hidden" name="status" value="Rejected">
                                            <button type="submit" class="btn btn-sm btn-outline-secondary">Reject payment</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($payment['paymentSource'] === 'admin_register'): ?>
                                        <form method="POST" class="d-inline" data-confirm="Remove this payment record permanently?">
                                            <input type="hidden" name="csrf_token" value="<?= kq_e(csrf_token()) ?>">
                                            <input type="hidden" name="payment_action" value="delete_payment">
                                            <input type="hidden" name="paymentID" value="<?= (int) $payment['paymentID'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Remove payment</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($payment['paymentSource'] === 'admin_register' && $payment['status'] === 'Verified' && $payment['transactionID'] !== null): ?>
                                        <a href="transaction_tracking.php#transactions" class="btn btn-sm btn-outline-primary">View transaction</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($payments === []): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No payment records match this view. Record a payment above or clear the filters.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const txSelect = document.getElementById('payment-transaction');
    const balanceBox = document.getElementById('selected-tx-balance-box');
    const balanceText = document.getElementById('selected-tx-balance-text');
    const fillBtn = document.getElementById('fill-remaining-btn');
    const amountInput = document.getElementById('payment-amount');

    if (!txSelect || !balanceBox || !balanceText || !fillBtn || !amountInput) {
        return;
    }

    const formatPeso = (val) => '₱' + Number(val || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    function updateBalancePreview() {
        const option = txSelect.options[txSelect.selectedIndex];
        if (!option || !option.value) {
            balanceBox.classList.add('d-none');
            balanceBox.classList.remove('d-flex');
            return;
        }
        const target = parseFloat(option.dataset.targetPrice || '0');
        const paid = parseFloat(option.dataset.paid || '0');
        const remaining = parseFloat(option.dataset.remaining || '0');
        const priceLabel = option.dataset.priceLabel || 'Price';

        balanceText.innerHTML = `${priceLabel}: <strong>${formatPeso(target)}</strong> · Verified paid: <strong class="text-success">${formatPeso(paid)}</strong> · Remaining: <strong>${remaining <= 0.01 && target > 0 ? 'Fully paid' : formatPeso(remaining)}</strong>`;
        balanceBox.classList.remove('d-none');
        balanceBox.classList.add('d-flex');

        if (remaining > 0.01) {
            fillBtn.classList.remove('d-none');
            fillBtn.dataset.remainingValue = remaining.toFixed(2);
        } else {
            fillBtn.classList.add('d-none');
        }
    }

    txSelect.addEventListener('change', updateBalancePreview);
    fillBtn.addEventListener('click', () => {
        if (fillBtn.dataset.remainingValue) {
            amountInput.value = fillBtn.dataset.remainingValue;
            amountInput.focus();
        }
    });
    updateBalancePreview();
});
</script>
<?php include 'includes/footer.php'; ?>
