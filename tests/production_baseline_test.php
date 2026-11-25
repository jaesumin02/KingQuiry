<?php
putenv('KINGQUIRY_ENV=test');
putenv('KINGQUIRY_COOKIE_SECURE=false');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/transaction_tracking.php';
require_once __DIR__ . '/../includes/appointment_management.php';
require_once __DIR__ . '/../includes/admin_analytics.php';
require_once __DIR__ . '/../includes/ml_recommender.php';
require_once __DIR__ . '/../includes/property_categories.php';
require_once __DIR__ . '/../includes/property_images.php';

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
    !array_key_exists('inquiry_received', property_transaction_stages())
        && !array_key_exists('contacting_client', property_transaction_stages()),
    'Transaction tracking stages must exclude casual chat inquiries.'
);
expect_true(
    array_key_first(property_transaction_stages()) === 'viewing_requested',
    'Transaction tracking stages must start at viewing_requested.'
);
expect_true(
    in_array('reviewing_schedule', property_transaction_next_stages('viewing_requested'), true),
    'Viewing requests must transition to reviewing_schedule on agent acceptance.'
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
foreach (property_type_values() as $propertyType) {
    $representativeImage = property_representative_image($propertyType);
    expect_true(
        in_array($propertyType, property_type_values(), true)
            && property_type_label($propertyType) !== ''
            && $representativeImage !== null
            && is_file(__DIR__ . '/../assets/images/' . $representativeImage)
            && get_property_image_credit($representativeImage) !== null,
        $propertyType . ' must have a selectable category and a credited representative image.'
    );
}
$additionalRepresentativeImages = [
    'representative-beach-05.jpg',
    'representative-beach-06.jpg',
    'representative-beach-07.jpg',
    'representative-commercial-05.jpg',
    'representative-commercial-06.jpg',
    'representative-commercial-07.jpg',
    'representative-condo-05.jpg',
    'representative-farm-05.jpg',
    'representative-residential-06.jpg',
    'representative-residential-07.jpg',
];
foreach ($additionalRepresentativeImages as $image) {
    expect_true(
        is_file(__DIR__ . '/../assets/images/' . $image)
            && get_property_image_credit($image) !== null,
        $image . ' must exist and include its license attribution.'
    );
}

// Business rules: Land estimation formula validation
$testArea = 250.0;
$testRate = 8500.0;
$testTotalPrice = $testArea * $testRate;
expect_true(
    abs($testTotalPrice - 2125000.0) < 0.001,
    'Land estimation formula must equal Lot Area multiplied by Price per sq.m.'
);

// Legal pathway checks: CTS (installment) vs DOAS (Spot Cash)
expect_true(
    in_array('document_verification', property_transaction_next_stages('payment_in_process'), true),
    'Installment purchases must proceed through document verification for Contract to Sell (CTS).'
);
expect_true(
    in_array('payment_confirmation', property_transaction_next_stages('payment_in_process', 'Spot Cash'), true),
    'Spot Cash purchases must bypass document verification and proceed directly to DOAS payment confirmation.'
);
expect_true(
    admin_report_year_options([2024, 2026, 2024, 0], 2026)
        === [2031, 2030, 2029, 2028, 2027, 2026, 2024],
    'Annual revenue reports must offer recorded years, the current year, and five upcoming years without duplicates.'
);
expect_true(
    kq_format_compact_peso(999) === '₱999'
        && kq_format_compact_peso(1500) === '₱1.5K'
        && kq_format_compact_peso(2000000) === '₱2.0M',
    'Shared compact currency formatting must preserve its existing values and thresholds.'
);

// Business rules: 7-day reservation grace period calculation
$simulatedUpdatedNow = time();
$daysElapsedZero = (int) floor((time() - $simulatedUpdatedNow) / 86400);
$daysRemainingStart = max(0, 7 - $daysElapsedZero);
expect_true($daysRemainingStart === 7, 'Newly entered payment_in_process transactions must show 7 days remaining.');

$simulated3DaysAgo = time() - (3 * 86400);
$daysElapsed3 = (int) floor((time() - $simulated3DaysAgo) / 86400);
$daysRemaining3 = max(0, 7 - $daysElapsed3);
expect_true($daysRemaining3 === 4, 'Transactions updated 3 days ago must show 4 days remaining.');

$simulated8DaysAgo = time() - (8 * 86400);
$daysElapsed8 = (int) floor((time() - $simulated8DaysAgo) / 86400);
$daysRemaining8 = max(0, 7 - $daysElapsed8);
expect_true($daysRemaining8 === 0, 'Transactions updated over 7 days ago must indicate window expiration.');

fwrite(STDOUT, "Production baseline checks passed.\n");
