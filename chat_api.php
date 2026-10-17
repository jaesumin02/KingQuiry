<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/location_directory.php';
require_once __DIR__ . '/includes/property_estimator.php';

function chat_respond(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function chat_require_admin(): int
{
    if (normalized_user_role($_SESSION['user'] ?? null) !== 'Admin') {
        chat_respond(['error' => 'Admin access is required.'], 403);
    }

    return (int) $_SESSION['user']['userID'];
}

function chat_conversation(mysqli $conn): int
{
    $sessionKey = hash('sha256', session_id());
    $userId = isset($_SESSION['user']['userID']) ? (int) $_SESSION['user']['userID'] : null;

    $stmt = $conn->prepare("SELECT conversation_id FROM chat_conversations WHERE session_key = ? AND status = 'open' ORDER BY conversation_id DESC LIMIT 1");
    $stmt->bind_param('s', $sessionKey);
    $stmt->execute();
    $conversation = $stmt->get_result()->fetch_assoc();

    if ($conversation) {
        $conversationId = (int) $conversation['conversation_id'];
        if ($userId !== null) {
            $update = $conn->prepare("UPDATE chat_conversations SET user_id = ? WHERE conversation_id = ?");
            $update->bind_param('ii', $userId, $conversationId);
            $update->execute();
        }
        return $conversationId;
    }

    $insert = $conn->prepare("INSERT INTO chat_conversations (session_key, user_id) VALUES (?, ?)");
    $insert->bind_param('si', $sessionKey, $userId);
    $insert->execute();
    $conversationId = (int) $conn->insert_id;

    $welcome = $conn->prepare("INSERT INTO chat_messages (conversation_id, sender, body) VALUES (?, 'bot', ?)");
    $welcomeText = "Hello! Welcome to KingQuiry. I can help with property questions and land estimates. Use the estimate calculator below or send us a message.";
    $welcome->bind_param('is', $conversationId, $welcomeText);
    $welcome->execute();

    return $conversationId;
}

function chat_add_message(mysqli $conn, int $conversationId, string $sender, string $body): int
{
    $stmt = $conn->prepare("INSERT INTO chat_messages (conversation_id, sender, body) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $conversationId, $sender, $body);
    $stmt->execute();
    return (int) $conn->insert_id;
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

function chat_csrf_token(): string
{
    if (!isset($_SESSION['chat_csrf'])) {
        $_SESSION['chat_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['chat_csrf'];
}

try {
    $action = $_SERVER['REQUEST_METHOD'] === 'GET'
        ? ($_GET['action'] ?? '')
        : '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            chat_respond(['error' => 'Invalid request body.'], 400);
        }
        $action = $payload['action'] ?? '';
        if (!is_string($payload['csrf'] ?? null) || !hash_equals(chat_csrf_token(), $payload['csrf'])) {
            chat_respond(['error' => 'Your session has expired. Refresh the page and try again.'], 403);
        }
    } else {
        $payload = [];
    }

    if ($action === 'bootstrap' || $action === 'poll') {
        $role = normalized_user_role($_SESSION['user'] ?? null);
        if ($role !== null && $role !== 'Client') {
            chat_respond(['error' => 'Customer chat is available to guests and client accounts.'], 403);
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
                    (SELECT m.body FROM chat_messages m WHERE m.conversation_id = c.conversation_id ORDER BY m.message_id DESC LIMIT 1) AS last_message
             FROM chat_conversations c
             LEFT JOIN users u ON u.userID = c.user_id
             WHERE c.status = 'open'
             ORDER BY c.updated_at DESC
             LIMIT 100"
        );
        chat_respond(['conversations' => $result->fetch_all(MYSQLI_ASSOC), 'admin_online' => chat_admin_is_online($conn)]);
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
            chat_require_admin();
            $conversationId = max(0, (int) ($payload['conversation_id'] ?? 0));
            $check = $conn->prepare("SELECT conversation_id FROM chat_conversations WHERE conversation_id = ? AND status = 'open'");
            $check->bind_param('i', $conversationId);
            $check->execute();
            if (!$check->get_result()->fetch_assoc()) {
                chat_respond(['error' => 'Conversation not found.'], 404);
            }
            chat_add_message($conn, $conversationId, 'admin', trim($payload['message']));
            chat_respond(['messages' => chat_messages($conn, $conversationId, max(0, (int) ($payload['after'] ?? 0)))]);
        }

        if (isset($_SESSION['user']) && normalized_user_role($_SESSION['user']) !== 'Client') {
            chat_respond(['error' => 'Only clients and guests can start customer conversations.'], 403);
        }

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
                'Based on %d comparable listing(s), the estimated range for %.2f sq.m. in %s is ₱%s–₱%s (about ₱%s/sq.m.). Confidence: %s. This is an indicative estimate, not an appraisal.',
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
            chat_add_message($conn, $conversationId, 'client', $message);

            if (!chat_admin_is_online($conn)) {
                $lowerMessage = mb_strtolower($message);
                if (preg_match('/hello|hi\b|good morning|good afternoon|good evening|kamusta|kumusta/', $lowerMessage)) {
                    $reply = 'Hello! Thanks for contacting KingQuiry. How can I help you today? You can also request a land estimate using the button below.';
                } elseif (preg_match('/estimate|estimation|presyo|price|halaga|sqm|square meter|lupa/', $lowerMessage)) {
                    $reply = 'I can help estimate a land price. Use the estimate calculator below and enter the area in square meters, property category, and location.';
                } elseif (preg_match('/thank|salamat/', $lowerMessage)) {
                    $reply = 'You’re welcome! Thank you for contacting KingQuiry.';
                } elseif (preg_match('/purchase|bought|order|checkout|bumili|binili/', $lowerMessage)) {
                    $reply = 'Thank you for your interest in KingQuiry properties! Share the listing title or location if you have a question, and our admin can follow up.';
                } elseif (preg_match('/appointment|book|schedule|viewing/', $lowerMessage)) {
                    $reply = 'To request a property viewing, open a listing and use its “Book Viewing Appointment” form. An agent will review your request.';
                } else {
                    $reply = 'Thanks for your message! Our admin is currently away. I can help with property questions, appointments, or a land estimate. Please leave your question and the team can follow up.';
                }
                chat_add_message($conn, $conversationId, 'bot', $reply);
            }
        }

        chat_respond([
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
