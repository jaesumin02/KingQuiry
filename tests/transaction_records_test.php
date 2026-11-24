<?php

$requestedEnvironment = strtolower((string) (getenv('KINGQUIRY_ENV') ?: 'development'));
if ($requestedEnvironment === 'production') {
    fwrite(STDERR, "Transaction record tests must not run against a production environment.\n");
    exit(1);
}

putenv('KINGQUIRY_ENV=test');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../includes/transaction_tracking.php';
require_once __DIR__ . '/../includes/appointment_management.php';

function expect_record_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$host = getenv('KINGQUIRY_TEST_DB_HOST') ?: (getenv('KINGQUIRY_DB_HOST') ?: 'localhost');
$username = getenv('KINGQUIRY_TEST_DB_USER') ?: (getenv('KINGQUIRY_DB_USER') ?: 'root');
$password = getenv('KINGQUIRY_TEST_DB_PASSWORD');
if ($password === false) {
    $password = getenv('KINGQUIRY_DB_PASSWORD');
}
$password = $password === false ? '' : $password;
$database = getenv('KINGQUIRY_TEST_DB_NAME') ?: (getenv('KINGQUIRY_DB_NAME') ?: 'kingquiry_db');
$portValue = getenv('KINGQUIRY_TEST_DB_PORT') ?: (getenv('KINGQUIRY_DB_PORT') ?: '3306');
$port = filter_var($portValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
if ($port === false) {
    fwrite(STDERR, "A valid MySQL port is required for transaction record tests.\n");
    exit(1);
}

$conn = new mysqli($host, $username, $password, $database, $port);
$conn->set_charset('utf8mb4');

$temporarySchemas = [
    "CREATE TEMPORARY TABLE users (
        userID INT NOT NULL PRIMARY KEY,
        role VARCHAR(40) NOT NULL,
        accountStatus VARCHAR(20) NOT NULL,
        fullname VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE properties (
        propertyID INT NOT NULL PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Available',
        isDeleted TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE appointments (
        appointmentID INT NOT NULL PRIMARY KEY,
        clientID INT NOT NULL,
        propertyID INT NOT NULL,
        agentID INT DEFAULT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'Pending',
        appointmentDate DATE NOT NULL,
        appointmentTime TIME NOT NULL
    ) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE feedbackinquiries (
        inquiryID INT NOT NULL PRIMARY KEY,
        clientID INT NOT NULL,
        propertyID INT NOT NULL
    ) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE agent_inquiry_assignments (
        inquiryID INT NOT NULL PRIMARY KEY,
        agentID INT NOT NULL,
        assignedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE property_transactions (
        transactionID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        propertyID INT NOT NULL,
        clientID INT NOT NULL,
        appointmentID INT DEFAULT NULL,
        inquiryID INT DEFAULT NULL,
        currentStage VARCHAR(40) NOT NULL,
        salePrice DECIMAL(12,2) DEFAULT NULL,
        paymentMethod VARCHAR(50) DEFAULT NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_test_appointment (appointmentID),
        UNIQUE KEY uq_test_inquiry (inquiryID)
    ) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE property_transaction_events (
        eventID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        transactionID BIGINT UNSIGNED NOT NULL,
        stage VARCHAR(40) NOT NULL,
        note VARCHAR(1000) DEFAULT NULL,
        changedBy INT DEFAULT NULL,
        eventKey VARCHAR(60) DEFAULT NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_test_event_key (transactionID, eventKey)
    ) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE property_transaction_payments (
        paymentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        transactionID BIGINT UNSIGNED NOT NULL,
        clientID INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        paymentMethod VARCHAR(50) NOT NULL,
        referenceNumber VARCHAR(100) DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        submittedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewedAt TIMESTAMP NULL DEFAULT NULL,
        reviewedBy INT DEFAULT NULL,
        reviewNote VARCHAR(1000) DEFAULT NULL,
        KEY idx_test_payment_transaction (transactionID, submittedAt)
    ) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE payments (
        paymentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        transactionID BIGINT UNSIGNED DEFAULT NULL,
        clientID INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        paymentType VARCHAR(40) NOT NULL,
        paymentMethod VARCHAR(50) NOT NULL,
        referenceNo VARCHAR(100) DEFAULT NULL,
        status VARCHAR(30) NOT NULL,
        paidAt DATE NOT NULL,
        note VARCHAR(1000) DEFAULT NULL,
        recordedBy INT DEFAULT NULL,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",
    "CREATE TEMPORARY TABLE user_notifications (
        notificationID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        userID INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        link VARCHAR(255) DEFAULT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'general',
        isRead TINYINT(1) NOT NULL DEFAULT 0,
        createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB",
];
foreach ($temporarySchemas as $schema) {
    $conn->query($schema);
}

$conn->query(
    "INSERT INTO users (userID, role, accountStatus, fullname, email) VALUES
        (101, 'Client', 'Active', 'Test Client', ''),
        (201, 'Sales Agent', 'Active', 'Test Agent', ''),
        (202, 'Sales Agent', 'Active', 'Other Test Agent', ''),
        (301, 'Admin', 'Active', 'Test Admin', '')"
);
$conn->query(
    "INSERT INTO properties (propertyID, title) VALUES
        (401, 'Inquiry fixture property'),
        (402, 'Viewing fixture property'),
        (403, 'Unassigned inquiry fixture property')"
);
$conn->query(
    "INSERT INTO appointments
        (appointmentID, clientID, propertyID, agentID, status, appointmentDate, appointmentTime)
     VALUES (501, 101, 402, 201, 'Pending', '2026-11-25', '10:00:00')"
);
$conn->query(
    'INSERT INTO feedbackinquiries (inquiryID, clientID, propertyID) VALUES
        (601, 101, 401),
        (602, 101, 403)'
);

create_property_transaction($conn, 401, 101, 'inquiry', 601, 301);
$inquiryTransactionResult = $conn->query(
    'SELECT transactionID FROM property_transactions WHERE inquiryID = 601 LIMIT 1'
);
$inquiryTransactionId = (int) $inquiryTransactionResult->fetch_assoc()['transactionID'];
claim_inquiry_for_agent($conn, 201, 601);

create_property_transaction($conn, 402, 101, 'appointment', 501, 301);
$appointmentTransactionResult = $conn->query(
    'SELECT transactionID FROM property_transactions WHERE appointmentID = 501 LIMIT 1'
);
$appointmentTransactionId = (int) $appointmentTransactionResult->fetch_assoc()['transactionID'];
$activeViewingRequest = get_active_property_viewing_request($conn, 402);
expect_record_test(
    (int) ($activeViewingRequest['appointmentID'] ?? 0) === 501,
    'A pending viewing request must block another active viewing for the same property.'
);
expect_record_test(
    get_active_property_viewing_request($conn, 402, 501) === null,
    'Checking an appointment for its own approval must exclude that same request.'
);
$completedViewingStage = $conn->prepare(
    "UPDATE property_transactions SET currentStage = 'viewing_completed' WHERE transactionID = ?"
);
$completedViewingStage->bind_param('i', $appointmentTransactionId);
$completedViewingStage->execute();
expect_record_test(
    get_active_property_viewing_request($conn, 402) === null,
    'A completed viewing must release the property for a later viewing request.'
);
create_property_transaction($conn, 403, 101, 'inquiry', 602, 301);
$unassignedTransactionResult = $conn->query(
    'SELECT transactionID FROM property_transactions WHERE inquiryID = 602 LIMIT 1'
);
$unassignedTransactionId = (int) $unassignedTransactionResult->fetch_assoc()['transactionID'];

$stageStmt = $conn->prepare(
    "UPDATE property_transactions SET currentStage = 'payment_in_process' WHERE transactionID = ?"
);
$stageStmt->bind_param('i', $inquiryTransactionId);
$stageStmt->execute();

submit_property_transaction_payment(
    $conn,
    $inquiryTransactionId,
    101,
    50000.00,
    'Bank Transfer',
    'TEST-CLIENT-REPORT-001'
);
$duplicatePaymentRejected = false;
try {
    submit_property_transaction_payment(
        $conn,
        $inquiryTransactionId,
        101,
        50000.00,
        'Bank Transfer',
        'TEST-CLIENT-REPORT-001'
    );
} catch (InvalidArgumentException $exception) {
    $duplicatePaymentRejected = true;
}
submit_property_transaction_payment(
    $conn,
    $inquiryTransactionId,
    101,
    12000.00,
    'Cash',
    'TEST-CLIENT-REPORT-REJECTED-002'
);
$paymentIdStmt = $conn->prepare(
    "SELECT paymentID
     FROM property_transaction_payments
     WHERE transactionID = ? AND referenceNumber = ?
     LIMIT 1"
);
$paymentReference = 'TEST-CLIENT-REPORT-001';
$paymentIdStmt->bind_param('is', $inquiryTransactionId, $paymentReference);
$paymentIdStmt->execute();
$paymentId = (int) $paymentIdStmt->get_result()->fetch_assoc()['paymentID'];
review_property_transaction_payment(
    $conn,
    $paymentId,
    'confirmed',
    'Integration-test confirmation',
    201
);
$rejectedPaymentResult = $conn->query(
    "SELECT paymentID
     FROM property_transaction_payments
     WHERE transactionID = $inquiryTransactionId
       AND referenceNumber = 'TEST-CLIENT-REPORT-REJECTED-002'
     LIMIT 1"
);
$rejectedPaymentId = (int) $rejectedPaymentResult->fetch_assoc()['paymentID'];
review_property_transaction_payment(
    $conn,
    $rejectedPaymentId,
    'rejected',
    'Integration-test rejection',
    201
);

$legacyPayment = $conn->prepare(
    "INSERT INTO payments
        (transactionID, clientID, amount, paymentType, paymentMethod, referenceNo, status, paidAt, note, recordedBy)
     VALUES (?, 101, 25000.00, 'Reservation', 'Cash', 'TEST-ADMIN-RECORD-001', 'Verified', '2026-11-20', 'Integration fixture', 301)"
);
$legacyPayment->bind_param('i', $appointmentTransactionId);
$legacyPayment->execute();

$clientTransactionsStmt = $conn->prepare(
    'SELECT transactionID FROM property_transactions WHERE clientID = ? ORDER BY transactionID'
);
$clientId = 101;
$clientTransactionsStmt->bind_param('i', $clientId);
$clientTransactionsStmt->execute();
$clientTransactions = $clientTransactionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$agentTransactionsStmt = $conn->prepare(
    'SELECT t.transactionID
     FROM property_transactions t
     LEFT JOIN appointments a ON a.appointmentID = t.appointmentID
     LEFT JOIN agent_inquiry_assignments ia ON ia.inquiryID = t.inquiryID
     WHERE a.agentID = ? OR ia.agentID = ?
     ORDER BY t.transactionID'
);
$agentId = 201;
$agentTransactionsStmt->bind_param('ii', $agentId, $agentId);
$agentTransactionsStmt->execute();
$agentTransactions = $agentTransactionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$adminTransactions = $conn->query('SELECT transactionID FROM property_transactions ORDER BY transactionID')->fetch_all(MYSQLI_ASSOC);

expect_record_test(count($clientTransactions) === 3, 'The Client view must find assigned and unassigned transaction fixtures.');
expect_record_test(count($agentTransactions) === 2, 'The assigned Agent view must find linked inquiry and viewing fixtures.');
expect_record_test(count($adminTransactions) === 3, 'The Admin view must find assigned and unassigned transaction fixtures.');

claim_inquiry_for_agent($conn, 201, 602);
$agentTransactionsStmt->execute();
$agentTransactionsAfterClaim = $agentTransactionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
expect_record_test(
    count($agentTransactionsAfterClaim) === 3
        && (int) $agentTransactionsAfterClaim[2]['transactionID'] === $unassignedTransactionId,
    'Claiming an inquiry must make its transaction visible in the Agent view.'
);
$otherAgentCannotClaim = false;
try {
    claim_inquiry_for_agent($conn, 202, 601);
} catch (InvalidArgumentException $exception) {
    $otherAgentCannotClaim = true;
}
expect_record_test($otherAgentCannotClaim, 'An Agent must not take an inquiry already assigned to another Agent.');

$conn->query("INSERT INTO properties (propertyID, title) VALUES (405, 'Duplicate approval fixture property')");
$conn->query(
    "INSERT INTO appointments
        (appointmentID, clientID, propertyID, status, appointmentDate, appointmentTime)
     VALUES
        (505, 101, 405, 'Pending', '2026-11-27', '10:00:00'),
        (506, 101, 405, 'Pending', '2026-11-28', '10:00:00')"
);
create_property_transaction($conn, 405, 101, 'appointment', 505, 101);
create_property_transaction($conn, 405, 101, 'appointment', 506, 101);
$duplicateViewingApprovalRejected = false;
try {
    update_appointment_tracking_status($conn, 505, 'Approved', 201);
} catch (InvalidArgumentException $exception) {
    $duplicateViewingApprovalRejected = true;
}
$duplicateViewingTransactionRejected = false;
$secondViewingTransaction = $conn->query(
    'SELECT transactionID FROM property_transactions WHERE appointmentID = 506'
)->fetch_assoc();
try {
    advance_property_transaction(
        $conn,
        (int) $secondViewingTransaction['transactionID'],
        'viewing_confirmed',
        'Integration-test duplicate approval',
        301
    );
} catch (InvalidArgumentException $exception) {
    $duplicateViewingTransactionRejected = true;
}
expect_record_test(
    $duplicateViewingApprovalRejected && $duplicateViewingTransactionRejected,
    'Both Agent dashboard approval and transaction-pipeline approval must reject a second active viewing for the same property.'
);

$paymentRecords = get_transaction_payment_records(
    $conn,
    [$inquiryTransactionId, $appointmentTransactionId, $unassignedTransactionId]
);
expect_record_test(
    count($paymentRecords[$inquiryTransactionId] ?? []) === 2
        && in_array('confirmed', array_column($paymentRecords[$inquiryTransactionId], 'status'), true)
        && in_array('rejected', array_column($paymentRecords[$inquiryTransactionId], 'status'), true)
        && count(array_unique(array_column($paymentRecords[$inquiryTransactionId], 'sourceLabel'))) === 1
        && $paymentRecords[$inquiryTransactionId][0]['sourceLabel'] === 'Client report',
    'Confirmed and rejected Client reports must appear in the shared transaction payment records.'
);
expect_record_test(
    count($paymentRecords[$appointmentTransactionId] ?? []) === 1
        && $paymentRecords[$appointmentTransactionId][0]['sourceLabel'] === 'Staff record'
        && $paymentRecords[$appointmentTransactionId][0]['status'] === 'confirmed',
    'An Admin-register payment must appear in the shared transaction payment records.'
);
expect_record_test(
    get_verified_transaction_payment_total($conn, $inquiryTransactionId) === 50000.0
        && get_verified_transaction_payment_total($conn, $appointmentTransactionId) === 25000.0,
    'Verified collection totals must include both payment ledgers.'
);

$paymentStatusStmt = $conn->prepare(
    'SELECT status, reviewedBy, reviewNote
     FROM property_transaction_payments
     WHERE paymentID = ?'
);
$paymentStatusStmt->bind_param('i', $paymentId);
$paymentStatusStmt->execute();
$reviewedPayment = $paymentStatusStmt->get_result()->fetch_assoc();
$eventStmt = $conn->prepare(
    'SELECT COUNT(*) AS eventCount
     FROM property_transaction_events
     WHERE transactionID = ?'
);
$eventStmt->bind_param('i', $inquiryTransactionId);
$eventStmt->execute();
$eventCount = (int) $eventStmt->get_result()->fetch_assoc()['eventCount'];

expect_record_test(
    $reviewedPayment['status'] === 'confirmed'
        && (int) $reviewedPayment['reviewedBy'] === $agentId
        && $reviewedPayment['reviewNote'] === 'Integration-test confirmation',
    'Payment confirmation must persist the result, reviewer, and note.'
);
expect_record_test($duplicatePaymentRejected, 'The same Client payment reference must not be recorded twice.');
expect_record_test($eventCount === 5, 'Inquiry creation and both payment submissions/reviews must be recorded in the timeline.');
expect_record_test(
    (int) $conn->query('SELECT COUNT(*) AS total FROM user_notifications')->fetch_assoc()['total'] === 2,
    'Confirmed and rejected payments must each create a Client notification in the test fixture.'
);

$conn->query("INSERT INTO properties (propertyID, title) VALUES (404, 'Sale lock fixture property')");
$conn->query(
    "INSERT INTO appointments
        (appointmentID, clientID, propertyID, status, appointmentDate, appointmentTime)
     VALUES (502, 101, 404, 'Pending', '2026-11-26', '11:00:00')"
);
$conn->query(
    "INSERT INTO property_transactions
        (transactionID, propertyID, clientID, appointmentID, currentStage, paymentMethod)
     VALUES
        (9001, 404, 101, NULL, 'payment_confirmation', 'Spot Cash'),
        (9002, 404, 101, 502, 'payment_confirmation', 'Spot Cash')"
);
$conn->query(
    "INSERT INTO property_transaction_payments
        (transactionID, clientID, amount, paymentMethod, referenceNumber, status, reviewedAt, reviewedBy)
     VALUES
        (9001, 101, 5000.00, 'Spot Cash', 'TEST-CLOSE-001', 'confirmed', CURRENT_TIMESTAMP, 301),
        (9002, 101, 5000.00, 'Spot Cash', 'TEST-CLOSE-002', 'confirmed', CURRENT_TIMESTAMP, 301)"
);
advance_property_transaction($conn, 9001, 'completed', 'Integration-test turnover', 301, 5000.00);
$soldProperty = $conn->query('SELECT status FROM properties WHERE propertyID = 404')->fetch_assoc();
$competingSaleRejected = false;
try {
    advance_property_transaction($conn, 9002, 'completed', 'Competing integration-test turnover', 301, 5000.00);
} catch (InvalidArgumentException $exception) {
    $competingSaleRejected = true;
}
$lockedSaleStage = $conn->query('SELECT currentStage FROM property_transactions WHERE transactionID = 9002')->fetch_assoc();
$competingAppointment = $conn->query('SELECT status FROM appointments WHERE appointmentID = 502')->fetch_assoc();
expect_record_test(
    $soldProperty['status'] === 'Sold'
        && $competingSaleRejected
        && $lockedSaleStage['currentStage'] === 'cancelled'
        && $competingAppointment['status'] === 'Cancelled',
    'Completing one sale must mark the property Sold, close competing transactions and viewings, and reject later completion.'
);

$conn->close();
fwrite(STDOUT, "Transaction record integration checks passed.\n");
