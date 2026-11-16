<?php
require_once __DIR__ . '/notifications.php';

function property_transaction_stages(): array
{
    return [
        'inquiry_received' => 'Inquiry received',
        'contacting_client' => 'Contacting client',
        'viewing_requested' => 'Viewing requested',
        'viewing_confirmed' => 'Viewing confirmed',
        'viewing_completed' => 'Viewing completed',
        'payment_in_process' => 'Payment in process',
        'document_verification' => 'Document verification',
        'payment_confirmation' => 'Payment confirmation',
        'completed' => 'Successful / completed',
        'cancelled' => 'Cancelled',
    ];
}

function property_transaction_next_stages(string $stage, ?string $paymentMethod = null): array
{
    $isSpotCash = $paymentMethod !== null && (strcasecmp($paymentMethod, 'Spot Cash') === 0 || strcasecmp($paymentMethod, 'Spot cash') === 0);

    $transitions = [
        'inquiry_received' => ['contacting_client', 'viewing_requested', 'cancelled'],
        'contacting_client' => ['viewing_requested', 'cancelled'],
        'viewing_requested' => ['viewing_confirmed', 'cancelled'],
        'viewing_confirmed' => ['viewing_completed', 'payment_in_process', 'cancelled'],
        'viewing_completed' => ['payment_in_process', 'document_verification', 'cancelled'],
        'payment_in_process' => $isSpotCash
            ? ['payment_confirmation', 'completed', 'cancelled']
            : ['document_verification', 'payment_confirmation', 'completed', 'cancelled'],
        'document_verification' => ['payment_confirmation', 'completed', 'cancelled'],
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
    $noteSection = $cleanNote !== '' ? "\n\nAgent update: " . $cleanNote : '';

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

    if ($newStage === 'viewing_confirmed') {
        return [
            'title' => 'Viewing schedule confirmed',
            'message' => sprintf(
                "Hello %s,\n\nYour property viewing for '%s' has been confirmed by your sales agent.%s\n\nPlease arrive on time for your scheduled site visit. Check your dashboard for details.",
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
                "Hello %s,\n\nYour property viewing for '%s' has been successfully completed! Your sales agent has finished conducting the site visit.%s\n\nNext stage: Payment in process. Your agent will now coordinate payment terms and agreement paperwork.",
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
                "Hello %s,\n\nPayment processing is now underway for '%s'. Your sales agent is actively coordinating the payment terms, down payment / reservation, and paperwork.%s\n\nNext stage: Document verification.",
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
            ? sprintf("Payment is done processing by your sales agent for '%s'! The transaction has now advanced to Document Verification.", $propertyTitle)
            : sprintf("Your transaction for '%s' has advanced to Document Verification.", $propertyTitle);

        return [
            'title' => $title,
            'message' => sprintf(
                "Hello %s,\n\n%s%s\n\nNext stage: Document verification. Your sales agent is reviewing property title deeds, tax declarations, and clearance documents.",
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
            $intro = sprintf("Your Spot Cash payment has been processed by your sales agent for '%s'! Since Spot Cash was selected, document verification was skipped, and your purchase has moved directly to Payment Confirmation and turnover preparation.", $propertyTitle);
        } elseif ($prevWasDoc) {
            $title = 'Document verification done — Payment confirmation underway';
            $intro = sprintf("Document verification has been completed by your sales agent for '%s'! All required property legal documents, titles, and clearances have been verified.", $propertyTitle);
        } else {
            $title = 'Payment confirmation in progress';
            $intro = sprintf("Your transaction for '%s' has advanced to Payment Confirmation.", $propertyTitle);
        }

        return [
            'title' => $title,
            'message' => sprintf(
                "Hello %s,\n\n%s%s\n\nNext stage: Payment confirmation. Your sales agent is finalizing official receipts, billing clearance, and turnover documents.",
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
                "Hello %s,\n\nCongratulations! Your property transaction for '%s' is officially completed and finalized, and the property is officially yours!\n\nVerified sale price: %s%s\n\nThank you for choosing 4 Kings Real Estate! You may now review your transaction summary and leave a 1-5 star rating for your sales agent on your client dashboard.",
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

    if ($existing) {
        $transactionId = (int) $existing['transactionID'];
        $currentStage = (string) $existing['currentStage'];
        if ($source === 'appointment' && in_array($currentStage, ['inquiry_received', 'contacting_client'], true)) {
            $currentStage = 'viewing_requested';
        }
        $updateStmt = $conn->prepare(
            "UPDATE property_transactions
             SET $sourceColumn = ?, currentStage = ?, updatedAt = CURRENT_TIMESTAMP
             WHERE transactionID = ?"
        );
        $updateStmt->bind_param('isi', $sourceId, $currentStage, $transactionId);
        $updateStmt->execute();

        $note = $source === 'appointment'
            ? 'Client requested a property viewing for this tracked inquiry.'
            : 'Client sent an additional property inquiry for this tracked request.';
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
    $note = $source === 'appointment'
        ? 'Client submitted a property viewing request.'
        : 'Client submitted a property inquiry.';
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

    if ($newStage === 'completed' && ($salePrice === null || $salePrice <= 0 || $salePrice > 9999999999.99)) {
        throw new InvalidArgumentException('Enter the verified sale price before marking this transaction successful.');
    }
    if ($note !== '' && strlen($note) > 1000) {
        throw new InvalidArgumentException('Tracking notes must be 1,000 characters or fewer.');
    }

    $conn->begin_transaction();
    try {
        $currentStmt = $conn->prepare(
            'SELECT transactionID, propertyID, clientID, currentStage, paymentMethod FROM property_transactions
             WHERE transactionID = ? FOR UPDATE'
        );
        $currentStmt->bind_param('i', $transactionId);
        $currentStmt->execute();
        $transaction = $currentStmt->get_result()->fetch_assoc();
        if (!$transaction) {
            throw new InvalidArgumentException('The selected transaction was not found.');
        }

        $currentStage = (string) $transaction['currentStage'];
        $effectivePaymentMethod = ($paymentMethod !== null && $paymentMethod !== '')
            ? $paymentMethod
            : ($transaction['paymentMethod'] ?? null);

        $allowedStages = property_transaction_next_stages($currentStage, $effectivePaymentMethod);
        $fallbackStages = property_transaction_next_stages($currentStage, null);
        if (!in_array($newStage, $allowedStages, true) && !in_array($newStage, $fallbackStages, true)) {
            throw new InvalidArgumentException('That step cannot follow the current status. Update the transaction in order or cancel it.');
        }

        $defaultNotes = [
            'viewing_confirmed' => 'Sales team confirmed the requested property viewing schedule.',
            'viewing_completed' => 'Sales team conducted and completed the property site visit with the client.',
            'payment_in_process' => 'Payment processing initiated: Sales agent is coordinating payment terms and paperwork.',
            'document_verification' => 'Payment processing completed. Sales agent is verifying property title deeds, clearances, and documents.',
            'payment_confirmation' => 'Document verification completed. Sales agent is finalizing payment confirmation and receipts.',
            'completed' => 'Payment confirmation complete. Transaction finalized and property successfully turned over.',
            'cancelled' => 'Transaction was cancelled.',
        ];
        $effectiveNote = $note !== '' ? $note : ($defaultNotes[$newStage] ?? 'Transaction advanced to ' . str_replace('_', ' ', $newStage));

        if ($newStage === 'completed') {
            $updateStmt = $conn->prepare(
                'UPDATE property_transactions
                 SET currentStage = ?, salePrice = ?, paymentMethod = COALESCE(?, paymentMethod), updatedAt = CURRENT_TIMESTAMP
                 WHERE transactionID = ?'
            );
            $updateStmt->bind_param('sdsi', $newStage, $salePrice, $effectivePaymentMethod, $transactionId);
            $updateStmt->execute();

            // Property officially sold & turned over: mark as 'Sold' so it disappears from public listings
            $propertyId = (int) $transaction['propertyID'];
            $soldStmt = $conn->prepare("UPDATE properties SET status = 'Sold' WHERE propertyID = ?");
            $soldStmt->bind_param('i', $propertyId);
            $soldStmt->execute();
        } else {
            $updateStmt = $conn->prepare(
                'UPDATE property_transactions
                 SET currentStage = ?, paymentMethod = COALESCE(?, paymentMethod), updatedAt = CURRENT_TIMESTAMP
                 WHERE transactionID = ?'
            );
            $updateStmt->bind_param('ssi', $newStage, $effectivePaymentMethod, $transactionId);
            $updateStmt->execute();
        }
        add_property_transaction_event($conn, $transactionId, $newStage, $effectiveNote, $actorId);

        // Record payment if agent provided payment details
        $clientId = (int) $transaction['clientID'];
        if ($paymentAmount !== null && $paymentAmount > 0 && !empty($effectivePaymentMethod)) {
            $refNumber = !empty($paymentRef) ? $paymentRef : 'Paperwork verified';
            $insPayment = $conn->prepare(
                "INSERT INTO property_transaction_payments
                    (transactionID, clientID, amount, paymentMethod, referenceNumber, status, reviewedAt, reviewedBy, reviewNote)
                 VALUES (?, ?, ?, ?, ?, 'confirmed', CURRENT_TIMESTAMP, ?, ?)"
            );
            $insPayment->bind_param('iidssis', $transactionId, $clientId, $paymentAmount, $effectivePaymentMethod, $refNumber, $actorId, $effectiveNote);
            $insPayment->execute();
        }

        // If moving to payment_confirmation, ensure at least one confirmed payment record exists
        if ($newStage === 'payment_confirmation') {
            $checkPay = $conn->prepare(
                "SELECT paymentID FROM property_transaction_payments
                 WHERE transactionID = ? AND status = 'confirmed'
                 LIMIT 1"
            );
            $checkPay->bind_param('i', $transactionId);
            $checkPay->execute();
            if (!$checkPay->get_result()->fetch_assoc()) {
                $paperworkMethod = !empty($effectivePaymentMethod) ? $effectivePaymentMethod : 'Paperwork (Face-to-face)';
                $ref = 'Paperwork confirmed';
                $reviewNote = $effectiveNote !== '' ? $effectiveNote : 'Paperwork and payment verified face-to-face.';
                $insPayment = $conn->prepare(
                    "INSERT INTO property_transaction_payments
                        (transactionID, clientID, amount, paymentMethod, referenceNumber, status, reviewedAt, reviewedBy, reviewNote)
                     VALUES (?, ?, 0.00, ?, ?, 'confirmed', CURRENT_TIMESTAMP, ?, ?)"
                );
                $insPayment->bind_param('iissis', $transactionId, $clientId, $paperworkMethod, $ref, $actorId, $reviewNote);
                $insPayment->execute();
            }
        }

        if ($newStage === 'viewing_confirmed' || $newStage === 'cancelled') {
            $appointmentStatus = $newStage === 'viewing_confirmed' ? 'Approved' : 'Cancelled';
            $appointmentStmt = $conn->prepare(
                'UPDATE appointments a
                 JOIN property_transactions t ON t.appointmentID = a.appointmentID
                 SET a.status = ?
                 WHERE t.transactionID = ?'
            );
            $appointmentStmt->bind_param('si', $appointmentStatus, $transactionId);
            $appointmentStmt->execute();
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

        if (is_array($details) && !empty($details['clientID'])) {
            $notif = get_transaction_stage_notification(
                $currentStage,
                $newStage,
                (string) ($details['fullname'] ?? 'there'),
                (string) ($details['title'] ?? 'the property'),
                $effectiveNote,
                $salePrice,
                $effectivePaymentMethod
            );
            send_property_notification(
                $conn,
                (int) $details['clientID'],
                $notif['title'],
                $notif['message']
            );
        }

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
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
    if ($transactionId <= 0 || $clientId <= 0 || !is_finite($amount) || $amount <= 0 || $amount > 9999999999.99) {
        throw new InvalidArgumentException('Enter a valid payment amount.');
    }
    if ($paymentMethod === '' || strlen($paymentMethod) > 50) {
        throw new InvalidArgumentException('Enter a payment method of 1 to 50 characters.');
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

    $conn->begin_transaction();
    try {
        $transactionStmt = $conn->prepare(
            'SELECT t.transactionID, t.clientID, t.currentStage, p.title, u.fullname
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

        $currentStage = (string) $transaction['currentStage'];
        if (in_array($currentStage, ['completed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Payments cannot be recorded on completed or cancelled transactions.');
        }

        $clientId = (int) $transaction['clientID'];
        $isConfirmed = ($status === 'confirmed');
        $newStage = $isConfirmed ? 'payment_confirmation' : 'payment_in_process';

        $updateStageStmt = $conn->prepare(
            'UPDATE property_transactions SET currentStage = ?, updatedAt = CURRENT_TIMESTAMP WHERE transactionID = ?'
        );
        $updateStageStmt->bind_param('si', $newStage, $transactionId);
        $updateStageStmt->execute();

        if ($isConfirmed) {
            $paperworkMethod = 'Paperwork (Face-to-face)';
            $ref = 'Paperwork confirmed';
            $reviewNote = $note !== '' ? $note : 'Paperwork and payment verified face-to-face.';
            $paymentStmt = $conn->prepare(
                "INSERT INTO property_transaction_payments
                    (transactionID, clientID, amount, paymentMethod, referenceNumber, status, reviewedAt, reviewedBy, reviewNote)
                 VALUES (?, ?, 0.00, ?, ?, 'confirmed', CURRENT_TIMESTAMP, ?, ?)"
            );
            $paymentStmt->bind_param('iissis', $transactionId, $clientId, $paperworkMethod, $ref, $actorId, $reviewNote);
            $paymentStmt->execute();
        }

        if ($isConfirmed) {
            $eventNote = sprintf(
                'Agent recorded face-to-face payment and paperwork (Status: confirmed).%s',
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
        send_property_notification($conn, $clientId, $notificationSubject, $notificationMessage);

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
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

    if ($transactionId <= 0 || $actorId <= 0 || !is_finite($amount) || $amount <= 0 || $amount > 9999999999.99) {
        throw new InvalidArgumentException('Enter a valid payment amount.');
    }
    if ($paymentMethod === '' || strlen($paymentMethod) > 50) {
        throw new InvalidArgumentException('Enter a payment method of 1 to 50 characters.');
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

    $conn->begin_transaction();
    try {
        $transactionStmt = $conn->prepare(
            'SELECT t.transactionID, t.clientID, t.currentStage, p.title, u.fullname
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
            'Agent recorded face-to-face payment of PHP %s via %s (Status: %s).%s',
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
            "Hello %s,\n\nYour agent recorded a payment of PHP %s for '%s' via %s during/following your viewing discussion.\nStatus: %s%s\n\nPlease check your client dashboard to view your transaction progress.",
            (string) ($transaction['fullname'] ?? 'there'),
            $formattedAmount,
            (string) ($transaction['title'] ?? 'the property'),
            $paymentMethod,
            ucfirst($paymentStatus),
            $note !== '' ? "\nNote: " . $note : ''
        );
        send_property_notification($conn, $clientId, $notificationSubject, $notificationMessage);

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
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

    $conn->begin_transaction();
    try {
        $paymentStmt = $conn->prepare(
            'SELECT p.transactionID, p.clientID, p.amount, p.paymentMethod, p.status,
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
            "Hello %s,\n\nYour payment of PHP %s for '%s' was %s by the sales team.%s\n\nPlease check your dashboard for the latest update.",
            (string) $payment['fullname'],
            $amount,
            (string) $payment['title'],
            $status,
            $reviewNote === '' ? '' : "\n\nNote: " . $reviewNote
        );
        send_property_notification($conn, (int) $payment['clientID'], $notificationSubject, $notificationMessage);

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
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

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'SELECT a.status, t.transactionID, t.currentStage
             FROM appointments a
             JOIN property_transactions t ON t.appointmentID = a.appointmentID
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
        if ($currentStatus === $newStatus) {
            $conn->commit();
            return;
        }
        if ($newStatus === 'Pending' && $currentStatus !== 'Pending') {
            throw new InvalidArgumentException('A confirmed or cancelled viewing request cannot be changed back to pending.');
        }

        if ($newStatus === 'Approved') {
            if ($currentStage !== 'viewing_requested') {
                throw new InvalidArgumentException('The transaction has already advanced beyond the viewing confirmation step.');
            }
            $newStage = 'viewing_confirmed';
            $note = 'Sales team confirmed the requested property viewing.';
        } elseif ($newStatus === 'Cancelled') {
            if (!in_array('cancelled', property_transaction_next_stages($currentStage), true)) {
                throw new InvalidArgumentException('This transaction is already closed and cannot be cancelled.');
            }
            $newStage = 'cancelled';
            $note = 'Sales team cancelled the viewing request and transaction.';
        } else {
            throw new InvalidArgumentException('The viewing request status cannot move back to pending.');
        }

        $updateTransaction = $conn->prepare(
            'UPDATE property_transactions SET currentStage = ? WHERE transactionID = ?'
        );
        $updateTransaction->bind_param('si', $newStage, $transactionId);
        $updateTransaction->execute();
        add_property_transaction_event($conn, $transactionId, $newStage, $note, $actorId);

        $updateAppointment = $conn->prepare(
            'UPDATE appointments SET status = ? WHERE appointmentID = ?'
        );
        $updateAppointment->bind_param('si', $newStatus, $appointmentId);
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
            send_property_notification($conn, (int) $details['clientID'], $notificationSubject, $notificationMessage);
        }

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
