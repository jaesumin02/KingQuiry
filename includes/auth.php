<?php
function kq_request_uses_https(): bool
{
    $secureOverride = getenv('KINGQUIRY_COOKIE_SECURE');
    if ($secureOverride !== false) {
        $parsedOverride = filter_var($secureOverride, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsedOverride === null) {
            error_log('KINGQUIRY_COOKIE_SECURE must be set to true or false.');
            http_response_code(503);
            exit('The service is temporarily unavailable. Please try again later.');
        }

        return $parsedOverride;
    }

    return isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== ''
        && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

function kq_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $secureCookie = kq_request_uses_https();
    $appEnvironment = strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development'));
    if (!in_array($appEnvironment, ['development', 'test', 'production'], true)) {
        error_log('KingQuiry environment must be development, test, or production.');
        http_response_code(503);
        exit('The service is temporarily unavailable. Please try again later.');
    }
    if ($appEnvironment === 'production') {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        if (!$secureCookie) {
            error_log('KingQuiry production sessions require HTTPS; verify TLS and KINGQUIRY_COOKIE_SECURE configuration.');
            http_response_code(503);
            exit('The service is temporarily unavailable. Please try again later.');
        }
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '1800');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        error_log('Unable to start a secure KingQuiry session.');
        http_response_code(503);
        exit('The service is temporarily unavailable. Please try again later.');
    }

    $now = time();
    $lastActivity = (int) ($_SESSION['last_activity_at'] ?? 0);
    if ($lastActivity > 0 && $now - $lastActivity > 1800) {
        $_SESSION = [];
        kq_regenerate_session_id_or_fail('Unable to rotate the expired KingQuiry session.');
    }
    $_SESSION['last_activity_at'] = $now;
}

function kq_regenerate_session_id_or_fail(string $logMessage): void
{
    if (session_status() === PHP_SESSION_ACTIVE && session_regenerate_id(true)) {
        return;
    }

    error_log($logMessage);
    http_response_code(503);
    exit('The service is temporarily unavailable. Please try again later.');
}

function kq_regenerate_session_after_login(array $user): void
{
    kq_regenerate_session_id_or_fail('Unable to secure the authenticated session.');

    $_SESSION['user'] = [
        'userID' => (int) $user['userID'],
        'fullname' => (string) ($user['fullname'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'accountStatus' => (string) ($user['accountStatus'] ?? 'Active'),
    ];
    $_SESSION['last_activity_at'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    unset($_SESSION['chat_csrf']);
}

function kq_sync_session_user(mysqli $conn): ?array
{
    static $checkedUserId = 0;
    static $syncedUser = null;
    $sessionUser = $_SESSION['user'] ?? null;
    if (!is_array($sessionUser) || !isset($sessionUser['userID'])) {
        return null;
    }

    $userId = filter_var($sessionUser['userID'], FILTER_VALIDATE_INT);
    if ($userId === false || $userId <= 0) {
        $_SESSION = ['last_activity_at' => time()];
        kq_regenerate_session_id_or_fail('Unable to rotate an invalid KingQuiry session.');
        return null;
    }
    if ($checkedUserId === (int) $userId) {
        return $syncedUser;
    }

    $stmt = $conn->prepare(
        'SELECT userID, fullname, email, role, accountStatus
         FROM users WHERE userID = ? LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user || ($user['accountStatus'] ?? 'Active') !== 'Active') {
        $_SESSION = ['last_activity_at' => time()];
        kq_regenerate_session_id_or_fail('Unable to rotate a revoked KingQuiry session.');
        $checkedUserId = (int) $userId;
        $syncedUser = null;
        return null;
    }

    $_SESSION['user'] = $user;
    $checkedUserId = (int) $userId;
    $syncedUser = $user;
    return $user;
}

function normalize_role_name(?string $role): ?string
{
    if (!is_string($role)) {
        return null;
    }

    $normalized = strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', $role)));
    $normalized = trim($normalized);

    $roleMap = [
        'admin' => 'Admin',
        'client' => 'Client',
        'agent' => 'Sales Agent',
        'sales agent' => 'Sales Agent',
        'salesagent' => 'Sales Agent',
        'sales_agent' => 'Sales Agent',
    ];

    return $roleMap[$normalized] ?? ucfirst($normalized);
}

function normalized_user_role(?array $user): ?string
{
    if (!is_array($user) || !isset($user['role']) || !is_string($user['role'])) {
        return null;
    }

    return normalize_role_name($user['role']);
}

function user_has_role(?array $user, string ...$roles): bool
{
    $userRole = normalized_user_role($user);
    if ($userRole === null) {
        return false;
    }

    foreach ($roles as $role) {
        if ($userRole === normalize_role_name($role)) {
            return true;
        }
    }

    return false;
}

function require_role(string ...$roles): void
{
    if (!user_has_role($_SESSION['user'] ?? null, ...$roles)) {
        $preferredRole = 'Client';

        foreach ($roles as $role) {
            $normalizedRole = normalize_role_name($role);
            if ($normalizedRole === 'Admin' || $normalizedRole === 'Sales Agent' || $normalizedRole === 'Client') {
                $preferredRole = $normalizedRole;
                break;
            }
        }

        $portal = $preferredRole === 'Admin'
            ? 'admin_login.php'
            : ($preferredRole === 'Sales Agent' ? 'agent_login.php' : 'client_login.php');

        header('Location: ' . $portal);
        exit();
    }
}

function apply_secure_headers(?string $contentType = null): void
{
    if (!headers_sent()) {
        if ($contentType !== null) {
            header('Content-Type: ' . $contentType);
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
        if (kq_request_uses_https()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function has_valid_csrf_token(): bool
{
    return is_string($_POST['csrf_token'] ?? null)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

kq_start_session();
if (isset($conn) && $conn instanceof mysqli) {
    kq_sync_session_user($conn);
}
