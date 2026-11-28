<?php
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/transaction_tracking.php';
require_once 'includes/property_images.php';

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
if (isset($_GET['claimed'])) {
    $formSuccess = 'The transaction was claimed and assigned to your account.';
}
if (isset($_GET['agent_assigned'])) {
    $formSuccess = 'Sales Agent successfully assigned to transaction.';
}
$actorId = (int) $_SESSION['user']['userID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment_review'])) {
    $paymentId = filter_var($_POST['paymentID'] ?? null, FILTER_VALIDATE_INT);
    $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
    $status = is_string($_POST['paymentStatus'] ?? null) ? $_POST['paymentStatus'] : '';
    $reviewNote = is_string($_POST['reviewNote'] ?? null) ? trim($_POST['reviewNote']) : '';
    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } elseif ($paymentId === false || $paymentId <= 0) {
        $formError = 'Choose a valid payment.';
    } else {
        try {
            review_property_transaction_payment($conn, (int) $paymentId, $status, $reviewNote, $actorId);
            $openParam = ($transactionId && $transactionId > 0) ? '&open_modal=' . $transactionId : '';
            header('Location: transaction_tracking.php?updated=1' . $openParam . '#transactions');
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
            $openParam = ($transactionId && $transactionId > 0) ? '&open_modal=' . $transactionId : '';
            header('Location: transaction_tracking.php?updated=1' . $openParam . '#transactions');
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
    $invalidSalePrice = $postedSalePrice !== null && $postedSalePrice !== '' && $salePrice === false;
    $invalidPaymentAmount = $postedPaymentAmount !== null && $postedPaymentAmount !== '' && $paymentAmount === false;

    if (!has_valid_csrf_token()) {
        $formError = 'Your session expired. Refresh the page and try again.';
    } elseif ($transactionId === false || $transactionId <= 0) {
        $formError = 'Choose a valid transaction.';
    } elseif ($invalidSalePrice) {
        $formError = 'Enter a valid sale price or leave the field blank.';
    } elseif ($invalidPaymentAmount) {
        $formError = 'Enter a valid payment amount or leave the field blank.';
    } elseif ($paymentMethod !== '' && !in_array($paymentMethod, property_staff_payment_methods(), true)) {
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
            $openParam = ($transactionId && $transactionId > 0) ? '&open_modal=' . $transactionId : '';
            header('Location: transaction_tracking.php?updated=1' . $openParam . '#transactions');
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
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_agent_to_transaction'])) {
    if ($role !== 'Admin') {
        $formError = 'Only an Admin can assign Sales Agents to transactions.';
    } else {
        $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
        $assignedAgentId = filter_var($_POST['assignedAgentID'] ?? null, FILTER_VALIDATE_INT);

        if (!has_valid_csrf_token()) {
            $formError = 'Your session expired. Refresh the page and try again.';
        } elseif ($transactionId === false || $transactionId <= 0 || $assignedAgentId === false || $assignedAgentId <= 0) {
            $formError = 'Choose a valid transaction and active Sales Agent.';
        } else {
            try {
                // Verify active agent
                $agentStmt = $conn->prepare("SELECT fullname, email FROM users WHERE userID = ? AND role IN ('Agent', 'Sales Agent') AND accountStatus = 'Active' LIMIT 1");
                $agentStmt->bind_param('i', $assignedAgentId);
                $agentStmt->execute();
                $agent = $agentStmt->get_result()->fetch_assoc();
                if (!$agent) {
                    throw new InvalidArgumentException('The selected Sales Agent is inactive or is not authorized.');
                }

                // Get transaction details
                $tStmt = $conn->prepare("SELECT transactionID, propertyID, clientID, appointmentID, inquiryID, currentStage FROM property_transactions WHERE transactionID = ? LIMIT 1");
                $tStmt->bind_param('i', $transactionId);
                $tStmt->execute();
                $tRow = $tStmt->get_result()->fetch_assoc();
                if (!$tRow) {
                    throw new InvalidArgumentException('Transaction not found.');
                }

                $conn->begin_transaction();

                // If transaction is linked to an appointment, assign agent
                if (!empty($tRow['appointmentID'])) {
                    $apptStmt = $conn->prepare("UPDATE appointments SET agentID = ? WHERE appointmentID = ?");
                    $apptStmt->bind_param('ii', $assignedAgentId, $tRow['appointmentID']);
                    $apptStmt->execute();
                }

                // If transaction is linked to an inquiry, assign agent in agent_inquiry_assignments
                if (!empty($tRow['inquiryID'])) {
                    $inqStmt = $conn->prepare("INSERT INTO agent_inquiry_assignments (inquiryID, agentID, assignedAt) VALUES (?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE agentID = ?, assignedAt = CURRENT_TIMESTAMP");
                    $inqStmt->bind_param('iii', $tRow['inquiryID'], $assignedAgentId, $assignedAgentId);
                    $inqStmt->execute();
                }

                $assignTransactionStmt = $conn->prepare(
                    'UPDATE property_transactions SET assignedAgentID = ? WHERE transactionID = ?'
                );
                $assignTransactionStmt->bind_param('ii', $assignedAgentId, $transactionId);
                $assignTransactionStmt->execute();

                $assignNote = 'Administrator manually assigned Sales Agent ' . $agent['fullname'] . ' to handle this transaction.';
                add_property_transaction_event($conn, (int) $transactionId, (string) $tRow['currentStage'], $assignNote, $actorId);

                // Notify the assigned agent
                send_property_notification(
                    $conn,
                    $assignedAgentId,
                    'Assigned to Transaction #KQT-' . $transactionId,
                    'You have been assigned to manage Transaction #KQT-' . $transactionId . ' by the administrator.',
                    'transaction_tracking.php#transactions',
                    'agent_assignment'
                );

                // Notify the client
                send_property_notification(
                    $conn,
                    (int) $tRow['clientID'],
                    'Sales Agent assigned',
                    'Sales Agent ' . $agent['fullname'] . ' has been assigned to assist you with your property transaction #KQT-' . $transactionId . '.',
                    'client_dashboard.php#transaction-tracking',
                    'agent_assigned'
                );

                $conn->commit();
                $openParam = ($transactionId && $transactionId > 0) ? '&open_modal=' . $transactionId : '';
                header('Location: transaction_tracking.php?agent_assigned=1' . $openParam . '#transactions');
                exit();
            } catch (Throwable $ex) {
                $conn->rollback();
                error_log('Agent assignment failed: ' . $ex->getMessage());
                $formError = $ex->getMessage();
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['claim_transaction'])) {
    if ($role !== 'Agent' && $role !== 'Sales Agent' && $role !== 'Admin') {
        $formError = 'Only an authorized Sales Agent or Admin can claim transactions.';
    } else {
        $transactionId = filter_var($_POST['transactionID'] ?? null, FILTER_VALIDATE_INT);
        if (!has_valid_csrf_token()) {
            $formError = 'Your session expired. Refresh the page and try again.';
        } elseif ($transactionId === false || $transactionId <= 0) {
            $formError = 'Choose a valid transaction to claim.';
        } else {
            try {
                claim_property_transaction($conn, (int) $transactionId, $actorId);
                if ($role === 'Admin') {
                    $openParam = ($transactionId && $transactionId > 0) ? '&open_modal=' . $transactionId : '';
                    header('Location: transaction_tracking.php?claimed=1' . $openParam . '#transactions');
                } else {
                    header('Location: agent_dashboard.php?claimed=1#agent-transactions');
                }
                exit();
            } catch (Throwable $ex) {
                error_log('Transaction claim failed: ' . $ex->getMessage());
                $formError = $ex->getMessage();
            }
        }
    }
}

if ($formError !== '') {
    $formSuccess = '';
}

$activeAgentsList = [];
if ($role === 'Admin') {
    $agentsQuery = $conn->query("SELECT userID, fullname, email FROM users WHERE role IN ('Agent', 'Sales Agent') AND accountStatus = 'Active' ORDER BY fullname ASC");
    if ($agentsQuery) {
        $activeAgentsList = $agentsQuery->fetch_all(MYSQLI_ASSOC);
    }
}

$transactionsSql =
    "SELECT t.transactionID, t.propertyID, t.clientID, t.appointmentID, t.inquiryID,
            t.currentStage, t.salePrice, t.paymentMethod, t.createdAt, t.updatedAt,
            p.title, p.location, p.image, p.propertyType, p.price AS listingPrice, l.municipalityName, l.provinceName,
            u.fullname AS clientName, u.email AS clientEmail,
            a.appointmentDate, a.appointmentTime,
            COALESCE(t.assignedAgentID, a.agentID, ia.agentID) AS assignedAgentID,
            COALESCE(transactionAgent.fullname, agentUser.fullname, inqAgentUser.fullname, 'Unassigned') AS assignedAgentName
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     JOIN users u ON u.userID = t.clientID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     LEFT JOIN users agentUser ON agentUser.userID = a.agentID
     LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
     LEFT JOIN users inqAgentUser ON inqAgentUser.userID = ia.agentID
     LEFT JOIN users transactionAgent ON transactionAgent.userID = t.assignedAgentID";
if ($role === 'Admin') {
    $transactions = $conn->query(
        $transactionsSql . "
         ORDER BY (t.currentStage IN ('completed', 'cancelled')) ASC, t.updatedAt DESC, t.transactionID DESC
         LIMIT 300"
    );
} else {
    $transactionsStmt = $conn->prepare(
        $transactionsSql . "
         WHERE ((t.assignedAgentID = ? OR a.agentID = ? OR ia.agentID = ?)
             OR (t.assignedAgentID IS NULL AND a.agentID IS NULL AND ia.agentID IS NULL))
         ORDER BY (t.currentStage IN ('completed', 'cancelled')) ASC, t.updatedAt DESC, t.transactionID DESC
         LIMIT 300"
    );
    $transactionsStmt->bind_param('iii', $actorId, $actorId, $actorId);
    $transactionsStmt->execute();
    $transactions = $transactionsStmt->get_result();
    $transactionsStmt->close();
}
$transactionRows = $transactions->fetch_all(MYSQLI_ASSOC);
$paymentRowsByTransaction = [];
$paymentSummariesByTransaction = [];
$eventsByTransaction = [];
if ($transactionRows !== []) {
    $transactionIds = array_map(static fn(array $row): int => (int) $row['transactionID'], $transactionRows);
    $transactionIdList = implode(',', $transactionIds);
    $paymentRowsByTransaction = get_transaction_payment_records($conn, $transactionIds);
    $paymentSummariesByTransaction = get_transaction_payment_summaries($conn, $transactionIds);

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
<header class="kq-dashboard-hero mb-4" id="transaction-overview">
    <img class="kq-official-logo kq-logo-dashboard kq-logo-on-dark" src="assets/images/kings-land-properties-logo-light.png" alt="4 Kings Realty Services">
    <div class="kq-dashboard-hero-copy">
        <div class="page-eyebrow">SALES PIPELINE</div>
        <h1 class="page-title h2 mb-1">Transaction tracking</h1>
        <p class="text-muted mb-0">Record each verified milestone so clients can follow their request through completion.</p>
    </div>
</header>
<?php endif; ?>

<div class="alert alert-info small" role="note">
    Transactions in this pipeline are completed through face-to-face paperwork and agreements. Record and verify payments before payment confirmation; verified payments must cover the sale price before completion. Click <strong>Record next step</strong> on any transaction below to advance milestones or record paperwork and payments.
</div>

<?php if ($formError !== ''): ?><div class="alert alert-danger" role="alert"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div><?php elseif ($formSuccess !== ''): ?><div class="alert alert-success" role="status"><?= htmlspecialchars($formSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

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
                    $propImage = basename((string) ($transaction['image'] ?? ''));
                    if ($propImage === '' || $propImage === 'default.jpg' || !is_file(__DIR__ . '/assets/images/' . $propImage)) {
                        $propImage = function_exists('property_category_image') ? (property_category_image((string) ($transaction['propertyType'] ?? '')) ?? '') : '';
                    }
                    $hasPropImage = $propImage !== '' && is_file(__DIR__ . '/assets/images/' . $propImage);
                    $propImgSrc = $hasPropImage ? ('assets/images/' . rawurlencode($propImage)) : 'assets/images/residential-property-01.jpg';
                    $txSummary = $paymentSummariesByTransaction[$tId] ?? ['verifiedTotal' => 0.0, 'verifiedCount' => 0, 'pendingTotal' => 0.0, 'pendingCount' => 0];
                    $txVerifiedPaid = (float) $txSummary['verifiedTotal'];
                    $txTargetPrice = $transaction['salePrice'] !== null ? (float) $transaction['salePrice'] : (float) ($transaction['listingPrice'] ?? 0);
                    $txRemaining = $txTargetPrice > 0 ? max($txTargetPrice - $txVerifiedPaid, 0.0) : 0.0;
                ?>
                    <tr>
                        <td class="fw-semibold">#KQT-<?= $tId ?></td>
                        <td>
                            <div class="d-flex align-items-start gap-3">
                                <div class="rounded-3 overflow-hidden flex-shrink-0 border shadow-sm position-relative" style="width: 64px; height: 64px; background: rgba(0,0,0,0.04);">
                                    <img src="<?= htmlspecialchars($propImgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100" style="object-fit: cover;" loading="lazy" onerror="this.onerror=null;this.src='assets/images/residential-property-01.jpg';">
                                </div>
                                <div class="flex-grow-1">
                                    <strong><?= htmlspecialchars($transaction['clientName'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars($transaction['clientEmail'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 fw-semibold text-dark"><?= htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($transaction['appointmentDate'])): ?>
                                        <div class="small text-muted">Viewing schedule: <?= htmlspecialchars($transaction['appointmentDate'] . ' ' . substr($transaction['appointmentTime'], 0, 5), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($transaction['inquiryID'])): ?>
                                        <div class="small text-muted">Inquiry #<?= (int) $transaction['inquiryID'] ?></div>
                                    <?php endif; ?>
                                    <div class="small mt-1">
                                        <span class="text-muted">Assigned Sales Agent: </span>
                                        <?php if (!empty($transaction['assignedAgentName']) && $transaction['assignedAgentName'] !== 'Unassigned'): ?>
                                            <span class="badge text-bg-light border text-dark fw-semibold">👤 <?= htmlspecialchars($transaction['assignedAgentName'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="badge text-bg-warning text-dark">⚠️ Unassigned Sales Agent</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($currentStage === 'completed' && $transaction['salePrice'] !== null): ?>
                                        <div class="small fw-semibold text-success mt-1">Verified sale: ₱<?= number_format((float) $transaction['salePrice'], 2) ?> · Paid: ₱<?= number_format($txVerifiedPaid, 2) ?></div>
                                    <?php elseif ($txVerifiedPaid > 0 || in_array($currentStage, ['payment_in_process', 'document_verification', 'payment_confirmation'], true)): ?>
                                        <div class="small text-muted mt-1">
                                            Paid: <strong class="text-success">₱<?= number_format($txVerifiedPaid, 2) ?></strong>
                                            <?php if ($txTargetPrice > 0): ?>
                                                · Balance: <strong>₱<?= number_format($txRemaining, 2) ?></strong>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php
                            $stageBadgeClass = property_transaction_stage_badge_class($currentStage);
                            ?>
                            <span class="badge rounded-pill <?= $stageBadgeClass ?>"><?= htmlspecialchars(property_transaction_stage_label($currentStage), ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($currentStage === 'payment_in_process'): ?>
                                <div class="small text-muted mt-1" style="max-width: 170px; font-size: 0.75rem; line-height: 1.25;">
                                    <i class="bi bi-clock-history"></i> Sales Agent reviewing payment information
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= htmlspecialchars($transaction['updatedAt'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex align-items-center gap-1 flex-wrap justify-content-end">
                                <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 shadow-sm text-nowrap" onclick="openDirectChat(<?= $tId ?>, '<?= addslashes(htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8')) ?>', '<?= addslashes(htmlspecialchars($transaction['clientName'], ENT_QUOTES, 'UTF-8')) ?>')" title="Direct chat with <?= htmlspecialchars($transaction['clientName'], ENT_QUOTES, 'UTF-8') ?>">
                                    <span>💬 Chat</span>
                                </button>
                                <?php if (empty($transaction['assignedAgentID']) && ($role === 'Agent' || $role === 'Sales Agent')): ?>
                                    <form method="post" action="transaction_tracking.php" class="d-inline" data-confirm="Claim this transaction request? It will be assigned to your account.">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="transactionID" value="<?= $tId ?>">
                                        <button type="submit" name="claim_transaction" class="btn btn-sm btn-success fw-semibold shadow-sm text-nowrap">
                                            <span>✋ Claim</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
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
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" class="text-center text-muted py-4">No tracked inquiries or viewing requests yet. New client requests will appear here.</td></tr>
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
        // Keep both method-dependent choices available so the client-side selector can switch them.
        if ($currentStage === 'payment_in_process') {
            $methodDependentStages = ['document_verification', 'payment_confirmation'];
            $nextStages = array_values(array_unique(array_merge($nextStages, $methodDependentStages)));
        }
        $location = !empty($transaction['municipalityName'])
            ? $transaction['municipalityName'] . ', ' . $transaction['provinceName']
            : $transaction['location'];
        $stageBadgeClass = match ($currentStage) {
            'completed' => 'text-bg-success',
            'cancelled' => 'text-bg-secondary',
            'reservation_requested' => 'text-bg-warning text-dark',
            'reservation_confirmed' => 'text-bg-success',
            'payment_in_process' => 'text-bg-warning text-dark',
            'payment_confirmation' => 'text-bg-info text-dark',
            default => 'text-bg-primary',
        };
        $transactionPayments = $paymentRowsByTransaction[$tId] ?? [];
        $txSummary = $paymentSummariesByTransaction[$tId] ?? ['verifiedTotal' => 0.0, 'verifiedCount' => 0, 'pendingTotal' => 0.0, 'pendingCount' => 0];
        $txVerifiedPaid = (float) $txSummary['verifiedTotal'];
        $txPendingPaid = (float) $txSummary['pendingTotal'];
        $txListingPrice = (float) ($transaction['listingPrice'] ?? 0);
        $txTargetPrice = $transaction['salePrice'] !== null ? (float) $transaction['salePrice'] : $txListingPrice;
        $txRemaining = $txTargetPrice > 0 ? max($txTargetPrice - $txVerifiedPaid, 0.0) : 0.0;
        $txPaidPercent = $txTargetPrice > 0 ? min(100, (int) round(($txVerifiedPaid / $txTargetPrice) * 100)) : 0;
        $propImage = basename((string) ($transaction['image'] ?? ''));
        if ($propImage === '' || $propImage === 'default.jpg' || !is_file(__DIR__ . '/assets/images/' . $propImage)) {
            $propImage = function_exists('property_category_image') ? (property_category_image((string) ($transaction['propertyType'] ?? '')) ?? '') : '';
        }
        $hasPropImage = $propImage !== '' && is_file(__DIR__ . '/assets/images/' . $propImage);
        $propImgSrc = $hasPropImage ? ('assets/images/' . rawurlencode($propImage)) : 'assets/images/residential-property-01.jpg';
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
                        <?php if ((int)($_GET['open_modal'] ?? 0) === $tId && $formSuccess !== ''): ?>
                            <div class="alert alert-success alert-dismissible fade show py-2 mb-3 shadow-sm small" role="alert">
                                <i class="bi bi-check-circle-fill me-1"></i> <?= htmlspecialchars($formSuccess, ENT_QUOTES, 'UTF-8') ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php elseif ((int)($_POST['transactionID'] ?? 0) === $tId && $formError !== ''): ?>
                            <div class="alert alert-danger alert-dismissible fade show py-2 mb-3 shadow-sm small" role="alert">
                                <i class="bi bi-exclamation-triangle-fill me-1"></i> <?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <!-- Transaction Overview Card -->
                        <div class="card p-3 mb-3 bg-light border-0 rounded-3">
                            <div class="d-flex align-items-center gap-3 mb-3 pb-2 border-bottom">
                                <div class="rounded-3 overflow-hidden flex-shrink-0 border shadow-sm position-relative" style="width: 58px; height: 58px; background: rgba(0,0,0,0.04);">
                                    <img src="<?= htmlspecialchars($propImgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100" style="object-fit: cover;" onerror="this.onerror=null;this.src='assets/images/residential-property-01.jpg';">
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <h6 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8') ?></h6>
                                    <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                            <div class="row g-2 align-items-center">
                                <div class="col-sm-6">
                                    <div class="small text-muted">Client</div>
                                    <strong class="text-dark fs-6"><?= htmlspecialchars($transaction['clientName'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars($transaction['clientEmail'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <div class="col-sm-6">
                                    <div class="small text-muted">Current Stage</div>
                                    <div>
                                        <span class="badge rounded-pill <?= $stageBadgeClass ?> px-3 py-1"><?= htmlspecialchars(property_transaction_stage_label($currentStage), ENT_QUOTES, 'UTF-8') ?></span>
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
                            <div class="small mt-2 pt-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <span class="text-muted">Assigned Sales Agent:</span>
                                <strong>👤 <?= htmlspecialchars($transaction['assignedAgentName'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        </div>

                        <?php if ($txVerifiedPaid > 0 || $txPendingPaid > 0 || in_array($currentStage, ['viewing_completed', 'payment_in_process', 'document_verification', 'payment_confirmation', 'completed'], true)): ?>
                            <div class="card p-3 mb-3 border rounded-3 bg-white shadow-none">
                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                    <span class="small fw-bold text-dark">💰 Payment Summary</span>
                                    <span class="small text-muted">
                                        <?= $txTargetPrice > 0 ? ($txPaidPercent . '% paid') : ($txSummary['verifiedCount'] . ' verified payment(s)') ?>
                                    </span>
                                </div>
                                <div class="row g-2 small text-center mb-2">
                                    <div class="col-4">
                                        <div class="p-2 rounded-2 bg-light border">
                                            <div class="text-muted" style="font-size: 0.72rem;"><?= $transaction['salePrice'] !== null ? 'Agreed Price' : 'Listing Price' ?></div>
                                            <div class="fw-bold text-dark">₱<?= number_format($txTargetPrice, 2) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-2 rounded-2 bg-light border">
                                            <div class="text-muted" style="font-size: 0.72rem;">Verified Paid</div>
                                            <div class="fw-bold text-success">₱<?= number_format($txVerifiedPaid, 2) ?></div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="p-2 rounded-2 bg-light border">
                                            <div class="text-muted" style="font-size: 0.72rem;">Remaining</div>
                                            <div class="fw-bold <?= $txRemaining <= 0.01 && $txTargetPrice > 0 ? 'text-success' : 'text-warning-emphasis' ?>">
                                                <?= ($txRemaining <= 0.01 && $txTargetPrice > 0) ? 'Fully paid' : ('₱' . number_format($txRemaining, 2)) ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($txTargetPrice > 0): ?>
                                    <div class="progress" style="height: 0.5rem;" role="progressbar" aria-valuenow="<?= $txPaidPercent ?>" aria-valuemin="0" aria-valuemax="100">
                                        <div class="progress-bar bg-success" style="width: <?= $txPaidPercent ?>%"></div>
                                    </div>
                                <?php endif; ?>
                                <?php if ($txPendingPaid > 0): ?>
                                    <div class="small text-warning-emphasis mt-2">⏳ Pending verification: <strong>₱<?= number_format($txPendingPaid, 2) ?></strong></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex gap-2 mb-3 flex-wrap">
                            <button type="button" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1 shadow-sm" onclick="openDirectChat(<?= $tId ?>, '<?= addslashes(htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8')) ?>', '<?= addslashes(htmlspecialchars($transaction['clientName'], ENT_QUOTES, 'UTF-8')) ?>')">
                                <span>💬 Direct Message Client</span>
                            </button>
                            <?php if (empty($transaction['assignedAgentID']) && ($role === 'Agent' || $role === 'Sales Agent')): ?>
                                <form method="post" action="transaction_tracking.php" class="d-inline" data-confirm="Claim this transaction request? It will be assigned to your account.">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">
                                    <button type="submit" name="claim_transaction" class="btn btn-sm btn-success fw-semibold shadow-sm">
                                        <span>✋ Claim & Assign to Me</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <!-- Admin Agent Assignment Dispatch (Admin Only) -->
                        <?php if ($role === 'Admin'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white rounded-3">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <span class="small fw-bold text-dark"><i class="bi bi-person-gear me-1"></i> Sales Agent assignment</span>
                                    <span class="badge <?= (!empty($transaction['assignedAgentName']) && $transaction['assignedAgentName'] !== 'Unassigned') ? 'text-bg-success' : 'text-bg-warning text-dark' ?>">
                                        <?= htmlspecialchars($transaction['assignedAgentName'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </div>
                                <form method="POST" class="d-flex flex-column flex-sm-row gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">
                                    <select name="assignedAgentID" class="form-select form-select-sm" required>
                                        <option value="">-- Choose a Sales Agent --</option>
                                        <?php foreach ($activeAgentsList as $actAgent): ?>
                                            <option value="<?= (int) $actAgent['userID'] ?>" <?= ((int) ($transaction['assignedAgentID'] ?? 0) === (int) $actAgent['userID']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($actAgent['fullname'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($actAgent['email'], ENT_QUOTES, 'UTF-8') ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" name="assign_agent_to_transaction" class="btn btn-sm btn-outline-primary text-nowrap fw-semibold">
                                        Assign Sales Agent
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <!-- Stage Guidance Alert (for subsequent milestone stages) -->
                        <?php if (!in_array($currentStage, ['reservation_requested', 'viewing_requested'], true)):
                            $stageGuidance = [
                                'reservation_confirmed' => 'Reservation confirmed (7-day hold active). The client can now schedule a property viewing, or you can coordinate/confirm the viewing schedule directly below.',
                                'reviewing_schedule' => 'Viewing request accepted. Review and confirm the viewing schedule with the client, then confirm the schedule.',
                                'viewing_confirmed' => 'The viewing schedule is confirmed. Conduct the property viewing as scheduled, then mark it as completed.',
                                'viewing_completed' => 'The property viewing is complete. Proceed to "Payment in process" once the client settles their down payment / balance terms.',
                                'payment_in_process' => $isTxSpotCash
                                    ? '⚡ Spot Cash selected: Document verification is bypassed. Prepare Deed of Absolute Sale (DOAS). Note: Title transfer and registration taxes are shouldered by the buyer.'
                                    : 'Prepare Contract to Sell (CTS) for installment terms (or DOAS if Spot Cash) and verify down payment settlement. Note: Title transfer is shouldered by the buyer.',
                                'document_verification' => 'Review property title deeds, tax declarations, and government clearance documents. Once verified, advance to "Payment confirmation". The client will be notified that document verification is done.',
                                'payment_confirmation' => 'Payment and official paperwork are verified. Once final turnover documents are ready, mark as "Successful / completed".',
                                'completed' => 'Transaction successfully closed and property turned over to client.',
                                'cancelled' => 'This transaction has been cancelled.',
                            ];
                            $guidanceText = $stageGuidance[$currentStage] ?? '';
                            if ($guidanceText !== ''):
                        ?>
                            <div class="alert alert-info small py-2 px-3 mb-3 d-flex align-items-start gap-2" role="status">
                                <span class="fs-5">ℹ️</span>
                                <div>
                                    <strong>Sales Agent next action:</strong> <?= htmlspecialchars($guidanceText, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </div>
                        <?php endif; endif; ?>

                        <?php if ($currentStage === 'reservation_confirmed'):
                            $stageUpdateTs = !empty($transaction['updatedAt']) ? strtotime($transaction['updatedAt']) : time();
                            $daysElapsed = (int) floor((time() - $stageUpdateTs) / 86400);
                            $daysLeft = max(0, 7 - $daysElapsed);
                        ?>
                            <div class="p-2 px-3 rounded-3 mb-3 bg-warning-subtle border border-warning small d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fs-5">⏳</span>
                                    <div>
                                        <strong class="text-dark">7-Day Reservation Hold Status:</strong>
                                        <div class="text-muted" style="font-size: 0.78rem;">
                                            <?php if ($daysLeft > 0): ?>
                                                Active reservation window: <strong><?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?> remaining</strong> for viewing, down payment, and compliance IDs before release.
                                            <?php else: ?>
                                                7-day reservation window elapsed. Check if client requested an extension or if property should be released.
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="badge <?= $daysLeft > 0 ? 'text-bg-warning text-dark' : 'text-bg-danger text-white' ?> fw-bold">
                                    <?= $daysLeft > 0 ? ($daysLeft . ' Day' . ($daysLeft === 1 ? '' : 's') . ' Left') : 'Window Elapsed' ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <!-- Agreed Payment Scheme Information -->
                        <?php
                        $agreedMethod = (string) ($transaction['paymentMethod'] ?? '');
                        $allSchemes = property_transaction_payment_schemes();
                        $schemeDetails = $allSchemes[$agreedMethod] ?? null;
                        if ($schemeDetails !== null):
                        ?>
                        <div class="card p-3 mb-3 border shadow-none bg-light rounded-3">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <span class="small text-muted fw-semibold"><i class="bi bi-wallet2 me-1"></i> Agreed Payment Scheme:</span>
                                <span class="badge text-bg-success px-2 py-1"><?= htmlspecialchars($schemeDetails['badge'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="small text-muted mt-1"><?= htmlspecialchars($schemeDetails['desc'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <?php endif; ?>

                        <!-- Milestone-Specific Functional Forms -->
                        <?php if ($currentStage === 'reservation_requested'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="fs-5">🔖</span>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">Step 1: Pre-Viewing Reservation Decision</h6>
                                        <div class="small text-muted">Confirm the client's reservation (and record reservation fee if settled) so they can schedule a property viewing.</div>
                                    </div>
                                </div>

                                <form method="POST" action="transaction_tracking.php" class="d-grid gap-3" id="advanceForm<?= $tId ?>" data-confirm="Confirm this reservation decision and notify the client?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Action <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" required>
                                            <option value="reservation_confirmed" selected>✅ Confirm Reservation (Start 7-Day Hold &amp; Unlock Viewing)</option>
                                            <option value="cancelled">❌ Cancel Reservation</option>
                                        </select>
                                    </div>

                                    <div class="card p-3 bg-light border-0 rounded-3">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1">
                                            <span>💳</span> Record Reservation Fee Receipt <span class="text-muted fw-normal">(optional)</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-res-amount-<?= $tId ?>">Reservation Amount (₱)</label>
                                                <input id="modal-res-amount-<?= $tId ?>" type="number" name="paymentAmount" class="form-control form-control-sm" min="0" max="9999999999.99" step="0.01" placeholder="e.g. 20000.00">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-res-method-<?= $tId ?>">Payment Scheme</label>
                                                <select id="modal-res-method-<?= $tId ?>" name="paymentMethod" class="form-select form-select-sm">
                                                    <?php foreach ($allSchemes as $sKey => $sVal): ?>
                                                        <option value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($agreedMethod, $sKey) === 0 ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($sVal['badge'], ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-res-ref-<?= $tId ?>">Official Receipt (OR) / Bank Ref #</label>
                                                <input id="modal-res-ref-<?= $tId ?>" type="text" name="paymentRef" class="form-control form-control-sm" maxlength="100" placeholder="e.g. OR-RES-2026-001">
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">
                                            Reservation Note <span class="text-muted fw-normal">(optional)</span>
                                        </label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Reservation verified. 7-day hold active; buyer may schedule viewing.">
                                    </div>

                                    <button type="submit" name="update_transaction" class="btn btn-success fw-bold py-2 w-100 shadow-sm mt-1">
                                        ✅ Confirm Reservation &amp; Unlock Viewing
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($currentStage === 'reservation_confirmed'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="fs-5">📅</span>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">Step 2: Schedule Property Viewing</h6>
                                        <div class="small text-muted">Reservation confirmed. The client can request a viewing date on the listing, or you can advance the viewing step directly.</div>
                                    </div>
                                </div>

                                <form method="POST" action="transaction_tracking.php" class="d-grid gap-3" id="advanceForm<?= $tId ?>" data-confirm="Update viewing step and notify the client?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Next Action <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" required>
                                            <option value="reviewing_schedule" selected>🗓️ Coordinate Viewing Schedule (Reviewing schedule)</option>
                                            <option value="viewing_confirmed">📅 Confirm &amp; Approve Viewing Schedule</option>
                                            <option value="cancelled">❌ Cancel Reservation / Release Property</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">
                                            Schedule Note <span class="text-muted fw-normal">(optional)</span>
                                        </label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Coordinating site visit date with reserved buyer.">
                                    </div>

                                    <button type="submit" name="update_transaction" class="btn btn-primary fw-bold py-2 w-100 shadow-sm mt-1">
                                        📅 Update Viewing Step &amp; Notify Client
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($currentStage === 'viewing_requested'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="fs-5">📅</span>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">Step 1: Viewing Request Decision</h6>
                                        <div class="small text-muted">The client submitted a property viewing request.</div>
                                    </div>
                                </div>

                                <form method="POST" action="transaction_tracking.php" class="d-grid gap-3" id="advanceForm<?= $tId ?>" data-confirm="Submit viewing response and notify the client?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Action <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" required>
                                            <option value="reviewing_schedule" selected>✓ Accept viewing (Reviewing schedule)</option>
                                            <option value="cancelled">❌ Cancel viewing</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">
                                            Additional Message / Note <span class="text-muted fw-normal">(optional)</span>
                                        </label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Viewing request received. Reviewing calendar with our team.">
                                        <small class="text-muted">On acceptance, this will be recorded as &ldquo;Viewing accepted by <?= htmlspecialchars($_SESSION['user']['fullname'] ?? 'Agent', ENT_QUOTES, 'UTF-8') ?>&rdquo;.</small>
                                    </div>

                                    <button type="submit" name="update_transaction" class="btn btn-primary fw-bold py-2 w-100 shadow-sm mt-1">
                                        ✓ Accept Viewing &amp; Review Schedule
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($currentStage === 'reviewing_schedule'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="fs-5">🗓️</span>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">Step 2: Coordinate &amp; Confirm Viewing Schedule</h6>
                                        <div class="small text-muted">Viewing accepted. Align on meeting time with the client, then approve the schedule.</div>
                                    </div>
                                </div>

                                <form method="POST" action="transaction_tracking.php" class="d-grid gap-3" id="advanceForm<?= $tId ?>" data-confirm="Confirm schedule and notify the client?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Milestone Action <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" required>
                                            <option value="viewing_confirmed" selected>📅 1. Confirm &amp; Approve Viewing Schedule</option>
                                            <option value="viewing_completed">✓ 2. Mark viewing as already conducted</option>
                                            <option value="cancelled">❌ Cancel viewing</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">
                                            Schedule Details / Note <span class="text-muted fw-normal">(optional)</span>
                                        </label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Schedule confirmed for Wednesday at 2:00 PM at site entrance.">
                                        <small class="text-muted">Recorded as &ldquo;Viewing schedule confirmed by <?= htmlspecialchars($_SESSION['user']['fullname'] ?? 'Agent', ENT_QUOTES, 'UTF-8') ?>&rdquo;.</small>
                                    </div>

                                    <button type="submit" name="update_transaction" class="btn btn-primary fw-bold py-2 w-100 shadow-sm mt-1">
                                        📅 Confirm Viewing Schedule &amp; Notify Client
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($currentStage === 'viewing_confirmed'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="fs-5">🚶</span>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">Step 3: Conduct Property Viewing</h6>
                                        <div class="small text-muted">Viewing is approved. Meet the client at the property and conduct the site tour.</div>
                                    </div>
                                </div>

                                <form method="POST" action="transaction_tracking.php" class="d-grid gap-3" id="advanceForm<?= $tId ?>" data-confirm="Record viewing outcome and notify the client?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Viewing Outcome <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" required>
                                            <option value="viewing_completed" selected>✓ Property Viewing Successfully Conducted</option>
                                            <option value="cancelled">❌ Client Did Not Attend / Cancelled Viewing</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">
                                            Viewing Feedback &amp; Next Steps <span class="text-muted fw-normal">(optional)</span>
                                        </label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Client toured parcel, highly satisfied with road access.">
                                    </div>

                                    <button type="submit" name="update_transaction" class="btn btn-primary fw-bold py-2 w-100 shadow-sm mt-1">
                                        ✓ Mark Viewing as Completed
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($currentStage === 'viewing_completed'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="fs-5">📝</span>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">Step 4: Down Payment &amp; Payment Terms Agreement</h6>
                                        <div class="small text-muted">Viewing completed. Settle down payment / payment terms and transition to payment processing.</div>
                                    </div>
                                </div>

                                <form method="POST" action="transaction_tracking.php" class="d-grid gap-3" id="advanceForm<?= $tId ?>" data-confirm="Initiate payment processing and notify the client?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Action <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" required>
                                            <option value="payment_in_process" selected>💳 Advance to Payment in Process (Down Payment / Terms Agreed)</option>
                                            <option value="cancelled">❌ Client Did Not Proceed / Cancel</option>
                                        </select>
                                    </div>

                                    <div class="card p-3 bg-light border-0 rounded-3">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1">
                                            <span>💳</span> Record Down Payment / Deposit <span class="text-muted fw-normal">(optional)</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-amount-<?= $tId ?>">Payment Amount (₱)</label>
                                                <input id="modal-payment-amount-<?= $tId ?>" type="number" name="paymentAmount" class="form-control form-control-sm" min="0" max="9999999999.99" step="0.01" placeholder="e.g. 150000.00">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-method-<?= $tId ?>">Payment Scheme</label>
                                                <select id="modal-payment-method-<?= $tId ?>" name="paymentMethod" class="form-select form-select-sm">
                                                    <?php foreach ($allSchemes as $sKey => $sVal): ?>
                                                        <option value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($agreedMethod, $sKey) === 0 ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($sVal['badge'], ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-ref-<?= $tId ?>">Receipt / Reference #</label>
                                                <input id="modal-payment-ref-<?= $tId ?>" type="text" name="paymentRef" class="form-control form-control-sm" maxlength="100" placeholder="e.g. OR-DP-2026-001">
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">Payment Update Note</label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Down payment terms agreed; proceeding to payment processing.">
                                    </div>

                                    <button type="submit" name="update_transaction" class="btn btn-primary fw-bold py-2 w-100 shadow-sm mt-1">
                                        💳 Start Payment Processing &amp; Record Terms
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($currentStage === 'payment_in_process'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="fs-5">💳</span>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">Step 5: Payment Processing &amp; Financial Accounting</h6>
                                        <div class="small text-muted">
                                            Verified Total: <strong class="text-success">₱<?= number_format(get_verified_transaction_payment_total($conn, $tId), 2) ?></strong>
                                            <?php if ($isTxSpotCash): ?> · <span class="badge text-bg-warning text-dark">⚡ Spot Cash</span><?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <form method="POST" action="transaction_tracking.php" class="d-grid gap-3" id="advanceForm<?= $tId ?>" data-confirm="Record payment and advance stage?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Next Milestone <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" data-next-stage-select="<?= $tId ?>" required>
                                            <option value="document_verification" data-doc-option="1" <?= !$isTxSpotCash ? 'selected' : 'disabled hidden' ?>>📑 Advance to Document Verification</option>
                                            <option value="payment_confirmation" data-confirm-option="1" <?= $isTxSpotCash ? 'selected' : '' ?>>✅ Advance to Payment Confirmation <?= $isTxSpotCash ? '(Spot Cash: Doc verification skipped)' : '(If docs pre-cleared)' ?></option>
                                            <option value="cancelled">❌ Cancel Transaction</option>
                                        </select>
                                        <div id="spot-cash-notice-<?= $tId ?>" class="small text-success fw-semibold mt-1 <?= $isTxSpotCash ? '' : 'd-none' ?>">
                                            ⚡ Spot Cash skips Document Verification and moves straight to Payment Confirmation.
                                        </div>
                                    </div>

                                    <div class="card p-3 bg-light border-0 rounded-3">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center justify-content-between gap-1 flex-wrap">
                                            <span><span>💵</span> Record Payment / Down Payment Receipt <span class="text-muted fw-normal">(optional)</span></span>
                                            <?php if ($txRemaining > 0): ?>
                                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-fill-amount="<?= number_format($txRemaining, 2, '.', '') ?>" data-fill-target="modal-payment-amount-<?= $tId ?>">
                                                    Fill remaining (₱<?= number_format($txRemaining, 2) ?>)
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-amount-<?= $tId ?>">Amount Paid (₱)</label>
                                                <input id="modal-payment-amount-<?= $tId ?>" type="number" name="paymentAmount" class="form-control form-control-sm" min="0" max="9999999999.99" step="0.01" placeholder="e.g. 150000.00">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-method-<?= $tId ?>">Payment Scheme</label>
                                                <select id="modal-payment-method-<?= $tId ?>" name="paymentMethod" class="form-select form-select-sm" data-payment-scheme-select="<?= $tId ?>">
                                                    <?php foreach ($allSchemes as $sKey => $sVal): ?>
                                                        <option value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($agreedMethod, $sKey) === 0 ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($sVal['badge'], ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-ref-<?= $tId ?>">Official Receipt (OR) / Bank Ref #</label>
                                                <input id="modal-payment-ref-<?= $tId ?>" type="text" name="paymentRef" class="form-control form-control-sm" maxlength="100" placeholder="e.g. OR-DP-2026-881">
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">Update Note</label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Payment processed. Contract paperwork ready for review.">
                                    </div>

                                    <button type="submit" name="update_transaction" id="step5-submit-<?= $tId ?>" class="btn btn-primary fw-bold py-2 w-100 shadow-sm mt-1">
                                        <?= $isTxSpotCash ? '✅ Advance to Payment Confirmation' : '📑 Advance to Document Verification' ?>
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($currentStage === 'document_verification'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="fs-5">📑</span>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">Step 6: Legal &amp; Document Verification Checklist</h6>
                                        <div class="small text-muted">Verify title deeds, tax clearances, and buyer compliance documents.</div>
                                    </div>
                                </div>

                                <div class="p-3 bg-light rounded-3 mb-3 border small">
                                    <div class="fw-bold text-dark mb-2">📋 Legal Compliance Checklist:</div>
                                    <div class="d-flex flex-column gap-1 text-muted">
                                        <div><i class="bi bi-check2-circle text-success me-1"></i> Certified True Copy of Title (TCT/CCT) from Registry of Deeds</div>
                                        <div><i class="bi bi-check2-circle text-success me-1"></i> Real Property Tax (Amilyar) &amp; Tax Declaration Clearances</div>
                                        <div><i class="bi bi-check2-circle text-success me-1"></i> Government-Issued Photo IDs &amp; Tax ID Number (TIN)</div>
                                        <div><i class="bi bi-check2-circle text-success me-1"></i> Contract to Sell (CTS) drafted and signed</div>
                                    </div>
                                </div>

                                <form method="POST" action="transaction_tracking.php" class="d-grid gap-3" id="advanceForm<?= $tId ?>" data-confirm="Confirm documents verified and advance to payment confirmation?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Verification Outcome <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" required>
                                            <option value="payment_confirmation" selected>✅ Documents Verified — Advance to Payment Confirmation</option>
                                            <option value="cancelled">❌ Legal Discrepancy Found / Cancel</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">
                                            Document Clearance Findings &amp; Note <span class="text-muted fw-normal">(optional)</span>
                                        </label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Title clean, no liens or encumbrances. Tax clearance certified for 2026.">
                                    </div>

                                    <button type="submit" name="update_transaction" class="btn btn-primary fw-bold py-2 w-100 shadow-sm mt-1">
                                        ✅ Confirm Documents Verified &amp; Notify Client
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($currentStage === 'payment_confirmation'): ?>
                            <div class="card p-3 mb-3 border shadow-none bg-white">
                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="fs-5">🏁</span>
                                    <div>
                                        <h6 class="fw-bold mb-0 text-dark">Step 7: Final Payment Confirmation &amp; Turnover</h6>
                                        <div class="small text-muted">
                                            Total Verified Payments: <strong class="text-success">₱<?= number_format(get_verified_transaction_payment_total($conn, $tId), 2) ?></strong>
                                        </div>
                                    </div>
                                </div>

                                <div class="alert alert-warning small py-2 px-3 mb-3">
                                    <i class="bi bi-info-circle-fill me-1"></i>
                                    <strong>Final Closing Requirement:</strong> Enter the final verified sale price. Marking as completed will finalize the sale and record the turnover date.
                                    <?php if ($role === 'Admin'): ?>
                                        The property will be marked <strong>Sold</strong> in the admin inventory and hidden from public listings.
                                    <?php else: ?>
                                        The property will become unavailable to other buyers.
                                    <?php endif; ?>
                                </div>

                                <form method="POST" action="transaction_tracking.php" class="d-grid gap-3" id="advanceForm<?= $tId ?>" data-confirm="Finalize transaction and close this deal?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="transactionID" value="<?= $tId ?>">

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-stage-<?= $tId ?>">
                                            Closing Action <span class="text-danger">*</span>
                                        </label>
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" required>
                                            <option value="completed" selected>🏆 1. Finalize Sale &amp; Mark Completed (Turnover property)</option>
                                            <option value="cancelled">❌ Cancel Transaction</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="form-label fw-bold text-dark small mb-1" for="modal-price-<?= $tId ?>">
                                            💰 Verified Sale Price (PHP) <span class="text-danger">*</span>
                                        </label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text">₱</span>
                                            <input id="modal-price-<?= $tId ?>" type="number" name="salePrice" class="form-control" min="0.01" max="9999999999.99" step="0.01" placeholder="Actual agreed sale price" value="<?= $transaction['salePrice'] !== null ? htmlspecialchars((string) $transaction['salePrice'], ENT_QUOTES, 'UTF-8') : ($txListingPrice > 0 ? number_format($txListingPrice, 2, '.', '') : '') ?>" required>
                                        </div>
                                        <small class="text-muted">Verified payments must cover this price before closing (currently ₱<?= number_format($txVerifiedPaid, 2) ?>).</small>
                                    </div>

                                    <div class="card p-3 bg-light border-0 rounded-3">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center justify-content-between gap-1 flex-wrap">
                                            <span><span>💵</span> Record Final / Remaining Payment <span class="text-muted fw-normal">(optional)</span></span>
                                            <?php if ($txRemaining > 0.01): ?>
                                                <button type="button" class="btn btn-sm btn-outline-success py-0 px-2" data-fill-amount="<?= number_format($txRemaining, 2, '.', '') ?>" data-fill-target="modal-final-payment-amount-<?= $tId ?>">
                                                    Fill remaining (₱<?= number_format($txRemaining, 2) ?>)
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-final-payment-amount-<?= $tId ?>">Amount Paid (₱)</label>
                                                <input id="modal-final-payment-amount-<?= $tId ?>" type="number" name="paymentAmount" class="form-control form-control-sm" min="0" max="9999999999.99" step="0.01" placeholder="Leave blank if already paid in full">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-final-payment-method-<?= $tId ?>">Payment Scheme</label>
                                                <select id="modal-final-payment-method-<?= $tId ?>" name="paymentMethod" class="form-select form-select-sm">
                                                    <?php foreach ($allSchemes as $sKey => $sVal): ?>
                                                        <option value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($agreedMethod, $sKey) === 0 ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($sVal['badge'], ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-final-payment-ref-<?= $tId ?>">Official Receipt (OR) / Bank Ref #</label>
                                                <input id="modal-final-payment-ref-<?= $tId ?>" type="text" name="paymentRef" class="form-control form-control-sm" maxlength="100" placeholder="e.g. OR-FINAL-2026-102">
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">Final Turnover Note <span class="text-muted fw-normal">(optional)</span></label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Deed of sale signed and notarized. Keys and documents turned over to buyer.">
                                    </div>

                                    <button type="submit" name="update_transaction" class="btn btn-success fw-bold py-2 w-100 shadow-sm mt-1">
                                        🏆 Close Deal &amp; Mark Transaction Completed
                                    </button>
                                </form>
                            </div>

                        <?php elseif ($currentStage === 'completed'): ?>
                            <div class="p-3 bg-success-subtle border border-success-subtle rounded-3 mb-3 text-center">
                                <span class="fs-1 d-block mb-1">🎉</span>
                                <h6 class="fw-bold text-success mb-1">Transaction Completed &amp; Property Turned Over</h6>
                                <p class="small text-muted mb-0">
                                    Verified Sale Price: <strong>₱<?= number_format((float) $transaction['salePrice'], 2) ?></strong>.
                                    <?php if ($role === 'Admin'): ?>
                                        Property status: <strong>Sold</strong>.
                                    <?php else: ?>
                                        This property is no longer available to other buyers.
                                    <?php endif; ?>
                                </p>
                            </div>

                        <?php elseif ($currentStage === 'cancelled'): ?>
                            <div class="alert alert-secondary small mb-3">
                                ℹ️ This transaction has been cancelled.
                            </div>
                        <?php endif; ?>

                        <!-- Payment Records Section (if any) -->
                        <?php if ($transactionPayments !== []): ?>
                            <div class="card p-3 border shadow-none bg-white mb-3">
                                <h6 class="fw-bold mb-2 text-dark">💳 Payment Records</h6>
                                <?php foreach ($transactionPayments as $payment):
                                    $displayPaymentStatus = match ($payment['status']) {
                                        'confirmed' => 'Verified',
                                        'rejected' => 'Rejected',
                                        default => 'Pending review',
                                    };
                                ?>
                                    <div class="border rounded-2 p-2 mb-2 small bg-light">
                                        <div class="d-flex justify-content-between gap-2">
                                            <strong>₱<?= number_format((float) $payment['amount'], 2) ?> · <?= htmlspecialchars($payment['paymentType'] ?? $payment['paymentMethod'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($payment['paymentMethod'], ENT_QUOTES, 'UTF-8') ?>)</strong>
                                            <span class="badge <?= $payment['status'] === 'confirmed' ? 'text-bg-success' : ($payment['status'] === 'rejected' ? 'text-bg-danger' : 'text-bg-warning') ?>">
                                                <?= htmlspecialchars($displayPaymentStatus, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                        <div class="text-muted small"><?= htmlspecialchars($payment['sourceLabel'], ENT_QUOTES, 'UTF-8') ?> · Recorded <?= htmlspecialchars($payment['submittedAt'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($payment['referenceNumber'])): ?>
                                            <div>Reference: <?= htmlspecialchars($payment['referenceNumber'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($payment['reviewNote'])): ?>
                                            <div class="text-muted"><?= htmlspecialchars($payment['reviewNote'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if ($payment['status'] === 'pending' && !empty($payment['isClientReport'])): ?>
                                            <form method="POST" class="d-grid gap-2 mt-2">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="transactionID" value="<?= $tId ?>">
                                                <input type="hidden" name="paymentID" value="<?= (int) $payment['paymentID'] ?>">
                                                <input id="modal-payment-review-note-<?= (int) $payment['paymentID'] ?>" type="text" name="reviewNote" class="form-control form-control-sm" maxlength="1000" placeholder="Optional note for client">
                                                <div class="d-flex gap-2">
                                                    <button type="submit" name="paymentStatus" value="confirmed" class="btn btn-sm btn-success" data-confirm="Confirm this client payment report?">Confirm payment</button>
                                                    <button type="submit" name="paymentStatus" value="rejected" class="btn btn-sm btn-outline-danger" data-confirm="Reject this client payment report?">Reject payment</button>
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
                                                <strong class="text-dark"><?= htmlspecialchars(property_transaction_stage_label((string) $evt['stage']), ENT_QUOTES, 'UTF-8') ?></strong>
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
    document.querySelectorAll('[data-payment-scheme-select]').forEach(schemeSelect => {
        const tid = schemeSelect.getAttribute('data-payment-scheme-select');
        const stageSelect = document.querySelector(`[data-next-stage-select="${tid}"]`);
        const spotCashNotice = document.getElementById('spot-cash-notice-' + tid);
        const submitBtn = document.getElementById('step5-submit-' + tid);

        function syncSpotCashUI() {
            const isSpotCash = schemeSelect.value.trim().toLowerCase() === 'spot cash';
            if (spotCashNotice) {
                spotCashNotice.classList.toggle('d-none', !isSpotCash);
            }
            if (stageSelect) {
                const docOpt = stageSelect.querySelector('option[data-doc-option="1"]');
                const confirmOpt = stageSelect.querySelector('option[data-confirm-option="1"]');
                if (docOpt) {
                    docOpt.disabled = isSpotCash;
                    docOpt.hidden = isSpotCash;
                }
                if (confirmOpt) {
                    confirmOpt.textContent = isSpotCash
                        ? '✅ Advance to Payment Confirmation (Spot Cash: Doc verification skipped)'
                        : '✅ Advance to Payment Confirmation (If docs pre-cleared)';
                }
                if (isSpotCash && stageSelect.value === 'document_verification') {
                    stageSelect.value = 'payment_confirmation';
                } else if (!isSpotCash && stageSelect.value === 'payment_confirmation') {
                    stageSelect.value = 'document_verification';
                }
            }
            if (submitBtn && stageSelect) {
                submitBtn.textContent = stageSelect.value === 'payment_confirmation'
                    ? '✅ Advance to Payment Confirmation'
                    : (stageSelect.value === 'cancelled' ? '❌ Cancel Transaction' : '📑 Advance to Document Verification');
            }
        }

        schemeSelect.addEventListener('change', syncSpotCashUI);
        if (stageSelect && submitBtn) {
            stageSelect.addEventListener('change', () => {
                submitBtn.textContent = stageSelect.value === 'payment_confirmation'
                    ? '✅ Advance to Payment Confirmation'
                    : (stageSelect.value === 'cancelled' ? '❌ Cancel Transaction' : '📑 Advance to Document Verification');
            });
        }
        syncSpotCashUI();
    });

    document.querySelectorAll('[data-fill-amount]').forEach(button => {
        button.addEventListener('click', () => {
            const targetId = button.getAttribute('data-fill-target');
            const targetInput = targetId ? document.getElementById(targetId) : null;
            if (targetInput) {
                targetInput.value = button.getAttribute('data-fill-amount') || '';
                targetInput.focus();
            }
        });
    });

    // Auto-reopen record next step modal when confirming actions or encountering validation feedback
    try {
        const urlParams = new URLSearchParams(window.location.search);
        const openModalId = urlParams.get('open_modal') || '<?= (int)($_POST['transactionID'] ?? 0) ?>';
        if (openModalId && parseInt(openModalId, 10) > 0) {
            const modalEl = document.getElementById('recordNextModal' + openModalId);
            if (modalEl && typeof bootstrap !== 'undefined') {
                const modalInstance = bootstrap.Modal.getOrCreateInstance(modalEl);
                modalInstance.show();
            }
        }
    } catch (e) {
        console.warn('Could not auto-open record step modal:', e);
    }
});
</script>

<?php include 'includes/footer.php'; ?>
