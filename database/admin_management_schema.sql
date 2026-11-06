-- Additive client-management and payments migration for an existing KingQuiry
-- database. Back up the database first. Safe to re-run: columns are added only
-- when missing and the payments table is created only when absent.

CREATE TABLE IF NOT EXISTS payments (
    paymentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transactionID BIGINT UNSIGNED DEFAULT NULL,
    clientID INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paymentType ENUM('Reservation', 'Down Payment', 'Installment', 'Full Payment', 'Other')
        NOT NULL DEFAULT 'Other',
    paymentMethod ENUM('Cash', 'Bank Transfer', 'GCash', 'Check', 'Other')
        NOT NULL DEFAULT 'Cash',
    referenceNo VARCHAR(100) DEFAULT NULL,
    status ENUM('Pending', 'Verified', 'Rejected') NOT NULL DEFAULT 'Pending',
    paidAt DATE NOT NULL,
    note VARCHAR(1000) DEFAULT NULL,
    recordedBy INT DEFAULT NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (paymentID),
    KEY idx_payments_transaction (transactionID),
    KEY idx_payments_client (clientID, paidAt),
    KEY idx_payments_status_date (status, paidAt),
    CONSTRAINT fk_payments_transaction
        FOREIGN KEY (transactionID) REFERENCES property_transactions (transactionID) ON DELETE SET NULL,
    CONSTRAINT fk_payments_client
        FOREIGN KEY (clientID) REFERENCES users (userID) ON DELETE CASCADE,
    CONSTRAINT fk_payments_recorded_by
        FOREIGN KEY (recordedBy) REFERENCES users (userID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @phone_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'phone'
);
SET @phone_column_ddl = IF(
    @phone_column_exists = 0,
    'ALTER TABLE users ADD COLUMN phone VARCHAR(30) DEFAULT NULL AFTER email',
    'SELECT 1'
);
PREPARE phone_column_statement FROM @phone_column_ddl;
EXECUTE phone_column_statement;
DEALLOCATE PREPARE phone_column_statement;

SET @address_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'address'
);
SET @address_column_ddl = IF(
    @address_column_exists = 0,
    'ALTER TABLE users ADD COLUMN address VARCHAR(255) DEFAULT NULL AFTER phone',
    'SELECT 1'
);
PREPARE address_column_statement FROM @address_column_ddl;
EXECUTE address_column_statement;
DEALLOCATE PREPARE address_column_statement;

SET @account_status_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'accountStatus'
);
SET @account_status_column_ddl = IF(
    @account_status_column_exists = 0,
    "ALTER TABLE users ADD COLUMN accountStatus ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active' AFTER role",
    'SELECT 1'
);
PREPARE account_status_column_statement FROM @account_status_column_ddl;
EXECUTE account_status_column_statement;
DEALLOCATE PREPARE account_status_column_statement;
