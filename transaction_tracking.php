<?php
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/transaction_tracking.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$role = normalized_user_role($_SESSION['user'] ?? null);
if (!in_array($role, ['Admin', 'Sales Agent'], true)) {
    header('Location: login.php');
    exit();
}

$formError = '';
$formSuccess = isset($_GET['updated']) ? 'Transaction tracking was updated.' : '';
$actorId = (int) $_SESSION['user']['userID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_transaction'])) {
    $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
    $newStage = is_string($_POST['stage'] ?? null) ? $_POST['stage'] : '';
    $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';
    $postedSalePrice = $_POST['salePrice'] ?? null;
    $salePrice = $postedSalePrice === null || $postedSalePrice === ''
        ? null
        : filter_var($postedSalePrice, FILTER_VALIDATE_FLOAT);
    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } elseif ($transactionId === false || $transactionId <= 0) {
        $formError = 'Choose a valid transaction.';
    } elseif ($newStage === 'completed' && ($salePrice === false || $salePrice === null)) {
        $formError = 'Enter the verified sale price before marking this transaction successful.';
    } else {
        try {
            advance_property_transaction(
                $conn,
                (int) $transactionId,
                $newStage,
                $note,
                $actorId,
                $salePrice === false ? null : ($salePrice === null ? null : (float) $salePrice)
            );
            header('Location: transaction_tracking.php?updated=1#transactions');
            exit();
        } catch (InvalidArgumentException $exception) {
            $formError = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            error_log('KingQuiry transaction status update failed: ' . $exception->getMessage());
            $formError = 'The transaction status could not be saved. Please retry.';
        } catch (Throwable $exception) {
            error_log('KingQuiry transaction update failed: ' . $exception->getMessage());
            $formError = 'The transaction status could not be saved. Please retry.';
        }
    }
}

$transactions = $conn->query(
    "SELECT t.transactionID, t.propertyID, t.clientID, t.appointmentID, t.inquiryID,
            t.currentStage, t.salePrice, t.createdAt, t.updatedAt,
            p.title, p.location, l.municipalityName, l.provinceName,
            u.fullname AS clientName, u.email AS clientEmail,
            a.appointmentDate, a.appointmentTime
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     JOIN users u ON u.userID = t.clientID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     ORDER BY (t.currentStage IN ('completed', 'cancelled')) ASC, t.updatedAt DESC, t.transactionID DESC
     LIMIT 300"
);
$stageLabels = property_transaction_stages();

include 'includes/header.php';
?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4" id="transactions">
    <div>
        <div class="page-eyebrow">Sales pipeline</div>
        <h1 class="page-title h2 mb-1">Transaction tracking</h1>
        <p class="text-muted mb-0">Record each verified milestone so clients can follow their request through completion.</p>
    </div>
    <?php if ($role === 'Admin'): ?><a href="admin_dashboard.php" class="btn btn-outline-primary">Admin dashboard</a><?php else: ?><a href="agent_dashboard.php" class="btn btn-outline-primary">Agent portal</a><?php endif; ?>
</div>

<div class="alert alert-info small" role="note">
    Updates are entered by staff and appear in the client's dashboard. This is a real-estate case timeline, not GPS/package tracking or payment processing. Mark a sale successful only after your organization verifies its completion.
</div>

<?php if ($formSuccess !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($formSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($formError !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<section class="card p-3 p-lg-4">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Tracking ref</th><th>Client / property</th><th>Current step</th><th>Last updated</th><th>Record next step</th></tr></thead>
            <tbody>
            <?php if ($transactions->num_rows > 0): ?>
                <?php while ($transaction = $transactions->fetch_assoc()):
                    $currentStage = (string) $transaction['currentStage'];
                    $nextStages = property_transaction_next_stages($currentStage);
                    $location = !empty($transaction['municipalityName'])
                        ? $transaction['municipalityName'] . ', ' . $transaction['provinceName']
                        : $transaction['location'];
                ?>
                    <tr>
                        <td class="fw-semibold">#KQT-<?= (int) $transaction['transactionID'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($transaction['clientName'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="small text-muted"><?= htmlspecialchars($transaction['clientEmail'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="mt-2 fw-semibold"><?= htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="small text-muted"><?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($transaction['appointmentDate'])): ?>
                                <div class="small text-muted">Viewing: <?= htmlspecialchars($transaction['appointmentDate'] . ' ' . substr($transaction['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if (!empty($transaction['inquiryID'])): ?>
                                <div class="small text-muted">Inquiry #<?= (int) $transaction['inquiryID'] ?></div>
                            <?php endif; ?>
                            <?php if ($currentStage === 'completed' && $transaction['salePrice'] !== null): ?>
                                <div class="small fw-semibold text-success mt-1">Verified sale: ₱<?= number_format((float) $transaction['salePrice'], 2) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge rounded-pill <?= $currentStage === 'completed' ? 'text-bg-success' : ($currentStage === 'cancelled' ? 'text-bg-secondary' : 'text-bg-primary') ?>"><?= htmlspecialchars($stageLabels[$currentStage] ?? 'Status unavailable', ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td class="small"><?= htmlspecialchars($transaction['updatedAt'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ($nextStages !== []): ?>
                                <form method="POST" class="d-grid gap-2" style="min-width: 220px;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= (int) $transaction['transactionID'] ?>">
                                    <label class="visually-hidden" for="transaction-stage-<?= (int) $transaction['transactionID'] ?>">Next tracking stage</label>
                                    <select id="transaction-stage-<?= (int) $transaction['transactionID'] ?>" name="stage" class="form-select form-select-sm" required>
                                        <option value="">Choose next step</option>
                                        <?php foreach ($nextStages as $nextStage): ?>
                                            <option value="<?= htmlspecialchars($nextStage, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($stageLabels[$nextStage], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="visually-hidden" for="transaction-note-<?= (int) $transaction['transactionID'] ?>">Update note</label>
                                    <input id="transaction-note-<?= (int) $transaction['transactionID'] ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="Optional client-visible update">
                                    <label class="small fw-semibold" for="transaction-sale-price-<?= (int) $transaction['transactionID'] ?>">Verified sale price (required for successful completion)</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text">₱</span>
                                        <input id="transaction-sale-price-<?= (int) $transaction['transactionID'] ?>" type="number" name="salePrice" class="form-control" min="0.01" max="9999999999.99" step="0.01" placeholder="Actual agreed price">
                                    </div>
                                    <button type="submit" name="update_transaction" class="btn btn-sm btn-primary">Save update</button>
                                </form>
                            <?php else: ?>
                                <span class="small text-muted">Tracking closed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No tracked inquiries or viewings yet. New client requests will appear here.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php include 'includes/footer.php'; ?>
