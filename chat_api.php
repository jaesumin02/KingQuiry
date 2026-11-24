<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);
if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/transaction_tracking.php';
kq_start_session();

function kq_json_send(array $data, int $code = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// Ensure direct_messages table and presence columns are present immediately
function ensure_direct_messages_table(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS `direct_messages` (
                `messageID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `transactionID` BIGINT UNSIGNED NOT NULL,
                `senderID` INT NOT NULL,
                `receiverID` INT DEFAULT NULL,
                `message` TEXT NOT NULL,
                `isRead` TINYINT(1) NOT NULL DEFAULT 0,
                `isAutomated` TINYINT(1) NOT NULL DEFAULT 0,
                `createdAt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`messageID`),
                KEY `idx_dm_transaction` (`transactionID`, `createdAt`),
                KEY `idx_dm_users` (`senderID`, `receiverID`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $colCheck = $conn->query("SHOW COLUMNS FROM `direct_messages` LIKE 'isAutomated'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE `direct_messages` ADD COLUMN `isAutomated` TINYINT(1) NOT NULL DEFAULT 0 AFTER `isRead`");
        }
        if (function_exists('ensure_user_presence_columns')) {
            ensure_user_presence_columns($conn);
        }
        $ensured = true;
    } catch (Throwable $e) {
        error_log('ensure_direct_messages_table error: ' . $e->getMessage());
    }
}
ensure_direct_messages_table($conn);

$currentUser = function_exists('kq_sync_session_user') ? kq_sync_session_user($conn) : ($_SESSION['user'] ?? null);
if (!is_array($currentUser) || empty($currentUser['userID'])) {
    kq_json_send(['ok' => false, 'code' => 'session_expired', 'error' => 'You must be signed in to use the messaging service.'], 401);
}

$currentUserId = (int) $currentUser['userID'];
$currentRole = normalized_user_role($currentUser);
$isStaffUser = ($currentRole === 'Admin' || $currentRole === 'Sales Agent' || $currentRole === 'Agent');

// Keep agent presence fresh without overriding intentional offline status
if ($isStaffUser && function_exists('update_user_presence')) {
    $actionParam = (string) ($_GET['action'] ?? ($_POST['action'] ?? 'fetch'));
    if ($actionParam !== 'set_presence' && $actionParam !== 'toggle_presence') {
        try {
            $checkStmt = $conn->prepare("SELECT availability_status FROM users WHERE userID = ? LIMIT 1");
            if ($checkStmt) {
                $checkStmt->bind_param('i', $currentUserId);
                $checkStmt->execute();
                $curStRow = $checkStmt->get_result()->fetch_assoc();
                $curAvailVal = strtolower((string) ($curStRow['availability_status'] ?? 'online'));
                if ($curAvailVal !== 'offline') {
                    update_user_presence($conn, $currentUserId, true, 'online');
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string) ($_GET['action'] ?? ($_POST['action'] ?? 'fetch'));

// Action: Heartbeat to update presence timestamp for active agent/user
if ($action === 'heartbeat') {
    $curAvail = 'online';
    if ($currentUserId > 0) {
        try {
            $curStmt = $conn->prepare("SELECT availability_status FROM users WHERE userID = ? LIMIT 1");
            if ($curStmt) {
                $curStmt->bind_param('i', $currentUserId);
                $curStmt->execute();
                $curRow = $curStmt->get_result()->fetch_assoc();
                $curAvail = $curRow['availability_status'] ?? 'online';
                $curOnline = ($curAvail !== 'offline');
                if (function_exists('update_user_presence')) {
                    update_user_presence($conn, $currentUserId, $curOnline, $curAvail);
                }
            }
        } catch (Throwable $e) {
            error_log('Heartbeat presence error: ' . $e->getMessage());
        }
    }
    kq_json_send(['ok' => true, 'is_online' => ($curAvail !== 'offline'), 'status' => $curAvail]);
}

// Action: Explicit toggle/set presence for agent
if ($action === 'toggle_presence' || $action === 'set_presence') {
    $target = strtolower(trim((string) ($_POST['status'] ?? ($_GET['status'] ?? ''))));
    if ($target !== 'online' && $target !== 'offline') {
        try {
            $curStmt = $conn->prepare("SELECT availability_status FROM users WHERE userID = ? LIMIT 1");
            if ($curStmt) {
                $curStmt->bind_param('i', $currentUserId);
                $curStmt->execute();
                $curRow = $curStmt->get_result()->fetch_assoc();
                $target = (strtolower((string) ($curRow['availability_status'] ?? 'offline')) === 'online') ? 'offline' : 'online';
            } else {
                $target = 'offline';
            }
        } catch (Throwable $e) {
            $target = 'offline';
        }
    }
    $isOnline = ($target === 'online');
    if (function_exists('update_user_presence')) {
        update_user_presence($conn, $currentUserId, $isOnline, $target);
    }
    kq_json_send(['ok' => true, 'status' => $target, 'is_online' => $isOnline]);
}

// Action: Retrieve presence info for a user
if ($action === 'get_presence') {
    $targetId = filter_var($_GET['user_id'] ?? $currentUserId, FILTER_VALIDATE_INT) ?: $currentUserId;
    $presence = get_agent_presence($conn, $targetId);
    kq_json_send([
        'ok' => true,
        'user_id' => $targetId,
        'is_online' => $presence['is_online'],
        'status' => $presence['availability_status'],
        'presence' => $presence,
    ]);
}

// Action to initiate or retrieve a direct conversation thread for a specific property
if ($action === 'initiate_property_chat') {
    if ($isStaffUser) {
        kq_json_send(['ok' => false, 'error' => 'Sales staff manage property inquiries through the Transaction Pipeline.'], 403);
    }
    $propId = filter_var($_GET['property_id'] ?? ($_POST['property_id'] ?? 0), FILTER_VALIDATE_INT);
    if ($propId === false || $propId <= 0) {
        kq_json_send(['ok' => false, 'error' => 'Invalid property reference.'], 400);
    }

    $propertyChatTransactionStarted = false;
    try {
        $conn->begin_transaction();
        $propertyChatTransactionStarted = true;
        $propStmt = $conn->prepare(
            'SELECT propertyID, title, location, status, isDeleted
             FROM properties
             WHERE propertyID = ?
             FOR UPDATE'
        );
        $propStmt->bind_param('i', $propId);
        $propStmt->execute();
        $property = $propStmt->get_result()->fetch_assoc();
        $propStmt->close();

        if (!$property) {
            $conn->rollback();
            $propertyChatTransactionStarted = false;
            kq_json_send(['ok' => false, 'error' => 'Property not found.'], 404);
        }

        if ((int) $property['isDeleted'] !== 0 || $property['status'] !== 'Available') {
            $conn->rollback();
            $propertyChatTransactionStarted = false;
            kq_json_send(['ok' => false, 'error' => 'This property is no longer available for inquiries.'], 409);
        }

    // Check if an active transaction already exists for this client and property
    $findTxStmt = $conn->prepare(
        "SELECT t.transactionID, t.propertyID, t.clientID, t.appointmentID, t.inquiryID, t.currentStage,
                p.title AS propertyTitle,
                a.appointmentDate, a.status AS appointmentStatus,
                COALESCE(agentUser.fullname, inqAgentUser.fullname, 'Assigned Sales Agent') AS assignedAgentName
         FROM property_transactions t
         JOIN properties p ON p.propertyID = t.propertyID
         LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
         LEFT JOIN users agentUser ON agentUser.userID = a.agentID
         LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
         LEFT JOIN users inqAgentUser ON inqAgentUser.userID = ia.agentID
         WHERE t.propertyID = ? AND t.clientID = ?
           AND t.currentStage NOT IN ('completed', 'cancelled')
         ORDER BY t.updatedAt DESC, t.transactionID DESC
         LIMIT 1"
    );
    $findTxStmt->bind_param('ii', $propId, $currentUserId);
    $findTxStmt->execute();
    $existingTx = $findTxStmt->get_result()->fetch_assoc();
    $findTxStmt->close();

    if ($existingTx) {
        $conn->commit();
        $propertyChatTransactionStarted = false;
        $hasSched = !empty($existingTx['appointmentDate']) && ($existingTx['appointmentStatus'] ?? '') !== 'Cancelled';
        if (!$hasSched && in_array((string)$existingTx['currentStage'], ['viewing_requested', 'viewing_confirmed', 'viewing_approved', 'viewing_completed', 'reservation_deposit', 'reservation_under_review', 'reservation_agreement', 'payment_in_process', 'payment_confirmation', 'completed'], true)) {
            $hasSched = true;
        }

        kq_json_send([
            'ok' => true,
            'transaction_id' => (int) $existingTx['transactionID'],
            'property_title' => (string) $existingTx['propertyTitle'],
            'partner_name' => (string) $existingTx['assignedAgentName'],
            'has_scheduled_viewing' => $hasSched,
        ]);
    }

    // Create a new inquiry and tracked transaction
    $inqMsg = 'Client opened a direct conversation thread regarding ' . $property['title'] . '.';
    $inqStmt = $conn->prepare("INSERT INTO feedbackinquiries (propertyID, clientID, message, status, createdAt) VALUES (?, ?, ?, 'Unread', CURRENT_TIMESTAMP)");
    $inqStmt->bind_param('iis', $propId, $currentUserId, $inqMsg);
    $inqStmt->execute();
    $newInqId = (int) $conn->insert_id;
    $inqStmt->close();

    ensure_user_presence_columns($conn);
    $staffRes = $conn->query(
        "SELECT userID, fullname 
         FROM users 
         WHERE role IN ('Agent', 'Sales Agent') 
           AND accountStatus = 'Active' 
         ORDER BY 
           (is_online = 1 AND availability_status = 'online' AND last_seen_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)) DESC,
           (role = 'Sales Agent') DESC, 
           RAND() 
         LIMIT 1"
    );
    $assignedAgentId = null;
    $assignedAgentName = 'Assigned Sales Agent';
    if ($staffRes && ($agentRow = $staffRes->fetch_assoc())) {
        $assignedAgentId = (int) $agentRow['userID'];
        $assignedAgentName = (string) $agentRow['fullname'];
        $asStmt = $conn->prepare("INSERT INTO agent_inquiry_assignments (inquiryID, agentID, assignedAt) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $asStmt->bind_param('ii', $newInqId, $assignedAgentId);
        $asStmt->execute();
        $asStmt->close();
    }

    $newTxStmt = $conn->prepare("INSERT INTO property_transactions (propertyID, clientID, inquiryID, currentStage) VALUES (?, ?, ?, 'inquiry_received')");
    $newTxStmt->bind_param('iii', $propId, $currentUserId, $newInqId);
    $newTxStmt->execute();
    $newTxId = (int) $conn->insert_id;
    $newTxStmt->close();

    $conn->commit();
    $propertyChatTransactionStarted = false;
    kq_json_send([
        'ok' => true,
        'transaction_id' => $newTxId,
        'property_title' => (string) $property['title'],
        'partner_name' => $assignedAgentName,
        'has_scheduled_viewing' => false,
    ]);
    } catch (Throwable $exception) {
        if ($propertyChatTransactionStarted) {
            $conn->rollback();
        }
        error_log('KingQuiry property chat intake failed: ' . $exception->getMessage());
        kq_json_send(['ok' => false, 'error' => 'This property conversation could not be started. Please try again.'], 500);
    }
}

$transactionId = filter_var($_GET['transaction_id'] ?? ($_POST['transaction_id'] ?? 0), FILTER_VALIDATE_INT);

if ($transactionId === false || $transactionId <= 0) {
    kq_json_send(['ok' => false, 'error' => 'Invalid transaction reference.'], 400);
}

// Verify transaction and authorization
$txStmt = $conn->prepare(
    "SELECT t.transactionID, t.propertyID, t.clientID, t.appointmentID, t.inquiryID, t.currentStage,
            p.title AS propertyTitle, p.location, p.price AS propertyPrice, p.pricePerSqm, p.areaSqm, p.propertyType, p.locationID,
            u.fullname AS clientName, u.email AS clientEmail,
            a.appointmentDate, a.appointmentTime, a.status AS appointmentStatus,
            COALESCE(a.agentID, ia.agentID) AS assignedAgentID,
            COALESCE(agentUser.fullname, inqAgentUser.fullname, 'Unassigned') AS assignedAgentName,
            COALESCE(agentUser.email, inqAgentUser.email, '') AS assignedAgentEmail,
            COALESCE(agentUser.phone, inqAgentUser.phone, '') AS assignedAgentPhone
     FROM property_transactions t
     JOIN properties p ON p.propertyID = t.propertyID
     JOIN users u ON u.userID = t.clientID
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     LEFT JOIN users agentUser ON agentUser.userID = a.agentID
     LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
     LEFT JOIN users inqAgentUser ON inqAgentUser.userID = ia.agentID
     WHERE t.transactionID = ?
     LIMIT 1"
);
$txStmt->bind_param('i', $transactionId);
$txStmt->execute();
$transaction = $txStmt->get_result()->fetch_assoc();

if (!$transaction) {
    kq_json_send(['ok' => false, 'error' => 'Transaction not found.'], 404);
}

$isClient = ($currentRole === 'Client' && (int) $transaction['clientID'] === $currentUserId);
$isStaff = ($currentRole === 'Admin' || $currentRole === 'Sales Agent' || $currentRole === 'Agent');

if (!$isClient && !$isStaff) {
    kq_json_send(['ok' => false, 'error' => 'You do not have access to this conversation.'], 403);
}

// Check if transaction / property already has a scheduled viewing
$hasScheduledViewing = false;
if (!empty($transaction['appointmentDate']) && ($transaction['appointmentStatus'] ?? '') !== 'Cancelled') {
    $hasScheduledViewing = true;
}
if (!$hasScheduledViewing && in_array((string) $transaction['currentStage'], [
    'viewing_approved', 'viewing_completed',
    'reservation_deposit', 'reservation_under_review', 'reservation_agreement',
    'payment_in_process', 'payment_confirmation', 'completed'
], true)) {
    $hasScheduledViewing = true;
}
if (!$hasScheduledViewing) {
    $activeApptStmt = $conn->prepare(
        "SELECT appointmentID FROM appointments
         WHERE propertyID = ? AND clientID = ? AND status IN ('Pending', 'Approved')
         LIMIT 1"
    );
    if ($activeApptStmt) {
        $activeApptStmt->bind_param('ii', $transaction['propertyID'], $transaction['clientID']);
        $activeApptStmt->execute();
        if ($activeApptStmt->get_result()->fetch_assoc()) {
            $hasScheduledViewing = true;
        }
    }
}

$sessionCsrfToken = function_exists('csrf_token') ? csrf_token() : ((string) ($_SESSION['csrf_token'] ?? ''));

if ($method === 'GET' || $action === 'fetch') {
    // Check if initial automated welcome message is needed
    $cntStmt = $conn->prepare("SELECT COUNT(*) AS total FROM direct_messages WHERE transactionID = ?");
    $cntStmt->bind_param('i', $transactionId);
    $cntStmt->execute();
    $totalCount = (int) ($cntStmt->get_result()->fetch_assoc()['total'] ?? 0);

    if ($totalCount === 0) {
        $welcomeSenderId = !empty($transaction['assignedAgentID']) ? (int) $transaction['assignedAgentID'] : null;
        if (!$welcomeSenderId) {
            $staffRes = $conn->query("SELECT userID FROM users WHERE role IN ('Agent', 'Sales Agent', 'Admin') AND accountStatus = 'Active' ORDER BY (role = 'Sales Agent') DESC, userID ASC LIMIT 1");
            if ($staffRes && ($row = $staffRes->fetch_assoc())) {
                $welcomeSenderId = (int) $row['userID'];
            }
        }

        if ($welcomeSenderId) {
            $clientName = trim((string) ($transaction['clientName'] ?? 'Valued Client'));
            $propTitle = trim((string) ($transaction['propertyTitle'] ?? 'this property'));
            $agentName = !empty($transaction['assignedAgentName']) && $transaction['assignedAgentName'] !== 'Unassigned'
                ? (string) $transaction['assignedAgentName']
                : 'Sales Agent';

            $welcomeMsg = "Hello {$clientName}! 👋 Welcome to 4 Kings Realty Services. I'm your Sales Agent ({$agentName}) for '{$propTitle}'. Feel free to message me here regarding viewing schedules, required documents, or payment terms. How can I assist you today?";

            $insWelcome = $conn->prepare(
                "INSERT INTO direct_messages (transactionID, senderID, receiverID, message, isRead, isAutomated, createdAt)
                 VALUES (?, ?, ?, ?, 0, 1, CURRENT_TIMESTAMP)"
            );
            $insWelcome->bind_param('iiis', $transactionId, $welcomeSenderId, $transaction['clientID'], $welcomeMsg);
            $insWelcome->execute();
        }
    }

    // Mark messages addressed to this user as read
    $markStmt = $conn->prepare("UPDATE direct_messages SET isRead = 1 WHERE transactionID = ? AND receiverID = ? AND isRead = 0");
    if ($markStmt) {
        $markStmt->bind_param('ii', $transactionId, $currentUserId);
        $markStmt->execute();
    }

    $msgStmt = $conn->prepare(
        "SELECT m.messageID, m.transactionID, m.senderID, m.receiverID, m.message, m.isAutomated, m.createdAt,
                u.fullname AS senderName, u.role AS senderRole
         FROM direct_messages m
         JOIN users u ON u.userID = m.senderID
         WHERE m.transactionID = ?
         ORDER BY m.createdAt ASC, m.messageID ASC
         LIMIT 250"
    );
    $msgStmt->bind_param('i', $transactionId);
    $msgStmt->execute();
    $rawMessages = $msgStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $messages = array_map(static function(array $m) use ($currentUserId, $isClient): array {
        $senderId = (int) $m['senderID'];
        $createdEpoch = strtotime($m['createdAt'] ?? 'now');
        $isAutomated = (bool) ($m['isAutomated'] ?? false);
        $isMe = $isAutomated ? !$isClient : ($senderId === $currentUserId);
        return [
            'id' => (int) $m['messageID'],
            'senderId' => $senderId,
            'senderName' => (string) ($m['senderName'] ?? 'User'),
            'senderRole' => (string) ($m['senderRole'] ?? 'User'),
            'isMe' => $isMe,
            'isAutomated' => $isAutomated,
            'message' => (string) $m['message'],
            'time' => date('M j, Y g:i A', $createdEpoch),
        ];
    }, $rawMessages);

    $assignedAgentId = !empty($transaction['assignedAgentID']) ? (int) $transaction['assignedAgentID'] : 0;
    $agentPresence = get_agent_presence($conn, $assignedAgentId);
    $agentIsOnline = (bool) ($agentPresence['is_online'] ?? false);

    $partnerName = $isClient
        ? (!empty($transaction['assignedAgentName']) && $transaction['assignedAgentName'] !== 'Unassigned' ? (string) $transaction['assignedAgentName'] : 'Sales Agent')
        : (string) $transaction['clientName'];

    kq_json_send([
        'ok' => true,
        'csrf_token' => $sessionCsrfToken,
        'is_client' => $isClient,
        'has_scheduled_viewing' => $hasScheduledViewing,
        'agent_presence' => $agentPresence,
        'agent_is_online' => $agentIsOnline,
        'partner_status' => $agentIsOnline ? 'online' : 'offline',
        'transaction' => [
            'id' => (int) $transaction['transactionID'],
            'propertyTitle' => (string) $transaction['propertyTitle'],
            'currentStage' => (string) $transaction['currentStage'],
            'clientName' => (string) $transaction['clientName'],
            'agentName' => (string) $transaction['assignedAgentName'],
            'agentId' => $assignedAgentId,
            'partnerName' => $partnerName,
            'hasScheduledViewing' => $hasScheduledViewing,
            'agentIsOnline' => $agentIsOnline,
        ],
        'messages' => $messages,
    ]);
}

if ($method === 'POST' || $action === 'send') {
    // CSRF verification with fallback check
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    $csrfMatches = is_string($token) && !empty($sessionCsrfToken) && hash_equals($sessionCsrfToken, $token);

    if (!$csrfMatches) {
        $secFetch = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        $hasCookie = isset($_COOKIE[session_name()]);
        if (!($hasCookie && ($secFetch === 'same-origin' || $secFetch === 'same-site' || $secFetch === 'none' || empty($secFetch)))) {
            kq_json_send(['ok' => false, 'error' => 'Security token invalid or expired. Please refresh the page and try again.'], 403);
        }
    }

    $rawMessage = is_string($_POST['message'] ?? null) ? trim((string) $_POST['message']) : '';
    if ($rawMessage === '') {
        kq_json_send(['ok' => false, 'error' => 'Message cannot be empty.'], 422);
    }
    if (mb_strlen($rawMessage) > 2000) {
        kq_json_send(['ok' => false, 'error' => 'Message exceeds 2,000 characters limit.'], 422);
    }

    // If an agent sends a message on an unassigned transaction, automatically assign the agent
    if ($isStaff && empty($transaction['assignedAgentID'])) {
        try {
            if (!empty($transaction['appointmentID'])) {
                $apptStmt = $conn->prepare("UPDATE appointments SET agentID = ? WHERE appointmentID = ?");
                $apptStmt->bind_param('ii', $currentUserId, $transaction['appointmentID']);
                $apptStmt->execute();
            }
            if (!empty($transaction['inquiryID'])) {
                $inqStmt = $conn->prepare("INSERT INTO agent_inquiry_assignments (inquiryID, agentID, assignedAt) VALUES (?, ?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE agentID = ?, assignedAt = CURRENT_TIMESTAMP");
                $inqStmt->bind_param('iii', $transaction['inquiryID'], $currentUserId, $currentUserId);
                $inqStmt->execute();
            }
            $transaction['assignedAgentID'] = $currentUserId;
            $agentName = (string) ($currentUser['fullname'] ?? 'Sales Agent');
            add_property_transaction_event($conn, $transactionId, (string) $transaction['currentStage'], 'Sales Agent ' . $agentName . ' initiated contact with client.', $currentUserId);
        } catch (Throwable $e) {
            error_log('Auto agent assignment on message failed: ' . $e->getMessage());
        }
    }

    // Determine receiver
    $receiverId = null;
    if ($isClient) {
        $receiverId = !empty($transaction['assignedAgentID']) ? (int) $transaction['assignedAgentID'] : null;
    } else {
        $receiverId = (int) $transaction['clientID'];
    }

    $insStmt = $conn->prepare(
        "INSERT INTO direct_messages (transactionID, senderID, receiverID, message, isRead, createdAt)
         VALUES (?, ?, ?, ?, 0, CURRENT_TIMESTAMP)"
    );
    $insStmt->bind_param('iiis', $transactionId, $currentUserId, $receiverId, $rawMessage);
    if (!$insStmt->execute()) {
        kq_json_send(['ok' => false, 'error' => 'Failed to deliver message. Please try again.'], 500);
    }

    $messageId = (int) $conn->insert_id;

    // Send in-app notification to receiver
    if ($receiverId !== null && $receiverId > 0 && function_exists('send_property_notification')) {
        $senderName = (string) ($currentUser['fullname'] ?? 'User');
        $notifTitle = 'New message from ' . $senderName;
        $notifBody = mb_substr($rawMessage, 0, 120);
        $notifLink = ($currentRole === 'Client') ? 'agent_dashboard.php#agent-transactions' : 'client_dashboard.php#transaction-tracking';
        send_property_notification($conn, $receiverId, $notifTitle, $notifBody, $notifLink, 'direct_message');
    }

    // If the message was sent by a Client, determine automated reply trigger:
    // 1) Quick Ask questions (Viewing, Payment terms, Required docs, Location) ALWAYS provide immediate property details.
    // 2) Generic chats (e.g. 'hehe', 'hello') only provide automated bot replies when the agent is offline/away.
    $autoReplyData = null;
    $assignedAgentId = !empty($transaction['assignedAgentID']) ? (int) $transaction['assignedAgentID'] : 0;
    $agentIsOnline = $assignedAgentId > 0 ? is_agent_online($conn, $assignedAgentId) : false;

    $lower = mb_strtolower($rawMessage);
    $propTitle = (string) $transaction['propertyTitle'];
    $propLocation = (string) $transaction['location'];
    $agentName = !empty($transaction['assignedAgentName']) && $transaction['assignedAgentName'] !== 'Unassigned'
        ? (string) $transaction['assignedAgentName']
        : 'Sales Agent';

    $isViewingQuery = (bool) preg_match('/(viewing|schedule|visit|tripping|tripp|appoint|date|time)/i', $lower);
    $isPaymentQuery = (bool) preg_match('/(price|payment|downpayment|dp|spot cash|cash|installment|cts|terms|monthly|amortiz)/i', $lower);
    $isDocsQuery = (bool) preg_match('/(document|requirement|title|deed|transfer|tax|bir|cert|doas)/i', $lower);
    $isLocationQuery = (bool) preg_match('/(location|where|address|barangay|map|directions|pin|lot)/i', $lower);
    $isQuickAskQuery = ($isViewingQuery || $isPaymentQuery || $isDocsQuery || $isLocationQuery);

    if ($isClient && ($isQuickAskQuery || !$agentIsOnline)) {
        $autoReplyText = null;

        if ($isViewingQuery) {
            if ($hasScheduledViewing) {
                if ($agentIsOnline) {
                    $autoReplyText = "📅 Note: A property viewing schedule is already on record for '{$propTitle}'. Your assigned Sales Agent ({$agentName}) is online and can assist with any rescheduling or additional details.";
                } else {
                    $autoReplyText = "📅 Note: A property viewing schedule is already on record for '{$propTitle}'. Your assigned Sales Agent ({$agentName}) is currently away/offline, but will review your message upon return. If you need to reschedule or change details, your agent will coordinate with you.";
                }
            } else {
                if ($agentIsOnline) {
                    $autoReplyText = "📅 Thank you for asking about scheduling a viewing for '{$propTitle}'! Site visits are available Monday to Saturday between 8:00 AM and 5:00 PM. Your assigned Sales Agent ({$agentName}) is online and will confirm your preferred schedule shortly.";
                } else {
                    $autoReplyText = "📅 Thank you for asking about scheduling a viewing for '{$propTitle}'! Site visits are available Monday to Saturday between 8:00 AM and 5:00 PM. Your assigned Sales Agent ({$agentName}) is currently away/offline and has been notified. They will coordinate the exact schedule with you as soon as they return.";
                }
            }
        } elseif ($isPaymentQuery) {
            if ($agentIsOnline) {
                $autoReplyText = "💰 Regarding payment terms for '{$propTitle}': Kings Land Properties supports Spot Cash (which transitions directly to DOAS preparation upon full payment) and structured installment plans via Contract to Sell (CTS). Your assigned Sales Agent ({$agentName}) is online and can provide your detailed computation.";
            } else {
                $autoReplyText = "💰 Regarding payment terms for '{$propTitle}': Kings Land Properties supports Spot Cash (which transitions directly to DOAS preparation upon full payment) and structured installment plans via Contract to Sell (CTS). Your agent ({$agentName}) is currently away/offline and will provide the full computation shortly.";
            }
        } elseif ($isDocsQuery) {
            if ($agentIsOnline) {
                $autoReplyText = "📄 Standard transaction requirements include 2 valid government IDs, TIN, Proof of Billing, and signed reservation paperwork. Standard transfer taxes and registration fees are buyer-shouldered upon completion. Your assigned Sales Agent ({$agentName}) is online to assist with document preparation.";
            } else {
                $autoReplyText = "📄 Standard transaction requirements include 2 valid government IDs, TIN, Proof of Billing, and signed reservation paperwork. Standard transfer taxes and registration fees are buyer-shouldered upon completion. Your agent ({$agentName}) is currently away/offline and will provide the complete checklist.";
            }
        } elseif ($isLocationQuery) {
            if ($agentIsOnline) {
                $autoReplyText = "📍 '{$propTitle}' is located in {$propLocation}. You can view the interactive map location on the listing page. Your assigned Sales Agent ({$agentName}) is online and can share exact meeting points and directions.";
            } else {
                $autoReplyText = "📍 '{$propTitle}' is located in {$propLocation}. You can view the interactive map location on the listing page. Your agent ({$agentName}) is currently away/offline and will share exact meeting points and landmark directions before your site visit.";
            }
        } else {
            // General conversation message (e.g. 'hehe', 'hello') while agent is away/offline
            $autoReplyText = "👋 Thank you for reaching out! Your message regarding '{$propTitle}' has been received. Your assigned Sales Agent ({$agentName}) is currently away/offline, but has been notified and will assist you as soon as they are back online. You may also contact our sales office directly at 0965 803 0933.";
        }

        if ($autoReplyText !== null) {
            $replySenderId = $assignedAgentId > 0 ? $assignedAgentId : null;
            $agentSenderName = (string) ($transaction['assignedAgentName'] ?? 'Sales Agent');
            $agentSenderRole = 'Sales Agent';

            if (!$replySenderId) {
                $staffRes = $conn->query("SELECT userID, fullname, role FROM users WHERE role IN ('Agent', 'Sales Agent', 'Admin') AND accountStatus = 'Active' ORDER BY (role = 'Sales Agent') DESC, (role = 'Agent') DESC, userID ASC LIMIT 1");
                if ($staffRes && ($staffRow = $staffRes->fetch_assoc())) {
                    $replySenderId = (int) $staffRow['userID'];
                    $agentSenderName = (string) $staffRow['fullname'];
                    $agentSenderRole = (string) $staffRow['role'];
                }
            }

            // CRITICAL: NEVER fallback $replySenderId to the client's own userID ($currentUserId)!
            if (!$replySenderId || $replySenderId === $currentUserId) {
                $agentLookup = $conn->query("SELECT userID, fullname, role FROM users WHERE userID != {$currentUserId} AND role IN ('Agent', 'Sales Agent', 'Admin') LIMIT 1");
                if ($agentLookup && ($agentRow = $agentLookup->fetch_assoc())) {
                    $replySenderId = (int) $agentRow['userID'];
                    $agentSenderName = (string) $agentRow['fullname'];
                    $agentSenderRole = (string) $agentRow['role'];
                } else {
                    $replySenderId = ($currentUserId === 1) ? 2 : 1;
                }
            }

            $insAuto = $conn->prepare(
                "INSERT INTO direct_messages (transactionID, senderID, receiverID, message, isRead, isAutomated, createdAt)
                 VALUES (?, ?, ?, ?, 0, 1, CURRENT_TIMESTAMP)"
            );
            $insAuto->bind_param('iiis', $transactionId, $replySenderId, $currentUserId, $autoReplyText);
            if ($insAuto->execute()) {
                $autoMsgId = (int) $conn->insert_id;
                $autoReplyData = [
                    'id' => $autoMsgId,
                    'senderId' => $replySenderId,
                    'senderName' => $agentSenderName,
                    'senderRole' => $agentSenderRole,
                    'isMe' => !$isClient,
                    'isAutomated' => true,
                    'message' => $autoReplyText,
                    'time' => date('M j, Y g:i A'),
                ];
            }
        }
    }

    // Retrieve full updated message list so frontend immediately renders messages without lag
    $allMsgStmt = $conn->prepare(
        "SELECT m.messageID, m.transactionID, m.senderID, m.receiverID, m.message, m.isAutomated, m.createdAt,
                u.fullname AS senderName, u.role AS senderRole
         FROM direct_messages m
         JOIN users u ON u.userID = m.senderID
         WHERE m.transactionID = ?
         ORDER BY m.createdAt ASC, m.messageID ASC
         LIMIT 250"
    );
    $allMsgStmt->bind_param('i', $transactionId);
    $allMsgStmt->execute();
    $rawMessages = $allMsgStmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $updatedMessages = array_map(static function(array $m) use ($currentUserId, $isClient): array {
        $senderId = (int) $m['senderID'];
        $createdEpoch = strtotime($m['createdAt'] ?? 'now');
        $isAutomated = (bool) ($m['isAutomated'] ?? false);
        $isMe = $isAutomated ? !$isClient : ($senderId === $currentUserId);
        return [
            'id' => (int) $m['messageID'],
            'senderId' => $senderId,
            'senderName' => (string) ($m['senderName'] ?? 'User'),
            'senderRole' => (string) ($m['senderRole'] ?? 'User'),
            'isMe' => $isMe,
            'isAutomated' => $isAutomated,
            'message' => (string) $m['message'],
            'time' => date('M j, Y g:i A', $createdEpoch),
        ];
    }, $rawMessages);

    kq_json_send([
        'ok' => true,
        'csrf_token' => $sessionCsrfToken,
        'is_client' => $isClient,
        'has_scheduled_viewing' => $hasScheduledViewing,
        'message' => [
            'id' => $messageId,
            'senderId' => $currentUserId,
            'senderName' => (string) ($currentUser['fullname'] ?? 'User'),
            'isMe' => true,
            'isAutomated' => false,
            'message' => $rawMessage,
            'time' => date('M j, Y g:i A'),
        ],
        'auto_reply' => $autoReplyData,
        'agent_is_online' => $agentIsOnline,
        'partner_status' => $agentIsOnline ? 'online' : 'offline',
        'messages' => $updatedMessages,
    ]);
}

kq_json_send(['ok' => false, 'error' => 'Method not allowed.'], 405);
