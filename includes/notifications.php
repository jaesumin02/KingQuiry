<?php
function send_property_notification(mysqli $conn, int $clientId, string $subject, string $message): bool
{
    $userStmt = $conn->prepare('SELECT email, fullname FROM users WHERE userID = ? LIMIT 1');
    $userStmt->bind_param('i', $clientId);
    $userStmt->execute();
    $user = $userStmt->get_result()->fetch_assoc();
    if (!$user || empty($user['email'])) {
        return false;
    }

    $to = (string) $user['email'];
    $headers = "From: no-reply@kingquiry.local\r\n"
        . "Reply-To: no-reply@kingquiry.local\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";

    if (function_exists('mail')) {
        try {
            $mailSent = mail($to, $subject, $message, $headers);
            if ($mailSent) {
                return true;
            }
        } catch (Throwable $exception) {
            error_log('KingQuiry notification mail failed: ' . $exception->getMessage());
        }
    }

    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        error_log('KingQuiry notification log directory could not be created.');
        return false;
    }

    $logFile = $logDir . '/email_notifications.log';
    $entry = sprintf(
        "[%s] To: %s | Subject: %s | Message: %s\n",
        date('c'),
        $to,
        $subject,
        $message
    );
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

    return true;
}
