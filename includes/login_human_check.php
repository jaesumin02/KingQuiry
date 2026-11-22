<?php
function issue_login_human_challenge(): array
{
    $now = time();
    $challenges = $_SESSION['login_human_challenges'] ?? [];
    if (!is_array($challenges)) {
        $challenges = [];
    }

    foreach ($challenges as $token => $challenge) {
        if (!is_array($challenge) || $now - (int) ($challenge['issued_at'] ?? 0) > 900) {
            unset($challenges[$token]);
        }
    }

    $token = bin2hex(random_bytes(24));
    $challenges[$token] = [
        'issued_at' => $now,
    ];

    if (count($challenges) > 10) {
        $challenges = array_slice($challenges, -10, null, true);
    }
    $_SESSION['login_human_challenges'] = $challenges;

    return ['token' => $token];
}

function verify_login_human_challenge(array $submitted): bool
{
    $token = is_string($submitted['human_token'] ?? null) ? $submitted['human_token'] : '';
    $challenges = $_SESSION['login_human_challenges'] ?? [];
    $challenge = is_array($challenges) ? ($challenges[$token] ?? null) : null;
    if ($token !== '' && is_array($challenges)) {
        unset($challenges[$token]);
        $_SESSION['login_human_challenges'] = $challenges;
    }

    if (!is_array($challenge)) {
        return false;
    }

    $issuedAt = (int) ($challenge['issued_at'] ?? 0);
    $elapsed = time() - $issuedAt;
    $honeypot = is_string($submitted['website'] ?? null) ? trim($submitted['website']) : 'invalid';
    $verified = is_string($submitted['human_verified'] ?? null) ? $submitted['human_verified'] : '';

    return ($submitted['human_check'] ?? '') === '1'
        && $verified === '1'
        && $honeypot === ''
        && $elapsed >= 0
        && $elapsed <= 900;
}
?>
