<?php
function kq_rate_limit_key_hash(string $scope, string $identity): string
{
    if (!preg_match('/\A[a-z0-9_-]{1,40}\z/', $scope) || $identity === '') {
        throw new InvalidArgumentException('A valid rate-limit scope and identity are required.');
    }

    $secret = getenv('KINGQUIRY_RATE_LIMIT_SECRET');
    if ($secret === false || strlen($secret) < 32) {
        if (strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development')) === 'production') {
            error_log('KINGQUIRY_RATE_LIMIT_SECRET must contain at least 32 characters in production.');
            http_response_code(503);
            exit('The service is temporarily unavailable. Please try again later.');
        }

        $secret = 'KingQuiry-local-development-rate-limit-key';
    }

    return hash_hmac('sha256', $scope . "\0" . $identity, $secret, true);
}

function kq_rate_limit_consume(
    mysqli $conn,
    string $scope,
    string $identity,
    int $limit,
    int $windowSeconds
): array {
    if ($limit < 1 || $windowSeconds < 1) {
        throw new InvalidArgumentException('Rate-limit thresholds must be positive.');
    }

    $windowSeconds = min($windowSeconds, 86400);
    $keyHash = kq_rate_limit_key_hash($scope, $identity);
    $windowSql = (int) $windowSeconds;
    $stmt = $conn->prepare(
        "INSERT INTO security_rate_limits (key_hash, window_started_at, attempts, expires_at)
         VALUES (?, CURRENT_TIMESTAMP, 1, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$windowSql} SECOND))
         ON DUPLICATE KEY UPDATE
            attempts = IF(
                window_started_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$windowSql} SECOND),
                1,
                attempts + 1
            ),
            expires_at = IF(
                window_started_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$windowSql} SECOND),
                DATE_ADD(CURRENT_TIMESTAMP, INTERVAL {$windowSql} SECOND),
                expires_at
            ),
            window_started_at = IF(
                window_started_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$windowSql} SECOND),
                CURRENT_TIMESTAMP,
                window_started_at
            )"
    );
    $stmt->bind_param('s', $keyHash);
    $stmt->execute();

    $readStmt = $conn->prepare(
        'SELECT attempts, GREATEST(0, TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP, expires_at)) AS retry_after
         FROM security_rate_limits
         WHERE key_hash = ?'
    );
    $readStmt->bind_param('s', $keyHash);
    $readStmt->execute();
    $bucket = $readStmt->get_result()->fetch_assoc();
    if (!$bucket) {
        throw new RuntimeException('Unable to read the rate-limit state.');
    }

    if (random_int(1, 100) === 1) {
        $conn->query('DELETE FROM security_rate_limits WHERE expires_at < CURRENT_TIMESTAMP LIMIT 100');
    }

    return [
        'allowed' => (int) $bucket['attempts'] <= $limit,
        'retry_after' => (int) $bucket['retry_after'],
    ];
}

function kq_login_rate_limit(mysqli $conn, string $identifier, string $ipAddress): ?int
{
    $ipAddress = $ipAddress !== '' ? $ipAddress : 'unknown';
    $normalizedIdentifier = strtolower(trim($identifier));
    $ipBucket = kq_rate_limit_consume($conn, 'login-ip', $ipAddress, 60, 900);
    $identityBucket = kq_rate_limit_consume(
        $conn,
        'login-identity',
        $ipAddress . "\0" . ($normalizedIdentifier !== '' ? $normalizedIdentifier : '[empty]'),
        8,
        900
    );

    if (!$ipBucket['allowed'] || !$identityBucket['allowed']) {
        return max(1, $ipBucket['retry_after'], $identityBucket['retry_after']);
    }

    return null;
}

function kq_clear_login_rate_limits(mysqli $conn, string $identifier, string $ipAddress): void
{
    $ipAddress = $ipAddress !== '' ? $ipAddress : 'unknown';
    $normalizedIdentifier = strtolower(trim($identifier));
    $keys = [
        kq_rate_limit_key_hash('login-ip', $ipAddress),
        kq_rate_limit_key_hash(
            'login-identity',
            $ipAddress . "\0" . ($normalizedIdentifier !== '' ? $normalizedIdentifier : '[empty]')
        ),
    ];
    $stmt = $conn->prepare('DELETE FROM security_rate_limits WHERE key_hash IN (?, ?)');
    $stmt->bind_param('ss', $keys[0], $keys[1]);
    $stmt->execute();
}

function kq_registration_rate_limit(mysqli $conn, string $email, string $ipAddress): ?int
{
    $ipAddress = $ipAddress !== '' ? $ipAddress : 'unknown';
    $normalizedEmail = strtolower(trim($email));
    $ipBucket = kq_rate_limit_consume($conn, 'register-ip', $ipAddress, 10, 3600);
    $emailBucket = kq_rate_limit_consume(
        $conn,
        'register-email',
        $ipAddress . "\0" . ($normalizedEmail !== '' ? $normalizedEmail : '[empty]'),
        5,
        3600
    );

    if (!$ipBucket['allowed'] || !$emailBucket['allowed']) {
        return max(1, $ipBucket['retry_after'], $emailBucket['retry_after']);
    }

    return null;
}
