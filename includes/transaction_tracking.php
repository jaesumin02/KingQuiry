<?php
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/appointment_management.php';

function property_transaction_stages(): array
{
    return [
        'inquiry_received' => 'Inquiry received',
        'contacting_client' => 'Contacting client',
        'reservation_requested' => 'Reservation requested',
        'reservation_confirmed' => 'Reservation confirmed (7-day hold)',
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

function property_transaction_stage_label(string $stage): string
{
    $labels = property_transaction_stages();
    return $labels[$stage] ?? ucfirst(str_replace('_', ' ', $stage));
}

function property_transaction_stage_badge_class(string $stage): string
{
    return match ($stage) {
        'completed', 'reservation_confirmed' => 'text-bg-success',
        'cancelled' => 'text-bg-secondary',
        'inquiry_received', 'reservation_requested', 'payment_in_process' => 'text-bg-warning text-dark',
        'contacting_client' => 'text-bg-info text-dark',
        'reviewing_schedule', 'viewing_confirmed', 'payment_confirmation' => 'text-bg-info text-dark',
        'viewing_requested', 'viewing_completed', 'document_verification' => 'text-bg-primary',
        default => 'text-bg-light text-dark',
    };
}

function claim_property_transaction(mysqli $conn, int $transactionId, int $actorId): void
{
    if ($transactionId <= 0 || $actorId <= 0) {
        throw new InvalidArgumentException('Choose a valid transaction to claim.');
    }

    $notification = null;
    $conn->begin_transaction();
    try {
        $actorStmt = $conn->prepare(
            "SELECT fullname FROM users
             WHERE userID = ? AND role IN ('Agent', 'Sales Agent', 'Admin') AND accountStatus = 'Active'
             FOR UPDATE"
        );
        $actorStmt->bind_param('i', $actorId);
        $actorStmt->execute();
        $actor = $actorStmt->get_result()->fetch_assoc();
        if (!$actor) {
            throw new InvalidArgumentException('An active Sales Agent account is required to claim a transaction.');
        }

        $transactionStmt = $conn->prepare(
            'SELECT transactionID, clientID, appointmentID, inquiryID, assignedAgentID, currentStage
             FROM property_transactions
             WHERE transactionID = ?
             FOR UPDATE'
        );
        $transactionStmt->bind_param('i', $transactionId);
        $transactionStmt->execute();
        $transaction = $transactionStmt->get_result()->fetch_assoc();
        if (!$transaction) {
            throw new InvalidArgumentException('The selected transaction was not found.');
        }

        $currentStage = (string) $transaction['currentStage'];
        if (in_array($currentStage, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('A completed or cancelled transaction cannot be claimed.');
        }

        $assignedAgentIds = [];
        $inquiryAssigned = false;
        if (!empty($transaction['assignedAgentID'])) {
            $assignedAgentIds[] = (int) $transaction['assignedAgentID'];
        }
        $appointmentId = (int) ($transaction['appointmentID'] ?? 0);
        if ($appointmentId > 0) {
            $appointmentStmt = $conn->prepare(
                'SELECT agentID FROM appointments WHERE appointmentID = ? FOR UPDATE'
            );
            $appointmentStmt->bind_param('i', $appointmentId);
            $appointmentStmt->execute();
            $appointment = $appointmentStmt->get_result()->fetch_assoc();
            if ($appointment && !empty($appointment['agentID'])) {
                $assignedAgentIds[] = (int) $appointment['agentID'];
            }
        }

        $inquiryId = (int) ($transaction['inquiryID'] ?? 0);
        if ($inquiryId > 0) {
            $assignmentStmt = $conn->prepare(
                'SELECT agentID FROM agent_inquiry_assignments WHERE inquiryID = ? FOR UPDATE'
            );
            $assignmentStmt->bind_param('i', $inquiryId);
            $assignmentStmt->execute();
            $assignment = $assignmentStmt->get_result()->fetch_assoc();
            if ($assignment) {
                $assignedAgentIds[] = (int) $assignment['agentID'];
                $inquiryAssigned = true;
            }
        }

        foreach (array_unique($assignedAgentIds) as $assignedAgentId) {
            if ($assignedAgentId !== $actorId) {
                throw new InvalidArgumentException('This transaction has already been claimed by another staff member.');
            }
        }

        if ($appointmentId > 0) {
            $assignAppointmentStmt = $conn->prepare(
                'UPDATE appointments SET agentID = ? WHERE appointmentID = ? AND agentID IS NULL'
            );
            $assignAppointmentStmt->bind_param('ii', $actorId, $appointmentId);
            $assignAppointmentStmt->execute();
        }

        if ($inquiryId > 0 && !$inquiryAssigned) {
            $assignInquiryStmt = $conn->prepare(
                'INSERT INTO agent_inquiry_assignments (inquiryID, agentID) VALUES (?, ?)'
            );
            $assignInquiryStmt->bind_param('ii', $inquiryId, $actorId);
            $assignInquiryStmt->execute();
        }

        if (empty($transaction['assignedAgentID'])) {
            $assignTransactionStmt = $conn->prepare(
                'UPDATE property_transactions SET assignedAgentID = ? WHERE transactionID = ? AND assignedAgentID IS NULL'
            );
            $assignTransactionStmt->bind_param('ii', $actorId, $transactionId);
            $assignTransactionStmt->execute();
        }

        $agentName = (string) $actor['fullname'];
        $claimedStage = $currentStage;
        $claimNote = 'Sales Agent ' . $agentName . ' claimed this transaction request.';
        if ($currentStage === 'viewing_requested') {
            $claimedStage = 'reviewing_schedule';
            $claimNote = 'Viewing accepted by ' . $agentName;
            $updateStageStmt = $conn->prepare(
                "UPDATE property_transactions
                 SET currentStage = 'reviewing_schedule', updatedAt = CURRENT_TIMESTAMP
                 WHERE transactionID = ?"
            );
            $updateStageStmt->bind_param('i', $transactionId);
            $updateStageStmt->execute();
        }

        add_property_transaction_event($conn, $transactionId, $claimedStage, $claimNote, $actorId);
        $notification = [
            'clientId' => (int) $transaction['clientID'],
            'agentName' => $agentName,
            'transactionId' => $transactionId,
            'stage' => $claimedStage,
        ];
        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    try {
        $message = $notification['stage'] === 'reviewing_schedule'
            ? 'Sales Agent ' . $notification['agentName'] . ' accepted your viewing request and is reviewing the schedule for transaction #KQT-' . $transactionId . '.'
            : 'Sales Agent ' . $notification['agentName'] . ' is now handling transaction #KQT-' . $transactionId . '.';
        send_property_notification(
            $conn,
            $notification['clientId'],
            'Sales Agent assigned',
            $message,
            'client_dashboard.php#transaction-tracking',
            'agent_assigned'
        );
    } catch (Throwable $exception) {
        error_log('KingQuiry transaction claim notification failed: ' . $exception->getMessage());
    }
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

function is_valid_transaction_currency_amount(float $amount): bool
{
    return is_finite($amount)
        && $amount >= 0
        && $amount <= 9999999999.99
        && abs($amount - round($amount, 2)) <= 0.000001;
}

function property_transaction_next_stages(string $stage, ?string $paymentMethod = null): array
{
    // ALGORITHM: State machine mapping each stage to allowed next stages; reservations precede viewings, and Spot Cash skips document verification.
    $isSpotCash = $paymentMethod !== null && (strcasecmp($paymentMethod, 'Spot Cash') === 0 || strcasecmp($paymentMethod, 'Spot cash') === 0);

    $transitions = [
        'inquiry_received' => ['contacting_client', 'viewing_requested', 'cancelled'],
        'contacting_client' => ['viewing_requested', 'cancelled'],
        'reservation_requested' => ['reservation_confirmed', 'cancelled'],
        'reservation_confirmed' => ['viewing_requested', 'reviewing_schedule', 'viewing_confirmed', 'payment_in_process', 'cancelled'],
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

    if ($newStage === 'reservation_requested') {
        return [
            'title' => 'Property reservation request received',
            'message' => sprintf(
                "Hello %s,\n\nYour reservation request for '%s' has been received by our Sales Agent team.%s\n\nOnce your reservation is confirmed, your 7-day reservation hold will begin and you can schedule your property viewing.",
                $clientName,
                $propertyTitle,
                $noteSection
            ),
        ];
    }

    if ($newStage === 'reservation_confirmed') {
        return [
            'title' => 'Reservation confirmed — You can now schedule a viewing',
            'message' => sprintf(
                "Hello %s,\n\nGreat news! Your reservation for '%s' has been confirmed and your 7-day reservation hold is now active.%s\n\nNext step: Visit the property listing or your dashboard to schedule your property viewing with your Sales Agent.",
                $clientName,
                $propertyTitle,
                $noteSection
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
                "Hello %s,\n\nYour property viewing for '%s' has been successfully completed! Your Sales Agent has completed the viewing.%s\n\nNext stage: Payment in process. Your Sales Agent will now coordinate your down payment / balance terms and agreement paperwork.",
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
                "Hello %s,\n\nPayment processing is now underway for '%s'. Your Sales Agent is actively coordinating your payment terms, down payment / balance settlement, and paperwork.%s\n\nNext stage: Document verification.",
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

function get_active_property_reservation(mysqli $conn, int $propertyId): ?array
{
    if ($propertyId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT transactionID, clientID, appointmentID, inquiryID, currentStage, paymentMethod, createdAt, updatedAt
         FROM property_transactions
         WHERE propertyID = ?
           AND currentStage NOT IN ('completed', 'cancelled', 'inquiry_received', 'contacting_client')
         ORDER BY updatedAt DESC, transactionID DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function create_property_reservation(
    mysqli $conn,
    int $propertyId,
    int $clientId,
    ?string $paymentScheme = null,
    ?float $reservationAmount = null,
    string $referenceNo = '',
    string $note = ''
): int {
    if ($propertyId <= 0 || $clientId <= 0) {
        throw new InvalidArgumentException('Choose a valid property and client account.');
    }
    if ($paymentScheme !== null && $paymentScheme !== '' && !in_array($paymentScheme, property_staff_payment_methods(), true)) {
        throw new InvalidArgumentException('Choose a supported payment scheme.');
    }
    if ($reservationAmount !== null && (!is_valid_transaction_currency_amount($reservationAmount) || $reservationAmount <= 0)) {
        throw new InvalidArgumentException('Enter a reservation amount greater than zero or leave it blank.');
    }
    if (strlen($referenceNo) > 100) {
        throw new InvalidArgumentException('The reference number must be 100 characters or fewer.');
    }
    if (strlen($note) > 1000) {
        throw new InvalidArgumentException('Reservation notes must be 1,000 characters or fewer.');
    }

    $cleanScheme = ($paymentScheme !== null && $paymentScheme !== '') ? $paymentScheme : null;
    $cleanRef = trim($referenceNo);
    $cleanNote = trim($note);

    $existingActiveStmt = $conn->prepare(
        "SELECT transactionID, clientID
         FROM property_transactions
         WHERE propertyID = ?
           AND currentStage NOT IN ('completed', 'cancelled', 'inquiry_received', 'contacting_client')
         LIMIT 1
         FOR UPDATE"
    );
    $existingActiveStmt->bind_param('i', $propertyId);
    $existingActiveStmt->execute();
    $existingActive = $existingActiveStmt->get_result()->fetch_assoc();
    $existingActiveStmt->close();

    if ($existingActive) {
        if ((int) $existingActive['clientID'] === $clientId) {
            throw new InvalidArgumentException('You already have an active reservation for this property.');
        }
        throw new InvalidArgumentException('This property already has an active reservation by another buyer.');
    }

    $inquiryTxStmt = $conn->prepare(
        "SELECT transactionID
         FROM property_transactions
         WHERE propertyID = ? AND clientID = ?
           AND currentStage IN ('inquiry_received', 'contacting_client')
           AND appointmentID IS NULL
         ORDER BY updatedAt DESC, transactionID DESC
         LIMIT 1
         FOR UPDATE"
    );
    $inquiryTxStmt->bind_param('ii', $propertyId, $clientId);
    $inquiryTxStmt->execute();
    $inquiryTx = $inquiryTxStmt->get_result()->fetch_assoc();
    $inquiryTxStmt->close();

    if ($inquiryTx) {
        $transactionId = (int) $inquiryTx['transactionID'];
        $updateStmt = $conn->prepare(
            "UPDATE property_transactions
             SET currentStage = 'reservation_requested',
                 paymentMethod = COALESCE(?, paymentMethod),
                 updatedAt = CURRENT_TIMESTAMP
             WHERE transactionID = ?"
        );
        $updateStmt->bind_param('si', $cleanScheme, $transactionId);
        $updateStmt->execute();
        $updateStmt->close();
    } else {
        $insertStmt = $conn->prepare(
            "INSERT INTO property_transactions
                (propertyID, clientID, currentStage, paymentMethod)
             VALUES (?, ?, 'reservation_requested', ?)"
        );
        $insertStmt->bind_param('iis', $propertyId, $clientId, $cleanScheme);
        $insertStmt->execute();
        $transactionId = (int) $conn->insert_id;
        $insertStmt->close();
    }

    $eventNote = 'Client submitted a pre-viewing property reservation request.';
    if ($cleanScheme !== null) {
        $eventNote .= ' Preferred payment scheme: ' . $cleanScheme . '.';
    }
    if ($cleanNote !== '') {
        $eventNote .= ' Note: ' . $cleanNote;
    }
    add_property_transaction_event($conn, $transactionId, 'reservation_requested', $eventNote, $clientId);

    if ($reservationAmount !== null && $reservationAmount > 0) {
        $mappedPayment = kq_map_staff_payment_to_register($cleanScheme ?? 'Cash', 'reservation_confirmed');
        $paidDate = date('Y-m-d');
        $pendingStatus = 'Pending';
        $paymentType = 'Reservation';
        $paymentNote = $cleanNote !== '' ? $cleanNote : 'Client submitted reservation fee details (pending verification).';
        $payStmt = $conn->prepare(
            'INSERT INTO payments
                (transactionID, clientID, amount, paymentType, paymentMethod, referenceNo, status, paidAt, note, recordedBy)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $payStmt->bind_param(
            'iidssssssi',
            $transactionId,
            $clientId,
            $reservationAmount,
            $paymentType,
            $mappedPayment['paymentMethod'],
            $cleanRef,
            $pendingStatus,
            $paidDate,
            $paymentNote,
            $clientId
        );
        $payStmt->execute();
        $payStmt->close();
    }

    return $transactionId;
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

    $stage = $source === 'appointment' ? 'viewing_requested' : 'inquiry_received';
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
        if ($source === 'appointment') {
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

        if ($source === 'appointment') {
            add_property_transaction_event($conn, $transactionId, 'viewing_requested', 'Client submitted a property viewing request.', $actorId);
        }
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
    if ($source === 'appointment') {
        add_property_transaction_event($conn, $transactionId, 'viewing_requested', 'Client submitted a property viewing request.', $actorId, 'initial');
    }
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

function reconcile_verified_payment_entries(array $trackerRows, array $registerRows): array
{
    if (empty($registerRows)) {
        $total = (float) array_sum(array_map(static fn(array $row): float => (float) ($row['amount'] ?? 0), $trackerRows));
        return ['total' => $total, 'count' => count($trackerRows)];
    }
    if (empty($trackerRows)) {
        $total = (float) array_sum(array_map(static fn(array $row): float => (float) ($row['amount'] ?? 0), $registerRows));
        return ['total' => $total, 'count' => count($registerRows)];
    }

    $reconciledTotal = 0.0;
    $reconciledCount = 0;
    $matchedRegisterIds = [];

    foreach ($trackerRows as $tRow) {
        $tAmount = (float) ($tRow['amount'] ?? 0);
        $tRef = trim((string) ($tRow['referenceNumber'] ?? ''));
        $reconciledTotal += $tAmount;
        $reconciledCount++;

        foreach ($registerRows as $rRow) {
            $rId = (int) ($rRow['paymentID'] ?? 0);
            if (isset($matchedRegisterIds[$rId])) {
                continue;
            }
            $rAmount = (float) ($rRow['amount'] ?? 0);
            $rRef = trim((string) ($rRow['referenceNo'] ?? $rRow['referenceNumber'] ?? ''));

            $isRefMatch = ($tRef !== '' && $rRef !== '' && strcasecmp($tRef, $rRef) === 0);
            $isAmountMatch = (abs($tAmount - $rAmount) < 0.01 && ($tRef === '' || $rRef === ''));

            if ($isRefMatch || $isAmountMatch) {
                $matchedRegisterIds[$rId] = true;
                break;
            }
        }
    }

    foreach ($registerRows as $rRow) {
        $rId = (int) ($rRow['paymentID'] ?? 0);
        if (!isset($matchedRegisterIds[$rId])) {
            $reconciledTotal += (float) ($rRow['amount'] ?? 0);
            $reconciledCount++;
        }
    }

    return ['total' => (float) $reconciledTotal, 'count' => $reconciledCount];
}

function kq_map_staff_payment_to_register(string $staffMethod, string $targetStage): array
{
    $registerMethod = match (trim($staffMethod)) {
        'Bank Transfer' => 'Bank Transfer',
        'Check' => 'Check',
        'Paperwork (Face-to-face)' => 'Other',
        default => 'Cash',
    };

    $paymentType = match (true) {
        strcasecmp(trim($staffMethod), 'Spot Cash') === 0 => 'Full Payment',
        $targetStage === 'reservation_confirmed' || $targetStage === 'reservation_requested' => 'Reservation',
        $targetStage === 'payment_in_process' => 'Down Payment',
        $targetStage === 'completed' => 'Full Payment',
        default => 'Down Payment',
    };

    return [
        'paymentMethod' => $registerMethod,
        'paymentType' => $paymentType,
    ];
}

function get_transaction_payment_summaries(mysqli $conn, array $transactionIds): array
{
    $transactionIds = array_values(array_unique(array_filter(
        array_map('intval', $transactionIds),
        static fn(int $transactionId): bool => $transactionId > 0
    )));
    if ($transactionIds === []) {
        return [];
    }

    $summaries = [];
    foreach ($transactionIds as $transactionId) {
        $summaries[$transactionId] = [
            'verifiedTotal' => 0.0,
            'verifiedCount' => 0,
            'pendingTotal' => 0.0,
            'pendingCount' => 0,
        ];
    }

    $transactionIdList = implode(',', $transactionIds);
    $verifiedTrackerByTx = [];
    $verifiedRegisterByTx = [];

    $trackerRes = $conn->query(
        "SELECT paymentID, transactionID, amount, referenceNumber, status
         FROM property_transaction_payments
         WHERE transactionID IN ($transactionIdList)
           AND status IN ('confirmed', 'pending', 'in_process')
           AND amount > 0"
    );
    while ($row = $trackerRes->fetch_assoc()) {
        $txId = (int) $row['transactionID'];
        $status = strtolower((string) $row['status']);
        if ($status === 'confirmed') {
            $verifiedTrackerByTx[$txId][] = $row;
        } else {
            $summaries[$txId]['pendingTotal'] += (float) $row['amount'];
            $summaries[$txId]['pendingCount']++;
        }
    }

    $registerRes = $conn->query(
        "SELECT paymentID, transactionID, amount, referenceNo, status
         FROM payments
         WHERE transactionID IN ($transactionIdList)
           AND status IN ('Verified', 'Pending')
           AND amount > 0"
    );
    while ($row = $registerRes->fetch_assoc()) {
        $txId = (int) $row['transactionID'];
        if ((string) $row['status'] === 'Verified') {
            $verifiedRegisterByTx[$txId][] = $row;
        } else {
            $summaries[$txId]['pendingTotal'] += (float) $row['amount'];
            $summaries[$txId]['pendingCount']++;
        }
    }

    foreach ($transactionIds as $txId) {
        $reconciled = reconcile_verified_payment_entries(
            $verifiedTrackerByTx[$txId] ?? [],
            $verifiedRegisterByTx[$txId] ?? []
        );
        $summaries[$txId]['verifiedTotal'] = $reconciled['total'];
        $summaries[$txId]['verifiedCount'] = $reconciled['count'];
    }

    return $summaries;
}

function get_verified_transaction_payment_total(mysqli $conn, int $transactionId): float
{
    if ($transactionId <= 0) {
        return 0.0;
    }

    $summaries = get_transaction_payment_summaries($conn, [$transactionId]);
    return (float) ($summaries[$transactionId]['verifiedTotal'] ?? 0.0);
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
                status, submittedAt, reviewNote, reviewedBy
         FROM property_transaction_payments
         WHERE transactionID IN ($transactionIdList)
         ORDER BY submittedAt DESC, paymentID DESC"
    );
    while ($payment = $trackerRows->fetch_assoc()) {
        $payment['status'] = strtolower((string) $payment['status']);
        $payment['paymentType'] = 'Transaction payment';
        $payment['sourceLabel'] = !empty($payment['reviewedBy']) ? 'Staff record' : 'Client report';
        $payment['isClientReport'] = empty($payment['reviewedBy']);
        $records[(int) $payment['transactionID']][] = $payment;
    }

    $registerRows = $conn->query(
        "SELECT paymentID, transactionID, amount, paymentType, paymentMethod, referenceNo AS referenceNumber,
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
        $payment['sourceLabel'] = 'Official ledger';
        $payment['isClientReport'] = false;
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

    $assignedAgentStmt = $conn->prepare(
        'SELECT assignedAgentID FROM property_transactions WHERE transactionID = ? LIMIT 1'
    );
    $transactionId = (int) ($transaction['transactionID'] ?? 0);
    $assignedAgentStmt->bind_param('i', $transactionId);
    $assignedAgentStmt->execute();
    $transactionAssignment = $assignedAgentStmt->get_result()->fetch_assoc();
    $assignedAgentStmt->close();
    $assignedAgentId = (int) ($transactionAssignment['assignedAgentID'] ?? 0);
    if ($assignedAgentId === $actorId) {
        return;
    }
    $hasAssignedTransactionAgent = $assignedAgentId > 0;

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
    if (!$hasAssignedTransactionAgent && !$hasAssignedAppointmentAgent && !$hasAssignedInquiryAgent) {
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
                'reservation_confirmed' => 'Reservation confirmed by ' . $actorName . '. 7-day reservation hold is active; client may now schedule a property viewing.',
                'reviewing_schedule' => 'Viewing accepted by ' . $actorName,
                'viewing_confirmed' => 'Viewing schedule confirmed by ' . $actorName . '.',
                'viewing_completed' => 'Sales Agent team completed the property viewing with the Client.',
                'payment_in_process' => 'Payment processing initiated: Sales Agent is coordinating down payment / balance terms and paperwork.',
                'document_verification' => 'Payment processing completed. Sales Agent is verifying property title deeds, clearances, and documents.',
                'payment_confirmation' => 'Document verification completed. Sales Agent is finalizing payment confirmation and receipts.',
                'completed' => 'Payment confirmation complete. Transaction finalized and property successfully turned over.',
                'cancelled' => 'Transaction was cancelled.',
            ];
            $effectiveNote = $cleanNote !== '' ? $cleanNote : ($defaultNotes[$newStage] ?? 'Transaction advanced to ' . str_replace('_', ' ', $newStage));
        }

        // Record payment in the unified official ledger if staff provided a payment amount
        $clientId = (int) $transaction['clientID'];
        if ($paymentAmount !== null && $paymentAmount > 0) {
            if (
                empty($effectivePaymentMethod)
                || !in_array($effectivePaymentMethod, property_staff_payment_methods(), true)
            ) {
                throw new InvalidArgumentException('Choose a supported payment method before recording a payment.');
            }
            if (!in_array($newStage, ['reservation_confirmed', 'payment_in_process', 'document_verification', 'payment_confirmation', 'completed'], true)) {
                throw new InvalidArgumentException('Payment details can only be recorded during reservation confirmation, payment processing, or finalization.');
            }
            $refNumber = !empty($paymentRef) ? $paymentRef : 'Paperwork verified';
            $mappedPayment = kq_map_staff_payment_to_register($effectivePaymentMethod, $newStage);
            $paidDate = date('Y-m-d');
            $verifiedStatus = 'Verified';
            $insPayment = $conn->prepare(
                'INSERT INTO payments
                    (transactionID, clientID, amount, paymentType, paymentMethod, referenceNo, status, paidAt, note, recordedBy)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insPayment->bind_param(
                'iidssssssi',
                $transactionId,
                $clientId,
                $paymentAmount,
                $mappedPayment['paymentType'],
                $mappedPayment['paymentMethod'],
                $refNumber,
                $verifiedStatus,
                $paidDate,
                $effectiveNote,
                $actorId
            );
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
