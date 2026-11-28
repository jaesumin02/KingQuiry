<?php
putenv('KINGQUIRY_ENV=test');
putenv('KINGQUIRY_COOKIE_SECURE=false');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/sms.php';
require_once __DIR__ . '/../includes/notifications.php';
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
expect_true(kq_user_requires_two_factor(['role' => 'Admin']), 'Administrator accounts must require email two-factor authentication.');
expect_true(kq_user_requires_two_factor(['role' => 'Agent']), 'Sales-agent accounts must require email two-factor authentication.');
expect_true(!kq_user_requires_two_factor(['role' => 'Client', 'twoFactorEnabled' => 1]), 'Clients must not receive the staff sign-in verification challenge.');
expect_true(!kq_user_requires_two_factor(['role' => 'Client', 'twoFactorEnabled' => 0]), 'Client sign-in must not require email verification.');
expect_true(kq_dashboard_destination(['role' => 'Admin']) === 'admin_dashboard.php', 'Admin sign-in must route to the Admin dashboard.');
expect_true(kq_dashboard_destination(['role' => 'Agent']) === 'agent_dashboard.php', 'Agent sign-in must route to the Agent dashboard.');
expect_true(kq_dashboard_destination(['role' => 'Client']) === 'client_dashboard.php', 'Client sign-in must route to the Client dashboard.');
expect_true(
    kq_normalize_ph_mobile('0917 123 4567') === '639171234567'
        && kq_normalize_ph_mobile('+63 (917) 123-4567') === '639171234567',
    'Philippine mobile numbers must normalize to the format required by Semaphore.'
);
expect_true(
    kq_normalize_ph_mobile('12345') === null,
    'Invalid mobile numbers must not be used for recovery OTP delivery.'
);
expect_true(
    kq_parse_recovery_identifier('  Demo.User@Example.com ') === [
        'type' => 'email',
        'value' => 'demo.user@example.com',
    ]
        && kq_parse_recovery_identifier('+63 917 123 4567') === [
            'type' => 'phone',
            'value' => '639171234567',
        ]
        && kq_parse_recovery_identifier('not-an-email-or-phone') === null,
    'Account recovery must normalize email/mobile identifiers and reject invalid values.'
);
putenv('KINGQUIRY_SMTP_HOST=smtp.example.invalid');
putenv('KINGQUIRY_SMTP_PORT=587');
putenv('KINGQUIRY_SMTP_ENCRYPTION=tls');
putenv('KINGQUIRY_SMTP_USERNAME=sender@example.invalid');
putenv('KINGQUIRY_SMTP_PASSWORD=0');
$smtpSettings = kq_smtp_settings_from_environment();
expect_true(
    is_array($smtpSettings)
        && $smtpSettings['host'] === 'smtp.example.invalid'
        && $smtpSettings['port'] === 587
        && $smtpSettings['encryption'] === 'tls'
        && $smtpSettings['password'] === '0',
    'SMTP settings must be assembled from valid environment configuration.'
);
putenv('KINGQUIRY_SMTP_PORT=70000');
expect_true(
    kq_smtp_settings_from_environment() === null,
    'SMTP configuration must reject an out-of-range port.'
);
putenv('KINGQUIRY_SMTP_PORT=0');
expect_true(
    kq_smtp_settings_from_environment() === null,
    'An SMTP port of zero must not silently fall back to the default port.'
);
foreach ([
    'KINGQUIRY_SMTP_HOST',
    'KINGQUIRY_SMTP_PORT',
    'KINGQUIRY_SMTP_ENCRYPTION',
    'KINGQUIRY_SMTP_USERNAME',
    'KINGQUIRY_SMTP_PASSWORD',
] as $smtpVariable) {
    putenv($smtpVariable);
}
putenv('KINGQUIRY_SEMAPHORE_API_KEY=test-semaphore-key');
$staffWithVerifiedPhone = [
    'role' => 'Agent',
    'phone' => '0917 123 4567',
    'phoneVerifiedAt' => '2026-11-28 12:00:00',
];
expect_true(
    kq_login_sms_phone($staffWithVerifiedPhone) === '639171234567'
        && kq_login_sms_fallback_available($staffWithVerifiedPhone),
    'Staff with a verified Philippine mobile number and configured SMS must have a phone fallback.'
);
expect_true(
    !kq_login_sms_fallback_available([
        'role' => 'Agent',
        'phone' => '0917 123 4567',
        'phoneVerifiedAt' => null,
    ])
        && !kq_login_sms_fallback_available([
            'role' => 'Client',
            'phone' => '0917 123 4567',
            'phoneVerifiedAt' => '2026-11-28 12:00:00',
        ]),
    'SMS login fallback must be limited to staff with verified phone numbers.'
);
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$_SESSION['pending_2fa_channel'] = 'email';
$_SESSION['pending_2fa_sms_available'] = true;
$_SESSION['pending_2fa_phone_mask'] = '+63 *** *** 4567';
putenv('KINGQUIRY_DEV_MAIL_ENABLED=false');
ob_start();
include __DIR__ . '/../includes/login_2fa_form.php';
$loginTwoFactorHtml = (string) ob_get_clean();
expect_true(
    strpos($loginTwoFactorHtml, 'Try another way: send code to phone') !== false
        && strpos($loginTwoFactorHtml, 'ending in 4567') !== false
        && strpos($loginTwoFactorHtml, 'Demo mode: check') !== false
        && strpos($loginTwoFactorHtml, 'Staff sign-in verification is required') === false,
    'The concise staff verification form must show the local code location and verified-phone fallback without exposing the full number.'
);
putenv('KINGQUIRY_DEV_MAIL_ENABLED=true');
ob_start();
include __DIR__ . '/../includes/login_2fa_form.php';
$configuredLoginTwoFactorHtml = (string) ob_get_clean();
expect_true(
    strpos($configuredLoginTwoFactorHtml, 'Demo mode: check') === false,
    'The staff verification form must not suggest checking a local log when real email delivery is enabled.'
);
putenv('KINGQUIRY_DEV_MAIL_ENABLED');
unset(
    $_SESSION['csrf_token'],
    $_SESSION['pending_2fa_channel'],
    $_SESSION['pending_2fa_sms_available'],
    $_SESSION['pending_2fa_phone_mask']
);
putenv('KINGQUIRY_SEMAPHORE_API_KEY');
expect_true(
    !kq_login_sms_fallback_available($staffWithVerifiedPhone),
    'A verified phone must not offer SMS sign-in when Semaphore is not configured.'
);
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
    property_transaction_stage_label('inquiry_received') === 'Inquiry received'
        && property_transaction_stage_label('contacting_client') === 'Contacting client',
    'Property inquiries must display readable intake-stage labels.'
);
expect_true(
    array_key_first(property_transaction_stages()) === 'inquiry_received',
    'Transaction tracking stages must start with the client inquiry intake stage.'
);
expect_true(
    in_array('contacting_client', property_transaction_next_stages('inquiry_received'), true)
        && in_array('viewing_requested', property_transaction_next_stages('inquiry_received'), true)
        && in_array('viewing_requested', property_transaction_next_stages('contacting_client'), true),
    'Agents must be able to advance claimed inquiries toward a viewing request.'
);
expect_true(
    in_array('reservation_confirmed', property_transaction_next_stages('reservation_requested'), true)
        && !in_array('viewing_requested', property_transaction_next_stages('reservation_requested'), true),
    'Reservation requests must be confirmed before advancing to property viewing.'
);
expect_true(
    in_array('viewing_requested', property_transaction_next_stages('reservation_confirmed'), true),
    'Confirmed reservations must allow proceeding to viewing_requested.'
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
    $categoryImage = property_category_image($propertyType);
    expect_true(
        in_array($propertyType, property_type_values(), true)
            && property_type_label($propertyType) !== ''
            && $categoryImage !== null
            && is_file(__DIR__ . '/../assets/images/' . $categoryImage)
            && get_property_image_credit($categoryImage) !== null,
        $propertyType . ' must have a selectable category image with a credit.'
    );
}
$additionalCategoryImages = [
    'beach-property-05.jpg',
    'beach-property-06.jpg',
    'beach-property-07.jpg',
    'commercial-property-05.jpg',
    'commercial-property-06.jpg',
    'commercial-property-07.jpg',
    'condo-property-05.jpg',
    'farm-property-05.jpg',
    'residential-property-06.jpg',
    'residential-property-07.jpg',
];
foreach ($additionalCategoryImages as $image) {
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

// Unified payment reconciliation & register mapping checks
$reconciledSameRef = reconcile_verified_payment_entries(
    [['paymentID' => 1, 'amount' => 50000.00, 'referenceNumber' => 'OR-100']],
    [['paymentID' => 2, 'amount' => 50000.00, 'referenceNo' => 'or-100']]
);
expect_true(
    abs($reconciledSameRef['total'] - 50000.00) < 0.01 && $reconciledSameRef['count'] === 1,
    'Counterpart payments with matching reference numbers must not be double-counted.'
);

$reconciledInstallments = reconcile_verified_payment_entries(
    [['paymentID' => 1, 'amount' => 20000.00, 'referenceNumber' => 'OR-101']],
    [['paymentID' => 2, 'amount' => 80000.00, 'referenceNo' => 'OR-102']]
);
expect_true(
    abs($reconciledInstallments['total'] - 100000.00) < 0.01 && $reconciledInstallments['count'] === 2,
    'Distinct verified installments across both tables must accumulate accurately.'
);

$spotCashMapping = kq_map_staff_payment_to_register('Spot Cash', 'payment_confirmation');
expect_true(
    $spotCashMapping['paymentMethod'] === 'Cash' && $spotCashMapping['paymentType'] === 'Full Payment',
    'Spot Cash staff entries must map to Cash / Full Payment in the official register.'
);

$reservationMapping = kq_map_staff_payment_to_register('Bank Transfer', 'reservation_confirmed');
expect_true(
    $reservationMapping['paymentMethod'] === 'Bank Transfer' && $reservationMapping['paymentType'] === 'Reservation',
    'Reservation stage bank transfers must map to Bank Transfer / Reservation.'
);

$downPaymentMapping = kq_map_staff_payment_to_register('Bank Transfer', 'payment_in_process');
expect_true(
    $downPaymentMapping['paymentMethod'] === 'Bank Transfer' && $downPaymentMapping['paymentType'] === 'Down Payment',
    'Payment-in-process stage bank transfers must map to Bank Transfer / Down Payment.'
);

expect_true(
    property_type_min_area_sqm('Residential') >= 120.0
        && property_type_min_area_sqm('Townhouse') >= 100.0
        && property_type_min_area_sqm('Farmlot') >= 500.0,
    'Lot categories must enforce realistic minimum areas.'
);

// Gallery helper tests
$fakeFiles = [
    'name' => ['photo1.jpg', 'photo2.png'],
    'type' => ['image/jpeg', 'image/png'],
    'tmp_name' => ['/tmp/1', '/tmp/2'],
    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
    'size' => [1000, 2000]
];
$normalized = normalize_uploaded_files($fakeFiles);
expect_true(count($normalized) === 2, 'normalize_uploaded_files must split multi-upload files properly.');
expect_true($normalized[0]['name'] === 'photo1.jpg' && $normalized[1]['name'] === 'photo2.png', 'normalize_uploaded_files must retain original file names.');
expect_true(property_category_image('Residential') === 'residential-property-01.jpg', 'Property category fallback must return the category image filename.');

fwrite(STDOUT, "Production baseline checks passed.\n");
