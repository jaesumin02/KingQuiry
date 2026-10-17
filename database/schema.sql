-- Fresh-install schema for KingQuiry.
-- Select/create the destination database before importing this file.
-- This file contains table definitions and the standard Batangas location
-- directory only; it intentionally contains no accounts or live records.

CREATE TABLE IF NOT EXISTS users (
    userID INT NOT NULL AUTO_INCREMENT,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    passwordHash VARCHAR(255) NOT NULL,
    role ENUM('Client', 'Agent', 'Admin') DEFAULT 'Client',
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (userID),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS property_locations (
    locationID INT UNSIGNED NOT NULL AUTO_INCREMENT,
    regionCode VARCHAR(10) NOT NULL,
    regionName VARCHAR(120) NOT NULL,
    provinceName VARCHAR(120) NOT NULL,
    municipalityName VARCHAR(120) NOT NULL,
    isActive TINYINT(1) NOT NULL DEFAULT 1,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (locationID),
    UNIQUE KEY uq_property_location (regionCode, provinceName, municipalityName),
    KEY idx_property_location_active (isActive, regionCode, provinceName, municipalityName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS properties (
    propertyID INT NOT NULL AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(12,2) NOT NULL,
    pricePerSqm DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    location VARCHAR(200) NOT NULL,
    locationID INT UNSIGNED DEFAULT NULL,
    barangay VARCHAR(120) DEFAULT NULL,
    areaSqm DECIMAL(12,2) DEFAULT NULL,
    propertyType VARCHAR(50) NOT NULL,
    landCharacteristics TEXT DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'Available',
    image VARCHAR(255) DEFAULT 'default.jpg',
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (propertyID),
    KEY idx_properties_location (locationID),
    CONSTRAINT fk_properties_location
        FOREIGN KEY (locationID)
        REFERENCES property_locations (locationID)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS appointments (
    appointmentID INT NOT NULL AUTO_INCREMENT,
    propertyID INT DEFAULT NULL,
    clientID INT DEFAULT NULL,
    appointmentDate DATE NOT NULL,
    appointmentTime TIME NOT NULL,
    status ENUM('Pending', 'Approved', 'Cancelled') DEFAULT 'Pending',
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (appointmentID),
    KEY idx_appointments_property (propertyID),
    KEY idx_appointments_client (clientID),
    KEY idx_appointments_status_date (status, appointmentDate),
    CONSTRAINT fk_appointments_property
        FOREIGN KEY (propertyID)
        REFERENCES properties (propertyID)
        ON DELETE CASCADE,
    CONSTRAINT fk_appointments_client
        FOREIGN KEY (clientID)
        REFERENCES users (userID)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS feedbackinquiries (
    inquiryID INT NOT NULL AUTO_INCREMENT,
    propertyID INT DEFAULT NULL,
    clientID INT DEFAULT NULL,
    message TEXT NOT NULL,
    status ENUM('Unread', 'Read', 'Replied') DEFAULT 'Unread',
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (inquiryID),
    KEY idx_feedbackinquiries_property (propertyID),
    KEY idx_feedbackinquiries_client (clientID),
    KEY idx_feedbackinquiries_status_created (status, createdAt),
    CONSTRAINT fk_feedbackinquiries_property
        FOREIGN KEY (propertyID)
        REFERENCES properties (propertyID)
        ON DELETE CASCADE,
    CONSTRAINT fk_feedbackinquiries_client
        FOREIGN KEY (clientID)
        REFERENCES users (userID)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS auditlogs (
    logID INT NOT NULL AUTO_INCREMENT,
    userID INT DEFAULT NULL,
    action TEXT NOT NULL,
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (logID),
    KEY idx_auditlogs_user (userID),
    CONSTRAINT fk_auditlogs_user
        FOREIGN KEY (userID)
        REFERENCES users (userID)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS chat_conversations (
    conversation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_key CHAR(64) NOT NULL,
    user_id INT DEFAULT NULL,
    status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (conversation_id),
    KEY idx_chat_conversations_session_status (session_key, status),
    KEY idx_chat_conversations_updated (updated_at),
    KEY idx_chat_conversations_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    message_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender ENUM('client', 'admin', 'bot') NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id),
    KEY idx_chat_messages_conversation (conversation_id, message_id),
    CONSTRAINT fk_chat_messages_conversation
        FOREIGN KEY (conversation_id)
        REFERENCES chat_conversations (conversation_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_admin_presence (
    admin_id INT NOT NULL,
    last_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_transactions (
    transactionID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    propertyID INT NOT NULL,
    clientID INT NOT NULL,
    appointmentID INT DEFAULT NULL,
    inquiryID INT DEFAULT NULL,
    currentStage VARCHAR(40) NOT NULL,
    salePrice DECIMAL(12,2) DEFAULT NULL,
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

INSERT INTO property_locations (regionCode, regionName, provinceName, municipalityName) VALUES
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Agoncillo'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Alitagtag'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Balayan'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Balete'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Batangas City'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Bauan'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Calaca City'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Calatagan'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Cuenca'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Ibaan'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Laurel'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Lemery'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Lian'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Lipa City'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Lobo'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Mabini'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Malvar'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Mataasnakahoy'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Nasugbu'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Padre Garcia'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Rosario'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'San Jose'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'San Juan'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'San Luis'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'San Nicolas'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'San Pascual'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Santa Teresita'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Santo Tomas City'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Taal'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Talisay'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Tanauan City'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Taysan'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Tingloy'),
('PH-40', 'CALABARZON (Region IV-A)', 'Batangas', 'Tuy')
ON DUPLICATE KEY UPDATE
    regionName = VALUES(regionName),
    isActive = 1;
