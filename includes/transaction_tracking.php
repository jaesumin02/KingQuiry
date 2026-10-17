<?php
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

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
