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
        'offer_negotiation' => 'Offer / negotiation',
        'reservation_processing' => 'Reservation processing',
        'document_verification' => 'Document verification',
        'payment_confirmation' => 'Payment confirmation',
        'completed' => 'Successful / completed',
        'cancelled' => 'Cancelled',
    ];
}

function property_transaction_next_stages(string $stage): array
{
    $transitions = [
        'inquiry_received' => ['contacting_client', 'viewing_requested', 'cancelled'],
        'contacting_client' => ['viewing_requested', 'cancelled'],
        'viewing_requested' => ['viewing_confirmed', 'cancelled'],
        'viewing_confirmed' => ['viewing_completed', 'cancelled'],
        'viewing_completed' => ['offer_negotiation', 'cancelled'],
        'offer_negotiation' => ['reservation_processing', 'cancelled'],
        'reservation_processing' => ['document_verification', 'cancelled'],
        'document_verification' => ['payment_confirmation', 'cancelled'],
        'payment_confirmation' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    return $transitions[$stage] ?? [];
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
    ?float $salePrice = null
): void {
    $stages = property_transaction_stages();
    if (!isset($stages[$newStage]) || $transactionId <= 0 || $actorId <= 0) {
        throw new InvalidArgumentException('Choose a valid transaction and status.');
    }

    if ($newStage === 'completed' && $note === '') {
        throw new InvalidArgumentException('Add a note confirming the sale and required documents were verified before marking it successful.');
    }
    if ($newStage === 'completed' && ($salePrice === null || $salePrice <= 0 || $salePrice > 9999999999.99)) {
        throw new InvalidArgumentException('Enter the verified sale price before marking this transaction successful.');
    }
    if ($newStage === 'payment_confirmation' && $note === '') {
        throw new InvalidArgumentException('Add a note confirming the payment status before recording payment confirmation.');
    }
    if ($note !== '' && strlen($note) > 1000) {
        throw new InvalidArgumentException('Tracking notes must be 1,000 characters or fewer.');
    }

    $conn->begin_transaction();
    try {
        $currentStmt = $conn->prepare(
            'SELECT currentStage FROM property_transactions
             WHERE transactionID = ? FOR UPDATE'
        );
        $currentStmt->bind_param('i', $transactionId);
        $currentStmt->execute();
        $transaction = $currentStmt->get_result()->fetch_assoc();
        if (!$transaction) {
            throw new InvalidArgumentException('The selected transaction was not found.');
        }

        $currentStage = (string) $transaction['currentStage'];
        if (!in_array($newStage, property_transaction_next_stages($currentStage), true)) {
            throw new InvalidArgumentException('That step cannot follow the current status. Update the transaction in order or cancel it.');
        }

        if ($newStage === 'payment_confirmation') {
            $paymentStmt = $conn->prepare(
                "SELECT paymentID FROM property_transaction_payments
                 WHERE transactionID = ? AND status = 'confirmed'
                 LIMIT 1"
            );
            $paymentStmt->bind_param('i', $transactionId);
            $paymentStmt->execute();
            if (!$paymentStmt->get_result()->fetch_assoc()) {
                throw new InvalidArgumentException('Confirm at least one recorded payment before moving this transaction to payment confirmation.');
            }
        }

        if ($newStage === 'completed') {
            $updateStmt = $conn->prepare(
                'UPDATE property_transactions
                 SET currentStage = ?, salePrice = ?, updatedAt = CURRENT_TIMESTAMP
                 WHERE transactionID = ?'
            );
            $updateStmt->bind_param('sdi', $newStage, $salePrice, $transactionId);
        } else {
            $updateStmt = $conn->prepare(
                'UPDATE property_transactions
                 SET currentStage = ?, updatedAt = CURRENT_TIMESTAMP
                 WHERE transactionID = ?'
            );
            $updateStmt->bind_param('si', $newStage, $transactionId);
        }
        $updateStmt->execute();

        add_property_transaction_event($conn, $transactionId, $newStage, $note, $actorId);

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
            $statusLabel = $newStage === 'completed' ? 'closed' : str_replace('_', ' ', $newStage);
            $notificationSubject = 'Property transaction update';
            $notificationMessage = sprintf(
                "Hello %s,\n\nYour property transaction for '%s' has been updated to '%s'.\n\nNote: %s\n\nPlease check your dashboard to review the latest status.",
                (string) ($details['fullname'] ?? 'there'),
                (string) ($details['title'] ?? 'the property'),
                ucfirst($statusLabel),
                $note
            );
            send_property_notification($conn, (int) $details['clientID'], $notificationSubject, $notificationMessage);
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
        if (!in_array((string) $transaction['currentStage'], ['document_verification', 'payment_confirmation'], true)) {
            throw new InvalidArgumentException('Payment details can be submitted during document verification or payment confirmation.');
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
