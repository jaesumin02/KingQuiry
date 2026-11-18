-- Additive agent-inquiry assignment migration for an existing KingQuiry database.
-- Back up the database before applying schema changes.

SET @agent_id_column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'appointments'
      AND COLUMN_NAME = 'agentID'
);
SET @agent_id_column_ddl = IF(
    @agent_id_column_exists = 0,
    'ALTER TABLE appointments ADD COLUMN agentID INT DEFAULT NULL AFTER clientID, ADD KEY idx_appointments_agent (agentID)',
    'SELECT 1'
);
PREPARE agent_id_column_statement FROM @agent_id_column_ddl;
EXECUTE agent_id_column_statement;
DEALLOCATE PREPARE agent_id_column_statement;

CREATE TABLE IF NOT EXISTS agent_dismissed_appointments (
    agentID INT NOT NULL,
    appointmentID INT NOT NULL,
    dismissedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (agentID, appointmentID),
    KEY idx_dismissed_appointment (appointmentID),
    CONSTRAINT fk_dismissed_agent
        FOREIGN KEY (agentID) REFERENCES users (userID) ON DELETE CASCADE,
    CONSTRAINT fk_dismissed_appointment
        FOREIGN KEY (appointmentID) REFERENCES appointments (appointmentID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_inquiry_assignments (
    inquiryID INT NOT NULL,
    agentID INT NOT NULL,
    assignedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (inquiryID),
    KEY idx_agent_inquiry_assignments_agent (agentID, assignedAt),
    CONSTRAINT fk_agent_inquiry_assignments_inquiry
        FOREIGN KEY (inquiryID) REFERENCES feedbackinquiries (inquiryID) ON DELETE CASCADE,
    CONSTRAINT fk_agent_inquiry_assignments_agent
        FOREIGN KEY (agentID) REFERENCES users (userID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
