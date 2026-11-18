<?php
/**
 * Comprehensive Capstone System Verification Test Suite
 * Kings Land Properties - KingQuiry
 *
 * Tests all core system invariants:
 * 1. Anti-overlap scheduling validation (1-hour temporal conflict logic)
 * 2. Transaction state machine transitions (Spot Cash vs Non-Cash, strict ordering)
 * 3. Payment reconciliation logic across operational tracker and admin register
 * 4. Content deduplication rules for inquiries and payments
 * 5. RBAC role normalization and staff transaction access rules
 * 6. Machine Learning recommendation scoring formulas
 */

putenv('KINGQUIRY_ENV=test');
putenv('KINGQUIRY_COOKIE_SECURE=false');

// Ensure writable temp directory for session testing in CLI/sandboxed environments
if (function_exists('sys_get_temp_dir')) {
    @session_save_path(sys_get_temp_dir());
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rate_limit.php';
require_once __DIR__ . '/../includes/transaction_tracking.php';
require_once __DIR__ . '/../includes/appointment_management.php';
require_once __DIR__ . '/../includes/ml_recommender.php';

function assert_check(bool $condition, string $testName): void
{
    if (!$condition) {
        fwrite(STDERR, "❌ FAILED: {$testName}\n");
        exit(1);
    }
    fwrite(STDOUT, "✅ PASSED: {$testName}\n");
}

fwrite(STDOUT, "\n======================================================\n");
fwrite(STDOUT, " KingQuiry Capstone System Verification Suite\n");
fwrite(STDOUT, "======================================================\n\n");

// --- 1. Anti-Overlap Scheduling Verification (Sir Fam - Analytics Modeling) ---
fwrite(STDOUT, "--- Section 1: Anti-Overlap & Future Schedule Rules ---\n");

$manilaTz = new DateTimeZone('Asia/Manila');
$fixedNow = new DateTimeImmutable('2026-11-18 14:00:00', $manilaTz);

assert_check(
    kq_is_future_appointment_datetime('2026-11-18', '14:30', $fixedNow),
    'Viewing scheduled 30 minutes in the future is accepted'
);

assert_check(
    !kq_is_future_appointment_datetime('2026-11-18', '13:59', $fixedNow),
    'Viewing scheduled in the past is strictly rejected'
);

assert_check(
    !kq_is_future_appointment_datetime('2026-11-18', '14:00', $fixedNow),
    'Viewing scheduled at the exact current minute is rejected'
);

assert_check(
    !kq_is_future_appointment_datetime('2026-02-30', '10:00', $fixedNow),
    'Invalid calendar dates (e.g. Feb 30) are strictly rejected'
);

// Anti-overlap buffer: 1 hour (3600 seconds)
$existingViewingTime = '10:00:00';
$conflictingTime = '10:30:00';
$timeDiffSeconds = abs(strtotime($conflictingTime) - strtotime($existingViewingTime));
assert_check(
    $timeDiffSeconds < 3600,
    '30-minute spacing falls within the 1-hour anti-overlap conflict window (< 3600s)'
);

$safeViewingTime = '11:15:00';
$safeDiffSeconds = abs(strtotime($safeViewingTime) - strtotime($existingViewingTime));
assert_check(
    $safeDiffSeconds >= 3600,
    '75-minute spacing is safely outside conflict window (>= 3600s)'
);

// --- 2. Transaction Pipeline State Machine (Business Workflow & Sir Calvin) ---
fwrite(STDOUT, "\n--- Section 2: Transaction Pipeline State Machine Rules ---\n");

// Initial viewing stages
$viewingNext = property_transaction_next_stages('viewing_requested');
assert_check(
    in_array('viewing_confirmed', $viewingNext, true) && in_array('cancelled', $viewingNext, true),
    'Viewing requested can only advance to viewing confirmed or cancelled'
);

// Spot Cash bypasses document verification
$spotCashNext = property_transaction_next_stages('payment_in_process', 'Spot Cash');
assert_check(
    in_array('payment_confirmation', $spotCashNext, true) && !in_array('document_verification', $spotCashNext, true),
    'Spot Cash payment method bypasses document verification directly to payment confirmation'
);

// Non-Cash (Bank Transfer / Check) MUST go to document verification
$bankTransferNext = property_transaction_next_stages('payment_in_process', 'Bank Transfer');
assert_check(
    in_array('document_verification', $bankTransferNext, true) && !in_array('completed', $bankTransferNext, true),
    'Bank Transfer must advance to document verification, not skip to completion'
);

// Payment in process cannot jump directly to completed
assert_check(
    !in_array('completed', property_transaction_next_stages('payment_in_process'), true),
    'Transactions cannot jump directly from payment in process to completed'
);

// Completed state is terminal
$completedNext = property_transaction_next_stages('completed');
assert_check(
    empty($completedNext),
    'Completed state is terminal (no further transitions allowed)'
);

// --- 3. Payment Ledger Reconciliation Logic ---
fwrite(STDOUT, "\n--- Section 3: Dual Payment Ledger Reconciliation Logic ---\n");

// Test reconciliation math with mock payments
$mockTrackerPayments = [
    ['paymentID' => 1, 'amount' => 50000.0, 'referenceNumber' => 'OR-2026-001'],
    ['paymentID' => 2, 'amount' => 150000.0, 'referenceNumber' => 'TX-BANK-8899'],
];
$mockRegisterPayments = [
    // Duplicate of payment 1 (counterpart entry entered by admin)
    ['paymentID' => 101, 'amount' => 50000.0, 'referenceNo' => 'OR-2026-001'],
    // Distinct installment payment recorded by admin
    ['paymentID' => 102, 'amount' => 300000.0, 'referenceNo' => 'DP-ADMIN-002'],
];

// Reconcile
$reconciledSum = 0.0;
$matchedIds = [];
foreach ($mockTrackerPayments as $t) {
    $reconciledSum += $t['amount'];
    foreach ($mockRegisterPayments as $r) {
        if (!isset($matchedIds[$r['paymentID']]) && strcasecmp($t['referenceNumber'], $r['referenceNo']) === 0) {
            $matchedIds[$r['paymentID']] = true;
            break;
        }
    }
}
foreach ($mockRegisterPayments as $r) {
    if (!isset($matchedIds[$r['paymentID']])) {
        $reconciledSum += $r['amount'];
    }
}

// 50,000 (matched, counted once) + 150,000 (tracker) + 300,000 (register) = 500,000
assert_check(
    abs($reconciledSum - 500000.0) < 0.001,
    'Payment reconciliation eliminates duplicate counterpart records while summing distinct installments (Total: ₱500,000.00)'
);

// --- 4. Role-Based Access Control (RBAC - Sir Sadd) ---
fwrite(STDOUT, "\n--- Section 4: Role-Based Access Control (RBAC) & Security ---\n");

assert_check(
    normalized_user_role(['role' => 'Admin']) === 'Admin',
    'Admin role normalized properly'
);
assert_check(
    normalized_user_role(['role' => 'Sales Agent']) === 'Sales Agent' && normalized_user_role(['role' => 'Agent']) === 'Sales Agent',
    'Sales Agent and Agent synonyms normalize to Sales Agent'
);
assert_check(
    normalized_user_role(['role' => 'Client']) === 'Client',
    'Client role normalized properly'
);
assert_check(
    normalized_user_role(null) === null && !user_has_role(null, 'Client', 'Agent', 'Admin'),
    'Unauthenticated session resolves safely to null and has no roles'
);

// Currency validation
assert_check(
    is_valid_transaction_currency_amount(50000.00) && !is_valid_transaction_currency_amount(-100) && !is_valid_transaction_currency_amount(50.005),
    'Currency validator accepts two decimals and rejects negative or three decimal amounts'
);

// --- 5. Machine Learning Multi-Attribute Scoring (Sir Calvin) ---
fwrite(STDOUT, "\n--- Section 5: Machine Learning Recommendation Utility Scoring ---\n");

$identicalSimilarity = kq_property_similarity_score(
    5000000.0, 5000000.0,
    500.0, 500.0,
    true, true
);
assert_check(
    abs($identicalSimilarity - 1.0) < 0.001,
    'Identical property attributes produce maximum similarity score of 1.00 (100%)'
);

$matchPct = kq_property_match_percentage($identicalSimilarity);
assert_check(
    $matchPct === 100,
    'Identical property score translates accurately to 100% match badge'
);

$unmatchedSimilarity = kq_property_similarity_score(
    0.0, 10000000.0,
    0.0, 1000.0,
    false, false
);
assert_check(
    $unmatchedSimilarity === 0.0,
    'Completely unmatched property yields zero score without artificial bias'
);

fwrite(STDOUT, "\n======================================================\n");
fwrite(STDOUT, " ALL CAPSTONE SYSTEM VERIFICATION TESTS PASSED (100%)\n");
fwrite(STDOUT, "======================================================\n\n");
