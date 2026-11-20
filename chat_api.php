<?php
require_once __DIR__ . '/includes/auth.php';
kq_start_session();
apply_secure_headers('application/json; charset=utf-8');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/location_directory.php';
require_once __DIR__ . '/includes/property_estimator.php';
require_once __DIR__ . '/includes/rate_limit.php';

function chat_respond(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function chat_enforce_rate_limit(
    mysqli $conn,
    string $scope,
    string $identity,
    int $limit,
    int $windowSeconds
): void {
    $result = kq_rate_limit_consume($conn, $scope, $identity, $limit, $windowSeconds);
    if (!$result['allowed']) {
        header('Retry-After: ' . max(1, $result['retry_after']));
        chat_respond(['error' => 'You are sending requests too quickly. Please wait and try again.'], 429);
    }
}

function chat_require_admin(): int
{
    $role = normalized_user_role($_SESSION['user'] ?? null);
    if (!in_array($role, ['Admin', 'Sales Agent'], true)) {
        chat_respond(['error' => 'Admin or Sales Agent access is required.'], 403);
    }

    return (int) $_SESSION['user']['userID'];
}

function chat_conversation(mysqli $conn): int
{
    $sessionKey = hash('sha256', session_id());
    $userId = isset($_SESSION['user']['userID']) ? (int) $_SESSION['user']['userID'] : null;

    if ($userId !== null) {
        $stmt = $conn->prepare("SELECT conversation_id FROM chat_conversations WHERE user_id = ? AND status = 'open' ORDER BY conversation_id DESC LIMIT 1");
        $stmt->bind_param('i', $userId);
    } else {
        $stmt = $conn->prepare("SELECT conversation_id FROM chat_conversations WHERE session_key = ? AND status = 'open' ORDER BY conversation_id DESC LIMIT 1");
        $stmt->bind_param('s', $sessionKey);
    }
    $stmt->execute();
    $conversation = $stmt->get_result()->fetch_assoc();

    if ($conversation) {
        $conversationId = (int) $conversation['conversation_id'];
        $update = $conn->prepare("UPDATE chat_conversations SET session_key = ?, user_id = ? WHERE conversation_id = ?");
        $update->bind_param('sii', $sessionKey, $userId, $conversationId);
        $update->execute();
        return $conversationId;
    }

    $insert = $conn->prepare("INSERT INTO chat_conversations (session_key, user_id) VALUES (?, ?)");
    $insert->bind_param('si', $sessionKey, $userId);
    $insert->execute();
    $conversationId = (int) $conn->insert_id;

    $welcome = $conn->prepare("INSERT INTO chat_messages (conversation_id, sender, body) VALUES (?, 'bot', ?)");
    $clientName = trim($_SESSION['user']['fullname'] ?? '');
    if ($clientName !== '') {
        $welcomeText = "Hello " . $clientName . "! Welcome to Kings Land Properties. Your assigned Sales Agent and support staff are here to assist you. Send us a message below and we will be happy to help!";
    } else {
        $welcomeText = "Hello! Welcome to Kings Land Properties. I can help with property questions and land estimates. Use the estimate calculator below or send us a message.";
    }
    $welcome->bind_param('is', $conversationId, $welcomeText);
    $welcome->execute();

    return $conversationId;
}

function chat_validate_csrf(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }
    if (hash_equals(chat_csrf_token(), $token)) {
        return true;
    }
    if (isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
        return true;
    }
    return false;
}

function chat_add_message(mysqli $conn, int $conversationId, string $sender, string $body): int
{
    $stmt = $conn->prepare("INSERT INTO chat_messages (conversation_id, sender, body) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $conversationId, $sender, $body);
    $stmt->execute();
    $insertId = (int) $conn->insert_id;

    $touch = $conn->prepare("UPDATE chat_conversations SET updated_at = CURRENT_TIMESTAMP WHERE conversation_id = ?");
    $touch->bind_param('i', $conversationId);
    $touch->execute();

    return $insertId;
}

function chat_messages(mysqli $conn, int $conversationId, int $afterId = 0): array
{
    $stmt = $conn->prepare("SELECT message_id, sender, body, created_at FROM chat_messages WHERE conversation_id = ? AND message_id > ? ORDER BY message_id ASC LIMIT 100");
    $stmt->bind_param('ii', $conversationId, $afterId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function chat_admin_is_online(mysqli $conn): bool
{
    $result = $conn->query("SELECT 1 FROM chat_admin_presence WHERE last_seen >= CURRENT_TIMESTAMP - INTERVAL 60 SECOND LIMIT 1");
    return $result->num_rows > 0;
}

function chat_admin_summary(mysqli $conn): array
{
    // Count every open conversation, independently of the limited conversation list.
    // Bot replies do not count as a staff response to a client's message.
    $summary = $conn->query(
        "SELECT COUNT(*) AS open_conversations,
                COALESCE(SUM(last_client_message > last_admin_message), 0) AS awaiting_staff_reply,
                COALESCE(SUM(client_messages), 0) AS client_messages
         FROM (
             SELECT c.conversation_id,
                    COALESCE(MAX(CASE WHEN m.sender = 'client' THEN m.message_id ELSE 0 END), 0) AS last_client_message,
                    COALESCE(MAX(CASE WHEN m.sender = 'admin' THEN m.message_id ELSE 0 END), 0) AS last_admin_message,
                    SUM(CASE WHEN m.sender = 'client' THEN 1 ELSE 0 END) AS client_messages
             FROM chat_conversations c
             LEFT JOIN chat_messages m ON m.conversation_id = c.conversation_id
             WHERE c.status = 'open'
             GROUP BY c.conversation_id
         ) AS open_threads"
    )->fetch_assoc();

    return [
        'open_conversations' => (int) $summary['open_conversations'],
        'awaiting_staff_reply' => (int) $summary['awaiting_staff_reply'],
        'client_messages' => (int) $summary['client_messages'],
    ];
}

if (!function_exists('chat_csrf_token')) {
    function chat_csrf_token(): string
    {
        if (!isset($_SESSION['chat_csrf'])) {
            $_SESSION['chat_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['chat_csrf'];
    }
}

try {
    if (isset($_SESSION['user'])) {
        if (kq_sync_session_user($conn) === null) {
            chat_respond(['error' => 'Your account is no longer active. Sign in again.'], 401);
        }
    }

    if (!empty($_FILES)) {
        chat_respond(['error' => 'File uploads are not supported. Text messages only.'], 400);
    }

    $action = $_SERVER['REQUEST_METHOD'] === 'GET'
        ? ($_GET['action'] ?? '')
        : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            chat_respond(['error' => 'Invalid request body.'], 400);
        }
        $action = $payload['action'] ?? '';
        if (!chat_validate_csrf($payload['csrf'] ?? null)) {
            chat_respond(['error' => 'Your session has expired. Refresh the page and try again.'], 403);
        }
    } else {
        $payload = [];
    }

    if ($action === 'bootstrap' || $action === 'poll') {
        $role = normalized_user_role($_SESSION['user'] ?? null);
        if ($role !== null && $role !== 'Client') {
            chat_respond(['error' => 'Chat is available to guests and Client accounts.'], 403);
        }
        $conversationId = chat_conversation($conn);
        $afterId = max(0, (int) ($_GET['after'] ?? 0));
        chat_respond([
            'csrf' => chat_csrf_token(),
            'conversation_id' => $conversationId,
            'messages' => chat_messages($conn, $conversationId, $action === 'bootstrap' ? 0 : $afterId),
            'admin_online' => chat_admin_is_online($conn),
        ]);
    }

    if ($action === 'agent_greeting') {
        $role = normalized_user_role($_SESSION['user'] ?? null);
        if ($role !== null && $role !== 'Client') {
            chat_respond(['error' => 'Chat is available to guests and Client accounts.'], 403);
        }

        $conversationId = chat_conversation($conn);

        $agentName = is_string($payload['agent_name'] ?? null) ? trim($payload['agent_name']) : '';
        $propertyTitle = is_string($payload['property_title'] ?? null) ? trim($payload['property_title']) : '';
        $transactionId = filter_var($payload['transaction_id'] ?? null, FILTER_VALIDATE_INT);

        $safeAgent = $agentName !== '' ? $agentName : 'Assigned Sales Agent';
        $safeProperty = $propertyTitle !== '' ? $propertyTitle : 'your selected property';
        $txRef = ($transactionId !== false && $transactionId > 0) ? "Transaction #KQT-{$transactionId}" : "your transaction";

        // Check if an automated greeting for this transaction has already been sent
        $alreadySent = false;
        if ($transactionId !== false && $transactionId > 0) {
            $checkStmt = $conn->prepare("SELECT message_id FROM chat_messages WHERE conversation_id = ? AND body LIKE ? LIMIT 1");
            $likePattern = "%#KQT-{$transactionId}%";
            $checkStmt->bind_param('is', $conversationId, $likePattern);
            $checkStmt->execute();
            if ($checkStmt->get_result()->fetch_assoc()) {
                $alreadySent = true;
            }
        }

        if (!$alreadySent) {
            $clientName = trim($_SESSION['user']['fullname'] ?? '');
            $greetingPrefix = $clientName !== '' ? "Hello {$clientName}!" : "Hello!";
            $greetingBody = "{$greetingPrefix} I am {$safeAgent}, your assigned Sales Agent for {$safeProperty} ({$txRef}). How can I assist you with your inquiry or transaction today?";
            chat_add_message($conn, $conversationId, 'admin', $greetingBody);
        }

        chat_respond([
            'csrf' => chat_csrf_token(),
            'conversation_id' => $conversationId,
            'messages' => chat_messages($conn, $conversationId, 0),
            'admin_online' => chat_admin_is_online($conn),
        ]);
    }

    if ($action === 'admin_bootstrap') {
        $adminId = chat_require_admin();
        chat_respond([
            'csrf' => chat_csrf_token(),
            'admin_id' => $adminId,
            'conversations' => [],
            'admin_online' => chat_admin_is_online($conn),
        ]);
    }

    if ($action === 'admin_heartbeat') {
        $adminId = chat_require_admin();
        $stmt = $conn->prepare("INSERT INTO chat_admin_presence (admin_id, last_seen) VALUES (?, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE last_seen = CURRENT_TIMESTAMP");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        chat_respond(['admin_online' => true]);
    }

    if ($action === 'admin_list') {
        chat_require_admin();
        $result = $conn->query(
            "SELECT c.conversation_id, c.user_id, c.updated_at,
                    COALESCE(u.fullname, 'Guest visitor') AS customer_name,
                    COALESCE((SELECT MAX(m.created_at) FROM chat_messages m WHERE m.conversation_id = c.conversation_id), c.updated_at) AS last_activity_at,
                    (SELECT m.body FROM chat_messages m WHERE m.conversation_id = c.conversation_id ORDER BY m.message_id DESC LIMIT 1) AS last_message
             FROM chat_conversations c
             LEFT JOIN users u ON u.userID = c.user_id
             WHERE c.status = 'open'
             ORDER BY last_activity_at DESC, c.conversation_id DESC
             LIMIT 100"
        );
        chat_respond([
            'conversations' => $result->fetch_all(MYSQLI_ASSOC),
            'summary' => chat_admin_summary($conn),
            'admin_online' => chat_admin_is_online($conn),
        ]);
    }

    if ($action === 'admin_messages') {
        chat_require_admin();
        $conversationId = max(0, (int) ($_GET['conversation_id'] ?? 0));
        $afterId = max(0, (int) ($_GET['after'] ?? 0));
        if ($conversationId === 0) {
            chat_respond(['error' => 'Select a conversation first.'], 400);
        }
        chat_respond(['messages' => chat_messages($conn, $conversationId, $afterId)]);
    }

    if ($action === 'send' || $action === 'estimate' || $action === 'admin_reply') {
        if (!is_string($payload['message'] ?? '') || trim($payload['message']) === '' || strlen($payload['message']) > 1000) {
            chat_respond(['error' => 'Messages must contain 1 to 1000 characters.'], 400);
        }

        if ($action === 'admin_reply') {
            $adminId = chat_require_admin();
            chat_enforce_rate_limit($conn, 'chat-admin', (string) $adminId, 60, 60);
            $conversationId = max(0, (int) ($payload['conversation_id'] ?? 0));
            $check = $conn->prepare("SELECT conversation_id, user_id FROM chat_conversations WHERE conversation_id = ? AND status = 'open'");
            $check->bind_param('i', $conversationId);
            $check->execute();
            $convData = $check->get_result()->fetch_assoc();
            if (!$convData) {
                chat_respond(['error' => 'Conversation not found.'], 404);
            }
            $cleanMessage = trim($payload['message']);
            chat_add_message($conn, $conversationId, 'admin', $cleanMessage);

            // Notify client if conversation is linked to a registered user
            if (!empty($convData['user_id'])) {
                require_once __DIR__ . '/includes/notifications.php';
                $clientUserId = (int) $convData['user_id'];
                $agentName = trim($_SESSION['user']['fullname'] ?? 'Sales Agent');
                $replyExcerpt = mb_strlen($cleanMessage) > 80 ? mb_substr($cleanMessage, 0, 77) . '...' : $cleanMessage;
                create_user_notification(
                    $conn,
                    $clientUserId,
                    'New Message from ' . $agentName,
                    $replyExcerpt,
                    'client_dashboard.php',
                    'chat'
                );
            }

            chat_respond([
                'csrf' => chat_csrf_token(),
                'messages' => chat_messages($conn, $conversationId, max(0, (int) ($payload['after'] ?? 0)))
            ]);
        }

        if (isset($_SESSION['user']) && normalized_user_role($_SESSION['user']) !== 'Client') {
            chat_respond(['error' => 'Only Clients and guests can start conversations.'], 403);
        }

        $clientIpAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) && $_SERVER['REMOTE_ADDR'] !== ''
            ? $_SERVER['REMOTE_ADDR']
            : 'unknown';
        $chatSessionKey = session_id();
        $messageLimit = $action === 'estimate' ? 5 : 12;
        chat_enforce_rate_limit($conn, 'chat-ip', $clientIpAddress, 40, 60);
        chat_enforce_rate_limit($conn, 'chat-session', $chatSessionKey, $messageLimit, 60);

        $conversationId = chat_conversation($conn);

        if ($action === 'estimate') {
            $area = filter_var($payload['area'] ?? null, FILTER_VALIDATE_FLOAT);
            $propertyType = $payload['property_type'] ?? '';
            $locationId = filter_var($payload['location_id'] ?? null, FILTER_VALIDATE_INT);
            if ($area === false || !is_string($propertyType) || $locationId === false) {
                chat_respond(['error' => 'Enter a valid area, category, and location.'], 400);
            }

            $estimate = estimate_property_value($conn, (float) $area, $propertyType, (int) $locationId);
            $clientMessage = sprintf(
                'Estimate request: %s sq.m., %s, %s',
                number_format((float) $area, 2),
                $propertyType,
                $estimate['location_name']
            );
            chat_add_message($conn, $conversationId, 'client', $clientMessage);
            $reply = sprintf(
                'Based on %d comparable listing(s), the estimated range for %.2f sq.m. in %s is ₱%s–₱%s (about ₱%s/sq.m.). Confidence: %s. This is an indicative estimate, not an appraisal. (Note: Title transfer, Documentary Stamp Tax, and registration fees are shouldered by the buyer).',
                $estimate['comparable_count'],
                (float) $area,
                $estimate['location_name'],
                number_format($estimate['low_estimate'], 2),
                number_format($estimate['high_estimate'], 2),
                number_format($estimate['price_per_sqm'], 2),
                strtolower($estimate['confidence'])
            );
            chat_add_message($conn, $conversationId, 'bot', $reply);
        } else {
            $message = trim($payload['message']);
            $targetAgentId = filter_var($payload['agent_id'] ?? null, FILTER_VALIDATE_INT);
            if ($targetAgentId !== false && $targetAgentId > 0) {
                $agentStmt = $conn->prepare(
                    "SELECT userID
                     FROM users
                     WHERE userID = ?
                       AND role IN ('Agent', 'Sales Agent')
                       AND accountStatus = 'Active'
                     LIMIT 1"
                );
                $agentStmt->bind_param('i', $targetAgentId);
                $agentStmt->execute();
                if (!$agentStmt->get_result()->fetch_assoc()) {
                    chat_respond(['error' => 'The selected sales agent is unavailable. Refresh the page and try again.'], 422);
                }
            }
            chat_add_message($conn, $conversationId, 'client', $message);

            // Determine if this is a direct agent conversation or transaction chat
            $isDirectAgentChat = false;
            $transactionId = filter_var($payload['transaction_id'] ?? null, FILTER_VALIDATE_INT);
            $clientUserId = isset($_SESSION['user']['userID']) ? (int) $_SESSION['user']['userID'] : 0;
            $clientName = trim($_SESSION['user']['fullname'] ?? 'Client');
            $messageExcerpt = mb_strlen($message) > 80 ? mb_substr($message, 0, 77) . '...' : $message;

            $assignedAgentId = null;
            if ($targetAgentId !== false && $targetAgentId > 0) {
                $assignedAgentId = (int) $targetAgentId;
                $isDirectAgentChat = true;
            } elseif ($transactionId !== false && $transactionId > 0) {
                $txStmt = $conn->prepare("SELECT COALESCE(
                        a.agentID,
                        ia.agentID,
                        (SELECT e.changedBy FROM property_transaction_events e WHERE e.transactionID = t.transactionID AND e.changedBy IS NOT NULL ORDER BY e.createdAt DESC LIMIT 1)
                    ) AS assignedAgentID
                    FROM property_transactions t
                    LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
                    LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
                    WHERE t.transactionID = ?");
                $txStmt->bind_param('i', $transactionId);
                $txStmt->execute();
                $txRow = $txStmt->get_result()->fetch_assoc();
                if (!empty($txRow['assignedAgentID'])) {
                    $assignedAgentId = (int) $txRow['assignedAgentID'];
                    $isDirectAgentChat = true;
                }
            } elseif ($clientUserId > 0) {
                // If client is logged in, find active assigned agent from latest transaction
                $cStmt = $conn->prepare("SELECT COALESCE(a.agentID, ia.agentID) AS assignedAgentID
                    FROM property_transactions t
                    LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
                    LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
                    WHERE t.clientID = ? AND t.currentStage != 'cancelled'
                    ORDER BY t.updatedAt DESC LIMIT 1");
                $cStmt->bind_param('i', $clientUserId);
                $cStmt->execute();
                $cRow = $cStmt->get_result()->fetch_assoc();
                if (!empty($cRow['assignedAgentID'])) {
                    $assignedAgentId = (int) $cRow['assignedAgentID'];
                    $isDirectAgentChat = true;
                }
            }

            // Create notification for assigned agent or staff
            require_once __DIR__ . '/includes/notifications.php';
            if ($assignedAgentId !== null && $assignedAgentId > 0) {
                create_user_notification(
                    $conn,
                    $assignedAgentId,
                    'New Message from ' . $clientName,
                    $messageExcerpt,
                    'admin_chat.php',
                    'chat'
                );
            } else {
                // Notify active staff/admins
                $staffResult = $conn->query("SELECT userID FROM users WHERE role IN ('Admin', 'Sales Agent') AND (accountStatus = 'Active' OR accountStatus IS NULL) LIMIT 5");
                if ($staffResult) {
                    while ($staffRow = $staffResult->fetch_assoc()) {
                        create_user_notification(
                            $conn,
                            (int) $staffRow['userID'],
                            'New Client Message (' . $clientName . ')',
                            $messageExcerpt,
                            'admin_chat.php',
                            'chat'
                        );
                    }
                }
            }

            // Only generate bot replies if this is not a direct client-to-agent chat
            if (!$isDirectAgentChat) {
                $lowerMessage = mb_strtolower($message);
                // ALGORITHM: Check keyword rules in order and return the first matching canned response.
                if (preg_match('/hello|hi\b|good morning|good afternoon|good evening|kamusta|kumusta|magandang umaga|magandang hapon|magandang gabi/', $lowerMessage)) {
                    $reply = 'Hello! Welcome to KingQuiry. I can help with property listings, viewing requests, contact details, and land estimates. What would you like to know?';
                } elseif (preg_match('/estimate|estimation|presyo|price|halaga|how much|magkano|sqm|square meter/', $lowerMessage)) {
                    $reply = 'For an indicative land estimate, use the calculator below and enter the area in square meters, property category, and location. The estimate is not an appraisal or a confirmed sale price.';
                } elseif (preg_match('/thank|salamat/', $lowerMessage)) {
                    $reply = 'You’re welcome! Thank you for contacting KingQuiry.';
                } elseif (preg_match('/reservation|reserve|7.?day|grace period|down.?payment|dp\b/', $lowerMessage)) {
                    $reply = 'Under Kings Land Properties policy, placing a reservation deposit initiates a strict 7-day grace period to complete your down payment and submit compliance documents. Once completed, your transaction advances to Contract to Sell (CTS) or Deed of Absolute Sale (DOAS).';
                } elseif (preg_match('/contract|cts|contract to sell|deed of absolute sale|doas|title transfer|transfer of title|taxes|tax\b|transfer fee/', $lowerMessage)) {
                    $reply = 'For installment purchases, a notarized Contract to Sell (CTS) is issued. For Spot Cash or fully paid purchases, a Deed of Absolute Sale (DOAS) is executed. Please note that title transfer, registration, and Documentary Stamp Taxes (DST) are shouldered by the buyer as standard practice.';
                } elseif (preg_match('/agent|live agent|human|speak with|talk to|kausap|staff/', $lowerMessage)) {
                    $reply = 'Your inquiry has been flagged for our live Sales Agents. A Sales Agent will review your questions and follow up directly here or through your Client dashboard.';
                } elseif (preg_match('/appointment|book|schedule|viewing|site visit|tripping|mag.?book|pasyal/', $lowerMessage)) {
                    $reply = 'To request a property viewing, sign in with a Client account, open the property listing, and submit the “Request a viewing” form. Requested viewing times must be at least one hour apart to avoid overlaps and ensure dedicated support from a Sales Agent.';
                } elseif (preg_match('/contact|phone|call|location|address|hours|open|oras|numero|tawag|saan|lokasyon|bukas/', $lowerMessage)) {
                    $reply = 'You can contact Kings Land Properties at 0965 803 0933 or visit Munting Indang, Nasugbu, Batangas. The contact listing says Always Open; staff reply times may vary.';
                } elseif (preg_match('/property|listing|available|bahay|condo|farm|commercial|beach lot|lupa|land|lote|bibili|buy|purchase|order/', $lowerMessage)) {
                    $reply = 'Browse the published listings in the Property Catalog. For confirmed availability, title, boundaries, price, or sale terms, send the listing title and our team will verify the details before you proceed.';
                } else {
                    $adminOnline = chat_admin_is_online($conn);
                    $reply = $adminOnline
                        ? 'Thanks for your message! The KingQuiry team has received it. An admin will reply here as soon as they are available.'
                        : 'Thanks for your message! Our admin is currently away. Your message is saved, and the team can follow up when they are available.';
                }
                chat_add_message($conn, $conversationId, 'bot', $reply);
            }
        }

        chat_respond([
            'csrf' => chat_csrf_token(),
            'messages' => chat_messages($conn, $conversationId, max(0, (int) ($payload['after'] ?? 0))),
            'admin_online' => chat_admin_is_online($conn),
        ]);
    }

    chat_respond(['error' => 'Unknown chat action.'], 400);
} catch (InvalidArgumentException $exception) {
    chat_respond(['error' => $exception->getMessage()], 400);
} catch (mysqli_sql_exception $exception) {
    error_log('KingQuiry chat database error: ' . $exception->getMessage());
    chat_respond(['error' => 'Chat is temporarily unavailable. Check the chat database setup or try again later.'], 500);
} catch (RuntimeException $exception) {
    chat_respond(['error' => $exception->getMessage()], 422);
}
