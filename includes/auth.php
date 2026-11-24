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
    $sessionTimeout = ($appEnvironment === 'production') ? 28800 : 86400; // 8h prod, 24h dev/test
    ini_set('session.gc_maxlifetime', (string) $sessionTimeout);
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
    if ($lastActivity > 0 && $now - $lastActivity > $sessionTimeout) {
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
    if ($checkedUserId === (int) $userId && $syncedUser !== null) {
        return $syncedUser;
    }

    try {
        $stmt = $conn->prepare(
            'SELECT userID, fullname, email, role, accountStatus
             FROM users WHERE userID = ? LIMIT 1'
        );
        if (!$stmt) {
            return $sessionUser;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res) {
            return $sessionUser;
        }
        $user = $res->fetch_assoc();
        if (!$user) {
            $_SESSION = ['last_activity_at' => time()];
            kq_regenerate_session_id_or_fail('Unable to rotate a non-existent KingQuiry session.');
            $checkedUserId = (int) $userId;
            $syncedUser = null;
            return null;
        }
        if (($user['accountStatus'] ?? 'Active') !== 'Active') {
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
    } catch (Throwable $e) {
        error_log('kq_sync_session_user query error: ' . $e->getMessage());
        return $sessionUser;
    }
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

if (!function_exists('chat_csrf_token')) {
    function chat_csrf_token(): string
    {
        if (!isset($_SESSION['chat_csrf'])) {
            $_SESSION['chat_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['chat_csrf'];
    }
}

function has_valid_csrf_token(): bool
{
    return is_string($_POST['csrf_token'] ?? null)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

function kq_log_auth_event(mysqli $conn, int $userId, string $event): void
{
    if ($userId <= 0 || !in_array($event, ['login', 'logout'], true)) {
        return;
    }

    try {
        $stmt = $conn->prepare('INSERT INTO auditlogs (userID, action) VALUES (?, ?)');
        $action = $event === 'login' ? 'User logged in.' : 'User logged out.';
        $stmt->bind_param('is', $userId, $action);
        $stmt->execute();
    } catch (mysqli_sql_exception $exception) {
        error_log('KingQuiry authentication audit log failed: ' . $exception->getMessage());
    }
}

function kq_process_user_login(mysqli $conn): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }

    if (!has_valid_csrf_token()) {
        return 'Your session expired. Refresh the page and try again.';
    }

    if (function_exists('verify_login_human_challenge') && !verify_login_human_challenge($_POST)) {
        return 'Complete the human check before signing in.';
    }

    $postedIdentifier = $_POST['identifier'] ?? $_POST['email'] ?? '';
    $identifier = is_string($postedIdentifier) ? trim(substr($postedIdentifier, 0, 100)) : '';
    $password = $_POST['password'] ?? '';
    $loginIpAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';

    if (function_exists('kq_login_rate_limit')) {
        $retryAfter = kq_login_rate_limit($conn, $identifier, $loginIpAddress);
        if ($retryAfter !== null) {
            return 'Too many sign-in attempts. Please wait ' . (int) ceil($retryAfter / 60) . ' minute(s) and try again.';
        }
    }

    if ($identifier !== '' && is_string($password) && $password !== '') {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? OR SUBSTRING_INDEX(email, '@', 1) = ? LIMIT 2");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            $normalizedRole = normalized_user_role($user);
            if (password_verify($password, $user['passwordHash'])) {
                if (($user['accountStatus'] ?? 'Active') === 'Inactive') {
                    return 'This account is inactive. Please contact the KingQuiry administrator.';
                }

                if (function_exists('kq_clear_login_rate_limits')) {
                    kq_clear_login_rate_limits($conn, $identifier, $loginIpAddress);
                }
                kq_regenerate_session_after_login($user);
                kq_log_auth_event($conn, (int) $user['userID'], 'login');
                if (function_exists('update_user_presence')) {
                    update_user_presence($conn, (int) $user['userID'], true, 'online');
                }
                $destination = $normalizedRole === 'Admin'
                    ? 'admin_dashboard.php'
                    : ($normalizedRole === 'Sales Agent' ? 'agent_dashboard.php' : 'index.php');
                header('Location: ' . $destination);
                exit();
            }
        }
    }

    return 'Invalid email or password. Please try again.';
}

if (!function_exists('ensure_user_presence_columns')) {
function ensure_user_presence_columns(mysqli $conn): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $colCheck = $conn->query("SHOW COLUMNS FROM `users` LIKE 'is_online'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE `users` ADD COLUMN `is_online` TINYINT(1) NOT NULL DEFAULT 0 AFTER `accountStatus`");
        }
        $colCheck = $conn->query("SHOW COLUMNS FROM `users` LIKE 'availability_status'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE `users` ADD COLUMN `availability_status` VARCHAR(20) NOT NULL DEFAULT 'offline' AFTER `is_online`");
        }
        $colCheck = $conn->query("SHOW COLUMNS FROM `users` LIKE 'last_seen_at'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE `users` ADD COLUMN `last_seen_at` TIMESTAMP NULL DEFAULT NULL AFTER `availability_status`");
        }
        $ensured = true;
    } catch (Throwable $e) {
        error_log('ensure_user_presence_columns warning: ' . $e->getMessage());
    }
}
}

if (!function_exists('update_user_presence')) {
function update_user_presence(mysqli $conn, int $userId, bool $isOnline, string $status): void
{
    if ($userId <= 0) {
        return;
    }

    ensure_user_presence_columns($conn);
    $allowedStatuses = ['online', 'offline', 'away'];
    $status = strtolower(trim($status));
    if (!in_array($status, $allowedStatuses, true)) {
        $status = $isOnline ? 'online' : 'offline';
    }

    $onlineFlag = ($isOnline && $status !== 'offline') ? 1 : 0;
    try {
        $stmt = $conn->prepare(
            'UPDATE users SET is_online = ?, availability_status = ?, last_seen_at = CURRENT_TIMESTAMP WHERE userID = ?'
        );
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('isi', $onlineFlag, $status, $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('update_user_presence error: ' . $e->getMessage());
    }
}

function is_agent_online(mysqli $conn, int $agentId): bool
{
    if ($agentId <= 0) {
        return false;
    }

    ensure_user_presence_columns($conn);
    try {
        $stmt = $conn->prepare(
            'SELECT is_online, availability_status, (last_seen_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS fresh 
             FROM users WHERE userID = ? LIMIT 1'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        $avail = strtolower((string) ($row['availability_status'] ?? 'offline'));
        if ($avail === 'offline') {
            return false;
        }

        // Agent is online if explicitly set to online/away and recently active (within 15 minutes) or currently flagged online
        return (int) ($row['is_online'] ?? 0) === 1 && (int) ($row['fresh'] ?? 0) === 1;
    } catch (Throwable $e) {
        error_log('is_agent_online error: ' . $e->getMessage());
        return false;
    }
}

function get_agent_presence(mysqli $conn, int $agentId): array
{
    $result = [
        'is_online' => false,
        'availability_status' => 'offline',
        'last_seen_at' => null,
    ];

    if ($agentId <= 0) {
        return $result;
    }

    ensure_user_presence_columns($conn);
    try {
        $stmt = $conn->prepare(
            'SELECT is_online, availability_status, last_seen_at, (last_seen_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)) AS fresh 
             FROM users WHERE userID = ? LIMIT 1'
        );
        if (!$stmt) {
            return $result;
        }
        $stmt->bind_param('i', $agentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $result;
        }

        $avail = strtolower((string) ($row['availability_status'] ?? 'offline'));
        $online = ($avail !== 'offline') && (int) ($row['is_online'] ?? 0) === 1 && (int) ($row['fresh'] ?? 0) === 1;

        $result['is_online'] = $online;
        $result['availability_status'] = $avail;
        $result['last_seen_at'] = $row['last_seen_at'] ?? null;
    } catch (Throwable $e) {
        error_log('get_agent_presence error: ' . $e->getMessage());
    }

    return $result;
}
}

kq_start_session();
if (isset($conn) && $conn instanceof mysqli) {
    kq_sync_session_user($conn);
}
