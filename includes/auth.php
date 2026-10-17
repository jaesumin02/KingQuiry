<?php
function normalized_user_role(?array $user): ?string
{
    if (!is_array($user) || !isset($user['role']) || !is_string($user['role'])) {
        return null;
    }

    return $user['role'] === 'Agent' ? 'Sales Agent' : $user['role'];
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
