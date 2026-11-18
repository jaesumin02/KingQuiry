<?php
putenv('KINGQUIRY_ENV=test');
putenv('KINGQUIRY_COOKIE_SECURE=false');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/transaction_tracking.php';
require_once __DIR__ . '/../includes/appointment_management.php';
require_once __DIR__ . '/../includes/ml_recommender.php';

function expect_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$cookie = session_get_cookie_params();
expect_true($cookie['httponly'] === true, 'Session cookies must be HttpOnly.');
expect_true(($cookie['samesite'] ?? '') === 'Lax', 'Session cookies must use SameSite=Lax.');
expect_true(ini_get('session.use_only_cookies') === '1', 'Session IDs must not be accepted from URLs.');
expect_true(ini_get('session.use_strict_mode') === '1', 'Strict session ID mode must be enabled.');
expect_true(!kq_request_uses_https(), 'An explicit false HTTPS override must remain false.');
putenv('KINGQUIRY_COOKIE_SECURE=true');
expect_true(kq_request_uses_https(), 'A trusted explicit HTTPS override must be honored.');
putenv('KINGQUIRY_COOKIE_SECURE=false');

$sessionIdBeforeLogin = session_id();
kq_regenerate_session_after_login([
    'userID' => 17,
    'fullname' => 'Test User',
    'email' => 'test@example.invalid',
    'role' => 'Client',
    'accountStatus' => 'Active',
    'passwordHash' => 'must-not-be-stored',
]);
expect_true(session_id() !== $sessionIdBeforeLogin, 'Login must rotate the session ID.');
expect_true(!isset($_SESSION['user']['passwordHash']), 'Password hashes must not be stored in the session.');
putenv('KINGQUIRY_RATE_LIMIT_SECRET=first-test-secret-with-at-least-32-bytes');
$firstRateLimitKey = kq_rate_limit_key_hash('login-ip', '192.0.2.1');
putenv('KINGQUIRY_RATE_LIMIT_SECRET=second-test-secret-with-at-least-32-bytes');
$secondRateLimitKey = kq_rate_limit_key_hash('login-ip', '192.0.2.1');
expect_true(strlen($firstRateLimitKey) === 32, 'Rate-limit keys must be stored as 32-byte HMAC digests.');
expect_true($firstRateLimitKey !== $secondRateLimitKey, 'Rate-limit keys must depend on the configured secret.');
putenv('KINGQUIRY_RATE_LIMIT_SECRET');
$fixedManilaNow = new DateTimeImmutable('2026-11-18 20:00:00', new DateTimeZone('Asia/Manila'));
expect_true(
    kq_is_future_appointment_datetime('2026-11-18', '20:01', $fixedManilaNow),
    'A same-day viewing scheduled after the current time must be accepted.'
);
expect_true(
    !kq_is_future_appointment_datetime('2026-11-18', '19:59', $fixedManilaNow),
    'A same-day viewing scheduled in the past must be rejected.'
);
expect_true(
    !kq_is_future_appointment_datetime('2026-11-18', '20:00', $fixedManilaNow),
    'A viewing scheduled for the current minute must be rejected.'
);
expect_true(
    !kq_is_future_appointment_datetime('2026-02-31', '10:00', $fixedManilaNow),
    'Normalized invalid calendar dates must be rejected.'
);
expect_true(
    !kq_is_future_appointment_datetime('2026-11-19', '25:00', $fixedManilaNow),
    'Invalid appointment times must be rejected.'
);
expect_true(
    !in_array('completed', property_transaction_next_stages('payment_in_process'), true),
    'Transactions must not skip directly from payment processing to completion.'
);
expect_true(
    in_array('completed', property_transaction_next_stages('payment_confirmation'), true),
    'Payment-confirmed transactions must retain the completion transition.'
);
expect_true(
    !in_array('payment_confirmation', property_transaction_next_stages('payment_in_process'), true),
    'Non-cash payment methods must pass document verification before payment confirmation.'
);
expect_true(
    in_array('payment_confirmation', property_transaction_next_stages('payment_in_process', 'Spot Cash'), true),
    'Spot Cash must retain its documented direct transition to payment confirmation.'
);
expect_true(
    in_array('Bank Transfer', property_client_payment_methods(), true)
        && !in_array('GCash', property_client_payment_methods(), true)
        && !in_array('GCash', property_staff_payment_methods(), true),
    'Client payment reporting must use supported methods and reject unsupported GCash.'
);
expect_true(
    is_valid_transaction_currency_amount(1250.50)
        && !is_valid_transaction_currency_amount(1250.505)
        && !is_valid_transaction_currency_amount(INF),
    'Transaction payment amounts must be finite and use no more than two decimal places.'
);
expect_true(
    kq_property_similarity_score(0.0, 1000000.0, 0.0, 500.0, false, false) === 0.0,
    'Unmatched properties with no price or area similarity must receive a zero similarity score.'
);
expect_true(
    kq_property_match_percentage(0.0) === 0,
    'Recommendation percentages must not impose a misleading minimum score.'
);

fwrite(STDOUT, "Production baseline checks passed.\n");
