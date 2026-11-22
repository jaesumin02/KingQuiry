<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/transaction_tracking.php';
kq_start_session();

header('Content-Type: application/json; charset=utf-8');

// Ensure direct_messages table is present immediately
function ensure_direct_messages_table(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
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
    $ensured = true;
}
ensure_direct_messages_table($conn);

$currentUser = $_SESSION['user'] ?? null;
if (!is_array($currentUser) || empty($currentUser['userID'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'You must be signed in to use the messaging service.']);
    exit();
}

$currentUserId = (int) $currentUser['userID'];
$currentRole = normalized_user_role($currentUser);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = (string) ($_GET['action'] ?? ($_POST['action'] ?? 'fetch'));

// Action: Heartbeat to update presence timestamp for active agent/user
if ($action === 'heartbeat') {
    if ($currentUserId > 0) {
        $curStmt = $conn->prepare("SELECT availability_status FROM users WHERE userID = ? LIMIT 1");
        if ($curStmt) {
            $curStmt->bind_param('i', $currentUserId);
            $curStmt->execute();
            $curRow = $curStmt->get_result()->fetch_assoc();
            $avail = $curRow['availability_status'] ?? 'online';
            $isOnline = ($avail !== 'offline');
            update_user_presence($conn, $currentUserId, $isOnline, $avail);
        }
    }
    echo json_encode(['ok' => true, 'is_online' => true]);
    exit();
}

// Action: Explicit toggle/set presence for agent
if ($action === 'toggle_presence' || $action === 'set_presence') {
    $target = (string) ($_POST['status'] ?? ($_GET['status'] ?? ''));
    if ($target !== 'online' && $target !== 'offline') {
        $curStmt = $conn->prepare("SELECT availability_status FROM users WHERE userID = ? LIMIT 1");
        if ($curStmt) {
            $curStmt->bind_param('i', $currentUserId);
            $curStmt->execute();
            $curRow = $curStmt->get_result()->fetch_assoc();
            $target = (($curRow['availability_status'] ?? 'offline') === 'online') ? 'offline' : 'online';
        } else {
            $target = 'offline';
        }
    }
    $isOnline = ($target === 'online');
    update_user_presence($conn, $currentUserId, $isOnline, $target);
    echo json_encode(['ok' => true, 'status' => $target, 'is_online' => $isOnline]);
    exit();
}

// Action to initiate or retrieve a direct conversation thread for a specific property
if ($action === 'initiate_property_chat') {
    $propId = filter_var($_GET['property_id'] ?? ($_POST['property_id'] ?? 0), FILTER_VALIDATE_INT);
    if ($propId === false || $propId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid property reference.']);
        exit();
    }

    $propStmt = $conn->prepare("SELECT propertyID, title, location, status FROM properties WHERE propertyID = ? LIMIT 1");
    $propStmt->bind_param('i', $propId);
    $propStmt->execute();
    $property = $propStmt->get_result()->fetch_assoc();

    if (!$property) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Property not found.']);
        exit();
    }

    if ($property['status'] === 'Sold') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'This property is sold and is no longer accepting inquiries.']);
        exit();
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

    if ($existingTx) {
        $hasSched = !empty($existingTx['appointmentDate']) && ($existingTx['appointmentStatus'] ?? '') !== 'Cancelled';
        if (!$hasSched && in_array((string)$existingTx['currentStage'], ['viewing_approved', 'viewing_completed', 'reservation_deposit', 'reservation_under_review', 'reservation_agreement', 'payment_in_process', 'payment_confirmation', 'completed'], true)) {
            $hasSched = true;
        }

        echo json_encode([
            'ok' => true,
            'transaction_id' => (int) $existingTx['transactionID'],
            'property_title' => (string) $existingTx['propertyTitle'],
            'partner_name' => (string) $existingTx['assignedAgentName'],
            'has_scheduled_viewing' => $hasSched,
        ]);
        exit();
    }

    // Create a new inquiry and tracked transaction
    $inqMsg = 'Client opened a direct conversation thread regarding ' . $property['title'] . '.';
    $inqStmt = $conn->prepare("INSERT INTO feedbackinquiries (propertyID, clientID, message, status, createdAt) VALUES (?, ?, ?, 'Unread', CURRENT_TIMESTAMP)");
    $inqStmt->bind_param('iis', $propId, $currentUserId, $inqMsg);
    $inqStmt->execute();
    $newInqId = (int) $conn->insert_id;

    $staffRes = $conn->query("SELECT userID, fullname FROM users WHERE role IN ('Agent', 'Sales Agent') AND accountStatus = 'Active' ORDER BY (role = 'Sales Agent') DESC, RAND() LIMIT 1");
    $assignedAgentId = null;
    $assignedAgentName = 'Assigned Sales Agent';
    if ($staffRes && ($agentRow = $staffRes->fetch_assoc())) {
        $assignedAgentId = (int) $agentRow['userID'];
        $assignedAgentName = (string) $agentRow['fullname'];
        $asStmt = $conn->prepare("INSERT INTO agent_inquiry_assignments (inquiryID, agentID, assignedAt) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $asStmt->bind_param('ii', $newInqId, $assignedAgentId);
        $asStmt->execute();
    }

    $newTxStmt = $conn->prepare("INSERT INTO property_transactions (propertyID, clientID, inquiryID, currentStage) VALUES (?, ?, ?, 'inquiry_received')");
    $newTxStmt->bind_param('iii', $propId, $currentUserId, $newInqId);
    $newTxStmt->execute();
    $newTxId = (int) $conn->insert_id;

    if (function_exists('add_property_transaction_event')) {
        add_property_transaction_event($conn, $newTxId, 'inquiry_received', 'Client initiated direct message thread for property.', $currentUserId);
    }

    echo json_encode([
        'ok' => true,
        'transaction_id' => $newTxId,
        'property_title' => (string) $property['title'],
        'partner_name' => $assignedAgentName,
        'has_scheduled_viewing' => false,
    ]);
    exit();
}

$transactionId = filter_var($_GET['transaction_id'] ?? ($_POST['transaction_id'] ?? 0), FILTER_VALIDATE_INT);

if ($transactionId === false || $transactionId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid transaction reference.']);
    exit();
}

// Verify transaction and authorization
$txStmt = $conn->prepare(
    "SELECT t.transactionID, t.propertyID, t.clientID, t.appointmentID, t.inquiryID, t.currentStage,
            p.title AS propertyTitle, p.location,
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
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Transaction not found.']);
    exit();
}

$isClient = ($currentRole === 'Client' && (int) $transaction['clientID'] === $currentUserId);
$isStaff = ($currentRole === 'Admin' || $currentRole === 'Sales Agent' || $currentRole === 'Agent');

if (!$isClient && !$isStaff) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have access to this conversation.']);
    exit();
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

    $messages = array_map(static function(array $m) use ($currentUserId): array {
        $senderId = (int) $m['senderID'];
        $createdEpoch = strtotime($m['createdAt'] ?? 'now');
        return [
            'id' => (int) $m['messageID'],
            'senderId' => $senderId,
            'senderName' => (string) ($m['senderName'] ?? 'User'),
            'senderRole' => (string) ($m['senderRole'] ?? 'User'),
            'isMe' => ($senderId === $currentUserId),
            'isAutomated' => (bool) ($m['isAutomated'] ?? false),
            'message' => (string) $m['message'],
            'time' => date('M j, Y g:i A', $createdEpoch),
        ];
    }, $rawMessages);

    $assignedAgentId = !empty($transaction['assignedAgentID']) ? (int) $transaction['assignedAgentID'] : 0;
    $agentPresence = get_agent_presence($conn, $assignedAgentId);

    $partnerName = $isClient
        ? (!empty($transaction['assignedAgentName']) && $transaction['assignedAgentName'] !== 'Unassigned' ? (string) $transaction['assignedAgentName'] : 'Sales Agent')
        : (string) $transaction['clientName'];

    echo json_encode([
        'ok' => true,
        'csrf_token' => $sessionCsrfToken,
        'is_client' => $isClient,
        'has_scheduled_viewing' => $hasScheduledViewing,
        'agent_presence' => $agentPresence,
        'agent_is_online' => $agentPresence['is_online'],
        'transaction' => [
            'id' => (int) $transaction['transactionID'],
            'propertyTitle' => (string) $transaction['propertyTitle'],
            'currentStage' => (string) $transaction['currentStage'],
            'clientName' => (string) $transaction['clientName'],
            'agentName' => (string) $transaction['assignedAgentName'],
            'agentId' => $assignedAgentId,
            'partnerName' => $partnerName,
            'hasScheduledViewing' => $hasScheduledViewing,
            'agentIsOnline' => $agentPresence['is_online'],
        ],
        'messages' => $messages,
    ]);
    exit();
}

if ($method === 'POST' || $action === 'send') {
    // CSRF verification with fallback check
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    $csrfMatches = is_string($token) && !empty($sessionCsrfToken) && hash_equals($sessionCsrfToken, $token);

    if (!$csrfMatches) {
        $secFetch = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        $hasCookie = isset($_COOKIE[session_name()]);
        if (!($hasCookie && ($secFetch === 'same-origin' || $secFetch === 'same-site' || $secFetch === 'none' || empty($secFetch)))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Security token invalid or expired. Please refresh the page and try again.']);
            exit();
        }
    }

    $rawMessage = is_string($_POST['message'] ?? null) ? trim((string) $_POST['message']) : '';
    if ($rawMessage === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Message cannot be empty.']);
        exit();
    }
    if (mb_strlen($rawMessage) > 2000) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Message exceeds 2,000 characters limit.']);
        exit();
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
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to deliver message. Please try again.']);
        exit();
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

    // If the message was sent by a Client, check the assigned agent's live online status:
    // ONLY send automated bot replies when the assigned agent is NOT online (offline/away).
    // NEVER fire automated replies when the agent is online and actively handling the conversation!
    $autoReplyData = null;
    $assignedAgentId = !empty($transaction['assignedAgentID']) ? (int) $transaction['assignedAgentID'] : 0;
    $agentIsOnline = $assignedAgentId > 0 ? is_agent_online($conn, $assignedAgentId) : false;

    if ($isClient && !$agentIsOnline) {
            $lower = mb_strtolower($rawMessage);
            $propTitle = (string) $transaction['propertyTitle'];
            $propLocation = (string) $transaction['location'];
            $agentName = !empty($transaction['assignedAgentName']) && $transaction['assignedAgentName'] !== 'Unassigned'
                ? (string) $transaction['assignedAgentName']
                : 'Sales Agent';

            $autoReplyText = null;
            if (preg_match('/(viewing|schedule|visit|tripping|tripp|appoint|date|time)/i', $lower)) {
                if ($hasScheduledViewing) {
                    $autoReplyText = "📅 [Automated Reply] Note: A property viewing schedule is already on record for '{$propTitle}'. Your assigned Sales Agent ({$agentName}) is currently away/offline, but will review your message upon return. If you need to reschedule or change details, your agent will coordinate with you.";
                } else {
                    $autoReplyText = "📅 [Automated Reply] Thank you for asking about scheduling a viewing for '{$propTitle}'! Site visits are available Monday to Saturday between 8:00 AM and 5:00 PM. Your assigned Sales Agent ({$agentName}) is currently away/offline and has been notified. They will coordinate the exact schedule with you as soon as they return.";
                }
            } elseif (preg_match('/(price|payment|downpayment|dp|spot cash|cash|installment|cts|terms|monthly|amortiz)/i', $lower)) {
                $autoReplyText = "💰 [Automated Reply] Regarding payment terms for '{$propTitle}': Kings Land Properties supports Spot Cash (which transitions directly to DOAS preparation upon full payment) and structured installment plans via Contract to Sell (CTS). Your agent ({$agentName}) is currently away/offline and will provide the full computation shortly.";
            } elseif (preg_match('/(document|requirement|title|deed|transfer|tax|bir|cert|doas)/i', $lower)) {
                $autoReplyText = "📄 [Automated Reply] Standard transaction requirements include 2 valid government IDs, TIN, Proof of Billing, and signed reservation paperwork. Standard transfer taxes and registration fees are buyer-shouldered upon completion. Your agent ({$agentName}) is currently away/offline and will provide the complete checklist.";
            } elseif (preg_match('/(location|where|address|barangay|map|directions|pin|lot)/i', $lower)) {
                $autoReplyText = "📍 [Automated Reply] '{$propTitle}' is located in {$propLocation}. You can view the interactive map location on the listing page. Your agent ({$agentName}) is currently away/offline and will share exact meeting points and landmark directions before your site visit.";
            } else {
                $autoReplyText = "👋 [Automated Reply] Thank you for reaching out! Your message regarding '{$propTitle}' has been received. Your assigned Sales Agent ({$agentName}) is currently away/offline, but has been notified and will assist you as soon as they are back online. You may also contact our sales office directly at 0965 803 0933.";
            }

            if ($autoReplyText !== null) {
                $replySenderId = $assignedAgentId > 0 ? $assignedAgentId : null;
                $agentSenderName = (string) ($transaction['assignedAgentName'] ?? 'Sales Agent');
                $agentSenderRole = 'Sales Agent';

                if (!$replySenderId) {
                    $staffRes = $conn->query("SELECT userID, fullname, role FROM users WHERE role IN ('Agent', 'Sales Agent', 'Admin') AND accountStatus = 'Active' ORDER BY (role = 'Sales Agent') DESC, userID ASC LIMIT 1");
                    if ($staffRes && ($staffRow = $staffRes->fetch_assoc())) {
                        $replySenderId = (int) $staffRow['userID'];
                        $agentSenderName = (string) $staffRow['fullname'];
                        $agentSenderRole = (string) $staffRow['role'];
                    }
                }

                if (!$replySenderId) {
                    $replySenderId = 1;
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
                        'isMe' => false,
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

    $updatedMessages = array_map(static function(array $m) use ($currentUserId): array {
        $senderId = (int) $m['senderID'];
        $createdEpoch = strtotime($m['createdAt'] ?? 'now');
        return [
            'id' => (int) $m['messageID'],
            'senderId' => $senderId,
            'senderName' => (string) ($m['senderName'] ?? 'User'),
            'senderRole' => (string) ($m['senderRole'] ?? 'User'),
            'isMe' => ($senderId === $currentUserId),
            'isAutomated' => (bool) ($m['isAutomated'] ?? false),
            'message' => (string) $m['message'],
            'time' => date('M j, Y g:i A', $createdEpoch),
        ];
    }, $rawMessages);

    echo json_encode([
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
        'messages' => $updatedMessages,
    ]);
    exit();
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
exit();
