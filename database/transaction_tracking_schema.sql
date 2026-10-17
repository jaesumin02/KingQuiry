-- Additive transaction-tracking migration for an existing KingQuiry database.
-- Back up the database first. Safe to re-run: source rows and their initial
-- tracking events are protected by unique source/event keys.

CREATE TABLE IF NOT EXISTS property_transactions (
    transactionID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    propertyID INT NOT NULL,
    clientID INT NOT NULL,
    appointmentID INT DEFAULT NULL,
    inquiryID INT DEFAULT NULL,
    currentStage VARCHAR(40) NOT NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (transactionID),
    UNIQUE KEY uq_property_transaction_appointment (appointmentID),
    UNIQUE KEY uq_property_transaction_inquiry (inquiryID),
    KEY idx_property_transactions_client (clientID, updatedAt),
    KEY idx_property_transactions_stage (currentStage, updatedAt),
    CONSTRAINT fk_property_transactions_property
        FOREIGN KEY (propertyID) REFERENCES properties (propertyID) ON DELETE CASCADE,
    CONSTRAINT fk_property_transactions_client
        FOREIGN KEY (clientID) REFERENCES users (userID) ON DELETE CASCADE,
    CONSTRAINT fk_property_transactions_appointment
        FOREIGN KEY (appointmentID) REFERENCES appointments (appointmentID) ON DELETE CASCADE,
    CONSTRAINT fk_property_transactions_inquiry
        FOREIGN KEY (inquiryID) REFERENCES feedbackinquiries (inquiryID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_transaction_events (
    eventID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transactionID BIGINT UNSIGNED NOT NULL,
    stage VARCHAR(40) NOT NULL,
    note VARCHAR(1000) DEFAULT NULL,
    changedBy INT DEFAULT NULL,
    eventKey VARCHAR(60) DEFAULT NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (eventID),
    UNIQUE KEY uq_property_transaction_event_key (transactionID, eventKey),
    KEY idx_property_transaction_events_timeline (transactionID, createdAt, eventID),
    CONSTRAINT fk_property_transaction_events_transaction
        FOREIGN KEY (transactionID) REFERENCES property_transactions (transactionID) ON DELETE CASCADE,
    CONSTRAINT fk_property_transaction_events_user
        FOREIGN KEY (changedBy) REFERENCES users (userID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sale_price_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'property_transactions'
      AND COLUMN_NAME = 'salePrice'
);
SET @sale_price_column_ddl = IF(
    @sale_price_column_exists = 0,
    'ALTER TABLE property_transactions ADD COLUMN salePrice DECIMAL(12,2) DEFAULT NULL AFTER currentStage',
    'SELECT 1'
);
PREPARE sale_price_column_statement FROM @sale_price_column_ddl;
EXECUTE sale_price_column_statement;
DEALLOCATE PREPARE sale_price_column_statement;

INSERT IGNORE INTO property_transactions
    (propertyID, clientID, appointmentID, currentStage, createdAt)
SELECT propertyID, clientID, appointmentID,
       CASE status
           WHEN 'Approved' THEN 'viewing_confirmed'
           WHEN 'Cancelled' THEN 'cancelled'
           ELSE 'viewing_requested'
       END,
       createdAt
FROM appointments
WHERE propertyID IS NOT NULL AND clientID IS NOT NULL;

UPDATE property_transactions t
JOIN feedbackinquiries i
    ON i.propertyID = t.propertyID
   AND i.clientID = t.clientID
SET t.inquiryID = i.inquiryID
WHERE t.appointmentID IS NOT NULL
  AND t.inquiryID IS NULL
  AND t.currentStage NOT IN ('completed', 'cancelled')
  AND NOT EXISTS (
      SELECT 1
      FROM property_transactions existing
      WHERE existing.inquiryID = i.inquiryID
  );

INSERT IGNORE INTO property_transactions
    (propertyID, clientID, inquiryID, currentStage, createdAt)
SELECT propertyID, clientID, inquiryID, 'inquiry_received', createdAt
FROM feedbackinquiries
WHERE propertyID IS NOT NULL
  AND clientID IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM property_transactions existing
      WHERE existing.inquiryID = feedbackinquiries.inquiryID
  );

INSERT IGNORE INTO property_transaction_events
    (transactionID, stage, note, eventKey, createdAt)
SELECT t.transactionID, t.currentStage,
       'Tracking was enabled for this existing record; earlier step-by-step history is unavailable.',
       'initial',
       CURRENT_TIMESTAMP
FROM property_transactions t
WHERE t.appointmentID IS NOT NULL OR t.inquiryID IS NOT NULL;
