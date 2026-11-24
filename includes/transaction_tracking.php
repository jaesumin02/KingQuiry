<?php
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/appointment_management.php';

function property_transaction_stages(): array
{
    return [
        'inquiry_received' => 'Inquiry received',
        'contacting_client' => 'Contacting client',
        'viewing_requested' => 'Viewing requested',
        'reviewing_schedule' => 'Reviewing schedule',
        'viewing_confirmed' => 'Viewing approved',
        'viewing_completed' => 'Viewing completed',
        'payment_in_process' => 'Payment in process',
        'document_verification' => 'Document verification',
        'payment_confirmation' => 'Payment confirmation',
        'completed' => 'Successful / completed',
        'cancelled' => 'Cancelled',
    ];
}

function property_client_payment_methods(): array
{
    return ['Cash', 'Bank Transfer', 'Check'];
}

function property_staff_payment_methods(): array
{
    return ['Spot Cash', 'Bank Transfer', 'Check', 'Paperwork (Face-to-face)'];
}

function property_transaction_payment_schemes(): array
{
    return [
        'Spot Cash' => [
            'label' => 'Spot Cash (Full Payment)',
            'short' => 'Spot Cash',
            'badge' => '⚡ Spot Cash',
            'desc' => 'Pay the entire purchase balance in full. Fastest property turnover, immediate Deed of Absolute Sale (DOAS) preparation, and document verification is expedited.',
            'icon' => '⚡',
            'bypassesDocs' => true,
        ],
        'Bank Transfer' => [
            'label' => 'Bank Financing / Bank Transfer',
            'short' => 'Bank Financing',
            'badge' => '🏦 Bank Financing',
            'desc' => 'Pay an initial down payment followed by bank loan / wire transfer. Requires standard bank pre-approval and title deed/clearance verification.',
            'icon' => '🏦',
            'bypassesDocs' => false,
        ],
        'Check' => [
            'label' => 'Installment (Post-Dated Checks)',
            'short' => 'PDC Installment',
            'badge' => '📝 PDC Installment',
            'desc' => 'Pay initial reservation deposit followed by structured monthly post-dated checks under an official Contract to Sell (CTS).',
            'icon' => '📝',
            'bypassesDocs' => false,
        ],
        'Paperwork (Face-to-face)' => [
            'label' => 'Paperwork (Face-to-face Agreement)',
            'short' => 'Face-to-Face Agreement',
            'badge' => '🤝 Face-to-Face Agreement',
            'desc' => 'Visit the 4 Kings Realty sales office with your assigned agent to negotiate custom installment terms, signed paperwork, and physical receipts.',
            'icon' => '🤝',
            'bypassesDocs' => false,
        ],
    ];
}

function set_property_transaction_payment_method(
    mysqli $conn,
    int $transactionId,
    int $clientId,
    string $paymentMethod,
    ?string $clientNote = null
): void {
    $schemes = property_transaction_payment_schemes();
    if (!isset($schemes[$paymentMethod]) || $transactionId <= 0 || $clientId <= 0) {
        throw new InvalidArgumentException('Choose a valid payment scheme for this transaction.');
    }

    $conn->begin_transaction();
    try {
        $txStmt = $conn->prepare(
            'SELECT t.transactionID, t.propertyID, t.clientID, t.currentStage, t.appointmentID, t.inquiryID,
                    p.title AS propertyTitle, u.fullname AS clientName
             FROM property_transactions t
             JOIN properties p ON p.propertyID = t.propertyID
             JOIN users u ON u.userID = t.clientID
             WHERE t.transactionID = ? AND t.clientID = ?
             LIMIT 1
             FOR UPDATE'
        );
        $txStmt->bind_param('ii', $transactionId, $clientId);
        $txStmt->execute();
        $tx = $txStmt->get_result()->fetch_assoc();
        $txStmt->close();

        if (!$tx) {
            throw new InvalidArgumentException('Transaction not found or you are not authorized to update it.');
        }

        if (in_array($tx['currentStage'], ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Cannot update the payment method for a closed transaction.');
        }

        $schemeInfo = $schemes[$paymentMethod];
        $updStmt = $conn->prepare(
            'UPDATE property_transactions
             SET paymentMethod = ?, updatedAt = CURRENT_TIMESTAMP
             WHERE transactionID = ?'
        );
        $updStmt->bind_param('si', $paymentMethod, $transactionId);
        $updStmt->execute();
        $updStmt->close();

        $eventNote = 'Client selected payment scheme: ' . $schemeInfo['label'];
        if ($clientNote !== null && trim($clientNote) !== '') {
            $eventNote .= ' (Note: ' . trim($clientNote) . ')';
        }
        add_property_transaction_event($conn, $transactionId, (string) $tx['currentStage'], $eventNote, $clientId);

        // Find assigned agent to notify
        $assignedAgentId = 0;
        $appointmentId = (int) ($tx['appointmentID'] ?? 0);
        if ($appointmentId > 0) {
            $agentStmt = $conn->prepare('SELECT agentID FROM appointments WHERE appointmentID = ? LIMIT 1');
            $agentStmt->bind_param('i', $appointmentId);
            $agentStmt->execute();
            $aRow = $agentStmt->get_result()->fetch_assoc();
            $agentStmt->close();
            if (!empty($aRow['agentID'])) {
                $assignedAgentId = (int) $aRow['agentID'];
            }
        }
        if ($assignedAgentId === 0) {
            $inquiryId = (int) ($tx['inquiryID'] ?? 0);
            if ($inquiryId > 0) {
                $inqStmt = $conn->prepare('SELECT agentID FROM agent_inquiry_assignments WHERE inquiryID = ? LIMIT 1');
                $inqStmt->bind_param('i', $inquiryId);
                $inqStmt->execute();
                $iRow = $inqStmt->get_result()->fetch_assoc();
                $inqStmt->close();
                if (!empty($iRow['agentID'])) {
                    $assignedAgentId = (int) $iRow['agentID'];
                }
            }
        }

        if ($assignedAgentId > 0) {
            create_user_notification(
                $conn,
                $assignedAgentId,
                'Buyer selected payment scheme',
                sprintf(
                    "Client %s chose '%s' as the payment scheme for '%s' (Transaction #KQT-%d).",
                    $tx['clientName'],
                    $schemeInfo['label'],
                    $tx['propertyTitle'],
                    $transactionId
                ),
                'transaction_tracking.php#transactions',
                'payment_scheme_selected'
            );
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function is_valid_transaction_currency_amount(float $amount): bool
{
    return is_finite($amount)
        && $amount >= 0
        && $amount <= 9999999999.99
        && abs($amount - round($amount, 2)) <= 0.000001;
}

function property_transaction_next_stages(string $stage, ?string $paymentMethod = null): array
{
    // ALGORITHM: State machine mapping each stage to allowed next stages; Spot Cash skips document verification.
    $isSpotCash = $paymentMethod !== null && (strcasecmp($paymentMethod, 'Spot Cash') === 0 || strcasecmp($paymentMethod, 'Spot cash') === 0);

    $transitions = [
        'inquiry_received' => ['contacting_client', 'viewing_requested', 'cancelled'],
        'contacting_client' => ['viewing_requested', 'cancelled'],
        'viewing_requested' => ['reviewing_schedule', 'viewing_confirmed', 'cancelled'],
        'reviewing_schedule' => ['viewing_confirmed', 'viewing_completed', 'cancelled'],
        'viewing_confirmed' => ['viewing_completed', 'payment_in_process', 'cancelled'],
        'viewing_completed' => ['payment_in_process', 'document_verification', 'cancelled'],
        'payment_in_process' => $isSpotCash
            ? ['payment_confirmation', 'cancelled']
            : ['document_verification', 'cancelled'],
        'document_verification' => ['payment_confirmation', 'cancelled'],
        'payment_confirmation' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    return $transitions[$stage] ?? [];
}

function get_transaction_stage_notification(
    string $prevStage,
    string $newStage,
    string $clientName,
    string $propertyTitle,
    string $note,
    ?float $salePrice = null,
    ?string $paymentMethod = null
): array {
    $cleanNote = trim($note);
    $noteSection = $cleanNote !== '' ? "\n\nSales Agent update: " . $cleanNote : '';

    if ($newStage === 'cancelled') {
        return [
            'title' => 'Property transaction cancelled',
            'message' => sprintf(
                "Hello %s,\n\nYour property transaction for '%s' has been marked as cancelled.%s\n\nIf you have any questions or would like to view another property, please reach out to our team.",
                $clientName,
                $propertyTitle,
                $cleanNote !== '' ? "\n\nReason: " . $cleanNote : ''
            ),
        ];
    }

    if ($newStage === 'reviewing_schedule') {
        return [
            'title' => 'Viewing request accepted — Reviewing schedule',
            'message' => sprintf(
                "Hello %s,\n\nYour viewing request for '%s' has been accepted by your Sales Agent. Your agent is now reviewing and coordinating the schedule details with you.%s\n\nCheck your dashboard or chat with your agent for updates.",
                $clientName,
                $propertyTitle,
                $noteSection
            ),
        ];
    }

    if ($newStage === 'viewing_confirmed') {
        return [
            'title' => 'Viewing schedule approved',
            'message' => sprintf(
                "Hello %s,\n\nYour property viewing for '%s' has been approved by your Sales Agent.%s\n\nPlease arrive on time for your scheduled property viewing. Check your dashboard for details.",
                $clientName,
                $propertyTitle,
                $noteSection
            ),
        ];
    }

    if ($newStage === 'viewing_completed') {
        return [
            'title' => 'Viewing completed — Next: Payment processing',
            'message' => sprintf(
                "Hello %s,\n\nYour property viewing for '%s' has been successfully completed! Your Sales Agent has completed the viewing.%s\n\nNext stage: Payment in process. Your Sales Agent will now coordinate payment terms and agreement paperwork.",
                $clientName,
                $propertyTitle,
                $noteSection
            ),
        ];
    }

    if ($newStage === 'payment_in_process') {
        return [
            'title' => 'Payment processing in progress',
            'message' => sprintf(
                "Hello %s,\n\nPayment processing is now underway for '%s'. Your Sales Agent is actively coordinating the payment terms, down payment / reservation, and paperwork.%s\n\nNext stage: Document verification.",
                $clientName,
                $propertyTitle,
                $noteSection
            ),
        ];
    }

    if ($newStage === 'document_verification') {
        $prevWasPayment = in_array($prevStage, ['payment_in_process', 'viewing_completed'], true);
        $title = $prevWasPayment
            ? 'Payment processing done — Document verification underway'
            : 'Document verification in progress';
        $intro = $prevWasPayment
            ? sprintf("Payment is done processing by your Sales Agent for '%s'! The transaction has now advanced to Document Verification.", $propertyTitle)
            : sprintf("Your transaction for '%s' has advanced to Document Verification.", $propertyTitle);

        return [
            'title' => $title,
            'message' => sprintf(
                "Hello %s,\n\n%s%s\n\nNext stage: Document verification. Your Sales Agent is reviewing property title deeds, tax declarations, and clearance documents.",
                $clientName,
                $intro,
                $noteSection
            ),
        ];
    }

    if ($newStage === 'payment_confirmation') {
        $isSpotCash = $paymentMethod !== null && (strcasecmp($paymentMethod, 'Spot Cash') === 0 || strcasecmp($paymentMethod, 'Spot cash') === 0);
        $prevWasDoc = ($prevStage === 'document_verification');
        
        if ($isSpotCash) {
            $title = 'Spot Cash payment processed — Payment confirmation underway';
            $intro = sprintf("Your Spot Cash payment has been processed by your Sales Agent for '%s'! Since Spot Cash was selected, document verification was skipped, and your purchase has moved directly to Payment Confirmation and turnover preparation.", $propertyTitle);
        } elseif ($prevWasDoc) {
            $title = 'Document verification done — Payment confirmation underway';
            $intro = sprintf("Document verification has been completed by your Sales Agent for '%s'! All required property legal documents, titles, and clearances have been verified.", $propertyTitle);
        } else {
            $title = 'Payment confirmation in progress';
            $intro = sprintf("Your transaction for '%s' has advanced to Payment Confirmation.", $propertyTitle);
        }

        return [
            'title' => $title,
            'message' => sprintf(
                "Hello %s,\n\n%s%s\n\nNext stage: Payment confirmation. Your Sales Agent is finalizing official receipts, billing clearance, and turnover documents.",
                $clientName,
                $intro,
                $noteSection
            ),
        ];
    }

    if ($newStage === 'completed') {
        $formattedPrice = $salePrice !== null ? '₱' . number_format($salePrice, 2) : 'Agreed price';
        return [
            'title' => 'Transaction completed — Property is officially yours!',
            'message' => sprintf(
                "Hello %s,\n\nCongratulations! Your property transaction for '%s' is officially completed and finalized, and the property is officially yours!\n\nVerified sale price: %s%s\n\nThank you for choosing 4 Kings Real Estate! You can review your transaction summary on your Client dashboard.",
                $clientName,
                $propertyTitle,
                $formattedPrice,
                $noteSection
            ),
        ];
    }

    $stageName = ucfirst(str_replace('_', ' ', $newStage));
    return [
        'title' => 'Transaction update: ' . $stageName,
        'message' => sprintf(
            "Hello %s,\n\nYour property transaction for '%s' has been updated to '%s'.%s\n\nPlease check your dashboard to review your progress.",
            $clientName,
            $propertyTitle,
            $stageName,
            $noteSection
        ),
    ];
}

function create_property_transaction(
    mysqli $conn,
    int $propertyId,
    int $clientId,
    string $source,
    int $sourceId,
    int $actorId
): void {
    if (!in_array($source, ['appointment', 'inquiry'], true) || $sourceId <= 0) {
        throw new InvalidArgumentException('Invalid transaction tracking source.');
    }

    $stage = 'viewing_requested';
    $appointmentId = $source === 'appointment' ? $sourceId : null;
    $inquiryId = $source === 'inquiry' ? $sourceId : null;
    $sourceColumn = $source === 'appointment' ? 'appointmentID' : 'inquiryID';
    $existingStmt = $conn->prepare(
        "SELECT transactionID, currentStage, $sourceColumn AS sourceID
         FROM property_transactions
         WHERE propertyID = ? AND clientID = ?
           AND currentStage NOT IN ('completed', 'cancelled')
           AND $sourceColumn IS NULL
         ORDER BY updatedAt DESC, transactionID DESC
         LIMIT 1
         FOR UPDATE"
    );
    $existingStmt->bind_param('ii', $propertyId, $clientId);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();

    if ($existing) {
        $transactionId = (int) $existing['transactionID'];
        $currentStage = (string) $existing['currentStage'];
        if (in_array($currentStage, ['inquiry_received', 'contacting_client'], true)) {
            $currentStage = 'viewing_requested';
        }
        $updateStmt = $conn->prepare(
            "UPDATE property_transactions
             SET $sourceColumn = ?, currentStage = ?, updatedAt = CURRENT_TIMESTAMP
             WHERE transactionID = ?"
        );
        $updateStmt->bind_param('isi', $sourceId, $currentStage, $transactionId);
        $updateStmt->execute();
        $updateStmt->close();

        $note = $source === 'appointment'
            ? 'Client requested a property viewing for this tracked inquiry.'
            : 'Client wants to view the property.';
        add_property_transaction_event($conn, $transactionId, $currentStage, $note, $actorId);
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO property_transactions
            (propertyID, clientID, appointmentID, inquiryID, currentStage)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iiiis', $propertyId, $clientId, $appointmentId, $inquiryId, $stage);
    $stmt->execute();

    $transactionId = (int) $conn->insert_id;
    $stmt->close();
    $note = $source === 'appointment'
        ? 'Client submitted a property viewing request.'
        : 'Client wants to view the property.';
    add_property_transaction_event($conn, $transactionId, $stage, $note, $actorId, 'initial');
}

function add_property_transaction_event(
    mysqli $conn,
    int $transactionId,
    string $stage,
    string $note,
    ?int $actorId,
    ?string $eventKey = null
): void {
    $stmt = $conn->prepare(
        'INSERT INTO property_transaction_events
            (transactionID, stage, note, changedBy, eventKey)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('issis', $transactionId, $stage, $note, $actorId, $eventKey);
    $stmt->execute();
    $stmt->close();
}

function get_verified_transaction_payment_total(mysqli $conn, int $transactionId): float
{
    // Retrieve verified payments from property_transaction_payments (operational tracker)
    $trackerStmt = $conn->prepare(
        "SELECT paymentID, amount, referenceNumber, submittedAt
         FROM property_transaction_payments
         WHERE transactionID = ? AND status = 'confirmed' AND amount > 0"
    );
    $trackerStmt->bind_param('i', $transactionId);
    $trackerStmt->execute();
    $trackerRows = $trackerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $trackerStmt->close();

    // Retrieve verified payments from payments (admin register)
    $registerStmt = $conn->prepare(
        "SELECT paymentID, amount, referenceNo, paidAt
         FROM payments
         WHERE transactionID = ? AND status = 'Verified' AND amount > 0"
    );
    $registerStmt->bind_param('i', $transactionId);
    $registerStmt->execute();
    $registerRows = $registerStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $registerStmt->close();

    // If only one ledger has records, return its sum directly
    if (empty($registerRows)) {
        return (float) array_sum(array_column($trackerRows, 'amount'));
    }
    if (empty($trackerRows)) {
        return (float) array_sum(array_column($registerRows, 'amount'));
    }

    // Both ledgers have records: Reconcile to avoid double-counting counterpart entries
    // while accurately accumulating distinct installment payments.
    $reconciledTotal = 0.0;
    $matchedRegisterIds = [];

    foreach ($trackerRows as $tRow) {
        $tAmount = (float) $tRow['amount'];
        $tRef = trim((string) ($tRow['referenceNumber'] ?? ''));
        $reconciledTotal += $tAmount;

        // Try to find counterpart in admin register
        foreach ($registerRows as $rRow) {
            $rId = (int) $rRow['paymentID'];
            if (isset($matchedRegisterIds[$rId])) {
                continue;
            }
            $rAmount = (float) $rRow['amount'];
            $rRef = trim((string) ($rRow['referenceNo'] ?? ''));

            $isRefMatch = ($tRef !== '' && $rRef !== '' && strcasecmp($tRef, $rRef) === 0);
            $isAmountMatch = (abs($tAmount - $rAmount) < 0.01 && ($tRef === '' || $rRef === ''));

            if ($isRefMatch || $isAmountMatch) {
                $matchedRegisterIds[$rId] = true;
                break;
            }
        }
    }

    // Add remaining unmatched verified payments from admin register (distinct installments)
    foreach ($registerRows as $rRow) {
        $rId = (int) $rRow['paymentID'];
        if (!isset($matchedRegisterIds[$rId])) {
            $reconciledTotal += (float) $rRow['amount'];
        }
    }

    return (float) $reconciledTotal;
}

function get_transaction_payment_records(mysqli $conn, array $transactionIds): array
{
    $transactionIds = array_values(array_unique(array_filter(
        array_map('intval', $transactionIds),
        static fn(int $transactionId): bool => $transactionId > 0
    )));
    if ($transactionIds === []) {
        return [];
    }

    $transactionIdList = implode(',', $transactionIds);
    $records = [];
    $trackerRows = $conn->query(
        "SELECT paymentID, transactionID, amount, paymentMethod, referenceNumber,
                status, submittedAt, reviewNote
         FROM property_transaction_payments
         WHERE transactionID IN ($transactionIdList)
         ORDER BY submittedAt DESC, paymentID DESC"
    );
    while ($payment = $trackerRows->fetch_assoc()) {
        $payment['status'] = strtolower((string) $payment['status']);
        $payment['sourceLabel'] = 'Client report';
        $records[(int) $payment['transactionID']][] = $payment;
    }

    $registerRows = $conn->query(
        "SELECT paymentID, transactionID, amount, paymentMethod, referenceNo AS referenceNumber,
                status, paidAt AS submittedAt, note AS reviewNote
         FROM payments
         WHERE transactionID IN ($transactionIdList)
         ORDER BY paidAt DESC, paymentID DESC"
    );
    while ($payment = $registerRows->fetch_assoc()) {
        $payment['status'] = match ((string) $payment['status']) {
            'Verified' => 'confirmed',
            'Rejected' => 'rejected',
            default => 'pending',
        };
        $payment['sourceLabel'] = 'Staff record';
        $records[(int) $payment['transactionID']][] = $payment;
    }

    foreach ($records as &$transactionRecords) {
        usort(
            $transactionRecords,
            static fn(array $left, array $right): int => strcmp(
                (string) $right['submittedAt'],
                (string) $left['submittedAt']
            ) ?: ((int) $right['paymentID'] <=> (int) $left['paymentID'])
        );
    }
    unset($transactionRecords);

    return $records;
}

function assert_staff_transaction_access(mysqli $conn, array $transaction, int $actorId): void
{
    $actorStmt = $conn->prepare('SELECT role, accountStatus FROM users WHERE userID = ? LIMIT 1');
    $actorStmt->bind_param('i', $actorId);
    $actorStmt->execute();
    $actor = $actorStmt->get_result()->fetch_assoc();
    $actorStmt->close();

    if (!$actor || ($actor['accountStatus'] ?? 'Inactive') !== 'Active') {
        throw new InvalidArgumentException('An active staff account is required to manage transactions.');
    }

    $role = strtolower(trim((string) $actor['role']));
    if ($role === 'admin') {
        return;
    }
    if (!in_array($role, ['agent', 'sales agent'], true)) {
        throw new InvalidArgumentException('Only an Admin or the assigned Sales Agent can manage this transaction.');
    }

    $hasAssignedAppointmentAgent = false;
    $appointmentId = (int) ($transaction['appointmentID'] ?? 0);
    if ($appointmentId > 0) {
        $appointmentStmt = $conn->prepare('SELECT agentID FROM appointments WHERE appointmentID = ? LIMIT 1');
        $appointmentStmt->bind_param('i', $appointmentId);
        $appointmentStmt->execute();
        $appointment = $appointmentStmt->get_result()->fetch_assoc();
        $appointmentStmt->close();
        if ($appointment && (int) ($appointment['agentID'] ?? 0) === $actorId) {
            return;
        }
        if (!empty($appointment['agentID'])) {
            $hasAssignedAppointmentAgent = true;
        }
    }

    $hasAssignedInquiryAgent = false;
    $inquiryId = (int) ($transaction['inquiryID'] ?? 0);
    if ($inquiryId > 0) {
        $inquiryStmt = $conn->prepare(
            'SELECT agentID FROM agent_inquiry_assignments WHERE inquiryID = ? LIMIT 1'
        );
        $inquiryStmt->bind_param('i', $inquiryId);
        $inquiryStmt->execute();
        $inquiry = $inquiryStmt->get_result()->fetch_assoc();
        $inquiryStmt->close();
        if ($inquiry && (int) ($inquiry['agentID'] ?? 0) === $actorId) {
            return;
        }
        if (!empty($inquiry['agentID'])) {
            $hasAssignedInquiryAgent = true;
        }
    }

    // If transaction is not yet assigned to any agent, allow the active sales agent to manage/claim it
    if (!$hasAssignedAppointmentAgent && !$hasAssignedInquiryAgent) {
        return;
    }

    throw new InvalidArgumentException('You may only manage transactions assigned to your account.');
}

function dispatch_transaction_notification_after_commit(
    mysqli $conn,
    int $clientId,
    array $notification,
    string $context
): void {
    try {
        $saved = send_property_notification(
            $conn,
            $clientId,
            (string) ($notification['title'] ?? ''),
            (string) ($notification['message'] ?? '')
        );
        if (!$saved) {
            error_log('KingQuiry ' . $context . ' notification could not be saved.');
        }
    } catch (Throwable $exception) {
        error_log('KingQuiry ' . $context . ' notification failed: ' . $exception->getMessage());
    }
}

function close_competing_transactions_for_sold_property(
    mysqli $conn,
    int $propertyId,
    int $completedTransactionId,
    int $actorId
): array {
    $stmt = $conn->prepare(
        "SELECT t.transactionID, t.clientID, t.appointmentID, t.currentStage,
                p.title AS propertyTitle, u.fullname AS clientName
         FROM property_transactions t
         JOIN properties p ON p.propertyID = t.propertyID
         JOIN users u ON u.userID = t.clientID
         WHERE t.propertyID = ? AND t.transactionID <> ?
           AND t.currentStage NOT IN ('completed', 'cancelled')
         FOR UPDATE"
    );
    $stmt->bind_param('ii', $propertyId, $completedTransactionId);
    $stmt->execute();
    $competingTransactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $notifications = [];
    $cancelStmt = $conn->prepare(
        "UPDATE property_transactions
         SET currentStage = 'cancelled', updatedAt = CURRENT_TIMESTAMP
         WHERE transactionID = ? AND currentStage NOT IN ('completed', 'cancelled')"
    );
    $cancelAppointmentStmt = $conn->prepare(
        "UPDATE appointments SET status = 'Cancelled' WHERE appointmentID = ?"
    );

    foreach ($competingTransactions as $competingTransaction) {
        $competingTransactionId = (int) $competingTransaction['transactionID'];
        $cancelStmt->bind_param('i', $competingTransactionId);
        $cancelStmt->execute();
        if ($cancelStmt->affected_rows !== 1) {
            continue;
        }

        $cancellationNote = 'This property is no longer available. This transaction has been closed.';
        add_property_transaction_event(
            $conn,
            $competingTransactionId,
            'cancelled',
            $cancellationNote,
            $actorId
        );
        $appointmentId = (int) ($competingTransaction['appointmentID'] ?? 0);
        if ($appointmentId > 0) {
            $cancelAppointmentStmt->bind_param('i', $appointmentId);
            $cancelAppointmentStmt->execute();
        }

        $notification = get_transaction_stage_notification(
            (string) $competingTransaction['currentStage'],
            'cancelled',
            (string) $competingTransaction['clientName'],
            (string) $competingTransaction['propertyTitle'],
            $cancellationNote
        );
        $notification['clientId'] = (int) $competingTransaction['clientID'];
        $notifications[] = $notification;
    }

    $cancelStmt->close();
    $cancelAppointmentStmt->close();

    return $notifications;
}

function advance_property_transaction(
    mysqli $conn,
    int $transactionId,
    string $newStage,
    string $note,
    int $actorId,
    ?float $salePrice = null,
    ?float $paymentAmount = null,
    ?string $paymentMethod = null,
    ?string $paymentRef = null
): void {
    $stages = property_transaction_stages();
    if (!isset($stages[$newStage]) || $transactionId <= 0 || $actorId <= 0) {
        throw new InvalidArgumentException('Choose a valid transaction and status.');
    }

    if ($newStage === 'completed' && ($salePrice === null || !is_valid_transaction_currency_amount($salePrice) || $salePrice <= 0)) {
        throw new InvalidArgumentException('Enter the verified sale price before marking this transaction successful.');
    }
    if ($paymentAmount !== null && !is_valid_transaction_currency_amount($paymentAmount)) {
        throw new InvalidArgumentException('Enter a non-negative payment amount with no more than two decimal places.');
    }
    if ($paymentRef !== null && strlen($paymentRef) > 100) {
        throw new InvalidArgumentException('The payment reference must be 100 characters or fewer.');
    }
    if ($paymentMethod !== null && $paymentMethod !== '' && !in_array($paymentMethod, property_staff_payment_methods(), true)) {
        throw new InvalidArgumentException('Choose a supported payment method.');
    }
    if ($note !== '' && strlen($note) > 1000) {
        throw new InvalidArgumentException('Tracking notes must be 1,000 characters or fewer.');
    }

    $notification = null;
    $competingSaleNotifications = [];
    $conn->begin_transaction();
    try {
        $currentStmt = $conn->prepare(
            'SELECT transactionID, propertyID, clientID, appointmentID, inquiryID, currentStage, paymentMethod FROM property_transactions
             WHERE transactionID = ? FOR UPDATE'
        );
        $currentStmt->bind_param('i', $transactionId);
        $currentStmt->execute();
        $transaction = $currentStmt->get_result()->fetch_assoc();
        $currentStmt->close();
        if (!$transaction) {
            throw new InvalidArgumentException('The selected transaction was not found.');
        }
        assert_staff_transaction_access($conn, $transaction, $actorId);

        $currentStage = (string) $transaction['currentStage'];
        $effectivePaymentMethod = ($paymentMethod !== null && $paymentMethod !== '')
            ? $paymentMethod
            : ($transaction['paymentMethod'] ?? null);

        $allowedStages = property_transaction_next_stages($currentStage, $effectivePaymentMethod);
        $fallbackStages = property_transaction_next_stages($currentStage, null);
        if (!in_array($newStage, $allowedStages, true) && !in_array($newStage, $fallbackStages, true)) {
            throw new InvalidArgumentException('That step cannot follow the current status. Update the transaction in order or cancel it.');
        }

        if ($newStage === 'reviewing_schedule' || $newStage === 'viewing_confirmed') {
            $appointmentId = (int) ($transaction['appointmentID'] ?? 0);
            if ($appointmentId > 0) {
                $assignedAgentStmt = $conn->prepare(
                    "SELECT a.agentID
                     FROM appointments a
                     JOIN users ag ON ag.userID = a.agentID
                     WHERE a.appointmentID = ?
                       AND ag.role IN ('Agent', 'Sales Agent')
                       AND ag.accountStatus = 'Active'
                     FOR UPDATE"
                );
                $assignedAgentStmt->bind_param('i', $appointmentId);
                $assignedAgentStmt->execute();
                $assignedAgentRow = $assignedAgentStmt->get_result()->fetch_assoc();
                $assignedAgentStmt->close();

                if (!$assignedAgentRow) {
                    $assignApptStmt = $conn->prepare(
                        "UPDATE appointments SET agentID = ? WHERE appointmentID = ? AND (agentID IS NULL OR agentID = 0)"
                    );
                    $assignApptStmt->bind_param('ii', $actorId, $appointmentId);
                    $assignApptStmt->execute();
                    $assignApptStmt->close();
                }
            }

            if ($newStage === 'viewing_confirmed') {
                $propertyLockStmt = $conn->prepare(
                    'SELECT propertyID FROM properties WHERE propertyID = ? FOR UPDATE'
                );
                $propertyId = (int) $transaction['propertyID'];
                $propertyLockStmt->bind_param('i', $propertyId);
                $propertyLockStmt->execute();
                if (!$propertyLockStmt->get_result()->fetch_assoc()) {
                    throw new InvalidArgumentException('The property for this viewing request could not be found.');
                }
                $propertyLockStmt->close();

                $otherActiveViewing = get_active_property_viewing_request($conn, $propertyId, $appointmentId);
                if ($otherActiveViewing !== null) {
                    $existingDate = date('M j, Y', strtotime((string) $otherActiveViewing['appointmentDate']));
                    $existingTime = date('g:i A', strtotime((string) $otherActiveViewing['appointmentTime']));
                    throw new InvalidArgumentException(sprintf(
                        'Another viewing request (#KQ-%d) is already active for this property on %s at %s. Complete or cancel it before approving this one.',
                        1000 + (int) $otherActiveViewing['appointmentID'],
                        $existingDate,
                        $existingTime
                    ));
                }
            }

            $inquiryId = (int) ($transaction['inquiryID'] ?? 0);
            if ($inquiryId > 0) {
                $inqAssignStmt = $conn->prepare(
                    "INSERT INTO agent_inquiry_assignments (inquiryID, agentID, assignedAt)
                     VALUES (?, ?, CURRENT_TIMESTAMP)
                     ON DUPLICATE KEY UPDATE agentID = COALESCE(agentID, VALUES(agentID))"
                );
                $inqAssignStmt->bind_param('ii', $inquiryId, $actorId);
                $inqAssignStmt->execute();
                $inqAssignStmt->close();
            }
        }

        // Retrieve acting staff's name for explicit update attribution
        $actorName = 'Sales Agent';
        $actorNameStmt = $conn->prepare('SELECT fullname FROM users WHERE userID = ? LIMIT 1');
        $actorNameStmt->bind_param('i', $actorId);
        $actorNameStmt->execute();
        $actorNameRow = $actorNameStmt->get_result()->fetch_assoc();
        $actorNameStmt->close();
        if (!empty($actorNameRow['fullname'])) {
            $actorName = trim((string) $actorNameRow['fullname']);
        }

        $cleanNote = trim($note);
        if ($currentStage === 'viewing_requested' && ($newStage === 'reviewing_schedule' || $newStage === 'viewing_confirmed')) {
            $viewingAcceptedPrefix = 'Viewing accepted by ' . $actorName;
            if ($cleanNote !== '') {
                if (stripos($cleanNote, 'viewing accepted') !== false) {
                    $effectiveNote = $cleanNote;
                } else {
                    $effectiveNote = $viewingAcceptedPrefix . ': ' . $cleanNote;
                }
            } else {
                $effectiveNote = $viewingAcceptedPrefix;
            }
        } elseif ($newStage === 'cancelled' && $currentStage === 'viewing_requested') {
            $viewingCancelledPrefix = 'Viewing cancelled by ' . $actorName;
            if ($cleanNote !== '') {
                $effectiveNote = $viewingCancelledPrefix . ': ' . $cleanNote;
            } else {
                $effectiveNote = $viewingCancelledPrefix;
            }
        } elseif ($currentStage === 'reviewing_schedule' && $newStage === 'viewing_confirmed') {
            $confirmPrefix = 'Viewing schedule confirmed by ' . $actorName;
            if ($cleanNote !== '') {
                if (stripos($cleanNote, 'confirmed by') !== false || stripos($cleanNote, 'viewing schedule confirmed') !== false) {
                    $effectiveNote = $cleanNote;
                } else {
                    $effectiveNote = $confirmPrefix . ': ' . $cleanNote;
                }
            } else {
                $effectiveNote = $confirmPrefix . '.';
            }
        } else {
            $defaultNotes = [
                'reviewing_schedule' => 'Viewing accepted by ' . $actorName,
                'viewing_confirmed' => 'Viewing schedule confirmed by ' . $actorName . '.',
                'viewing_completed' => 'Sales Agent team completed the property viewing with the Client.',
                'payment_in_process' => 'Payment processing initiated: Sales Agent is coordinating payment terms and paperwork.',
                'document_verification' => 'Payment processing completed. Sales Agent is verifying property title deeds, clearances, and documents.',
                'payment_confirmation' => 'Document verification completed. Sales Agent is finalizing payment confirmation and receipts.',
                'completed' => 'Payment confirmation complete. Transaction finalized and property successfully turned over.',
                'cancelled' => 'Transaction was cancelled.',
            ];
            $effectiveNote = $cleanNote !== '' ? $cleanNote : ($defaultNotes[$newStage] ?? 'Transaction advanced to ' . str_replace('_', ' ', $newStage));
        }

        // Record payment if agent provided payment details in this submission so it counts toward verification
        $clientId = (int) $transaction['clientID'];
        if ($paymentAmount !== null && $paymentAmount > 0) {
            if (
                empty($effectivePaymentMethod)
                || !in_array($effectivePaymentMethod, property_staff_payment_methods(), true)
            ) {
                throw new InvalidArgumentException('Choose a supported payment method before recording a payment.');
            }
            if (!in_array($newStage, ['payment_in_process', 'document_verification', 'payment_confirmation', 'completed'], true)) {
                throw new InvalidArgumentException('Payment details can only be recorded during payment processing or finalization.');
            }
            $refNumber = !empty($paymentRef) ? $paymentRef : 'Paperwork verified';
            $insPayment = $conn->prepare(
                "INSERT INTO property_transaction_payments
                    (transactionID, clientID, amount, paymentMethod, referenceNumber, status, reviewedAt, reviewedBy, reviewNote)
                 VALUES (?, ?, ?, ?, ?, 'confirmed', CURRENT_TIMESTAMP, ?, ?)"
            );
            $insPayment->bind_param('iidssis', $transactionId, $clientId, $paymentAmount, $effectivePaymentMethod, $refNumber, $actorId, $effectiveNote);
            $insPayment->execute();
            $insPayment->close();
        }

        if ($newStage === 'completed') {
            $propertyId = (int) $transaction['propertyID'];
            $propertyStmt = $conn->prepare(
                "SELECT status, isDeleted FROM properties WHERE propertyID = ? FOR UPDATE"
            );
            $propertyStmt->bind_param('i', $propertyId);
            $propertyStmt->execute();
            $property = $propertyStmt->get_result()->fetch_assoc();
            $propertyStmt->close();
            if (!$property || (int) $property['isDeleted'] !== 0 || $property['status'] !== 'Available') {
                throw new InvalidArgumentException('This property is no longer available for sale.');
            }

            $verifiedPaymentTotal = get_verified_transaction_payment_total($conn, $transactionId);
            if ($verifiedPaymentTotal + 0.01 < (float) $salePrice) {
                throw new InvalidArgumentException(
                    'The verified payment total must cover the recorded sale price before completing this transaction.'
                );
            }

            $updateStmt = $conn->prepare(
                'UPDATE property_transactions
                 SET currentStage = ?, salePrice = ?, paymentMethod = COALESCE(?, paymentMethod), updatedAt = CURRENT_TIMESTAMP
                 WHERE transactionID = ?'
            );
            $updateStmt->bind_param('sdsi', $newStage, $salePrice, $effectivePaymentMethod, $transactionId);
            $updateStmt->execute();
            $updateStmt->close();

            // Property officially sold & turned over: mark as 'Sold' so it disappears from public listings
            $soldStmt = $conn->prepare("UPDATE properties SET status = 'Sold' WHERE propertyID = ?");
            $soldStmt->bind_param('i', $propertyId);
            $soldStmt->execute();
            $soldStmt->close();
            $competingSaleNotifications = close_competing_transactions_for_sold_property(
                $conn,
                $propertyId,
                $transactionId,
                $actorId
            );
        } else {
            $updateStmt = $conn->prepare(
                'UPDATE property_transactions
                 SET currentStage = ?, paymentMethod = COALESCE(?, paymentMethod), updatedAt = CURRENT_TIMESTAMP
                 WHERE transactionID = ?'
            );
            $updateStmt->bind_param('ssi', $newStage, $effectivePaymentMethod, $transactionId);
            $updateStmt->execute();
            $updateStmt->close();
        }
        add_property_transaction_event($conn, $transactionId, $newStage, $effectiveNote, $actorId);

        if ($newStage === 'payment_confirmation') {
            if (get_verified_transaction_payment_total($conn, $transactionId) <= 0.0) {
                throw new InvalidArgumentException(
                    'Record and verify a payment greater than zero before moving this transaction to payment confirmation.'
                );
            }
        }

        if ($newStage === 'viewing_confirmed' || $newStage === 'cancelled') {
            $appointmentStatus = $newStage === 'viewing_confirmed' ? 'Approved' : 'Cancelled';
            $appointmentStmt = $conn->prepare(
                'UPDATE appointments a
                 JOIN property_transactions t ON t.appointmentID = a.appointmentID
                 SET a.status = ?, a.agentID = COALESCE(a.agentID, ?)
                 WHERE t.transactionID = ?'
            );
            $appointmentStmt->bind_param('sii', $appointmentStatus, $actorId, $transactionId);
            $appointmentStmt->execute();
            $appointmentStmt->close();
        }

        $detailsStmt = $conn->prepare(
            'SELECT t.propertyID, t.clientID, p.title, u.fullname
             FROM property_transactions t
             JOIN properties p ON p.propertyID = t.propertyID
             JOIN users u ON u.userID = t.clientID
             WHERE t.transactionID = ?
             LIMIT 1'
        );
        $detailsStmt->bind_param('i', $transactionId);
        $detailsStmt->execute();
        $details = $detailsStmt->get_result()->fetch_assoc();
        $detailsStmt->close();

        if (is_array($details) && !empty($details['clientID'])) {
            $notification = get_transaction_stage_notification(
                $currentStage,
                $newStage,
                (string) ($details['fullname'] ?? 'there'),
                (string) ($details['title'] ?? 'the property'),
                $effectiveNote,
                $salePrice,
                $effectivePaymentMethod
            );
            $notification['clientId'] = (int) $details['clientID'];
        }

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    if ($notification !== null) {
        dispatch_transaction_notification_after_commit(
            $conn,
            (int) $notification['clientId'],
            $notification,
            'transaction update'
        );
    }
    foreach ($competingSaleNotifications as $competingSaleNotification) {
        dispatch_transaction_notification_after_commit(
            $conn,
            (int) $competingSaleNotification['clientId'],
            $competingSaleNotification,
            'competing transaction cancellation'
        );
    }
}

function submit_property_transaction_payment(
    mysqli $conn,
    int $transactionId,
    int $clientId,
    float $amount,
    string $paymentMethod,
    string $referenceNumber
): void {
    $paymentMethod = trim($paymentMethod);
    $referenceNumber = trim($referenceNumber);
    if ($transactionId <= 0 || $clientId <= 0 || !is_valid_transaction_currency_amount($amount) || $amount <= 0) {
        throw new InvalidArgumentException('Enter a valid payment amount.');
    }
    if (!in_array($paymentMethod, property_client_payment_methods(), true)) {
        throw new InvalidArgumentException('Choose Cash, Bank Transfer, or Check as the payment method.');
    }
    if (strlen($referenceNumber) > 100) {
        throw new InvalidArgumentException('The payment reference must be 100 characters or fewer.');
    }

    $conn->begin_transaction();
    try {
        $transactionStmt = $conn->prepare(
            'SELECT currentStage FROM property_transactions
             WHERE transactionID = ? AND clientID = ?
             FOR UPDATE'
        );
        $transactionStmt->bind_param('ii', $transactionId, $clientId);
        $transactionStmt->execute();
        $transaction = $transactionStmt->get_result()->fetch_assoc();
        if (!$transaction) {
            throw new InvalidArgumentException('The selected transaction was not found.');
        }
        if (!in_array((string) $transaction['currentStage'], ['payment_in_process', 'document_verification', 'payment_confirmation'], true)) {
            throw new InvalidArgumentException('Payment details can be submitted during payment in process, document verification, or payment confirmation.');
        }

        // Deduplication check: prevent submitting the same payment reference or duplicate reporting within 120 seconds
        if ($referenceNumber !== '') {
            $dupPayStmt = $conn->prepare(
                'SELECT paymentID FROM property_transaction_payments
                 WHERE transactionID = ? AND clientID = ? AND referenceNumber = ?
                 LIMIT 1'
            );
            $dupPayStmt->bind_param('iis', $transactionId, $clientId, $referenceNumber);
            $dupPayStmt->execute();
            if ($dupPayStmt->get_result()->fetch_assoc()) {
                throw new InvalidArgumentException('You have already submitted a payment record with this reference number.');
            }
        } else {
            $dupPayStmt = $conn->prepare(
                'SELECT paymentID FROM property_transaction_payments
                 WHERE transactionID = ? AND clientID = ? AND amount = ? AND paymentMethod = ?
                   AND submittedAt >= DATE_SUB(NOW(), INTERVAL 120 SECOND)
                 LIMIT 1'
            );
            $dupPayStmt->bind_param('iids', $transactionId, $clientId, $amount, $paymentMethod);
            $dupPayStmt->execute();
            if ($dupPayStmt->get_result()->fetch_assoc()) {
                throw new InvalidArgumentException('You have recently submitted this payment details for staff review. Please allow our team time to process it.');
            }
        }

        $paymentStmt = $conn->prepare(
            "INSERT INTO property_transaction_payments
                (transactionID, clientID, amount, paymentMethod, referenceNumber)
             VALUES (?, ?, ?, ?, ?)"
        );
        $paymentStmt->bind_param('iidss', $transactionId, $clientId, $amount, $paymentMethod, $referenceNumber);
        $paymentStmt->execute();
        $paymentNote = sprintf(
            'Client submitted a payment of PHP %s via %s for staff review.',
            number_format($amount, 2),
            $paymentMethod
        );
        add_property_transaction_event(
            $conn,
            $transactionId,
            (string) $transaction['currentStage'],
            $paymentNote,
            $clientId
        );
        $touchStmt = $conn->prepare(
            'UPDATE property_transactions SET updatedAt = CURRENT_TIMESTAMP WHERE transactionID = ?'
        );
        $touchStmt->bind_param('i', $transactionId);
        $touchStmt->execute();
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function record_staff_face_to_face_payment(
    mysqli $conn,
    int $transactionId,
    string $status,
    string $note,
    int $actorId
): void {
    $note = trim($note);
    $status = trim($status);
    if (!in_array($status, ['confirmed', 'in_process'], true)) {
        throw new InvalidArgumentException('Select a valid payment status (Confirmed or In process).');
    }
    if ($transactionId <= 0 || $actorId <= 0) {
        throw new InvalidArgumentException('Choose a valid transaction.');
    }
    if (strlen($note) > 1000) {
        throw new InvalidArgumentException('The paperwork note must be 1,000 characters or fewer.');
    }

    $notification = null;
    $conn->begin_transaction();
    try {
        $transactionStmt = $conn->prepare(
            'SELECT t.transactionID, t.clientID, t.appointmentID, t.inquiryID, t.currentStage, p.title, u.fullname
             FROM property_transactions t
             JOIN properties p ON p.propertyID = t.propertyID
             JOIN users u ON u.userID = t.clientID
             WHERE t.transactionID = ?
             FOR UPDATE'
        );
        $transactionStmt->bind_param('i', $transactionId);
        $transactionStmt->execute();
        $transaction = $transactionStmt->get_result()->fetch_assoc();
        if (!$transaction) {
            throw new InvalidArgumentException('The selected transaction was not found.');
        }
        assert_staff_transaction_access($conn, $transaction, $actorId);

        $currentStage = (string) $transaction['currentStage'];
        if (in_array($currentStage, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Payments cannot be recorded on completed or cancelled transactions.');
        }

        $clientId = (int) $transaction['clientID'];
        $isConfirmed = ($status === 'confirmed');
        $newStage = $isConfirmed ? 'payment_confirmation' : 'payment_in_process';
        if ($newStage !== $currentStage && !in_array($newStage, property_transaction_next_stages($currentStage), true)) {
            throw new InvalidArgumentException('Payment and paperwork cannot advance from the current transaction stage.');
        }
        if ($isConfirmed && get_verified_transaction_payment_total($conn, $transactionId) <= 0.0) {
            throw new InvalidArgumentException(
                'Record and verify a payment greater than zero before confirming payment and paperwork.'
            );
        }

        $updateStageStmt = $conn->prepare(
            'UPDATE property_transactions SET currentStage = ?, updatedAt = CURRENT_TIMESTAMP WHERE transactionID = ?'
        );
        $updateStageStmt->bind_param('si', $newStage, $transactionId);
        $updateStageStmt->execute();

        if ($isConfirmed) {
            $eventNote = sprintf(
                'Sales Agent recorded face-to-face payment and paperwork (Status: confirmed).%s',
                $note !== '' ? ' Note: ' . $note : ' All paperwork and payment confirmed.'
            );
        } else {
            $eventNote = sprintf(
                'Payment and paperwork in process with client.%s',
                $note !== '' ? ' Note: ' . $note : ' Paperwork currently being processed.'
            );
        }

        add_property_transaction_event(
            $conn,
            $transactionId,
            $newStage,
            $eventNote,
            $actorId
        );

        $notificationSubject = $isConfirmed ? 'Payment & paperwork confirmed' : 'Payment & paperwork in process';
        $notificationMessage = sprintf(
            "Hello %s,\n\nYour property transaction for '%s' has updated payment and paperwork status to '%s'.%s\n\nPlease check your client dashboard to review your transaction progress.",
            (string) ($transaction['fullname'] ?? 'there'),
            (string) ($transaction['title'] ?? 'the property'),
            $isConfirmed ? 'Confirmed' : 'In process',
            $note !== '' ? "\nNote: " . $note : ''
        );
        $notification = [
            'clientId' => $clientId,
            'title' => $notificationSubject,
            'message' => $notificationMessage,
        ];

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    if ($notification !== null) {
        dispatch_transaction_notification_after_commit($conn, $notification['clientId'], $notification, 'face-to-face payment');
    }
}

function record_staff_transaction_payment(
    mysqli $conn,
    int $transactionId,
    float $amount,
    string $paymentMethod,
    string $referenceNumber,
    string $paymentStatus,
    string $note,
    int $actorId,
    bool $advanceToPaymentInProcess = true
): void {
    $paymentMethod = trim($paymentMethod);
    $referenceNumber = trim($referenceNumber);
    $note = trim($note);

    if ($transactionId <= 0 || $actorId <= 0 || !is_valid_transaction_currency_amount($amount) || $amount <= 0) {
        throw new InvalidArgumentException('Enter a valid payment amount.');
    }
    if (!in_array($paymentMethod, property_staff_payment_methods(), true)) {
        throw new InvalidArgumentException('Choose a supported payment method.');
    }
    if (strlen($referenceNumber) > 100) {
        throw new InvalidArgumentException('The payment reference must be 100 characters or fewer.');
    }
    if (!in_array($paymentStatus, ['confirmed', 'pending'], true)) {
        throw new InvalidArgumentException('Select a valid payment status (Confirmed or Pending review).');
    }
    if (strlen($note) > 1000) {
        throw new InvalidArgumentException('The payment note must be 1,000 characters or fewer.');
    }

    $isSpotCash = strcasecmp($paymentMethod, 'Spot cash') === 0;
    if ($isSpotCash) {
        $paymentStatus = 'confirmed';
        $advanceToPaymentInProcess = true;
    }

    $notification = null;
    $conn->begin_transaction();
    try {
        $transactionStmt = $conn->prepare(
            'SELECT t.transactionID, t.clientID, t.appointmentID, t.inquiryID, t.currentStage, p.title, u.fullname
             FROM property_transactions t
             JOIN properties p ON p.propertyID = t.propertyID
             JOIN users u ON u.userID = t.clientID
             WHERE t.transactionID = ?
             FOR UPDATE'
        );
        $transactionStmt->bind_param('i', $transactionId);
        $transactionStmt->execute();
        $transaction = $transactionStmt->get_result()->fetch_assoc();
        if (!$transaction) {
            throw new InvalidArgumentException('The selected transaction was not found.');
        }
        assert_staff_transaction_access($conn, $transaction, $actorId);

        $currentStage = (string) $transaction['currentStage'];
        if (in_array($currentStage, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Payments cannot be recorded on completed or cancelled transactions.');
        }

        $clientId = (int) $transaction['clientID'];
        if ($paymentStatus === 'confirmed') {
            $paymentStmt = $conn->prepare(
                "INSERT INTO property_transaction_payments
                    (transactionID, clientID, amount, paymentMethod, referenceNumber, status, reviewedAt, reviewedBy, reviewNote)
                 VALUES (?, ?, ?, ?, ?, 'confirmed', CURRENT_TIMESTAMP, ?, ?)"
            );
            $paymentStmt->bind_param('iidssis', $transactionId, $clientId, $amount, $paymentMethod, $referenceNumber, $actorId, $note);
        } else {
            $paymentStmt = $conn->prepare(
                "INSERT INTO property_transaction_payments
                    (transactionID, clientID, amount, paymentMethod, referenceNumber, status)
                 VALUES (?, ?, ?, ?, ?, 'pending')"
            );
            $paymentStmt->bind_param('iidss', $transactionId, $clientId, $amount, $paymentMethod, $referenceNumber);
        }
        $paymentStmt->execute();

        $newStage = $currentStage;
        $shouldAdvance = $advanceToPaymentInProcess || in_array($currentStage, ['viewing_completed', 'viewing_confirmed'], true);
        if ($shouldAdvance && in_array($currentStage, ['inquiry_received', 'contacting_client', 'viewing_requested', 'viewing_confirmed', 'viewing_completed'], true)) {
            $newStage = 'payment_in_process';
            $updateStageStmt = $conn->prepare(
                'UPDATE property_transactions SET currentStage = ?, updatedAt = CURRENT_TIMESTAMP WHERE transactionID = ?'
            );
            $updateStageStmt->bind_param('si', $newStage, $transactionId);
            $updateStageStmt->execute();
        } else {
            $touchStmt = $conn->prepare(
                'UPDATE property_transactions SET updatedAt = CURRENT_TIMESTAMP WHERE transactionID = ?'
            );
            $touchStmt->bind_param('i', $transactionId);
            $touchStmt->execute();
        }

        $formattedAmount = number_format($amount, 2);
        $eventNote = sprintf(
            'Sales Agent recorded face-to-face payment of PHP %s via %s (Status: %s).%s',
            $formattedAmount,
            $paymentMethod,
            $paymentStatus,
            $note !== '' ? ' Note: ' . $note : ''
        );
        add_property_transaction_event(
            $conn,
            $transactionId,
            $newStage,
            $eventNote,
            $actorId
        );

        $notificationSubject = 'Face-to-face payment recorded (' . ucfirst($paymentStatus) . ')';
        $notificationMessage = sprintf(
            "Hello %s,\n\nYour Sales Agent recorded a payment of PHP %s for '%s' via %s during/following your property viewing.\nStatus: %s%s\n\nPlease check your Client dashboard to view your transaction progress.",
            (string) ($transaction['fullname'] ?? 'there'),
            $formattedAmount,
            (string) ($transaction['title'] ?? 'the property'),
            $paymentMethod,
            ucfirst($paymentStatus),
            $note !== '' ? "\nNote: " . $note : ''
        );
        $notification = [
            'clientId' => $clientId,
            'title' => $notificationSubject,
            'message' => $notificationMessage,
        ];

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    if ($notification !== null) {
        dispatch_transaction_notification_after_commit($conn, $notification['clientId'], $notification, 'staff payment');
    }
}

function review_property_transaction_payment(
    mysqli $conn,
    int $paymentId,
    string $status,
    string $reviewNote,
    int $actorId
): void {
    if ($paymentId <= 0 || $actorId <= 0 || !in_array($status, ['confirmed', 'rejected'], true)) {
        throw new InvalidArgumentException('Choose a valid payment and review status.');
    }
    if (strlen($reviewNote) > 1000) {
        throw new InvalidArgumentException('The payment review note must be 1,000 characters or fewer.');
    }

    $notification = null;
    $conn->begin_transaction();
    try {
        $paymentStmt = $conn->prepare(
            'SELECT p.transactionID, p.clientID, p.amount, p.paymentMethod, p.status,
                    t.appointmentID, t.inquiryID,
                    t.currentStage, u.fullname, pr.title
             FROM property_transaction_payments p
             JOIN property_transactions t ON t.transactionID = p.transactionID
             JOIN users u ON u.userID = p.clientID
             JOIN properties pr ON pr.propertyID = t.propertyID
             WHERE p.paymentID = ?
             FOR UPDATE'
        );
        $paymentStmt->bind_param('i', $paymentId);
        $paymentStmt->execute();
        $payment = $paymentStmt->get_result()->fetch_assoc();
        if (!$payment) {
            throw new InvalidArgumentException('The selected payment was not found.');
        }
        assert_staff_transaction_access($conn, $payment, $actorId);
        if ($payment['status'] !== 'pending') {
            throw new InvalidArgumentException('This payment has already been reviewed.');
        }

        $transactionId = (int) $payment['transactionID'];
        $updateStmt = $conn->prepare(
            'UPDATE property_transaction_payments
             SET status = ?, reviewedAt = CURRENT_TIMESTAMP, reviewedBy = ?, reviewNote = ?
             WHERE paymentID = ?'
        );
        $updateStmt->bind_param('sisi', $status, $actorId, $reviewNote, $paymentId);
        $updateStmt->execute();
        $touchStmt = $conn->prepare(
            'UPDATE property_transactions SET updatedAt = CURRENT_TIMESTAMP WHERE transactionID = ?'
        );
        $touchStmt->bind_param('i', $transactionId);
        $touchStmt->execute();

        $amount = number_format((float) $payment['amount'], 2);
        $note = sprintf(
            'Payment of PHP %s via %s was %s by staff.%s',
            $amount,
            (string) $payment['paymentMethod'],
            $status,
            $reviewNote === '' ? '' : ' ' . $reviewNote
        );
        add_property_transaction_event(
            $conn,
            $transactionId,
            (string) $payment['currentStage'],
            $note,
            $actorId
        );

        $notificationSubject = 'Property payment ' . $status;
        $notificationMessage = sprintf(
            "Hello %s,\n\nYour payment of PHP %s for '%s' was %s by the Sales Agent team.%s\n\nPlease check your dashboard for the latest update.",
            (string) $payment['fullname'],
            $amount,
            (string) $payment['title'],
            $status,
            $reviewNote === '' ? '' : "\n\nNote: " . $reviewNote
        );
        $notification = [
            'clientId' => (int) $payment['clientID'],
            'title' => $notificationSubject,
            'message' => $notificationMessage,
        ];

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    if ($notification !== null) {
        dispatch_transaction_notification_after_commit($conn, $notification['clientId'], $notification, 'payment review');
    }
}

function update_appointment_tracking_status(
    mysqli $conn,
    int $appointmentId,
    string $newStatus,
    int $actorId
): void {
    if (!in_array($newStatus, ['Pending', 'Approved', 'Cancelled'], true)) {
        throw new InvalidArgumentException('Choose a valid viewing request status.');
    }

    require_once __DIR__ . '/appointment_management.php';
    ensure_appointment_management_schema($conn);

    $clientNotification = null;
    $conn->begin_transaction();
    try {
        $agentLockStmt = $conn->prepare(
            "SELECT userID FROM users
             WHERE userID = ? AND role IN ('Agent', 'Sales Agent') AND accountStatus = 'Active'
             FOR UPDATE"
        );
        $agentLockStmt->bind_param('i', $actorId);
        $agentLockStmt->execute();
        if (!$agentLockStmt->get_result()->fetch_assoc()) {
            throw new InvalidArgumentException('An active Sales Agent account is required to update viewing assignments.');
        }

        $stmt = $conn->prepare(
            'SELECT a.propertyID, a.status, a.agentID, a.appointmentDate, a.appointmentTime,
                    t.transactionID, t.currentStage, ag.fullname AS agentName
             FROM appointments a
             JOIN property_transactions t ON t.appointmentID = a.appointmentID
             LEFT JOIN users ag ON ag.userID = a.agentID
             WHERE a.appointmentID = ?
             FOR UPDATE'
        );
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            throw new InvalidArgumentException('The viewing request or its tracking record was not found.');
        }

        $currentStatus = (string) $row['status'];
        $currentStage = (string) $row['currentStage'];
        $transactionId = (int) $row['transactionID'];
        $propertyId = (int) $row['propertyID'];
        $assignedAgentId = !empty($row['agentID']) ? (int) $row['agentID'] : null;
        $assignedAgentName = (string) ($row['agentName'] ?? 'another Sales Agent');

        // Check if viewing was already taken by another Sales Agent
        if ($assignedAgentId !== null && $assignedAgentId !== $actorId) {
            throw new InvalidArgumentException("This viewing request was already taken by {$assignedAgentName}.");
        }

        if ($newStatus === 'Approved') {
            $propertyLockStmt = $conn->prepare(
                'SELECT propertyID FROM properties WHERE propertyID = ? FOR UPDATE'
            );
            $propertyLockStmt->bind_param('i', $propertyId);
            $propertyLockStmt->execute();
            if (!$propertyLockStmt->get_result()->fetch_assoc()) {
                throw new InvalidArgumentException('The property for this viewing request could not be found.');
            }
            $propertyLockStmt->close();

            $otherActiveViewing = get_active_property_viewing_request(
                $conn,
                $propertyId,
                $appointmentId
            );
            if ($otherActiveViewing !== null) {
                $existingDate = date('M j, Y', strtotime((string) $otherActiveViewing['appointmentDate']));
                $existingTime = date('g:i A', strtotime((string) $otherActiveViewing['appointmentTime']));
                throw new InvalidArgumentException(sprintf(
                    'Another viewing request (#KQ-%d) is already active for this property on %s at %s. Complete or cancel it before approving this one.',
                    1000 + (int) $otherActiveViewing['appointmentID'],
                    $existingDate,
                    $existingTime
                ));
            }
        }

        if ($currentStatus === $newStatus && $assignedAgentId === $actorId) {
            $conn->commit();
            return;
        }
        if ($newStatus === 'Pending' && $currentStatus !== 'Pending') {
            throw new InvalidArgumentException('An approved or cancelled viewing request cannot be changed back to pending.');
        }

        if ($newStatus === 'Approved') {
            if ($currentStage !== 'viewing_requested' && $currentStatus !== 'Approved') {
                throw new InvalidArgumentException('The transaction has already advanced beyond the viewing confirmation step.');
            }
            $newStage = 'viewing_confirmed';
            $note = 'Sales Agent team approved the requested property viewing.';
        } elseif ($newStatus === 'Cancelled') {
            if (!in_array('cancelled', property_transaction_next_stages($currentStage), true)) {
                throw new InvalidArgumentException('This transaction is already closed and cannot be cancelled.');
            }
            $newStage = 'cancelled';
            $note = 'Sales team cancelled the viewing request and transaction.';
        } else {
            throw new InvalidArgumentException('The viewing request status cannot move back to pending.');
        }

        if ($newStatus === 'Approved') {
            $scheduleConflictStmt = $conn->prepare(
                "SELECT appointmentDate, appointmentTime
                 FROM appointments
                 WHERE agentID = ?
                   AND appointmentID <> ?
                   AND status = 'Approved'
                   AND ABS(TIMESTAMPDIFF(
                       SECOND,
                       TIMESTAMP(appointmentDate, appointmentTime),
                       TIMESTAMP(?, ?)
                   )) < 3600
                 LIMIT 1
                 FOR UPDATE"
            );
            $scheduleConflictStmt->bind_param(
                'iiss',
                $actorId,
                $appointmentId,
                $row['appointmentDate'],
                $row['appointmentTime']
            );
            $scheduleConflictStmt->execute();
            $scheduleConflict = $scheduleConflictStmt->get_result()->fetch_assoc();
            if ($scheduleConflict) {
                $conflictTime = date('M j, Y', strtotime($scheduleConflict['appointmentDate']))
                    . ' at ' . substr($scheduleConflict['appointmentTime'], 0, 5);
                throw new InvalidArgumentException(
                    "You already have an approved viewing within one hour of {$conflictTime}."
                );
            }
        }

        $updateTransaction = $conn->prepare(
            'UPDATE property_transactions SET currentStage = ? WHERE transactionID = ?'
        );
        $updateTransaction->bind_param('si', $newStage, $transactionId);
        $updateTransaction->execute();
        add_property_transaction_event($conn, $transactionId, $newStage, $note, $actorId);

        $updateAppointment = $conn->prepare(
            'UPDATE appointments SET status = ?, agentID = ? WHERE appointmentID = ?'
        );
        $updateAppointment->bind_param('sii', $newStatus, $actorId, $appointmentId);
        $updateAppointment->execute();

        $detailsStmt = $conn->prepare(
            'SELECT a.clientID, a.appointmentDate, a.appointmentTime, p.title, u.fullname
             FROM appointments a
             JOIN properties p ON p.propertyID = a.propertyID
             JOIN users u ON u.userID = a.clientID
             WHERE a.appointmentID = ?
             LIMIT 1'
        );
        $detailsStmt->bind_param('i', $appointmentId);
        $detailsStmt->execute();
        $details = $detailsStmt->get_result()->fetch_assoc();

        if (is_array($details) && !empty($details['clientID'])) {
            $statusLabel = $newStatus === 'Approved' ? 'approved' : 'cancelled';
            $notificationSubject = 'Property viewing request ' . $statusLabel;
            $notificationMessage = sprintf(
                "Hello %s,\n\nYour viewing request for '%s' on %s at %s has been %s.\n\nPlease check your dashboard for the latest updates.",
                (string) ($details['fullname'] ?? 'there'),
                (string) ($details['title'] ?? 'the property'),
                (string) $details['appointmentDate'],
                (string) $details['appointmentTime'],
                $statusLabel
            );
            $clientNotification = [
                'clientId' => (int) $details['clientID'],
                'title' => $notificationSubject,
                'message' => $notificationMessage,
            ];
        }

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    if ($clientNotification !== null) {
        dispatch_transaction_notification_after_commit($conn, $clientNotification['clientId'], $clientNotification, 'viewing update');
    }

    if ($newStatus === 'Approved') {
        try {
            notify_agents_viewing_taken($conn, $appointmentId, $actorId);
        } catch (Throwable $exception) {
            error_log('KingQuiry agent viewing notification failed: ' . $exception->getMessage());
        }
    }
}
