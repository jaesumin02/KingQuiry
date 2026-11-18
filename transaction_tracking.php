<?php
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/transaction_tracking.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$role = normalized_user_role($_SESSION['user'] ?? null);
if (!user_has_role($_SESSION['user'] ?? null, 'Admin', 'Sales Agent')) {
    header('Location: client_login.php');
    exit();
}

$formError = '';
$formSuccess = isset($_GET['updated']) ? 'Transaction tracking was updated.' : '';
$actorId = (int) $_SESSION['user']['userID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment_review'])) {
    $paymentId = filter_var($_POST['paymentID'] ?? null, FILTER_VALIDATE_INT);
    $status = is_string($_POST['paymentStatus'] ?? null) ? $_POST['paymentStatus'] : '';
    $reviewNote = is_string($_POST['reviewNote'] ?? null) ? trim($_POST['reviewNote']) : '';
    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } elseif ($paymentId === false || $paymentId <= 0) {
        $formError = 'Choose a valid payment.';
    } else {
        try {
            review_property_transaction_payment($conn, (int) $paymentId, $status, $reviewNote, $actorId);
            header('Location: transaction_tracking.php?updated=1#transactions');
            exit();
        } catch (InvalidArgumentException $exception) {
            $formError = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            error_log('KingQuiry payment review failed: ' . $exception->getMessage());
            $formError = 'The payment review could not be saved. Please retry.';
        } catch (Throwable $exception) {
            error_log('KingQuiry payment review failed: ' . $exception->getMessage());
            $formError = 'The payment review could not be saved. Please retry.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_agent_payment'])) {
    $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
    $paymentStatus = is_string($_POST['paymentStatus'] ?? null) ? trim($_POST['paymentStatus']) : 'confirmed';
    $paymentNote = is_string($_POST['paymentNote'] ?? null) ? trim($_POST['paymentNote']) : '';

    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } elseif ($transactionId === false || $transactionId <= 0) {
        $formError = 'Choose a valid transaction.';
    } else {
        try {
            record_staff_face_to_face_payment(
                $conn,
                (int) $transactionId,
                $paymentStatus,
                $paymentNote,
                $actorId
            );
            header('Location: transaction_tracking.php?updated=1#transactions');
            exit();
        } catch (InvalidArgumentException $exception) {
            $formError = $exception->getMessage();
        } catch (mysqli_sql_exception $exception) {
            error_log('KingQuiry agent payment recording failed: ' . $exception->getMessage());
            $formError = 'The payment update could not be saved. Please retry.';
        } catch (Throwable $exception) {
            error_log('KingQuiry agent payment recording failed: ' . $exception->getMessage());
            $formError = 'The payment update could not be saved. Please retry.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_transaction'])) {
    $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
    $newStage = is_string($_POST['stage'] ?? null) ? $_POST['stage'] : '';
    $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';
    $postedSalePrice = $_POST['salePrice'] ?? null;
    $salePrice = $postedSalePrice === null || $postedSalePrice === ''
        ? null
        : filter_var($postedSalePrice, FILTER_VALIDATE_FLOAT);

    $postedPaymentAmount = $_POST['paymentAmount'] ?? null;
    $paymentAmount = $postedPaymentAmount === null || $postedPaymentAmount === ''
        ? null
        : filter_var($postedPaymentAmount, FILTER_VALIDATE_FLOAT);
    $paymentMethod = is_string($_POST['paymentMethod'] ?? null) ? trim($_POST['paymentMethod']) : '';
    $paymentRef = is_string($_POST['paymentRef'] ?? null) ? trim($_POST['paymentRef']) : '';
    $allowedPaymentMethods = ['Spot Cash', 'Bank Transfer', 'Check', 'Paperwork (Face-to-face)'];

    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } elseif ($transactionId === false || $transactionId <= 0) {
        $formError = 'Choose a valid transaction.';
    } elseif ($paymentMethod !== '' && !in_array($paymentMethod, $allowedPaymentMethods, true)) {
        $formError = 'Choose a supported payment method.';
    } elseif ($newStage === 'completed' && ($salePrice === false || $salePrice === null || $salePrice <= 0)) {
        $formError = 'Enter the verified sale price before marking this transaction successful.';
    } else {
        try {
            advance_property_transaction(
                $conn,
                (int) $transactionId,
                $newStage,
                $note,
                $actorId,
                $salePrice === false ? null : ($salePrice === null ? null : (float) $salePrice),
                $paymentAmount === false ? null : ($paymentAmount === null ? null : (float) $paymentAmount),
                $paymentMethod !== '' ? $paymentMethod : null,
                $paymentRef !== '' ? $paymentRef : null
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
            t.currentStage, t.salePrice, t.paymentMethod, t.createdAt, t.updatedAt,
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
$transactionRows = $transactions->fetch_all(MYSQLI_ASSOC);
$paymentRowsByTransaction = [];
$eventsByTransaction = [];
if ($transactionRows !== []) {
    $transactionIds = array_map(static fn(array $row): int => (int) $row['transactionID'], $transactionRows);
    $transactionIdList = implode(',', $transactionIds);
    $paymentRows = $conn->query(
        "SELECT paymentID, transactionID, clientID, amount, paymentMethod, referenceNumber,
                status, submittedAt, reviewNote
         FROM property_transaction_payments
         WHERE transactionID IN ($transactionIdList)
         ORDER BY submittedAt DESC, paymentID DESC"
    );
    while ($payment = $paymentRows->fetch_assoc()) {
        $paymentRowsByTransaction[(int) $payment['transactionID']][] = $payment;
    }

    $eventsQuery = $conn->query(
        "SELECT e.eventID, e.transactionID, e.stage, e.note, e.createdAt,
                u.fullname AS changedByName
         FROM property_transaction_events e
         LEFT JOIN users u ON u.userID = e.changedBy
         WHERE e.transactionID IN ($transactionIdList)
         ORDER BY e.createdAt DESC, e.eventID DESC"
    );
    while ($evt = $eventsQuery->fetch_assoc()) {
        $eventsByTransaction[(int) $evt['transactionID']][] = $evt;
    }
}
$stageLabels = property_transaction_stages();

include 'includes/header.php';
?>

<div class="transaction-tracking-page" id="transactions">
<?php if ($role !== 'Admin'): ?>
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-4">
    <div>
        <div class="page-eyebrow">Sales pipeline</div>
        <h1 class="page-title h2 mb-1">Transaction tracking</h1>
        <p class="text-muted mb-0">Record each verified milestone so clients can follow their request through completion.</p>
    </div>
    <a href="agent_dashboard.php" class="btn btn-outline-primary">Agent portal</a>
</div>
<?php endif; ?>

<div class="alert alert-info small" role="note">
    Transactions in this pipeline are completed through face-to-face paperwork and agreements. Click <strong>Record next step</strong> on any transaction below to advance milestones or record paperwork and payments.
</div>

<?php if ($formSuccess !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($formSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if ($formError !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<section class="card p-3 p-lg-4">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr>
                    <th>Tracking ref</th>
                    <th>Client / property</th>
                    <th>Current step</th>
                    <th>Last updated</th>
                    <th class="text-end">Record next step</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($transactionRows !== []): ?>
                <?php foreach ($transactionRows as $transaction):
                    $tId = (int) $transaction['transactionID'];
                    $currentStage = (string) $transaction['currentStage'];
                    $nextStages = property_transaction_next_stages($currentStage);
                    $location = !empty($transaction['municipalityName'])
                        ? $transaction['municipalityName'] . ', ' . $transaction['provinceName']
                        : $transaction['location'];
                ?>
                    <tr>
                        <td class="fw-semibold">#KQT-<?= $tId ?></td>
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
                            <span class="badge rounded-pill <?= $stageBadgeClass ?>"><?= htmlspecialchars($stageLabels[$currentStage] ?? 'Status unavailable', ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($currentStage === 'payment_in_process'): ?>
                                <div class="small text-muted mt-1" style="max-width: 170px; font-size: 0.75rem; line-height: 1.25;">
                                    <i class="bi bi-clock-history"></i> Agent inspecting / filling payment form
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars($transaction['updatedAt'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end">
                            <?php if ($nextStages !== [] || ($currentStage !== 'completed' && $currentStage !== 'cancelled')): ?>
                                <button type="button" class="btn btn-sm btn-primary fw-semibold d-inline-flex align-items-center gap-1 shadow-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#recordNextModal<?= $tId ?>" title="Open Record Next Step window">
                                    <span>📝</span>
                                    <span>Record next step</span>
                                </button>
                            <?php else: ?>
                                <div class="d-inline-flex align-items-center gap-1">
                                    <span class="badge text-bg-light border text-muted">Closed</span>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#recordNextModal<?= $tId ?>" title="View details">
                                        👁️ View
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No tracked inquiries or viewings yet. New client requests will appear here.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Record Next Step Modals -->
<?php if ($transactionRows !== []): ?>
    <?php foreach ($transactionRows as $transaction):
        $tId = (int) $transaction['transactionID'];
        $currentStage = (string) $transaction['currentStage'];
        $isTxSpotCash = strcasecmp((string)($transaction['paymentMethod'] ?? ''), 'Spot Cash') === 0;
        $nextStages = property_transaction_next_stages($currentStage, $isTxSpotCash ? 'Spot Cash' : null);
        // Ensure for payment_in_process both stages are available in DOM for dynamic JS switching
        if ($currentStage === 'payment_in_process') {
            $allStagesForProcess = ['document_verification', 'payment_confirmation', 'completed', 'cancelled'];
            $nextStages = array_values(array_unique(array_merge($nextStages, $allStagesForProcess)));
        }
        $location = !empty($transaction['municipalityName'])
            ? $transaction['municipalityName'] . ', ' . $transaction['provinceName']
            : $transaction['location'];
        $stageBadgeClass = match ($currentStage) {
            'completed' => 'text-bg-success',
            'cancelled' => 'text-bg-secondary',
            'payment_in_process' => 'text-bg-warning text-dark',
            'payment_confirmation' => 'text-bg-info text-dark',
            default => 'text-bg-primary',
        };
        $transactionPayments = $paymentRowsByTransaction[$tId] ?? [];
    ?>
        <div class="modal fade" id="recordNextModal<?= $tId ?>" tabindex="-1" aria-labelledby="recordNextModalLabel<?= $tId ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                    <div class="modal-header border-0 text-white" style="background: linear-gradient(135deg, #142d4e 0%, #174a70 100%); padding: 1.25rem 1.5rem;">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fs-5">📝</span>
                            <div>
                                <h5 class="modal-title h6 mb-0 text-white" id="recordNextModalLabel<?= $tId ?>">
                                    Record Next Step — #KQT-<?= $tId ?>
                                </h5>
                                <div class="small text-white-50">
                                    <?= htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body p-3 p-md-4 text-start">
                        <!-- Transaction Overview Card -->
                        <div class="card p-3 mb-3 bg-light border-0 rounded-3">
                            <div class="row g-2 align-items-center">
                                <div class="col-sm-6">
                                    <div class="small text-muted">Client</div>
                                    <strong class="text-dark fs-6"><?= htmlspecialchars($transaction['clientName'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars($transaction['clientEmail'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="small text-muted">Current Stage</div>
                                    <div>
                                        <span class="badge rounded-pill <?= $stageBadgeClass ?> px-3 py-1"><?= htmlspecialchars($stageLabels[$currentStage] ?? $currentStage, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="small text-muted mt-1">Last updated: <?= htmlspecialchars($transaction['updatedAt'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                            <?php if (!empty($transaction['appointmentDate'])): ?>
                                <div class="small text-muted mt-2 pt-2 border-top">
                                    📅 <strong>Viewing schedule:</strong> <?= htmlspecialchars($transaction['appointmentDate'] . ' at ' . substr($transaction['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?> · 📍 <?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($currentStage === 'completed' && $transaction['salePrice'] !== null): ?>
                                <div class="small fw-semibold text-success mt-2 pt-2 border-top">
                                    💰 <strong>Verified sale price:</strong> ₱<?= number_format((float) $transaction['salePrice'], 2) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Post-Viewing Stages Pipeline Stepper -->
                        <?php
                        $postViewingPipeline = [
                            'viewing_requested'   => '1. Viewing requested',
                            'viewing_confirmed'   => '2. Viewing confirmed',
                            'viewing_completed'   => '3. Viewing completed',
                            'payment_in_process'  => '4. Payment in process',
                            'document_verification' => '5. Document verification',
                            'payment_confirmation' => '6. Payment confirmation',
                            'completed'           => '7. Completed',
                        ];
                        if ($isTxSpotCash) {
                            $postViewingPipeline = [
                                'viewing_requested'   => '1. Viewing requested',
                                'viewing_confirmed'   => '2. Viewing confirmed',
                                'viewing_completed'   => '3. Viewing completed',
                                'payment_in_process'  => '4. Payment in process',
                                'payment_confirmation' => '5. Payment confirmation',
                                'completed'           => '6. Completed',
                            ];
                        }
                        $pipelineKeys = array_keys($postViewingPipeline);
                        $currentStageIdx = array_search($currentStage, $pipelineKeys, true);
                        ?>
                        <div class="border rounded-3 p-3 bg-white mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="small fw-bold text-dark"><i class="bi bi-diagram-3 me-1"></i> Transaction Pipeline</span>
                                <span class="badge text-bg-light border text-dark small fw-semibold">Step <?= $currentStageIdx !== false ? ($currentStageIdx + 1) : 0 ?> of <?= count($postViewingPipeline) ?></span>
                            </div>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($postViewingPipeline as $pKey => $pLabel):
                                    $stepIdx = array_search($pKey, $pipelineKeys, true);
                                    $isPassed = ($currentStageIdx !== false && $stepIdx < $currentStageIdx) || $currentStage === 'completed';
                                    $isCurrent = ($currentStageIdx !== false && $pKey === $currentStage) && $currentStage !== 'completed';
                                ?>
                                    <div class="d-flex align-items-center gap-1 px-2 py-1 rounded small <?= $isCurrent ? 'bg-primary text-white fw-bold shadow-sm' : ($isPassed ? 'bg-success text-white' : 'bg-light border text-secondary') ?>" style="font-size: 0.74rem;">
                                        <?php if ($isPassed): ?>
                                            <span>✓</span>
                                        <?php elseif ($isCurrent): ?>
                                            <span>●</span>
                                        <?php else: ?>
                                            <span>○</span>
                                        <?php endif; ?>
                                        <span><?= htmlspecialchars($pLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Stage Guidance Alert -->
                        <?php
                        $stageGuidance = [
                            'viewing_requested' => 'Client requested a site visit. Confirm or approve the viewing schedule.',
                            'viewing_confirmed' => 'Viewing is confirmed! Conduct the property site visit on schedule, then mark "Viewing completed".',
                            'viewing_completed' => 'Site visit was completed! Next, proceed to "Payment in process" once client settles the reservation deposit.',
                            'payment_in_process' => $isTxSpotCash
                                ? '⚡ Spot Cash selected: Document verification is bypassed. Prepare Deed of Absolute Sale (DOAS). Note: Title transfer and registration taxes are shouldered by the buyer.'
                                : '⏳ 7-Day Reservation Policy Active: Buyer has 7 days to complete down payment and IDs. Prepare Contract to Sell (CTS) for installment terms (or DOAS if Spot Cash). Note: Title transfer is shouldered by the buyer.',
                            'document_verification' => 'Review property title deeds, tax declarations, and government clearance documents. Once verified, advance to "Payment confirmation". The client will be notified that document verification is done.',
                            'payment_confirmation' => 'Payment and official paperwork are verified. Once final turnover documents are ready, mark as "Successful / completed".',
                            'completed' => 'Transaction successfully closed and property turned over to client.',
                            'cancelled' => 'This transaction has been cancelled.',
                        ];
                        $guidanceText = $stageGuidance[$currentStage] ?? 'Update transaction to the next verified milestone.';
                        ?>
                        <div class="alert alert-info small py-2 px-3 mb-3 d-flex align-items-start gap-2" role="status">
                            <span class="fs-5">ℹ️</span>
                            <div>
                                <strong>Agent Next Action:</strong> <?= htmlspecialchars($guidanceText, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>

                        <!-- Unified Advance Milestone Form -->
                        <?php if ($nextStages !== []):
                            $stageOptionLabels = [
                                'viewing_completed'     => '✓ 1. Mark Viewing as Completed (Site visit conducted on site)',
                                'payment_in_process'    => '💳 2. Payment in Process (Processing payment & paperwork)',
                                'document_verification' => '📑 3. Document Verification (Payment is done processing)',
                                'payment_confirmation'  => '✅ 4. Payment Confirmation (Documents verified / Spot Cash)',
                                'completed'             => '🏆 5. Mark as Successful / Completed (Final turnover & sale finalized)',
                                'cancelled'             => '❌ Cancel Transaction',
                                'viewing_confirmed'     => '📅 Confirm Viewing Schedule',
                                'contacting_client'     => '📞 Contacting Client',
                            ];
                            $defaultNextStage = $nextStages[0] ?? '';
                            if ($isTxSpotCash && $defaultNextStage === 'document_verification') {
                                $defaultNextStage = 'payment_confirmation';
                            }
                            $showPaymentFields = in_array($currentStage, ['viewing_completed', 'payment_in_process'], true)
                                || in_array($defaultNextStage, ['payment_in_process', 'document_verification', 'payment_confirmation'], true);
                        ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="fs-6">🚀</span>
                                    <h6 class="fw-bold mb-0 text-dark">Advance Stage &amp; Notify Client</h6>
                                </div>
                                <p class="small text-muted mb-3">Select the next verified stage. Once submitted, the system will record the milestone and immediately dispatch a notification alerting the client that the process for that stage is done.</p>

                                <form method="POST" class="d-grid gap-3" id="advanceForm<?= $tId ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Choose next step <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold stage-dropdown-trigger" data-tid="<?= $tId ?>" required>
                                            <option value="">Choose next step</option>
                                            <?php foreach ($nextStages as $idx => $nextStage):
                                                $isDocVerification = ($nextStage === 'document_verification');
                                                $hideDocOption = ($isDocVerification && $isTxSpotCash);
                                                $isSelected = ($nextStage === $defaultNextStage);
                                            ?>
                                                <option value="<?= htmlspecialchars($nextStage, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-skip-spot-cash="<?= $isDocVerification ? '1' : '0' ?>"
                                                    style="<?= $hideDocOption ? 'display: none;' : '' ?>"
                                                    <?= $hideDocOption ? 'disabled' : '' ?>
                                                    <?= $isSelected ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($stageOptionLabels[$nextStage] ?? $stageLabels[$nextStage], ENT_QUOTES, 'UTF-8') ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <!-- Optional Payment / Paperwork Details (Relevant during payment stages) -->
                                    <div class="card p-3 bg-light border-0 rounded-3" id="payment-fields-<?= $tId ?>" style="<?= $showPaymentFields ? '' : 'display: none;' ?>">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1">
                                            <span>💳</span> Record Payment / Paperwork Details <span class="text-muted fw-normal">(optional)</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-amount-<?= $tId ?>">Payment Amount (₱)</label>
                                                <input id="modal-payment-amount-<?= $tId ?>" type="number" name="paymentAmount" class="form-control form-control-sm" min="0" max="9999999999.99" step="0.01" placeholder="e.g. 50000.00">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-method-<?= $tId ?>">Payment Method</label>
                                                <select id="modal-payment-method-<?= $tId ?>" name="paymentMethod" class="form-select form-select-sm payment-method-trigger" data-tid="<?= $tId ?>">
                                                    <?php if (in_array(strtolower((string) ($transaction['paymentMethod'] ?? '')), ['gcash', 'maya', 'gcash / maya'], true)): ?>
                                                        <option value="" selected disabled>Existing payment method unavailable</option>
                                                    <?php endif; ?>
                                                    <option value="Spot Cash" <?= $isTxSpotCash ? 'selected' : '' ?>>⚡ Spot Cash (Document verification skipped)</option>
                                                    <option value="Bank Transfer" <?= strcasecmp((string)($transaction['paymentMethod'] ?? ''), 'Bank Transfer') === 0 ? 'selected' : '' ?>>🏦 Bank Transfer (Requires document verification)</option>
                                                    <option value="Check" <?= in_array(strtolower((string)($transaction['paymentMethod'] ?? '')), ['check', 'cheque'], true) ? 'selected' : '' ?>>📝 Check (Requires document verification)</option>
                                                    <option value="Paperwork (Face-to-face)" <?= empty($transaction['paymentMethod']) || strcasecmp((string)($transaction['paymentMethod'] ?? ''), 'Paperwork (Face-to-face)') === 0 ? 'selected' : '' ?>>🤝 Paperwork (Face-to-face agreement)</option>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <div class="alert alert-warning py-1 px-2 small mb-0 d-flex align-items-center gap-1 <?= $isTxSpotCash ? '' : 'd-none' ?>" id="spot-cash-notice-<?= $tId ?>">
                                                    <span class="fs-6">⚡</span>
                                                    <span><strong>Spot Cash selected:</strong> Document verification stage is automatically skipped.</span>
                                                </div>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-ref-<?= $tId ?>">Payment Reference / OR # (optional)</label>
                                                <input id="modal-payment-ref-<?= $tId ?>" type="text" name="paymentRef" class="form-control form-control-sm" maxlength="100" placeholder="e.g. OR-2026-001 or Down Payment Agreement Ref">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Verified Final Sale Price (Required when completing) -->
                                    <div class="card p-3 bg-light border-0 rounded-3" id="sale-price-fields-<?= $tId ?>" style="<?= $defaultNextStage === 'completed' ? '' : 'display: none;' ?>">
                                        <label class="form-label fw-bold text-dark small mb-1" for="modal-price-<?= $tId ?>">
                                            💰 Verified Sale Price (PHP) <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">₱</span>
                                            <input id="modal-price-<?= $tId ?>" type="number" name="salePrice" class="form-control" min="0.01" max="9999999999.99" step="0.01" placeholder="Actual agreed price" value="<?= $transaction['salePrice'] !== null ? htmlspecialchars((string) $transaction['salePrice'], ENT_QUOTES, 'UTF-8') : '' ?>">
                                        </div>
                                        <small class="text-muted">Required when finalizing transaction as Completed.</small>
                                    </div>

                                    <!-- Client-Visible Update Note -->
                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">Client-Visible Update Note</label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Payment is done processing; title deed and documents verified.">
                                        <small class="text-muted">This note will appear directly in the client's notification and progress timeline.</small>
                                    </div>

                                    <button type="submit" name="update_transaction" class="btn btn-primary fw-bold py-2 w-100 shadow-sm mt-1">
                                        🚀 Advance Stage &amp; Notify Client
                                    </button>
                                    <div class="text-center text-muted small mt-1">
                                        <i class="bi bi-bell-fill text-warning me-1"></i>
                                        The client will be automatically notified that the process for this stage is complete.
                                    </div>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-secondary small mb-3">
                                ℹ️ This transaction is marked as <strong><?= htmlspecialchars($stageLabels[$currentStage] ?? $currentStage, ENT_QUOTES, 'UTF-8') ?></strong> and has reached its final stage.
                            </div>
                        <?php endif; ?>

                        <!-- Payment Records Section (if any) -->
                        <?php if ($transactionPayments !== []): ?>
                            <div class="card p-3 border shadow-none bg-white mb-3">
                                <h6 class="fw-bold mb-2 text-dark">💳 Payment Records</h6>
                                <?php foreach ($transactionPayments as $payment): ?>
                                    <div class="border rounded-2 p-2 mb-2 small bg-light">
                                        <div class="d-flex justify-content-between gap-2">
                                            <strong>₱<?= number_format((float) $payment['amount'], 2) ?> · <?= htmlspecialchars($payment['paymentMethod'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span class="badge <?= $payment['status'] === 'confirmed' ? 'text-bg-success' : ($payment['status'] === 'rejected' ? 'text-bg-danger' : 'text-bg-warning') ?>">
                                                <?= htmlspecialchars(ucfirst($payment['status']), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                        <div class="text-muted small">Submitted <?= htmlspecialchars($payment['submittedAt'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($payment['referenceNumber'])): ?>
                                            <div>Reference: <?= htmlspecialchars($payment['referenceNumber'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($payment['reviewNote'])): ?>
                                            <div class="text-muted"><?= htmlspecialchars($payment['reviewNote'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if ($payment['status'] === 'pending'): ?>
                                            <form method="POST" class="d-grid gap-2 mt-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="paymentID" value="<?= (int) $payment['paymentID'] ?>">
                                                <input id="modal-payment-review-note-<?= (int) $payment['paymentID'] ?>" type="text" name="reviewNote" class="form-control form-control-sm" maxlength="1000" placeholder="Optional note for client">
                                                <div class="d-flex gap-2">
                                                    <button type="submit" name="paymentStatus" value="confirmed" class="btn btn-sm btn-success">Confirm</button>
                                                    <button type="submit" name="paymentStatus" value="rejected" class="btn btn-sm btn-outline-danger">Reject</button>
                                                </div>
                                                <input type="hidden" name="submit_payment_review" value="1">
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Milestone Timeline / Event History -->
                        <?php $txEvents = $eventsByTransaction[$tId] ?? []; ?>
                        <?php if ($txEvents !== []): ?>
                            <div class="card p-3 border shadow-none bg-white">
                                <h6 class="fw-bold mb-2 text-dark">📜 Milestone History</h6>
                                <div class="d-grid gap-2" style="max-height: 220px; overflow-y: auto;">
                                    <?php foreach ($txEvents as $evt): ?>
                                        <div class="border-start border-3 border-primary ps-2 py-1 small">
                                            <div class="d-flex justify-content-between gap-2">
                                                <strong class="text-dark"><?= htmlspecialchars($stageLabels[$evt['stage']] ?? ucfirst(str_replace('_', ' ', $evt['stage'])), ENT_QUOTES, 'UTF-8') ?></strong>
                                                <span class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($evt['createdAt'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="text-muted small"><?= htmlspecialchars($evt['note'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php if (!empty($evt['changedByName'])): ?>
                                                <div class="text-secondary" style="font-size: 0.7rem;">Updated by: <?= htmlspecialchars($evt['changedByName'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="modal-footer bg-light p-2 justify-content-between">
                        <span class="small text-muted">Updates are immediately reflected on the client's progress tracker.</span>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const stageNotePlaceholders = {
        'viewing_completed': 'e.g. Conducted site visit with client on site. Client confirmed interest in proceeding.',
        'payment_in_process': 'e.g. Reservation deposit agreed; preparing down payment paperwork.',
        'document_verification': 'e.g. Payment is done processing; title deed and documents verified.',
        'payment_confirmation': 'e.g. All legal clearances verified; finalizing official receipts.',
        'completed': 'e.g. Payment confirmed in full; deed of sale notarized and property turned over.',
        'cancelled': 'e.g. Client decided not to proceed.',
    };

    document.querySelectorAll('.stage-dropdown-trigger').forEach(select => {
        const tid = select.getAttribute('data-tid');
        const paymentBox = document.getElementById('payment-fields-' + tid);
        const salePriceBox = document.getElementById('sale-price-fields-' + tid);
        const salePriceInput = document.getElementById('modal-price-' + tid);
        const noteInput = document.getElementById('modal-note-' + tid);

        function updateModalFields(stage) {
            if (stageNotePlaceholders[stage] && noteInput && !noteInput.value) {
                noteInput.placeholder = stageNotePlaceholders[stage];
            }
            if (salePriceBox) {
                if (stage === 'completed') {
                    salePriceBox.style.display = '';
                    if (salePriceInput) salePriceInput.setAttribute('required', 'required');
                } else {
                    salePriceBox.style.display = 'none';
                    if (salePriceInput) salePriceInput.removeAttribute('required');
                }
            }
            if (paymentBox) {
                if (stage === 'payment_in_process' || stage === 'document_verification' || stage === 'payment_confirmation') {
                    paymentBox.style.display = '';
                } else {
                    paymentBox.style.display = 'none';
                }
            }
        }

        select.addEventListener('change', function() {
            updateModalFields(this.value);
        });

        if (select.value) {
            updateModalFields(select.value);
        }
    });

    document.querySelectorAll('.payment-method-trigger').forEach(paySelect => {
        const tid = paySelect.getAttribute('data-tid');
        const stageSelect = document.getElementById('modal-stage-' + tid);
        const spotCashNotice = document.getElementById('spot-cash-notice-' + tid);
        const noteInput = document.getElementById('modal-note-' + tid);

        function handlePaymentMethodChange(method) {
            const isSpotCash = (method || '').trim().toLowerCase() === 'spot cash';
            if (spotCashNotice) {
                if (isSpotCash) {
                    spotCashNotice.classList.remove('d-none');
                } else {
                    spotCashNotice.classList.add('d-none');
                }
            }

            if (stageSelect) {
                const docOpt = stageSelect.querySelector('option[data-skip-spot-cash="1"]');
                if (docOpt) {
                    if (isSpotCash) {
                        docOpt.style.display = 'none';
                        docOpt.disabled = true;
                        if (stageSelect.value === 'document_verification') {
                            stageSelect.value = 'payment_confirmation';
                            stageSelect.dispatchEvent(new Event('change'));
                        }
                    } else {
                        docOpt.style.display = '';
                        docOpt.disabled = false;
                        if (stageSelect.value === 'payment_confirmation' && (method.includes('Bank') || method.includes('Check'))) {
                            stageSelect.value = 'document_verification';
                            stageSelect.dispatchEvent(new Event('change'));
                        }
                    }
                }
            }

            if (isSpotCash && noteInput && !noteInput.value) {
                noteInput.placeholder = 'e.g. Spot Cash payment received. Document verification skipped; preparing deed turnover.';
            }
        }

        paySelect.addEventListener('change', function() {
            handlePaymentMethodChange(this.value);
        });

        if (paySelect.value) {
            handlePaymentMethodChange(paySelect.value);
        }
    });
});
</script>

<?php include 'includes/footer.php'; ?>
