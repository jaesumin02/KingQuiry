<?php
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
        header('Location: login.php');
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
