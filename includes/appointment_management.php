<?php
/**
 * Appointment Management & Agent Assignment Helpers
 */

function ensure_appointment_management_schema(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
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

    $agentName = (string) ($info['agentName'] ?? 'An agent');
    $propertyTitle = (string) ($info['propertyTitle'] ?? 'Requested Property');
    $formattedDate = date('M j, Y', strtotime($info['appointmentDate']));
    $formattedTime = date('g:i A', strtotime($info['appointmentTime']));

    $title = 'Viewing request taken';
    $message = sprintf(
        "Viewing request #KQ-%d for '%s' (%s at %s) was already taken and confirmed by %s.",
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
