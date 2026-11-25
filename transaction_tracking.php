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
    $formSuccess = 'Transaction request claimed and viewing accepted.';
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
                $tStmt = $conn->prepare("SELECT transactionID, propertyID, clientID, appointmentID, inquiryID, currentStage FROM property_transactions WHERE transactionID = ? LIMIT 1");
                $tStmt->bind_param('i', $transactionId);
                $tStmt->execute();
                $tRow = $tStmt->get_result()->fetch_assoc();
                if (!$tRow) {
                    throw new InvalidArgumentException('Transaction not found.');
                }

                $conn->begin_transaction();

                if (!empty($tRow['appointmentID'])) {
                    $apptStmt = $conn->prepare("UPDATE appointments SET agentID = ? WHERE appointmentID = ?");
                    $apptStmt->bind_param('ii', $actorId, $tRow['appointmentID']);
                    $apptStmt->execute();
                    $apptStmt->close();
                }

                if (!empty($tRow['inquiryID'])) {
                    $inqStmt = $conn->prepare("INSERT INTO agent_inquiry_assignments (inquiryID, agentID, assignedAt) VALUES (?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE agentID = ?, assignedAt = CURRENT_TIMESTAMP");
                    $inqStmt->bind_param('iii', $tRow['inquiryID'], $actorId, $actorId);
                    $inqStmt->execute();
                    $inqStmt->close();
                }

                $agentName = (string) ($_SESSION['user']['fullname'] ?? 'Sales Agent');

                // If claiming a viewing_requested transaction, advance stage to reviewing_schedule to replace viewing_requested!
                if ($tRow['currentStage'] === 'viewing_requested') {
                    $claimStage = 'reviewing_schedule';
                    $updateStageStmt = $conn->prepare("UPDATE property_transactions SET currentStage = 'reviewing_schedule', updatedAt = CURRENT_TIMESTAMP WHERE transactionID = ?");
                    $updateStageStmt->bind_param('i', $transactionId);
                    $updateStageStmt->execute();
                    $updateStageStmt->close();
                    $claimNote = 'Viewing accepted by ' . $agentName;
                } else {
                    $claimStage = (string) $tRow['currentStage'];
                    $claimNote = 'Sales Agent ' . $agentName . ' claimed this transaction request.';
                }

                add_property_transaction_event($conn, (int) $transactionId, $claimStage, $claimNote, $actorId);

                send_property_notification(
                    $conn,
                    (int) $tRow['clientID'],
                    'Sales Agent assigned',
                    'Sales Agent ' . $agentName . ' has accepted your request and is now reviewing the schedule for transaction #KQT-' . $transactionId . '.',
                    'client_dashboard.php#transaction-tracking',
                    'agent_assigned'
                );

                $conn->commit();
                $openParam = ($transactionId && $transactionId > 0) ? '&open_modal=' . $transactionId : '';
                header('Location: transaction_tracking.php?claimed=1' . $openParam . '#transactions');
                exit();
            } catch (Throwable $ex) {
                $conn->rollback();
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
            p.title, p.location, p.image, p.propertyType, l.municipalityName, l.provinceName,
            u.fullname AS clientName, u.email AS clientEmail,
            a.appointmentDate, a.appointmentTime,
            COALESCE(a.agentID, ia.agentID) AS assignedAgentID,
            COALESCE(agentUser.fullname, inqAgentUser.fullname, 'Unassigned') AS assignedAgentName
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     JOIN users u ON u.userID = t.clientID
     LEFT JOIN property_locations l ON l.locationID = p.locationID
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     LEFT JOIN users agentUser ON agentUser.userID = a.agentID
     LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
     LEFT JOIN users inqAgentUser ON inqAgentUser.userID = ia.agentID";
if ($role === 'Admin') {
    $transactions = $conn->query(
        $transactionsSql . "
         WHERE t.currentStage NOT IN ('inquiry_received', 'contacting_client')
         ORDER BY (t.currentStage IN ('completed', 'cancelled')) ASC, t.updatedAt DESC, t.transactionID DESC
         LIMIT 300"
    );
} else {
    $transactionsStmt = $conn->prepare(
        $transactionsSql . "
         WHERE t.currentStage NOT IN ('inquiry_received', 'contacting_client')
           AND ((a.agentID = ? OR ia.agentID = ?) OR (a.agentID IS NULL AND ia.agentID IS NULL))
         ORDER BY (t.currentStage IN ('completed', 'cancelled')) ASC, t.updatedAt DESC, t.transactionID DESC
         LIMIT 300"
    );
    $transactionsStmt->bind_param('ii', $actorId, $actorId);
    $transactionsStmt->execute();
    $transactions = $transactionsStmt->get_result();
    $transactionsStmt->close();
}
$transactionRows = $transactions->fetch_all(MYSQLI_ASSOC);
$paymentRowsByTransaction = [];
$eventsByTransaction = [];
if ($transactionRows !== []) {
    $transactionIds = array_map(static fn(array $row): int => (int) $row['transactionID'], $transactionRows);
    $transactionIdList = implode(',', $transactionIds);
    $paymentRowsByTransaction = get_transaction_payment_records($conn, $transactionIds);

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
                        $propImage = function_exists('property_representative_image') ? (property_representative_image((string) ($transaction['propertyType'] ?? '')) ?? '') : '';
                    }
                    $hasPropImage = $propImage !== '' && is_file(__DIR__ . '/assets/images/' . $propImage);
                    $propImgSrc = $hasPropImage ? ('assets/images/' . rawurlencode($propImage)) : 'assets/images/representative-residential-01.jpg';
                ?>
                    <tr>
                        <td class="fw-semibold">#KQT-<?= $tId ?></td>
                        <td>
                            <div class="d-flex align-items-start gap-3">
                                <div class="rounded-3 overflow-hidden flex-shrink-0 border shadow-sm position-relative" style="width: 64px; height: 64px; background: rgba(0,0,0,0.04);">
                                    <img src="<?= htmlspecialchars($propImgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100" style="object-fit: cover;" loading="lazy" onerror="this.onerror=null;this.src='assets/images/representative-residential-01.jpg';">
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
                                        <div class="small fw-semibold text-success mt-1">Verified sale: ₱<?= number_format((float) $transaction['salePrice'], 2) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php
                            $stageBadgeClass = match ($currentStage) {
                                'completed' => 'text-bg-success',
                                'cancelled' => 'text-bg-secondary',
                                'reviewing_schedule' => 'text-bg-info text-dark',
                                'payment_in_process' => 'text-bg-warning text-dark',
                                'payment_confirmation' => 'text-bg-info text-dark',
                                default => 'text-bg-primary',
                            };
                            ?>
                            <span class="badge rounded-pill <?= $stageBadgeClass ?>"><?= htmlspecialchars($stageLabels[$currentStage] ?? 'Status unavailable', ENT_QUOTES, 'UTF-8') ?></span>
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
            'payment_in_process' => 'text-bg-warning text-dark',
            'payment_confirmation' => 'text-bg-info text-dark',
            default => 'text-bg-primary',
        };
        $transactionPayments = $paymentRowsByTransaction[$tId] ?? [];
        $propImage = basename((string) ($transaction['image'] ?? ''));
        if ($propImage === '' || $propImage === 'default.jpg' || !is_file(__DIR__ . '/assets/images/' . $propImage)) {
            $propImage = function_exists('property_representative_image') ? (property_representative_image((string) ($transaction['propertyType'] ?? '')) ?? '') : '';
        }
        $hasPropImage = $propImage !== '' && is_file(__DIR__ . '/assets/images/' . $propImage);
        $propImgSrc = $hasPropImage ? ('assets/images/' . rawurlencode($propImage)) : 'assets/images/representative-residential-01.jpg';
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
                                    <img src="<?= htmlspecialchars($propImgSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($transaction['title'], ENT_QUOTES, 'UTF-8') ?>" class="w-100 h-100" style="object-fit: cover;" onerror="this.onerror=null;this.src='assets/images/representative-residential-01.jpg';">
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
                            <div class="small mt-2 pt-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <span class="text-muted">Assigned Sales Agent:</span>
                                <strong>👤 <?= htmlspecialchars($transaction['assignedAgentName'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        </div>

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
                        <?php if ($currentStage !== 'viewing_requested'):
                            $stageGuidance = [
                                'reviewing_schedule' => 'Viewing request accepted. Review and confirm the viewing schedule with the client, then confirm the schedule.',
                                'viewing_confirmed' => 'The viewing schedule is confirmed. Conduct the property viewing as scheduled, then mark it as completed.',
                                'viewing_completed' => 'The property viewing is complete. Proceed to "Payment in process" once the client settles the reservation deposit.',
                                'payment_in_process' => $isTxSpotCash
                                    ? '⚡ Spot Cash selected: Document verification is bypassed. Prepare Deed of Absolute Sale (DOAS). Note: Title transfer and registration taxes are shouldered by the buyer.'
                                    : '⏳ 7-Day Reservation Policy Active: Buyer has 7 days to complete down payment and IDs. Prepare Contract to Sell (CTS) for installment terms (or DOAS if Spot Cash). Note: Title transfer is shouldered by the buyer.',
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

                        <?php if ($currentStage === 'payment_in_process'):
                            $stageUpdateTs = !empty($transaction['updatedAt']) ? strtotime($transaction['updatedAt']) : time();
                            $daysElapsed = (int) floor((time() - $stageUpdateTs) / 86400);
                            $daysLeft = max(0, 7 - $daysElapsed);
                        ?>
                            <div class="p-2 px-3 rounded-3 mb-3 bg-warning-subtle border border-warning small d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fs-5">⏳</span>
                                    <div>
                                        <strong class="text-dark">7-Day Reservation Policy Status:</strong>
                                        <div class="text-muted" style="font-size: 0.78rem;">
                                            <?php if ($daysLeft > 0): ?>
                                                Active reservation window: <strong><?= $daysLeft ?> day<?= $daysLeft === 1 ? '' : 's' ?> remaining</strong> for client down payment and compliance IDs before release.
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
                        <?php if ($currentStage === 'viewing_requested'): ?>
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
                                        <h6 class="fw-bold mb-0 text-dark">Step 4: Reservation &amp; Payment Terms Agreement</h6>
                                        <div class="small text-muted">Viewing completed. Settle initial reservation fee and transition to payment processing.</div>
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
                                            <option value="payment_in_process" selected>💳 Advance to Payment in Process (Reservation Settled)</option>
                                            <option value="cancelled">❌ Client Did Not Proceed / Cancel</option>
                                        </select>
                                    </div>

                                    <div class="card p-3 bg-light border-0 rounded-3">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1">
                                            <span>💳</span> Record Initial Reservation Deposit <span class="text-muted fw-normal">(optional)</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-amount-<?= $tId ?>">Reservation Amount (₱)</label>
                                                <input id="modal-payment-amount-<?= $tId ?>" type="number" name="paymentAmount" class="form-control form-control-sm" min="0" max="9999999999.99" step="0.01" placeholder="e.g. 20000.00">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-method-<?= $tId ?>">Payment Scheme</label>
                                                <select id="modal-payment-method-<?= $tId ?>" name="paymentMethod" class="form-select form-select-sm">
                                                    <?php foreach ($allSchemes as $sKey => $sVal): ?>
                                                        <option value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($agreedMethod, $sKey) === 0 ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($sVal['badge'] . ' — ' . $sVal['short'], ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-ref-<?= $tId ?>">Receipt / Reference #</label>
                                                <input id="modal-payment-ref-<?= $tId ?>" type="text" name="paymentRef" class="form-control form-control-sm" maxlength="100" placeholder="e.g. OR-RES-2026-001">
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="form-label fw-semibold small mb-1" for="modal-note-<?= $tId ?>">Reservation Update Note</label>
                                        <input id="modal-note-<?= $tId ?>" type="text" name="note" class="form-control form-control-sm" maxlength="1000" placeholder="e.g. Reservation deposit settled. 7-day down payment grace period active.">
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
                                        <select id="modal-stage-<?= $tId ?>" name="stage" class="form-select form-select-sm fw-semibold" required>
                                            <?php if ($isTxSpotCash): ?>
                                                <option value="payment_confirmation" selected>✅ Advance to Payment Confirmation (Spot Cash: Doc verification skipped)</option>
                                            <?php else: ?>
                                                <option value="document_verification" selected>📑 Advance to Document Verification</option>
                                                <option value="payment_confirmation">✅ Advance to Payment Confirmation (If docs pre-cleared)</option>
                                            <?php endif; ?>
                                            <option value="cancelled">❌ Cancel Transaction</option>
                                        </select>
                                    </div>

                                    <div class="card p-3 bg-light border-0 rounded-3">
                                        <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-1">
                                            <span>💵</span> Record Payment / Down Payment Receipt <span class="text-muted fw-normal">(optional)</span>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-amount-<?= $tId ?>">Amount Paid (₱)</label>
                                                <input id="modal-payment-amount-<?= $tId ?>" type="number" name="paymentAmount" class="form-control form-control-sm" min="0" max="9999999999.99" step="0.01" placeholder="e.g. 150000.00">
                                            </div>
                                            <div class="col-sm-6">
                                                <label class="form-label small mb-1 fw-semibold" for="modal-payment-method-<?= $tId ?>">Payment Scheme</label>
                                                <select id="modal-payment-method-<?= $tId ?>" name="paymentMethod" class="form-select form-select-sm">
                                                    <?php foreach ($allSchemes as $sKey => $sVal): ?>
                                                        <option value="<?= htmlspecialchars($sKey, ENT_QUOTES, 'UTF-8') ?>" <?= strcasecmp($agreedMethod, $sKey) === 0 ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($sVal['badge'] . ' — ' . $sVal['short'], ENT_QUOTES, 'UTF-8') ?>
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

                                    <button type="submit" name="update_transaction" class="btn btn-primary fw-bold py-2 w-100 shadow-sm mt-1">
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
                                            <input id="modal-price-<?= $tId ?>" type="number" name="salePrice" class="form-control" min="0.01" max="9999999999.99" step="0.01" placeholder="Actual agreed sale price" value="<?= $transaction['salePrice'] !== null ? htmlspecialchars((string) $transaction['salePrice'], ENT_QUOTES, 'UTF-8') : '' ?>" required>
                                        </div>
                                        <small class="text-muted">Must be covered by verified payments.</small>
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
                                <?php foreach ($transactionPayments as $payment): ?>
                                    <div class="border rounded-2 p-2 mb-2 small bg-light">
                                        <div class="d-flex justify-content-between gap-2">
                                            <strong>₱<?= number_format((float) $payment['amount'], 2) ?> · <?= htmlspecialchars($payment['paymentMethod'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span class="badge <?= $payment['status'] === 'confirmed' ? 'text-bg-success' : ($payment['status'] === 'rejected' ? 'text-bg-danger' : 'text-bg-warning') ?>">
                                                <?= htmlspecialchars(ucfirst($payment['status']), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                        <div class="text-muted small"><?= htmlspecialchars($payment['sourceLabel'], ENT_QUOTES, 'UTF-8') ?> · Recorded <?= htmlspecialchars($payment['submittedAt'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($payment['referenceNumber'])): ?>
                                            <div>Reference: <?= htmlspecialchars($payment['referenceNumber'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($payment['reviewNote'])): ?>
                                            <div class="text-muted"><?= htmlspecialchars($payment['reviewNote'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if ($payment['status'] === 'pending' && $payment['sourceLabel'] === 'Client report'): ?>
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
        'viewing_completed': 'e.g. Completed the property viewing with the client. The client confirmed interest in proceeding.',
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
            const paymentMethodSelect = document.getElementById('modal-payment-method-' + tid);
            if (
                this.dataset.paymentInProcess === '1'
                && this.value === 'payment_confirmation'
                && paymentMethodSelect
                && paymentMethodSelect.value.trim().toLowerCase() !== 'spot cash'
            ) {
                this.value = 'document_verification';
            }
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
                        if (stageSelect.value === 'payment_confirmation') {
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
