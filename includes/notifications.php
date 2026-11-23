<?php

function ensure_notifications_table(mysqli $conn): void
{
    if (strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development')) === 'production') {
        return;
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS user_notifications (
            notificationID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            userID INT NOT NULL,
            title VARCHAR(150) NOT NULL,
            message TEXT NOT NULL,
            link VARCHAR(255) DEFAULT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'general',
            isRead TINYINT(1) NOT NULL DEFAULT 0,
            createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (notificationID),
            KEY idx_user_notifications_user_read (userID, isRead, createdAt),
            KEY idx_user_notifications_created (createdAt),
            CONSTRAINT fk_user_notifications_user
                FOREIGN KEY (userID)
                REFERENCES users (userID)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function send_property_email_log(mysqli $conn, int $userId, string $subject, string $message): bool
{
    $userStmt = $conn->prepare('SELECT email, fullname FROM users WHERE userID = ? LIMIT 1');
    $userStmt->bind_param('i', $userId);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    $userStmt->close();
    if (!$user || empty($user['email'])) {
        return false;
    }

    $to = (string) $user['email'];

    if (strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development')) === 'production') {
        $fromAddress = getenv('KINGQUIRY_MAIL_FROM');
        if (
            !function_exists('mail')
            || filter_var($to, FILTER_VALIDATE_EMAIL) === false
            || !is_string($fromAddress)
            || filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false
        ) {
            error_log('KingQuiry outbound email is unavailable or has an invalid sender/recipient; the in-app notification was saved only.');
            return false;
        }

        $headers = "From: KingQuiry <{$fromAddress}>\r\n"
            . "Reply-To: {$fromAddress}\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";
        $sent = mail($to, $subject, $message, $headers);
        if (!$sent) {
            error_log('KingQuiry outbound email could not be accepted by the configured mail transport.');
        }
        return $sent;
    }

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        error_log('KingQuiry development email log directory could not be created.');
        return false;
    }

    $entry = sprintf(
        "[%s] To: %s | Subject: %s | Message: %s\n",
        date('c'),
        $to,
        $subject,
        $message
    );
    if (file_put_contents($logDir . '/email_notifications.log', $entry, FILE_APPEND | LOCK_EX) === false) {
        error_log('KingQuiry development email log could not be written.');
        return false;
    }

    return true;
}

function create_user_notification(
    mysqli $conn,
    int $userId,
    string $title,
    string $message,
    ?string $link = null,
    string $type = 'general'
): bool {
    ensure_notifications_table($conn);

    $title = trim($title);
    $message = trim($message);
    $link = $link !== null ? trim($link) : null;
    $type = trim($type);

    if ($userId <= 0 || $title === '' || $message === '') {
        return false;
    }

    $stmt = $conn->prepare(
        'INSERT INTO user_notifications (userID, title, message, link, type, isRead)
         VALUES (?, ?, ?, ?, ?, 0)'
    );
    $stmt->bind_param('issss', $userId, $title, $message, $link, $type);
    $saved = $stmt->execute();
    $stmt->close();

    // Also dispatch email log
    send_property_email_log($conn, $userId, $title, $message);

    return $saved;
}

/**
 * Backwards-compatible helper: creates in-app notification and email log.
 */
function send_property_notification(
    mysqli $conn,
    int $clientId,
    string $subject,
    string $message,
    ?string $link = null,
    string $type = 'transaction_update'
): bool {
    return create_user_notification(
        $conn,
        $clientId,
        $subject,
        $message,
        $link ?? 'client_dashboard.php#transaction-tracking',
        $type
    );
}

function notify_agents_new_viewing(
    mysqli $conn,
    int $appointmentId,
    int $propertyId,
    int $clientId,
    string $date,
    string $time
): bool {
    ensure_notifications_table($conn);

    $infoStmt = $conn->prepare(
        'SELECT p.title AS propertyTitle, u.fullname AS clientName, u.email AS clientEmail, u.phone AS clientPhone
         FROM properties p, users u
         WHERE p.propertyID = ? AND u.userID = ?
         LIMIT 1'
    );
    $infoStmt->bind_param('ii', $propertyId, $clientId);
    $infoStmt->execute();
    $info = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();

    $propertyTitle = (string) ($info['propertyTitle'] ?? 'Requested Property');
    $clientName = (string) ($info['clientName'] ?? 'A Client');
    $formattedDate = date('M j, Y', strtotime($date));
    $formattedTime = date('g:i A', strtotime($time));

    $notifTitle = 'New viewing requested';
    $notifMessage = sprintf(
        "Client %s submitted a viewing request for '%s' on %s at %s.",
        $clientName,
        $propertyTitle,
        $formattedDate,
        $formattedTime
    );
    $link = 'agent_dashboard.php#appointments';

    $agentRes = $conn->query(
        "SELECT userID, fullname, email FROM users
         WHERE (role IN ('Agent', 'Admin') OR role LIKE '%Agent%')
           AND accountStatus = 'Active'"
    );

    if (!$agentRes) {
        return false;
    }

    $agents = $agentRes->fetch_all(MYSQLI_ASSOC);
    $agentRes->free();

    $agentCount = 0;
    $allNotificationsSaved = true;
    foreach ($agents as $agent) {
        $agentCount++;
        $agentId = (int) $agent['userID'];
        if (!create_user_notification($conn, $agentId, $notifTitle, $notifMessage, $link, 'viewing_requested')) {
            $allNotificationsSaved = false;
        }
    }

    return $agentCount > 0 && $allNotificationsSaved;
}

function notify_agents_new_inquiry(
    mysqli $conn,
    int $inquiryId,
    int $propertyId,
    int $clientId,
    string $inquiryText
): bool {
    ensure_notifications_table($conn);

    $infoStmt = $conn->prepare(
        'SELECT p.title AS propertyTitle, u.fullname AS clientName
         FROM properties p, users u
         WHERE p.propertyID = ? AND u.userID = ?
         LIMIT 1'
    );
    $infoStmt->bind_param('ii', $propertyId, $clientId);
    $infoStmt->execute();
    $info = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();

    $propertyTitle = (string) ($info['propertyTitle'] ?? 'Requested Property');
    $clientName = (string) ($info['clientName'] ?? 'A Client');
    $snippet = mb_substr(trim($inquiryText), 0, 120, 'UTF-8');

    $notifTitle = 'New property inquiry';
    $notifMessage = sprintf(
        "Client %s inquired about '%s': \"%s\"",
        $clientName,
        $propertyTitle,
        $snippet
    );
    $link = 'agent_dashboard.php#inquiries';

    $agentRes = $conn->query(
        "SELECT userID, fullname, email FROM users
         WHERE (role IN ('Agent', 'Admin') OR role LIKE '%Agent%')
           AND accountStatus = 'Active'"
    );

    if (!$agentRes) {
        return false;
    }

    $agents = $agentRes->fetch_all(MYSQLI_ASSOC);
    $agentRes->free();

    $agentCount = 0;
    $allNotificationsSaved = true;
    foreach ($agents as $agent) {
        $agentCount++;
        $agentId = (int) $agent['userID'];
        if (!create_user_notification($conn, $agentId, $notifTitle, $notifMessage, $link, 'inquiry_received')) {
            $allNotificationsSaved = false;
        }
    }

    return $agentCount > 0 && $allNotificationsSaved;
}

function get_user_notifications(mysqli $conn, int $userId, int $limit = 20): array
{
    ensure_notifications_table($conn);
    $limit = max(1, min(100, $limit));

    $stmt = $conn->prepare(
        'SELECT notificationID, userID, title, message, link, type, isRead, createdAt
         FROM user_notifications
         WHERE userID = ?
         ORDER BY createdAt DESC, notificationID DESC
         LIMIT ?'
    );
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $result;
}

function get_user_unread_notification_count(mysqli $conn, int $userId): int
{
    ensure_notifications_table($conn);

    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS unreadCount
         FROM user_notifications
         WHERE userID = ? AND isRead = 0'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int) ($row['unreadCount'] ?? 0);
}

function mark_notification_as_read(mysqli $conn, int $notificationId, int $userId): bool
{
    ensure_notifications_table($conn);

    $stmt = $conn->prepare(
        'UPDATE user_notifications
         SET isRead = 1
         WHERE notificationID = ? AND userID = ?'
    );
    $stmt->bind_param('ii', $notificationId, $userId);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

function mark_all_notifications_as_read(mysqli $conn, int $userId): bool
{
    ensure_notifications_table($conn);

    $stmt = $conn->prepare(
        'UPDATE user_notifications
         SET isRead = 1
         WHERE userID = ? AND isRead = 0'
    );
    $stmt->bind_param('i', $userId);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}
