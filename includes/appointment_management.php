<?php
/**
 * Appointment Management & Agent Assignment Helpers
 */

function kq_is_future_appointment_datetime(
    string $date,
    string $time,
    ?DateTimeImmutable $now = null
): bool {
    $timeZone = new DateTimeZone('Asia/Manila');
    $dateTimeString = $date . ' ' . $time;
    $appointmentAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dateTimeString, $timeZone);
    $parseErrors = DateTimeImmutable::getLastErrors();

    if (
        $appointmentAt === false
        || ($parseErrors !== false && ($parseErrors['warning_count'] > 0 || $parseErrors['error_count'] > 0))
        || $appointmentAt->format('Y-m-d H:i') !== $dateTimeString
    ) {
        return false;
    }

    $currentTime = $now !== null
        ? $now->setTimezone($timeZone)
        : new DateTimeImmutable('now', $timeZone);

    return $appointmentAt > $currentTime;
}

function get_active_property_viewing_request(
    mysqli $conn,
    int $propertyId,
    int $excludeAppointmentId = 0
): ?array
{
    if ($propertyId <= 0) {
        throw new InvalidArgumentException('Choose a valid property.');
    }

    $stmt = $conn->prepare(
        "SELECT a.appointmentID, a.appointmentDate, a.appointmentTime
         FROM appointments a
         LEFT JOIN property_transactions t ON t.appointmentID = a.appointmentID
         WHERE a.propertyID = ?
           AND (? = 0 OR a.appointmentID <> ?)
           AND a.status IN ('Pending', 'Approved')
           AND (
               t.transactionID IS NULL
               OR t.currentStage NOT IN (
                   'viewing_completed', 'payment_in_process', 'document_verification',
                   'payment_confirmation', 'completed', 'cancelled'
               )
           )
         ORDER BY a.appointmentDate ASC, a.appointmentTime ASC, a.appointmentID ASC
         LIMIT 1"
    );
    $stmt->bind_param('iii', $propertyId, $excludeAppointmentId, $excludeAppointmentId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $request ?: null;
}

function ensure_appointment_management_schema(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    if (strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development')) === 'production') {
        $agentColumnStmt = $conn->prepare(
            'SELECT COUNT(*) AS columnCount
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $tableName = 'appointments';
        $columnName = 'agentID';
        $agentColumnStmt->bind_param('ss', $tableName, $columnName);
        $agentColumnStmt->execute();
        if ((int) ($agentColumnStmt->get_result()->fetch_assoc()['columnCount'] ?? 0) === 0) {
            throw new RuntimeException('The production appointments table is missing agentID. Apply the documented migration.');
        }

        $dismissedTable = $conn->query("SHOW TABLES LIKE 'agent_dismissed_appointments'");
        if ($dismissedTable->num_rows === 0) {
            throw new RuntimeException('The production database is missing agent_dismissed_appointments. Apply the documented migration.');
        }
        $ensured = true;
        return;
    }

    // Check if agentID column exists in appointments
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS columnCount
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "appointments"
           AND COLUMN_NAME = "agentID"'
    );
    if ($stmt) {
        $stmt->execute();
        $columnExists = (int) ($stmt->get_result()->fetch_assoc()['columnCount'] ?? 0) > 0;
        $stmt->close();

        if (!$columnExists) {
            $conn->query("ALTER TABLE appointments ADD COLUMN agentID INT DEFAULT NULL AFTER clientID, ADD KEY idx_appointments_agent (agentID)");
        }
    }

    // Create table for agent dismissed appointments
    $conn->query(
        "CREATE TABLE IF NOT EXISTS agent_dismissed_appointments (
            agentID INT NOT NULL,
            appointmentID INT NOT NULL,
            dismissedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (agentID, appointmentID),
            KEY idx_dismissed_appointment (appointmentID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

function dismiss_appointment_for_agent(mysqli $conn, int $agentId, int $appointmentId): bool
{
    ensure_appointment_management_schema($conn);

    $stmt = $conn->prepare(
        'INSERT IGNORE INTO agent_dismissed_appointments (agentID, appointmentID) VALUES (?, ?)'
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $agentId, $appointmentId);
    return $stmt->execute();
}

function claim_inquiry_for_agent(mysqli $conn, int $agentId, int $inquiryId): void
{
    if ($agentId <= 0 || $inquiryId <= 0) {
        throw new InvalidArgumentException('Choose a valid inquiry.');
    }

    $conn->begin_transaction();
    try {
        $agentStmt = $conn->prepare(
            "SELECT userID FROM users
             WHERE userID = ? AND role IN ('Agent', 'Sales Agent') AND accountStatus = 'Active'
             LIMIT 1"
        );
        $agentStmt->bind_param('i', $agentId);
        $agentStmt->execute();
        if (!$agentStmt->get_result()->fetch_assoc()) {
            throw new InvalidArgumentException('An active Sales Agent account is required to claim an inquiry.');
        }

        $inquiryStmt = $conn->prepare('SELECT inquiryID FROM feedbackinquiries WHERE inquiryID = ? FOR UPDATE');
        $inquiryStmt->bind_param('i', $inquiryId);
        $inquiryStmt->execute();
        if (!$inquiryStmt->get_result()->fetch_assoc()) {
            throw new InvalidArgumentException('The selected inquiry was not found.');
        }

        $assignmentStmt = $conn->prepare(
            'SELECT agentID FROM agent_inquiry_assignments WHERE inquiryID = ? LIMIT 1 FOR UPDATE'
        );
        $assignmentStmt->bind_param('i', $inquiryId);
        $assignmentStmt->execute();
        $assignment = $assignmentStmt->get_result()->fetch_assoc();
        if ($assignment && (int) $assignment['agentID'] !== $agentId) {
            throw new InvalidArgumentException('This inquiry has already been claimed by another Sales Agent.');
        }
        if (!$assignment) {
            $claimStmt = $conn->prepare(
                'INSERT INTO agent_inquiry_assignments (inquiryID, agentID) VALUES (?, ?)'
            );
            $claimStmt->bind_param('ii', $inquiryId, $agentId);
            $claimStmt->execute();
        }

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}

function notify_agents_viewing_taken(mysqli $conn, int $appointmentId, int $actingAgentId): void
{
    require_once __DIR__ . '/notifications.php';
    ensure_notifications_table($conn);

    $stmt = $conn->prepare(
        "SELECT a.appointmentDate, a.appointmentTime, p.title AS propertyTitle,
                u.fullname AS clientName, ag.fullname AS agentName
         FROM appointments a
         JOIN properties p ON p.propertyID = a.propertyID
         JOIN users u ON u.userID = a.clientID
         JOIN users ag ON ag.userID = ?
         WHERE a.appointmentID = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ii', $actingAgentId, $appointmentId);
    $stmt->execute();
    $info = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$info) {
        return;
    }

    $agentName = (string) ($info['agentName'] ?? 'A Sales Agent');
    $propertyTitle = (string) ($info['propertyTitle'] ?? 'Requested Property');
    $formattedDate = date('M j, Y', strtotime($info['appointmentDate']));
    $formattedTime = date('g:i A', strtotime($info['appointmentTime']));

    $title = 'Viewing request taken';
    $message = sprintf(
        "Viewing request #KQ-%d for '%s' (%s at %s) was already taken and approved by %s.",
        1000 + $appointmentId,
        $propertyTitle,
        $formattedDate,
        $formattedTime,
        $agentName
    );
    $link = 'agent_dashboard.php#appointments';

    // Find all other active agents and admins
    $agentRes = $conn->prepare(
        "SELECT userID FROM users
         WHERE (role IN ('Agent', 'Admin') OR role LIKE '%Agent%')
           AND accountStatus = 'Active'
           AND userID != ?"
    );
    if ($agentRes) {
        $agentRes->bind_param('i', $actingAgentId);
        $agentRes->execute();
        $res = $agentRes->get_result();
        while ($row = $res->fetch_assoc()) {
            $otherAgentId = (int) $row['userID'];
            create_user_notification($conn, $otherAgentId, $title, $message, $link, 'viewing_taken');
        }
        $agentRes->close();
    }
}
