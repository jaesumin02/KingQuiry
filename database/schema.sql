-- Fresh-install schema for KingQuiry.
-- Automatically creates and selects the target database if not already selected.
-- Import only into an empty or disposable database. The demo-data section at
-- the end resets seeded application records before loading its sample dataset.

CREATE DATABASE IF NOT EXISTS `kingquiry_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `kingquiry_db`;

-- This file contains the application tables, Batangas location directory,
-- and a complete local demonstration dataset. Listing photos marked
-- representative may not depict the exact property.

DROP TABLE IF EXISTS `agent_reviews`;

CREATE TABLE IF NOT EXISTS users (
    userID INT NOT NULL AUTO_INCREMENT,
    fullname VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    passwordHash VARCHAR(255) NOT NULL,
    role ENUM('Client', 'Agent', 'Admin') DEFAULT 'Client',
    accountStatus ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
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
    isDeleted TINYINT(1) NOT NULL DEFAULT 0,
    deletedAt TIMESTAMP NULL DEFAULT NULL,
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
    latitude DECIMAL(10, 7) DEFAULT NULL,
    longitude DECIMAL(10, 7) DEFAULT NULL,
    barangay VARCHAR(120) DEFAULT NULL,
    areaSqm DECIMAL(12,2) DEFAULT NULL,
    propertyType VARCHAR(50) NOT NULL,
    landCharacteristics TEXT DEFAULT NULL,
    status VARCHAR(50) DEFAULT 'Available',
    image VARCHAR(255) DEFAULT 'default.jpg',
    isDeleted TINYINT(1) NOT NULL DEFAULT 0,
    deletedAt TIMESTAMP NULL DEFAULT NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (propertyID),
    KEY idx_properties_location (locationID),
    CONSTRAINT fk_properties_location
        FOREIGN KEY (locationID)
        REFERENCES property_locations (locationID)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS property_images (
    imageID INT NOT NULL AUTO_INCREMENT,
    propertyID INT NOT NULL,
    imagePath VARCHAR(255) NOT NULL,
    caption VARCHAR(150) DEFAULT NULL,
    sortOrder INT NOT NULL DEFAULT 0,
    isPrimary TINYINT(1) NOT NULL DEFAULT 0,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (imageID),
    KEY idx_property_images_property (propertyID),
    CONSTRAINT fk_property_images_property
        FOREIGN KEY (propertyID)
        REFERENCES properties (propertyID)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS appointments (
    appointmentID INT NOT NULL AUTO_INCREMENT,
    propertyID INT DEFAULT NULL,
    clientID INT DEFAULT NULL,
    agentID INT DEFAULT NULL,
    appointmentDate DATE NOT NULL,
    appointmentTime TIME NOT NULL,
    status ENUM('Pending', 'Approved', 'Cancelled') DEFAULT 'Pending',
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (appointmentID),
    KEY idx_appointments_property (propertyID),
    KEY idx_appointments_client (clientID),
    KEY idx_appointments_agent (agentID),
    KEY idx_appointments_status_date (status, appointmentDate),
    CONSTRAINT fk_appointments_property
        FOREIGN KEY (propertyID)
        REFERENCES properties (propertyID)
        ON DELETE CASCADE,
    CONSTRAINT fk_appointments_client
        FOREIGN KEY (clientID)
        REFERENCES users (userID)
        ON DELETE CASCADE,
    CONSTRAINT fk_appointments_agent
        FOREIGN KEY (agentID)
        REFERENCES users (userID)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
    assignedAgentID INT DEFAULT NULL,
    currentStage VARCHAR(40) NOT NULL,
    salePrice DECIMAL(12,2) DEFAULT NULL,
    paymentMethod VARCHAR(50) DEFAULT NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (transactionID),
    UNIQUE KEY uq_property_transaction_appointment (appointmentID),
    UNIQUE KEY uq_property_transaction_inquiry (inquiryID),
    KEY idx_property_transactions_agent (assignedAgentID, updatedAt),
    KEY idx_property_transactions_client (clientID, updatedAt),
    KEY idx_property_transactions_stage (currentStage, updatedAt),
    CONSTRAINT fk_property_transactions_property
        FOREIGN KEY (propertyID) REFERENCES properties (propertyID) ON DELETE CASCADE,
    CONSTRAINT fk_property_transactions_client
        FOREIGN KEY (clientID) REFERENCES users (userID) ON DELETE CASCADE,
    CONSTRAINT fk_property_transactions_appointment
        FOREIGN KEY (appointmentID) REFERENCES appointments (appointmentID) ON DELETE CASCADE,
    CONSTRAINT fk_property_transactions_inquiry
        FOREIGN KEY (inquiryID) REFERENCES feedbackinquiries (inquiryID) ON DELETE CASCADE,
    CONSTRAINT fk_property_transactions_agent
        FOREIGN KEY (assignedAgentID) REFERENCES users (userID) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS direct_messages (
    messageID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transactionID BIGINT UNSIGNED NOT NULL,
    senderID INT NOT NULL,
    receiverID INT DEFAULT NULL,
    message TEXT NOT NULL,
    isRead TINYINT(1) NOT NULL DEFAULT 0,
    isAutomated TINYINT(1) NOT NULL DEFAULT 0,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (messageID),
    KEY idx_dm_transaction (transactionID, createdAt),
    KEY idx_dm_users (senderID, receiverID),
    CONSTRAINT fk_dm_transaction
        FOREIGN KEY (transactionID) REFERENCES property_transactions (transactionID) ON DELETE CASCADE,
    CONSTRAINT fk_dm_sender
        FOREIGN KEY (senderID) REFERENCES users (userID) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_transaction_payments (
    paymentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    PRIMARY KEY (paymentID),
    KEY idx_transaction_payments_transaction (transactionID, submittedAt),
    KEY idx_transaction_payments_status (status, submittedAt),
    CONSTRAINT fk_transaction_payments_transaction
        FOREIGN KEY (transactionID) REFERENCES property_transactions (transactionID) ON DELETE CASCADE,
    CONSTRAINT fk_transaction_payments_client
        FOREIGN KEY (clientID) REFERENCES users (userID) ON DELETE CASCADE,
    CONSTRAINT fk_transaction_payments_reviewer
        FOREIGN KEY (reviewedBy) REFERENCES users (userID) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_notifications (
    notificationID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    userID INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) DEFAULT NULL,
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    isRead TINYINT(1) NOT NULL DEFAULT 0,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notificationID),
    KEY idx_user_notifications_user_read (userID, isRead, createdAt),
    KEY idx_user_notifications_created (createdAt),
    CONSTRAINT fk_user_notifications_user
        FOREIGN KEY (userID)
        REFERENCES users (userID)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    paymentID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transactionID BIGINT UNSIGNED DEFAULT NULL,
    clientID INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    paymentType ENUM('Reservation', 'Down Payment', 'Installment', 'Full Payment', 'Other')
        NOT NULL DEFAULT 'Other',
    paymentMethod ENUM('Cash', 'Bank Transfer', 'Check', 'Other')
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

CREATE TABLE IF NOT EXISTS security_rate_limits (
    key_hash BINARY(32) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (key_hash),
    KEY idx_security_rate_limits_expiry (expires_at)
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


-- ══════════════════════════════════════════════════════════════════════
-- KingQuiry 2-Year Comprehensive Real Estate Historical & Active Dataset
-- Spanning: December 2024 to November 2026
-- Includes: Users (Admin/Agents/Clients), Properties, Galleries,
--           Appointments, Inquiries, 2 Years of Completed Transactions,
--           Active Pipeline Deals, Transaction Events, and Payment Records.
-- ══════════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Clear existing operational data
TRUNCATE TABLE `property_images`;
TRUNCATE TABLE `payments`;
TRUNCATE TABLE `property_transaction_payments`;
TRUNCATE TABLE `property_transaction_events`;
TRUNCATE TABLE `property_transactions`;
TRUNCATE TABLE `appointments`;
TRUNCATE TABLE `agent_dismissed_appointments`;
TRUNCATE TABLE `feedbackinquiries`;
TRUNCATE TABLE `agent_inquiry_assignments`;
TRUNCATE TABLE `direct_messages`;
TRUNCATE TABLE `chat_messages`;
TRUNCATE TABLE `chat_conversations`;
TRUNCATE TABLE `user_notifications`;
TRUNCATE TABLE `properties`;
TRUNCATE TABLE `users`;

-- 2. Users: 1 Admin, 3 Licensed Sales Agents, 12 Filipino Buyers/Clients
INSERT INTO `users` (`userID`, `fullname`, `email`, `phone`, `address`, `passwordHash`, `role`, `accountStatus`, `createdAt`) VALUES
(1, 'Kings Administrator', 'admin@kingquiry.ph', '09171234567', 'Batangas City, Batangas', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Admin', 'Active', '2024-12-01 08:00:00'),
(2, 'Juan Dela Cruz', 'juan.agent@kingquiry.ph', '09181112233', 'Lipa City, Batangas', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Agent', 'Active', '2024-12-01 08:30:00'),
(3, 'Maria Santos', 'maria.agent@kingquiry.ph', '09182223344', 'Batangas City, Batangas', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Agent', 'Active', '2024-12-01 08:30:00'),
(4, 'Carlos Reyes', 'carlos.agent@kingquiry.ph', '09183334455', 'Tanauan City, Batangas', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Agent', 'Active', '2024-12-01 08:30:00'),
(5, 'Roberto Tan', 'roberto.tan@gmail.com', '09201110001', 'Alabang, Muntinlupa City', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2024-12-15 09:00:00'),
(6, 'Elena Villanueva', 'elena.villanueva@yahoo.com', '09201110002', 'Quezon City, Metro Manila', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2025-01-10 10:30:00'),
(7, 'Mark Bautista', 'mark.bautista@gmail.com', '09201110003', 'Taguig City, BGC', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2025-02-14 14:15:00'),
(8, 'Grace Lim', 'grace.lim@outlook.com', '09201110004', 'Makati City, Metro Manila', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2025-03-20 11:00:00'),
(9, 'Ricardo Mendoza', 'ricardo.mendoza@gmail.com', '09201110005', 'Lipa City, Batangas', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2025-04-18 16:45:00'),
(10, 'Danilo Garcia', 'danilo.garcia@gmail.com', '09201110006', 'Pasig City, Metro Manila', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2025-05-12 09:30:00'),
(11, 'Patricia Aquino', 'patricia.aquino@yahoo.com', '09201110007', 'Batangas City, Batangas', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2025-06-25 13:20:00'),
(12, 'Antonio Cruz', 'antonio.cruz@gmail.com', '09201110008', 'Mandaluyong City', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2025-07-30 15:10:00'),
(13, 'Lourdes Ramos', 'lourdes.ramos@hotmail.com', '09201110009', 'San Juan, Metro Manila', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2025-09-05 10:00:00'),
(14, 'Ferdinand Dizon', 'ferdinand.dizon@gmail.com', '09201110010', 'Santa Rosa, Laguna', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2025-10-14 11:30:00'),
(15, 'Carmina Soriano', 'carmina.soriano@gmail.com', '09201110011', 'Calamba, Laguna', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2026-01-08 14:00:00'),
(16, 'Benjamin Ilagan', 'benjamin.ilagan@gmail.com', '09201110012', 'Taal, Batangas', '$2y$10$5UfyMtDSYy5JctaCNDNOT.xX9GFhm0UMHJsBDCBqG21Y7UJu534OW', 'Client', 'Active', '2026-02-18 09:45:00');

-- 3. Properties: 24 complete demo listings across Batangas Municipalities
INSERT INTO `properties` (`propertyID`, `title`, `description`, `price`, `pricePerSqm`, `location`, `locationID`, `latitude`, `longitude`, `barangay`, `areaSqm`, `propertyType`, `landCharacteristics`, `status`, `image`, `createdAt`) VALUES
(1, 'Prime Residential Corner Lot in Lipa City', 'Exclusive subdivision corner residential lot with complete underground drainage, wide frontage, and 24/7 security.', 1800000.0, 7200.0, 'Lipa City, Batangas', 14, 13.9419, 121.1631, 'Marawoy', 250.0, 'Residential', 'Corner lot, flat terrain, concrete paved roads, Meralco & water connected.', 'Sold', 'representative-residential-01.jpg', '2024-12-20 10:00:00'),
(2, 'Agro-Farm Lot with Fruit Trees in San Jose', 'Productive agricultural farm lot planted with mature cacao and rambutan trees. Accessible via barangay paved road.', 3200000.0, 1600.0, 'San Jose, Batangas', 22, 13.8821, 121.1042, 'Pinagtung-ulan', 2000.0, 'Farmlot', 'Gentle slope, fertile volcanic loam soil, perimeter fenced with deep well.', 'Sold', 'representative-farm-01.jpg', '2025-01-15 09:30:00'),
(3, 'Executive Studio Condo in Batangas City CBD', 'Modern executive studio condominium with panoramic views of Batangas Bay and first-rate building amenities.', 2900000.0, 96666.67, 'Batangas City, Batangas', 5, 13.7565, 121.0583, 'Pallocan West', 30.0, 'Condo', 'Mid-floor unit with balcony, fully titled, fiber optic internet ready.', 'Sold', 'representative-condo-01.jpg', '2025-02-28 11:00:00'),
(4, 'Commercial Highway Parcel in Santo Tomas', 'High-visibility commercial land along Maharlika Highway, ideal for retail strip, logistics bay, or office showroom.', 6500000.0, 13000.0, 'Santo Tomas City, Batangas', 28, 14.1083, 121.1417, 'San Antonio', 500.0, 'Commercial', 'Prime highway frontage 24 meters, high-traffic commercial corridor.', 'Sold', 'representative-commercial-01.jpg', '2025-04-10 14:00:00'),
(5, 'Two-Storey Modern House and Lot in Tanauan', 'Spacious single-detached house and lot featuring 3 bedrooms, 2 bathrooms, modern kitchen, and 2-car covered garage.', 4200000.0, 28000.0, 'Tanauan City, Batangas', 31, 14.0833, 121.15, 'Darasa', 150.0, 'House and Lot', 'Modern architectural finish, gated community with clubhouse and pool.', 'Sold', 'representative-residential-06.jpg', '2025-06-18 10:15:00'),
(6, 'White Sand Beachside Leisure Lot in Calatagan', 'Prestigious beach parcel located a short walk from clear shoreline waters, perfect for a private beach rest house.', 5800000.0, 7250.0, 'Calatagan, Batangas', 8, 13.8322, 120.6319, 'Santa Ana', 800.0, 'Beachlot', 'Clean coastal breeze, sandy ground, titled land with private right-of-way.', 'Sold', 'representative-beach-01.jpg', '2025-08-05 13:45:00'),
(7, 'Scenic Residential Lot in Batangas City Hills', 'Elevated residential lot offering breezy mountain views and cooler temperatures inside a master-planned village.', 2100000.0, 6000.0, 'Batangas City, Batangas', 5, 13.7745, 121.0712, 'Kumintang Ibaba', 350.0, 'Residential', 'Elevated plateau, no flooding history, underground power cables.', 'Sold', 'representative-residential-02.jpg', '2025-11-20 09:00:00'),
(8, 'Organic Coffee & Agri-Farm Lot in Ibaan', 'Fenced agricultural estate planted with Liberica (Kapeng Barako) coffee trees and mahogany border trees.', 3500000.0, 1166.67, 'Ibaan, Batangas', 10, 13.8189, 121.1325, 'Matala', 3000.0, 'Farmlot', 'Rectangular shape, natural stream at rear border, accessible by truck.', 'Sold', 'representative-farm-02.jpg', '2026-01-10 10:30:00'),
(9, 'Contemporary Townhouse Unit in Bauan', 'Brand new 3-storey contemporary townhouse near coastal industrial hub and commercial establishments.', 3800000.0, 38000.0, 'Bauan, Batangas', 6, 13.7917, 121.0083, 'Poblacion', 100.0, 'Townhouse', 'Reinforced concrete, customized built-in cabinets, CCTV provisions.', 'Sold', 'representative-residential-04.jpg', '2026-03-05 11:20:00'),
(10, 'Commercial Corner Lot along Ayala Highway', 'Premier corner lot facing major intersection with exceptional customer foot-traffic and heavy vehicle flow.', 7200000.0, 16000.0, 'Lipa City, Batangas', 14, 13.955, 121.158, 'Sabang', 450.0, 'Commercial', 'Dual-road frontage, commercial zoning certificate available.', 'Sold', 'representative-commercial-02.jpg', '2026-05-12 15:00:00'),
(11, 'Punta Fuego Coastal Sea-View Estate in Nasugbu', 'Exclusive seaside residential property with panoramic sunset views over the South China Sea.', 6400000.0, 8000.0, 'Nasugbu, Batangas', 19, 14.12, 120.59, 'Balaytigue', 800.0, 'Beachlot', 'Gated luxury resort community, club shares eligibility, cliffside sea view.', 'Sold', 'representative-beach-02.jpg', '2026-07-22 09:15:00'),
(12, 'Highway Residential Lot in Rosario', 'Accessible residential property along municipal secondary highway, close to schools, market, and town hall.', 2600000.0, 5200.0, 'Rosario, Batangas', 21, 13.8464, 121.2056, 'Namunga', 500.0, 'Residential', 'Wide road right-of-way, ready for immediate home construction.', 'Sold', 'representative-residential-03.jpg', '2026-09-02 14:30:00'),
(13, 'Laiya Coastal Eco-Farm Lot in San Juan', 'Dual-purpose farm and leisure lot situated 10 minutes from Laiya beaches. Highly sought-after agri-tourism corridor.', 4500000.0, 1800.0, 'San Juan, Batangas', 23, 13.738, 121.401, 'Laiya Aplaya', 2500.0, 'Farmlot', 'Flat topography, coconut palms, electricity available on site.', 'Reserved', 'representative-farm-03.jpg', '2026-08-10 10:00:00'),
(14, 'Seaside Mountain View Beach Lot in Lobo', 'Tranquil coastal property with stunning natural rocky shoreline and crystal clear snorkeling waters.', 5200000.0, 5200.0, 'Lobo, Batangas', 15, 13.6481, 121.2089, 'Sawang', 1000.0, 'Beachlot', 'Spectacular coastline, fresh ocean breeze, peaceful eco-sanctuary.', 'Reserved', 'representative-beach-03.jpg', '2026-08-25 11:30:00'),
(15, 'Modern 1-Bedroom Executive Condo in Lipa', 'Finished 1-bedroom unit in newly turned-over mid-rise tower with swimming pool, gym, and sky garden.', 3100000.0, 88571.43, 'Lipa City, Batangas', 14, 13.938, 121.16, 'Tambo', 35.0, 'Condo', 'High-floor view, inclusive of kitchen rangehood and smart lock.', 'Reserved', 'representative-condo-02.jpg', '2026-09-10 16:00:00'),
(16, 'Heritage Residential Lot in Taal Town Center', 'Historic town residential parcel near the famous Basilica of Saint Martin. Rich cultural environment.', 1950000.0, 6500.0, 'Taal, Batangas', 29, 13.8794, 120.9239, 'Poblacion 2', 300.0, 'Residential', 'Well-maintained neighborhood, walking distance to heritage district.', 'Available', 'representative-residential-05.jpg', '2026-10-01 10:00:00'),
(17, 'Warehouse & Logistics Lot in Calaca Industrial', 'Heavy commercial/industrial parcel ideal for storage facility, warehouse depot, or contractor yard.', 5800000.0, 4833.33, 'Calaca City, Batangas', 7, 13.9292, 120.8125, 'Salong', 1200.0, 'Commercial', 'Heavy truck bypass road access, 3-phase electric line on street.', 'Available', 'representative-commercial-03.jpg', '2026-10-15 13:00:00'),
(18, 'Hobby Farm Lot in Alitagtag overlooking Lake', 'Gentle hillside farm parcel with distant views of Taal Lake. Perfect for weekend gardening or retreat cabin.', 2400000.0, 1600.0, 'Alitagtag, Batangas', 2, 13.8639, 121.0028, 'Ping-As', 1500.0, 'Farmlot', 'Cool breeze, volcanic soil, clean perimeter markings.', 'Available', 'representative-farm-04.jpg', '2026-10-20 15:30:00'),
(19, 'Livestock & Agro-Farm in Padre Garcia', 'Large agricultural acreage located in the Cattle Capital of the Philippines. Suitable for livestock or farming.', 2800000.0, 1120.0, 'Padre Garcia, Batangas', 20, 13.8767, 121.2133, 'San Miguel', 2500.0, 'Farmlot', 'Water well on site, gently sloping terrain, adjacent to access road.', 'Available', 'representative-farm-05.jpg', '2026-11-01 09:00:00'),
(20, 'Anilao Dive Resort & Coastal Lot in Mabini', 'World-renowned diving destination shoreline property with deep sea drop-off and stunning marine life.', 7500000.0, 12500.0, 'Mabini, Batangas', 16, 13.75, 120.9167, 'San Jose', 600.0, 'Beachlot', 'Prime tourism zone, crystal waters, ideal for boutique diving resort.', 'Available', 'representative-beach-04.jpg', '2026-11-05 10:30:00'),
(21, 'Green Valley Subdivision Lot in Malvar', 'Gated suburban residential lot near STAR Tollway Malvar exit. Fast commuting access to Metro Manila.', 2200000.0, 7333.33, 'Malvar, Batangas', 17, 14.0456, 121.1567, 'Poblacion', 300.0, 'Residential', 'Complete clubhouse amenities, wide concrete roads, landscaped parks.', 'Available', 'representative-residential-07.jpg', '2026-11-10 14:00:00'),
(22, 'Two-Storey Modern Villa in Lemery', 'Newly completed 4-bedroom family home with spacious living areas, balcony, and modern finishes.', 5100000.0, 25500.0, 'Lemery, Batangas', 12, 13.8833, 120.9, 'District 1', 200.0, 'House and Lot', 'Modern architectural design, granite kitchen counters, perimeter fence.', 'Available', 'representative-residential-06.jpg', '2026-11-15 11:00:00'),
(23, 'Commercial Land Parcel in Balayan Town Proper', 'Commercial parcel along provincial thoroughfare, ideal for bank branch, pharmacy, or grocery building.', 6200000.0, 12400.0, 'Balayan, Batangas', 3, 13.9333, 120.7333, 'Caloocan', 500.0, 'Commercial', 'High pedestrian traffic, 18-meter frontage, complete commercial permits.', 'Available', 'representative-commercial-04.jpg', '2026-11-18 13:45:00'),
(24, 'Studio Unit in Batangas City Bayfront Tower', 'Turnkey studio unit with panoramic harbor views, swimming pool access, and gym amenities.', 3400000.0, 113333.33, 'Batangas City, Batangas', 5, 13.76, 121.06, 'Bolbok', 30.0, 'Condo', 'Fully finished, fire alarm & sprinkler, 24/7 security reception.', 'Available', 'representative-condo-03.jpg', '2026-11-20 16:00:00');

-- 4. Multi-Photo Property Galleries: 3 to 4 Photos per Listing
INSERT INTO `property_images` (`propertyID`, `imagePath`, `caption`, `sortOrder`, `isPrimary`, `createdAt`) VALUES
(1, 'representative-residential-01.jpg', 'Subdivision road & neighborhood frontage', 1, 1, '2024-12-20 10:00:00'),
(1, 'representative-residential-02.jpg', 'Lot elevation & boundary', 2, 0, '2024-12-20 10:00:00'),
(1, 'representative-residential-03.jpg', 'Street access & gutter view', 3, 0, '2024-12-20 10:00:00'),
(2, 'representative-farm-01.jpg', 'Main open terrain & trees', 1, 1, '2025-01-15 09:30:00'),
(2, 'representative-farm-02.jpg', 'Fruit tree orchard rows', 2, 0, '2025-01-15 09:30:00'),
(2, 'representative-farm-05.jpg', 'Boundary fence & access road', 3, 0, '2025-01-15 09:30:00'),
(3, 'representative-condo-01.jpg', 'Studio interior & balcony view', 1, 1, '2025-02-28 11:00:00'),
(3, 'representative-condo-02.jpg', 'Kitchenette area', 2, 0, '2025-02-28 11:00:00'),
(3, 'representative-condo-04.jpg', 'Building amenities & lobby', 3, 0, '2025-02-28 11:00:00'),
(4, 'representative-commercial-01.jpg', 'Highway frontage & visibility', 1, 1, '2025-04-10 14:00:00'),
(4, 'representative-commercial-02.jpg', 'Adjacent commercial structures', 2, 0, '2025-04-10 14:00:00'),
(4, 'representative-commercial-03.jpg', 'Paved parking corridor', 3, 0, '2025-04-10 14:00:00'),
(5, 'representative-residential-06.jpg', 'Exterior facade & garage', 1, 1, '2025-06-18 10:15:00'),
(5, 'representative-residential-04.jpg', 'Front garden & patio', 2, 0, '2025-06-18 10:15:00'),
(5, 'representative-residential-07.jpg', 'Living area & dining layout', 3, 0, '2025-06-18 10:15:00'),
(6, 'representative-beach-01.jpg', 'Clear coastal waters', 1, 1, '2025-08-05 13:45:00'),
(6, 'representative-beach-02.jpg', 'Beachfront shoreline', 2, 0, '2025-08-05 13:45:00'),
(6, 'representative-beach-06.jpg', 'Sandy perimeter & trees', 3, 0, '2025-08-05 13:45:00'),
(7, 'representative-residential-02.jpg', 'Hillside subdivision street view', 1, 1, '2025-11-20 09:00:00'),
(7, 'representative-residential-01.jpg', 'Panoramic mountain perspective', 2, 0, '2025-11-20 09:00:00'),
(7, 'representative-residential-03.jpg', 'Subdivision clubhouse grounds', 3, 0, '2025-11-20 09:00:00'),
(8, 'representative-farm-02.jpg', 'Coffee orchard rows', 1, 1, '2026-01-10 10:30:00'),
(8, 'representative-farm-03.jpg', 'Boundary trees & farm road', 2, 0, '2026-01-10 10:30:00'),
(8, 'representative-farm-01.jpg', 'Natural water stream nearby', 3, 0, '2026-01-10 10:30:00'),
(9, 'representative-residential-04.jpg', 'Contemporary townhouse facade', 1, 1, '2026-03-05 11:20:00'),
(9, 'representative-residential-05.jpg', 'Townhouse interior & stairs', 2, 0, '2026-03-05 11:20:00'),
(9, 'representative-residential-06.jpg', 'Covered carport space', 3, 0, '2026-03-05 11:20:00'),
(10, 'representative-commercial-02.jpg', 'Ayala Highway intersection view', 1, 1, '2026-05-12 15:00:00'),
(10, 'representative-commercial-01.jpg', 'Corner lot perspective', 2, 0, '2026-05-12 15:00:00'),
(10, 'representative-commercial-03.jpg', 'Traffic and pedestrian flow', 3, 0, '2026-05-12 15:00:00'),
(11, 'representative-beach-02.jpg', 'Punta Fuego cliffside ocean view', 1, 1, '2026-07-22 09:15:00'),
(11, 'representative-beach-03.jpg', 'Sunset shoreline vantage', 2, 0, '2026-07-22 09:15:00'),
(11, 'representative-beach-01.jpg', 'Resort estate road access', 3, 0, '2026-07-22 09:15:00'),
(12, 'representative-residential-03.jpg', 'Rosario secondary highway access', 1, 1, '2026-09-02 14:30:00'),
(12, 'representative-residential-02.jpg', 'Residential neighborhood surroundings', 2, 0, '2026-09-02 14:30:00'),
(12, 'representative-residential-07.jpg', 'Corner property fence line', 3, 0, '2026-09-02 14:30:00'),
(13, 'representative-farm-03.jpg', 'Laiya farm terrain with coconut palms', 1, 1, '2026-08-10 10:00:00'),
(13, 'representative-farm-04.jpg', 'Farm road approach', 2, 0, '2026-08-10 10:00:00'),
(14, 'representative-beach-03.jpg', 'Lobo natural cove shoreline', 1, 1, '2026-08-25 11:30:00'),
(14, 'representative-beach-04.jpg', 'Clear snorkeling waters view', 2, 0, '2026-08-25 11:30:00'),
(15, 'representative-condo-02.jpg', 'Modern 1-BR layout in Lipa', 1, 1, '2026-09-10 16:00:00'),
(15, 'representative-condo-03.jpg', 'Tower balcony view & city skyline', 2, 0, '2026-09-10 16:00:00'),
(16, 'representative-residential-05.jpg', 'Taal heritage street setting', 1, 1, '2026-10-01 10:00:00'),
(16, 'representative-residential-04.jpg', 'Lot frontage & neighboring homes', 2, 0, '2026-10-01 10:00:00'),
(17, 'representative-commercial-03.jpg', 'Industrial bypass road frontage', 1, 1, '2026-10-15 13:00:00'),
(17, 'representative-commercial-02.jpg', 'Wide gate entry for freight trucks', 2, 0, '2026-10-15 13:00:00'),
(18, 'representative-farm-04.jpg', 'Alitagtag sloping garden lot', 1, 1, '2026-10-20 15:30:00'),
(18, 'representative-farm-05.jpg', 'Distant view of Taal Lake basin', 2, 0, '2026-10-20 15:30:00'),
(19, 'representative-farm-05.jpg', 'Padre Garcia expansive livestock field', 1, 1, '2026-11-01 09:00:00'),
(19, 'representative-farm-01.jpg', 'Water well and barn location', 2, 0, '2026-11-01 09:00:00'),
(20, 'representative-beach-04.jpg', 'Anilao crystal diving cove shoreline', 1, 1, '2026-11-05 10:30:00'),
(20, 'representative-beach-05.jpg', 'Boutique dive resort boat dock zone', 2, 0, '2026-11-05 10:30:00'),
(21, 'representative-residential-07.jpg', 'Malvar landscaped village entrance', 1, 1, '2026-11-10 14:00:00'),
(21, 'representative-residential-03.jpg', 'Subdivision parks and playground', 2, 0, '2026-11-10 14:00:00'),
(22, 'representative-residential-06.jpg', 'Modern Lemery villa exterior', 1, 1, '2026-11-15 11:00:00'),
(22, 'representative-residential-02.jpg', 'Living & dining interior', 2, 0, '2026-11-15 11:00:00'),
(23, 'representative-commercial-04.jpg', 'Commercial avenue frontage', 1, 1, '2026-11-18 13:45:00'),
(23, 'representative-commercial-01.jpg', 'Open building layout', 2, 0, '2026-11-18 13:45:00'),
(24, 'representative-condo-03.jpg', 'Harbor view studio layout', 1, 1, '2026-11-20 16:00:00'),
(24, 'representative-condo-01.jpg', 'Executive lobby & concierge', 2, 0, '2026-11-20 16:00:00'),
(13, 'representative-farm-01.jpg', 'Representative farm landscape', 3, 0, '2026-08-10 10:00:00'),
(14, 'representative-beach-05.jpg', 'Representative coastal view', 3, 0, '2026-08-25 11:30:00'),
(15, 'representative-condo-01.jpg', 'Representative condominium interior', 3, 0, '2026-09-10 16:00:00'),
(16, 'representative-residential-01.jpg', 'Representative residential neighborhood', 3, 0, '2026-10-01 10:00:00'),
(17, 'representative-commercial-01.jpg', 'Representative commercial property frontage', 3, 0, '2026-10-15 13:00:00'),
(18, 'representative-farm-02.jpg', 'Representative farmland view', 3, 0, '2026-10-20 15:30:00'),
(19, 'representative-farm-04.jpg', 'Representative farmland view', 3, 0, '2026-11-01 09:00:00'),
(20, 'representative-beach-02.jpg', 'Representative coastal property view', 3, 0, '2026-11-05 10:30:00'),
(21, 'representative-residential-02.jpg', 'Representative subdivision view', 3, 0, '2026-11-10 14:00:00'),
(22, 'representative-residential-05.jpg', 'Representative house interior', 3, 0, '2026-11-15 11:00:00'),
(23, 'representative-commercial-02.jpg', 'Representative commercial land view', 3, 0, '2026-11-18 13:45:00'),
(24, 'representative-condo-02.jpg', 'Representative condo interior', 3, 0, '2026-11-20 16:00:00');

-- 5. Viewing Appointments: Approved Historical Meetups & Pending Requests
INSERT INTO `appointments` (`appointmentID`, `propertyID`, `clientID`, `agentID`, `appointmentDate`, `appointmentTime`, `status`, `createdAt`) VALUES
(1, 1, 5, 2, '2025-01-05', '10:00:00', 'Approved', '2024-12-28 09:00:00'),
(2, 2, 6, 3, '2025-02-08', '14:00:00', 'Approved', '2025-01-20 11:15:00'),
(3, 3, 7, 4, '2025-03-12', '11:00:00', 'Approved', '2025-03-02 14:30:00'),
(4, 4, 8, 2, '2025-04-25', '15:30:00', 'Approved', '2025-04-12 10:00:00'),
(5, 5, 9, 3, '2025-06-28', '09:30:00', 'Approved', '2025-06-20 08:45:00'),
(6, 6, 10, 4, '2025-08-18', '10:00:00', 'Approved', '2025-08-08 16:00:00'),
(7, 7, 11, 2, '2026-01-15', '14:00:00', 'Approved', '2025-12-05 10:15:00'),
(8, 8, 12, 3, '2026-02-20', '09:30:00', 'Approved', '2026-01-18 11:30:00'),
(9, 9, 13, 4, '2026-04-10', '11:00:00', 'Approved', '2026-03-12 14:00:00'),
(10, 10, 14, 2, '2026-06-02', '15:00:00', 'Approved', '2026-05-18 09:20:00'),
(11, 11, 15, 3, '2026-08-08', '10:30:00', 'Approved', '2026-07-28 13:45:00'),
(12, 12, 16, 4, '2026-09-18', '14:30:00', 'Approved', '2026-09-05 10:00:00'),
(13, 13, 6, 2, '2026-08-18', '10:00:00', 'Approved', '2026-08-11 11:00:00'),
(14, 14, 5, 3, '2026-09-02', '14:00:00', 'Approved', '2026-08-26 15:30:00'),
(15, 15, 8, 4, '2026-09-22', '11:00:00', 'Approved', '2026-09-12 10:45:00'),
(16, 16, 7, 2, '2026-10-12', '09:30:00', 'Approved', '2026-10-02 16:15:00'),
(17, 17, 9, 3, '2026-11-28', '14:30:00', 'Pending', '2026-11-18 10:00:00'),
(18, 20, 10, 4, '2026-11-29', '10:00:00', 'Pending', '2026-11-22 13:30:00');

-- 6. Property Transactions: 12 Completed Sales (2025-2026) & 4 Active Deals
INSERT INTO `property_transactions` (`transactionID`, `propertyID`, `clientID`, `appointmentID`, `inquiryID`, `currentStage`, `salePrice`, `paymentMethod`, `createdAt`, `updatedAt`) VALUES
(1, 1, 5, 1, NULL, 'completed', 1800000.00, 'Spot Cash', '2024-12-28 09:00:00', '2025-01-25 15:00:00'),
(2, 2, 6, 2, NULL, 'completed', 3200000.00, 'Installment (Post-Dated Checks)', '2025-01-20 11:15:00', '2025-03-20 16:30:00'),
(3, 3, 7, 3, NULL, 'completed', 2900000.00, 'Bank Financing', '2025-03-02 14:30:00', '2025-05-18 11:00:00'),
(4, 4, 8, 4, NULL, 'completed', 6500000.00, 'Spot Cash', '2025-04-12 10:00:00', '2025-07-15 14:00:00'),
(5, 5, 9, 5, NULL, 'completed', 4200000.00, 'Installment (Post-Dated Checks)', '2025-06-20 08:45:00', '2025-09-22 10:30:00'),
(6, 6, 10, 6, NULL, 'completed', 5800000.00, 'Bank Financing', '2025-08-08 16:00:00', '2025-11-28 15:45:00'),
(7, 7, 11, 7, NULL, 'completed', 2100000.00, 'Spot Cash', '2025-12-05 10:15:00', '2026-02-18 14:00:00'),
(8, 8, 12, 8, NULL, 'completed', 3500000.00, 'Installment (Post-Dated Checks)', '2026-01-18 11:30:00', '2026-04-15 16:00:00'),
(9, 9, 13, 9, NULL, 'completed', 3800000.00, 'Bank Financing', '2026-03-12 14:00:00', '2026-06-20 11:30:00'),
(10, 10, 14, 10, NULL, 'completed', 7200000.00, 'Spot Cash', '2026-05-18 09:20:00', '2026-08-10 15:15:00'),
(11, 11, 15, 11, NULL, 'completed', 6400000.00, 'Bank Financing', '2026-07-28 13:45:00', '2026-10-18 10:00:00'),
(12, 12, 16, 12, NULL, 'completed', 2600000.00, 'Spot Cash', '2026-09-05 10:00:00', '2026-11-12 16:30:00'),
(13, 13, 6, 13, NULL, 'payment_confirmation', 4500000.00, 'Installment (Post-Dated Checks)', '2026-08-11 11:00:00', '2026-11-15 14:00:00'),
(14, 14, 5, 14, NULL, 'payment_in_process', 5200000.00, 'Bank Financing', '2026-08-26 15:30:00', '2026-11-05 10:30:00'),
(15, 15, 8, 15, NULL, 'document_verification', 3100000.00, 'Bank Financing', '2026-09-12 10:45:00', '2026-10-25 11:15:00'),
(16, 16, 7, 16, NULL, 'viewing_completed', 1950000.00, NULL, '2026-10-02 16:15:00', '2026-10-15 09:00:00');

-- 7. Milestone History Events: 8-Stage Progression for Every Completed Deal
INSERT INTO `property_transaction_events` (`transactionID`, `stage`, `changedBy`, `note`, `createdAt`) VALUES
(1, 'viewing_requested', 2, 'Viewing request approved and site tour scheduled.', '2025-01-05 10:00:00'),
(1, 'viewing_completed', 2, 'On-site viewing completed with client. Property boundaries inspected.', '2025-01-05 15:00:00'),
(1, 'reservation_deposited', 2, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2025-01-05 16:30:00'),
(1, 'document_verification', 2, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Spot Cash.', '2025-01-05 10:00:00'),
(1, 'payment_in_process', 2, 'Agreed payment scheme: Spot Cash. Payment schedule formalized.', '2025-01-10 11:30:00'),
(1, 'notarization_and_closing', 2, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2025-01-15 14:00:00'),
(1, 'turnover_and_title', 2, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2025-01-18 10:00:00'),
(1, 'completed', 2, 'Transaction successfully closed. Verified sale price: ₱1,800,000.00. Title turned over to buyer.', '2025-01-25 15:00:00'),
(2, 'viewing_requested', 3, 'Viewing request approved and site tour scheduled.', '2025-02-08 14:00:00'),
(2, 'viewing_completed', 3, 'On-site viewing completed with client. Property boundaries inspected.', '2025-02-08 15:00:00'),
(2, 'reservation_deposited', 3, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2025-02-08 16:30:00'),
(2, 'document_verification', 3, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Installment (Post-Dated Checks).', '2025-03-05 10:00:00'),
(2, 'payment_in_process', 3, 'Agreed payment scheme: Installment (Post-Dated Checks). Payment schedule formalized.', '2025-03-10 11:30:00'),
(2, 'notarization_and_closing', 3, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2025-03-15 14:00:00'),
(2, 'turnover_and_title', 3, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2025-03-18 10:00:00'),
(2, 'completed', 3, 'Transaction successfully closed. Verified sale price: ₱3,200,000.00. Title turned over to buyer.', '2025-03-20 16:30:00'),
(3, 'viewing_requested', 4, 'Viewing request approved and site tour scheduled.', '2025-03-12 11:00:00'),
(3, 'viewing_completed', 4, 'On-site viewing completed with client. Property boundaries inspected.', '2025-03-12 15:00:00'),
(3, 'reservation_deposited', 4, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2025-03-12 16:30:00'),
(3, 'document_verification', 4, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Bank Financing.', '2025-05-05 10:00:00'),
(3, 'payment_in_process', 4, 'Agreed payment scheme: Bank Financing. Payment schedule formalized.', '2025-05-10 11:30:00'),
(3, 'notarization_and_closing', 4, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2025-05-15 14:00:00'),
(3, 'turnover_and_title', 4, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2025-05-18 10:00:00'),
(3, 'completed', 4, 'Transaction successfully closed. Verified sale price: ₱2,900,000.00. Title turned over to buyer.', '2025-05-18 11:00:00'),
(4, 'viewing_requested', 2, 'Viewing request approved and site tour scheduled.', '2025-04-25 15:30:00'),
(4, 'viewing_completed', 2, 'On-site viewing completed with client. Property boundaries inspected.', '2025-04-25 15:00:00'),
(4, 'reservation_deposited', 2, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2025-04-25 16:30:00'),
(4, 'document_verification', 2, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Spot Cash.', '2025-07-05 10:00:00'),
(4, 'payment_in_process', 2, 'Agreed payment scheme: Spot Cash. Payment schedule formalized.', '2025-07-10 11:30:00'),
(4, 'notarization_and_closing', 2, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2025-07-15 14:00:00'),
(4, 'turnover_and_title', 2, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2025-07-18 10:00:00'),
(4, 'completed', 2, 'Transaction successfully closed. Verified sale price: ₱6,500,000.00. Title turned over to buyer.', '2025-07-15 14:00:00'),
(5, 'viewing_requested', 3, 'Viewing request approved and site tour scheduled.', '2025-06-28 09:30:00'),
(5, 'viewing_completed', 3, 'On-site viewing completed with client. Property boundaries inspected.', '2025-06-28 15:00:00'),
(5, 'reservation_deposited', 3, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2025-06-28 16:30:00'),
(5, 'document_verification', 3, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Installment (Post-Dated Checks).', '2025-09-05 10:00:00'),
(5, 'payment_in_process', 3, 'Agreed payment scheme: Installment (Post-Dated Checks). Payment schedule formalized.', '2025-09-10 11:30:00'),
(5, 'notarization_and_closing', 3, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2025-09-15 14:00:00'),
(5, 'turnover_and_title', 3, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2025-09-18 10:00:00'),
(5, 'completed', 3, 'Transaction successfully closed. Verified sale price: ₱4,200,000.00. Title turned over to buyer.', '2025-09-22 10:30:00'),
(6, 'viewing_requested', 4, 'Viewing request approved and site tour scheduled.', '2025-08-18 10:00:00'),
(6, 'viewing_completed', 4, 'On-site viewing completed with client. Property boundaries inspected.', '2025-08-18 15:00:00'),
(6, 'reservation_deposited', 4, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2025-08-18 16:30:00'),
(6, 'document_verification', 4, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Bank Financing.', '2025-11-05 10:00:00'),
(6, 'payment_in_process', 4, 'Agreed payment scheme: Bank Financing. Payment schedule formalized.', '2025-11-10 11:30:00'),
(6, 'notarization_and_closing', 4, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2025-11-15 14:00:00'),
(6, 'turnover_and_title', 4, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2025-11-18 10:00:00'),
(6, 'completed', 4, 'Transaction successfully closed. Verified sale price: ₱5,800,000.00. Title turned over to buyer.', '2025-11-28 15:45:00'),
(7, 'viewing_requested', 2, 'Viewing request approved and site tour scheduled.', '2026-01-15 14:00:00'),
(7, 'viewing_completed', 2, 'On-site viewing completed with client. Property boundaries inspected.', '2026-01-15 15:00:00'),
(7, 'reservation_deposited', 2, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2026-01-15 16:30:00'),
(7, 'document_verification', 2, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Spot Cash.', '2026-02-05 10:00:00'),
(7, 'payment_in_process', 2, 'Agreed payment scheme: Spot Cash. Payment schedule formalized.', '2026-02-10 11:30:00'),
(7, 'notarization_and_closing', 2, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2026-02-15 14:00:00'),
(7, 'turnover_and_title', 2, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2026-02-18 10:00:00'),
(7, 'completed', 2, 'Transaction successfully closed. Verified sale price: ₱2,100,000.00. Title turned over to buyer.', '2026-02-18 14:00:00'),
(8, 'viewing_requested', 3, 'Viewing request approved and site tour scheduled.', '2026-02-20 09:30:00'),
(8, 'viewing_completed', 3, 'On-site viewing completed with client. Property boundaries inspected.', '2026-02-20 15:00:00'),
(8, 'reservation_deposited', 3, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2026-02-20 16:30:00'),
(8, 'document_verification', 3, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Installment (Post-Dated Checks).', '2026-04-05 10:00:00'),
(8, 'payment_in_process', 3, 'Agreed payment scheme: Installment (Post-Dated Checks). Payment schedule formalized.', '2026-04-10 11:30:00'),
(8, 'notarization_and_closing', 3, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2026-04-15 14:00:00'),
(8, 'turnover_and_title', 3, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2026-04-18 10:00:00'),
(8, 'completed', 3, 'Transaction successfully closed. Verified sale price: ₱3,500,000.00. Title turned over to buyer.', '2026-04-15 16:00:00'),
(9, 'viewing_requested', 4, 'Viewing request approved and site tour scheduled.', '2026-04-10 11:00:00'),
(9, 'viewing_completed', 4, 'On-site viewing completed with client. Property boundaries inspected.', '2026-04-10 15:00:00'),
(9, 'reservation_deposited', 4, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2026-04-10 16:30:00'),
(9, 'document_verification', 4, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Bank Financing.', '2026-06-05 10:00:00'),
(9, 'payment_in_process', 4, 'Agreed payment scheme: Bank Financing. Payment schedule formalized.', '2026-06-10 11:30:00'),
(9, 'notarization_and_closing', 4, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2026-06-15 14:00:00'),
(9, 'turnover_and_title', 4, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2026-06-18 10:00:00'),
(9, 'completed', 4, 'Transaction successfully closed. Verified sale price: ₱3,800,000.00. Title turned over to buyer.', '2026-06-20 11:30:00'),
(10, 'viewing_requested', 2, 'Viewing request approved and site tour scheduled.', '2026-06-02 15:00:00'),
(10, 'viewing_completed', 2, 'On-site viewing completed with client. Property boundaries inspected.', '2026-06-02 15:00:00'),
(10, 'reservation_deposited', 2, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2026-06-02 16:30:00'),
(10, 'document_verification', 2, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Spot Cash.', '2026-08-05 10:00:00'),
(10, 'payment_in_process', 2, 'Agreed payment scheme: Spot Cash. Payment schedule formalized.', '2026-08-10 11:30:00'),
(10, 'notarization_and_closing', 2, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2026-08-15 14:00:00'),
(10, 'turnover_and_title', 2, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2026-08-18 10:00:00'),
(10, 'completed', 2, 'Transaction successfully closed. Verified sale price: ₱7,200,000.00. Title turned over to buyer.', '2026-08-10 15:15:00'),
(11, 'viewing_requested', 3, 'Viewing request approved and site tour scheduled.', '2026-08-08 10:30:00'),
(11, 'viewing_completed', 3, 'On-site viewing completed with client. Property boundaries inspected.', '2026-08-08 15:00:00'),
(11, 'reservation_deposited', 3, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2026-08-08 16:30:00'),
(11, 'document_verification', 3, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Bank Financing.', '2026-10-05 10:00:00'),
(11, 'payment_in_process', 3, 'Agreed payment scheme: Bank Financing. Payment schedule formalized.', '2026-10-10 11:30:00'),
(11, 'notarization_and_closing', 3, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2026-10-15 14:00:00'),
(11, 'turnover_and_title', 3, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2026-10-18 10:00:00'),
(11, 'completed', 3, 'Transaction successfully closed. Verified sale price: ₱6,400,000.00. Title turned over to buyer.', '2026-10-18 10:00:00'),
(12, 'viewing_requested', 4, 'Viewing request approved and site tour scheduled.', '2026-09-18 14:30:00'),
(12, 'viewing_completed', 4, 'On-site viewing completed with client. Property boundaries inspected.', '2026-09-18 15:00:00'),
(12, 'reservation_deposited', 4, 'Official reservation deposit of ₱30,000 received. Property reserved for 30 days.', '2026-09-18 16:30:00'),
(12, 'document_verification', 4, 'Valid IDs, TIN, and client KYC documentation verified. Scheme: Spot Cash.', '2026-11-05 10:00:00'),
(12, 'payment_in_process', 4, 'Agreed payment scheme: Spot Cash. Payment schedule formalized.', '2026-11-10 11:30:00'),
(12, 'notarization_and_closing', 4, 'Deed of Absolute Sale / Contract to Sell signed and formally notarized.', '2026-11-15 14:00:00'),
(12, 'turnover_and_title', 4, 'BIR Certificate Authorizing Registration (CAR) secured; Registry of Deeds title endorsed.', '2026-11-18 10:00:00'),
(12, 'completed', 4, 'Transaction successfully closed. Verified sale price: ₱2,600,000.00. Title turned over to buyer.', '2026-11-12 16:30:00'),
(13, 'viewing_requested', 2, 'Viewing request received for Laiya Coastal Eco-Farm Lot.', '2026-08-11 11:00:00'),
(13, 'viewing_completed', 2, 'Viewing completed with client Elena Villanueva.', '2026-08-18 14:00:00'),
(13, 'reservation_deposited', 2, 'Initial reservation deposit of ₱30,000 recorded.', '2026-08-20 10:30:00'),
(13, 'document_verification', 2, 'All government IDs and proof of income verified.', '2026-09-10 11:00:00'),
(13, 'payment_in_process', 2, 'Agreed on CTS Installment plan with 24 post-dated checks.', '2026-10-05 14:00:00'),
(13, 'notarization_and_closing', 2, 'Contract to Sell (CTS) submitted for notary public endorsement.', '2026-11-15 14:00:00'),
(14, 'viewing_requested', 3, 'Viewing requested for Seaside Mountain View Beach Lot in Lobo.', '2026-08-26 15:30:00'),
(14, 'viewing_completed', 3, 'Viewing completed with client Roberto Tan.', '2026-09-02 16:00:00'),
(14, 'reservation_deposited', 3, 'Reservation deposit of ₱50,000 acknowledged.', '2026-09-05 10:00:00'),
(14, 'document_verification', 3, 'Bank loan pre-approval letter received and verified.', '2026-10-12 11:30:00'),
(14, 'payment_in_process', 3, 'Bank loan processing: Letter of Guarantee (LOG) awaited.', '2026-11-05 10:30:00'),
(15, 'viewing_requested', 4, 'Viewing scheduled for 1-Bedroom Condo in Lipa.', '2026-09-12 10:45:00'),
(15, 'viewing_completed', 4, 'Client Grace Lim inspected unit and amenities.', '2026-09-22 13:00:00'),
(15, 'reservation_deposited', 4, 'Reservation fee of ₱20,000 paid via bank transfer.', '2026-09-25 15:00:00'),
(15, 'document_verification', 4, 'Reviewing buyer documents for developer contract endorsement.', '2026-10-25 11:15:00'),
(16, 'viewing_requested', 2, 'Viewing request booked for Heritage Residential Lot in Taal.', '2026-10-02 16:15:00'),
(16, 'viewing_completed', 2, 'Client Mark Bautista completed property site tour.', '2026-10-15 09:00:00');

-- 8. Client Transaction Payments Tracking Ledger (property_transaction_payments)
INSERT INTO `property_transaction_payments` (`paymentID`, `transactionID`, `clientID`, `amount`, `paymentMethod`, `referenceNumber`, `status`, `submittedAt`, `reviewedAt`, `reviewedBy`, `reviewNote`) VALUES
(1, 1, 5, 30000.00, 'Bank Transfer', 'REF-2025-0105', 'confirmed', '2025-01-08 09:30:00', '2025-01-08 10:00:00', 2, 'Official reservation fee verified.'),
(2, 1, 5, 1770000.00, 'Bank Transfer', 'REF-2025-0125', 'confirmed', '2025-01-25 11:00:00', '2025-01-25 14:00:00', 2, 'Remaining balance settled via manager''''s check.'),
(3, 2, 6, 30000.00, 'Cash', 'REF-2025-0210', 'confirmed', '2025-02-12 10:00:00', '2025-02-12 11:00:00', 3, 'Reservation deposit verified at sales office.'),
(4, 2, 6, 610000.00, 'Check', 'REF-2025-0315', 'confirmed', '2025-03-15 13:00:00', '2025-03-15 14:30:00', 3, '20% down payment check cleared under CTS terms.'),
(5, 2, 6, 2560000.00, 'Check', 'REF-2025-0320', 'confirmed', '2025-03-20 15:00:00', '2025-03-20 16:30:00', 3, 'Full CTS balance cleared and settled.'),
(6, 3, 7, 25000.00, 'Bank Transfer', 'REF-2025-0315', 'confirmed', '2025-03-16 09:00:00', '2025-03-16 09:30:00', 4, 'Condo reservation fee credited.'),
(7, 3, 7, 555000.00, 'Bank Transfer', 'REF-2025-0420', 'confirmed', '2025-04-20 14:00:00', '2025-04-20 15:00:00', 4, 'Equity down payment cleared. Bank loan approved.'),
(8, 3, 7, 2320000.00, 'Bank Transfer', 'REF-2025-0518', 'confirmed', '2025-05-18 10:00:00', '2025-05-18 11:00:00', 4, 'Bank loan proceeds takeout confirmed by depository bank.'),
(9, 4, 8, 50000.00, 'Check', 'REF-2025-0428', 'confirmed', '2025-04-29 09:45:00', '2025-04-29 10:15:00', 2, 'Commercial property reservation verified.'),
(10, 4, 8, 6450000.00, 'Bank Transfer', 'REF-2025-0715', 'confirmed', '2025-07-15 10:30:00', '2025-07-15 11:30:00', 2, 'Full commercial land purchase amount settled.'),
(11, 5, 9, 30000.00, 'Bank Transfer', 'REF-2025-0701', 'confirmed', '2025-07-02 11:00:00', '2025-07-02 14:00:00', 3, 'House & lot reservation fee.'),
(12, 5, 9, 810000.00, 'Check', 'REF-2025-0815', 'confirmed', '2025-08-15 15:00:00', '2025-08-15 16:00:00', 3, '20% down payment received; CTS checks issued.'),
(13, 5, 9, 3360000.00, 'Check', 'REF-2025-0922', 'confirmed', '2025-09-22 09:30:00', '2025-09-22 10:30:00', 3, 'CTS installment post-dated checks matured and cleared.'),
(14, 6, 10, 50000.00, 'Bank Transfer', 'REF-2025-0820', 'confirmed', '2025-08-22 10:00:00', '2025-08-22 10:45:00', 4, 'Beach lot reservation deposit.'),
(15, 6, 10, 1110000.00, 'Bank Transfer', 'REF-2025-1010', 'confirmed', '2025-10-10 11:00:00', '2025-10-10 13:30:00', 4, 'Beach parcel equity payment completed.'),
(16, 6, 10, 4640000.00, 'Bank Transfer', 'REF-2025-1128', 'confirmed', '2025-11-28 14:00:00', '2025-11-28 15:45:00', 4, 'Mortgage financing takeout settled by financing partner.'),
(17, 7, 11, 30000.00, 'Bank Transfer', 'REF-2026-0120', 'confirmed', '2026-01-22 09:30:00', '2026-01-22 10:00:00', 2, 'Reservation deposit verified.'),
(18, 7, 11, 2070000.00, 'Bank Transfer', 'REF-2026-0218', 'confirmed', '2026-02-18 10:00:00', '2026-02-18 11:30:00', 2, 'Full spot cash balance cleared.'),
(19, 8, 12, 35000.00, 'Cash', 'REF-2026-0225', 'confirmed', '2026-02-26 11:00:00', '2026-02-26 14:00:00', 3, 'Farmlot reservation deposit.'),
(20, 8, 12, 665000.00, 'Check', 'REF-2026-0330', 'confirmed', '2026-03-30 14:00:00', '2026-03-30 15:45:00', 3, '20% down payment check cleared under CTS.'),
(21, 8, 12, 2800000.00, 'Check', 'REF-2026-0415', 'confirmed', '2026-04-15 15:00:00', '2026-04-15 16:00:00', 3, 'Installment contract settled in full.'),
(22, 9, 13, 30000.00, 'Bank Transfer', 'REF-2026-0415', 'confirmed', '2026-04-18 09:00:00', '2026-04-18 09:30:00', 4, 'Townhouse reservation deposit.'),
(23, 9, 13, 730000.00, 'Bank Transfer', 'REF-2026-0520', 'confirmed', '2026-05-20 14:30:00', '2026-05-20 16:00:00', 4, 'Equity down payment received. Bank LOG approved.'),
(24, 9, 13, 3040000.00, 'Bank Transfer', 'REF-2026-0620', 'confirmed', '2026-06-20 10:00:00', '2026-06-20 11:30:00', 4, 'Bank loan balance disbursement verified.'),
(25, 10, 14, 50000.00, 'Check', 'REF-2026-0610', 'confirmed', '2026-06-12 10:15:00', '2026-06-12 11:00:00', 2, 'Commercial corner lot reservation.'),
(26, 10, 14, 7150000.00, 'Bank Transfer', 'REF-2026-0810', 'confirmed', '2026-08-10 11:00:00', '2026-08-10 14:00:00', 2, 'Full spot cash payment verified.'),
(27, 11, 15, 50000.00, 'Bank Transfer', 'REF-2026-0812', 'confirmed', '2026-08-15 10:00:00', '2026-08-15 10:30:00', 3, 'Punta Fuego beach lot reservation.'),
(28, 11, 15, 1230000.00, 'Bank Transfer', 'REF-2026-0925', 'confirmed', '2026-09-25 14:00:00', '2026-09-25 15:15:00', 3, 'Equity payment verified; bank balance drawn.'),
(29, 11, 15, 5120000.00, 'Bank Transfer', 'REF-2026-1018', 'confirmed', '2026-10-18 09:30:00', '2026-10-18 10:00:00', 3, 'Bank loan proceeds release acknowledged.'),
(30, 12, 16, 30000.00, 'Bank Transfer', 'REF-2026-0922', 'confirmed', '2026-09-24 10:30:00', '2026-09-24 11:00:00', 4, 'Rosario residential lot reservation.'),
(31, 12, 16, 2570000.00, 'Bank Transfer', 'REF-2026-1112', 'confirmed', '2026-11-12 13:00:00', '2026-11-12 14:30:00', 4, 'Full purchase settlement cleared.'),
(32, 13, 6, 30000.00, 'Bank Transfer', 'REF-2026-0820', 'confirmed', '2026-08-20 10:00:00', '2026-08-20 10:30:00', 2, 'Reservation fee for Laiya farm lot.'),
(33, 13, 6, 870000.00, 'Check', 'REF-2026-0928', 'confirmed', '2026-09-28 13:30:00', '2026-09-28 14:00:00', 2, '20% down payment under CTS agreement.'),
(34, 14, 5, 50000.00, 'Bank Transfer', 'REF-2026-0905', 'confirmed', '2026-09-05 09:30:00', '2026-09-05 10:00:00', 3, 'Beach lot reservation fee acknowledged.'),
(35, 14, 5, 990000.00, 'Bank Transfer', 'REF-2026-1020', 'confirmed', '2026-10-20 14:00:00', '2026-10-20 15:30:00', 3, '20% equity down payment received.'),
(36, 15, 8, 20000.00, 'Bank Transfer', 'REF-2026-0925', 'confirmed', '2026-09-25 14:00:00', '2026-09-25 15:00:00', 4, 'Condo reservation fee received.'),
(37, 15, 8, 600000.00, 'Bank Transfer', 'REF-2026-1120', 'pending', '2026-11-20 10:00:00', NULL, NULL, 'Equity down payment submitted for staff review.');

-- 9. Official Accounting Payments Ledger (payments)
INSERT INTO `payments` (`paymentID`, `transactionID`, `clientID`, `amount`, `paymentType`, `paymentMethod`, `referenceNo`, `status`, `paidAt`, `note`, `recordedBy`, `createdAt`) VALUES
(1, 1, 5, 30000.00, 'Reservation', 'Bank Transfer', 'REF-2025-0105', 'Verified', '2025-01-08', 'Official reservation receipt issued.', 2, '2025-01-08 10:00:00'),
(2, 1, 5, 1770000.00, 'Full Payment', 'Bank Transfer', 'REF-2025-0125', 'Verified', '2025-01-25', 'Full spot cash settlement verified.', 2, '2025-01-25 14:00:00'),
(3, 2, 6, 30000.00, 'Reservation', 'Cash', 'REF-2025-0210', 'Verified', '2025-02-12', 'Cash reservation deposit logged.', 3, '2025-02-12 11:00:00'),
(4, 2, 6, 610000.00, 'Down Payment', 'Check', 'REF-2025-0315', 'Verified', '2025-03-15', '20% equity check cleared.', 3, '2025-03-15 14:30:00'),
(5, 2, 6, 2560000.00, 'Installment', 'Check', 'REF-2025-0320', 'Verified', '2025-03-20', 'Remaining CTS installment settlement.', 3, '2025-03-20 16:30:00'),
(6, 3, 7, 25000.00, 'Reservation', 'Bank Transfer', 'REF-2025-0315', 'Verified', '2025-03-16', 'Condo reservation credited to developer.', 4, '2025-03-16 09:30:00'),
(7, 3, 7, 555000.00, 'Down Payment', 'Bank Transfer', 'REF-2025-0420', 'Verified', '2025-04-20', 'Client equity down payment confirmed.', 4, '2025-04-20 15:00:00'),
(8, 3, 7, 2320000.00, 'Full Payment', 'Bank Transfer', 'REF-2025-0518', 'Verified', '2025-05-18', 'Bank takeout proceeds credited.', 4, '2025-05-18 11:00:00'),
(9, 4, 8, 50000.00, 'Reservation', 'Check', 'REF-2025-0428', 'Verified', '2025-04-29', 'Commercial land reservation confirmed.', 2, '2025-04-29 10:15:00'),
(10, 4, 8, 6450000.00, 'Full Payment', 'Bank Transfer', 'REF-2025-0715', 'Verified', '2025-07-15', 'Spot cash wire transfer verified.', 2, '2025-07-15 11:30:00'),
(11, 5, 9, 30000.00, 'Reservation', 'Bank Transfer', 'REF-2025-0701', 'Verified', '2025-07-02', 'Reservation fee recorded.', 3, '2025-07-02 14:00:00'),
(12, 5, 9, 810000.00, 'Down Payment', 'Check', 'REF-2025-0815', 'Verified', '2025-08-15', 'Down payment check verified.', 3, '2025-08-15 16:00:00'),
(13, 5, 9, 3360000.00, 'Installment', 'Check', 'REF-2025-0922', 'Verified', '2025-09-22', 'CTS installment balance verified.', 3, '2025-09-22 10:30:00'),
(14, 6, 10, 50000.00, 'Reservation', 'Bank Transfer', 'REF-2025-0820', 'Verified', '2025-08-22', 'Calatagan beach parcel reservation.', 4, '2025-08-22 10:45:00'),
(15, 6, 10, 1110000.00, 'Down Payment', 'Bank Transfer', 'REF-2025-1010', 'Verified', '2025-10-10', 'Equity down payment cleared.', 4, '2025-10-10 13:30:00'),
(16, 6, 10, 4640000.00, 'Full Payment', 'Bank Transfer', 'REF-2025-1128', 'Verified', '2025-11-28', 'Bank financing balance cleared.', 4, '2025-11-28 15:45:00'),
(17, 7, 11, 30000.00, 'Reservation', 'Bank Transfer', 'REF-2026-0120', 'Verified', '2026-01-22', 'Reservation deposit verified.', 2, '2026-01-22 10:00:00'),
(18, 7, 11, 2070000.00, 'Full Payment', 'Bank Transfer', 'REF-2026-0218', 'Verified', '2026-02-18', 'Spot cash wire cleared.', 2, '2026-02-18 11:30:00'),
(19, 8, 12, 35000.00, 'Reservation', 'Cash', 'REF-2026-0225', 'Verified', '2026-02-26', 'Farmlot reservation cash verified.', 3, '2026-02-26 14:00:00'),
(20, 8, 12, 665000.00, 'Down Payment', 'Check', 'REF-2026-0330', 'Verified', '2026-03-30', 'Down payment check cleared.', 3, '2026-03-30 15:45:00'),
(21, 8, 12, 2800000.00, 'Installment', 'Check', 'REF-2026-0415', 'Verified', '2026-04-15', 'Installment CTS post-dated check.', 3, '2026-04-15 16:00:00'),
(22, 9, 13, 30000.00, 'Reservation', 'Bank Transfer', 'REF-2026-0415', 'Verified', '2026-04-18', 'Townhouse reservation deposit.', 4, '2026-04-18 09:30:00'),
(23, 9, 13, 730000.00, 'Down Payment', 'Bank Transfer', 'REF-2026-0520', 'Verified', '2026-05-20', 'Equity down payment cleared.', 4, '2026-05-20 16:00:00'),
(24, 9, 13, 3040000.00, 'Full Payment', 'Bank Transfer', 'REF-2026-0620', 'Verified', '2026-06-20', 'Bank loan release verified.', 4, '2026-06-20 11:30:00'),
(25, 10, 14, 50000.00, 'Reservation', 'Check', 'REF-2026-0610', 'Verified', '2026-06-12', 'Ayala Highway lot reservation.', 2, '2026-06-12 11:00:00'),
(26, 10, 14, 7150000.00, 'Full Payment', 'Bank Transfer', 'REF-2026-0810', 'Verified', '2026-08-10', 'Full spot cash payment verified.', 2, '2026-08-10 14:00:00'),
(27, 11, 15, 50000.00, 'Reservation', 'Bank Transfer', 'REF-2026-0812', 'Verified', '2026-08-15', 'Punta Fuego beach lot reservation.', 3, '2026-08-15 10:30:00'),
(28, 11, 15, 1230000.00, 'Down Payment', 'Bank Transfer', 'REF-2026-0925', 'Verified', '2026-09-25', 'Equity deposit cleared.', 3, '2026-09-25 15:15:00'),
(29, 11, 15, 5120000.00, 'Full Payment', 'Bank Transfer', 'REF-2026-1018', 'Verified', '2026-10-18', 'Bank financing disbursement confirmed.', 3, '2026-10-18 10:00:00'),
(30, 12, 16, 30000.00, 'Reservation', 'Bank Transfer', 'REF-2026-0922', 'Verified', '2026-09-24', 'Rosario property reservation.', 4, '2026-09-24 11:00:00'),
(31, 12, 16, 2570000.00, 'Full Payment', 'Bank Transfer', 'REF-2026-1112', 'Verified', '2026-11-12', 'Full balance wire settled.', 4, '2026-11-12 14:30:00'),
(32, 13, 6, 30000.00, 'Reservation', 'Bank Transfer', 'REF-2026-0820', 'Verified', '2026-08-20', 'Laiya farm reservation fee.', 2, '2026-08-20 10:30:00'),
(33, 13, 6, 870000.00, 'Down Payment', 'Check', 'REF-2026-0928', 'Verified', '2026-09-28', '20% down payment under CTS.', 2, '2026-09-28 14:00:00'),
(34, 14, 5, 50000.00, 'Reservation', 'Bank Transfer', 'REF-2026-0905', 'Verified', '2026-09-05', 'Lobo beach parcel reservation.', 3, '2026-09-05 10:00:00'),
(35, 14, 5, 990000.00, 'Down Payment', 'Bank Transfer', 'REF-2026-1020', 'Verified', '2026-10-20', 'Equity down payment received.', 3, '2026-10-20 15:30:00'),
(36, 15, 8, 20000.00, 'Reservation', 'Bank Transfer', 'REF-2026-0925', 'Verified', '2026-09-25', 'Condo reservation deposit.', 4, '2026-09-25 15:00:00'),
(37, 15, 8, 600000.00, 'Down Payment', 'Bank Transfer', 'REF-2026-1120', 'Pending', '2026-11-20', 'Down payment verification in review.', 4, '2026-11-20 10:00:00');

-- 10. Client Property Inquiries
INSERT INTO `feedbackinquiries` (`inquiryID`, `propertyID`, `clientID`, `message`, `status`, `createdAt`) VALUES
(1, 19, 5, 'Hello! I am interested in the Padre Garcia farm lot. Is the terrain completely flat and is electricity readily available?', 'Read', '2026-11-03 10:15:00'),
(2, 20, 7, 'Good day, regarding the Anilao dive resort property: does it have direct beachfront access for boat docking?', 'Read', '2026-11-08 14:30:00'),
(3, 22, 8, 'Hi Kings Land team, is the Lemery modern villa ready for occupancy? Can we schedule an inspection this coming Saturday?', 'Read', '2026-11-16 11:00:00'),
(4, 23, 11, 'Hello, can you send the exact dimensions and zoning classification of the Balayan commercial parcel?', 'Unread', '2026-11-21 09:45:00'),
(5, 24, 12, 'Hi! Does the Batangas City Bayfront studio include parking slots or is that purchased separately?', 'Unread', '2026-11-23 16:20:00');

SET FOREIGN_KEY_CHECKS = 1;

-- ══════════════════════════════════════════════════════════════════════
-- 2-Year Dataset Generation Complete.
-- Total 2025 Revenue: ₱24,400,000.00 (6 Completed Deals)
-- Total 2026 Revenue: ₱25,600,000.00 (6 Completed Deals)
-- Total Historical Revenue: ₱50,000,000.00
-- Active Pipeline: 4 Deals (₱14,750,000.00 in progress)
-- ══════════════════════════════════════════════════════════════════════
