-- Fresh-install schema for KingQuiry.
-- Select/create the destination database before importing this file.
-- This file contains table definitions, the standard Batangas location
-- directory, and property listings for all supported categories. Listing
-- photos marked representative may not depict the exact property.

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
    currentStage VARCHAR(40) NOT NULL,
    salePrice DECIMAL(12,2) DEFAULT NULL,
    paymentMethod VARCHAR(50) DEFAULT NULL,
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

CREATE TABLE IF NOT EXISTS agent_reviews (
    reviewID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transactionID BIGINT UNSIGNED NOT NULL,
    clientID INT NOT NULL,
    agentID INT DEFAULT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT DEFAULT NULL,
    createdAt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reviewID),
    UNIQUE KEY uq_agent_reviews_transaction (transactionID),
    KEY idx_agent_reviews_client (clientID),
    KEY idx_agent_reviews_agent (agentID),
    KEY idx_agent_reviews_rating (rating),
    CONSTRAINT fk_agent_reviews_transaction
        FOREIGN KEY (transactionID) REFERENCES property_transactions (transactionID) ON DELETE CASCADE,
    CONSTRAINT fk_agent_reviews_client
        FOREIGN KEY (clientID) REFERENCES users (userID) ON DELETE CASCADE,
    CONSTRAINT fk_agent_reviews_agent
        FOREIGN KEY (agentID) REFERENCES users (userID) ON DELETE SET NULL
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

-- Available property listings for each supported category.
INSERT INTO properties (
    title,
    description,
    price,
    pricePerSqm,
    location,
    locationID,
    areaSqm,
    propertyType,
    landCharacteristics,
    status,
    image
)
SELECT
    demo.title,
    demo.description,
    demo.price,
    demo.pricePerSqm,
    'Batangas City, Batangas',
    location.locationID,
    demo.areaSqm,
    demo.propertyType,
    demo.landCharacteristics,
    'Available',
    demo.image
FROM (
    SELECT
        'Residential Lot in Batangas City' AS title,
        'Residential lot in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.' AS description,
        1500000.00 AS price,
        3000.00 AS pricePerSqm,
        500.00 AS areaSqm,
        'Residential' AS propertyType,
        'Residential lot in Batangas City.' AS landCharacteristics,
        'representative-residential-05.jpg' AS image
    UNION ALL SELECT
        'Commercial Property in Batangas City',
        'Commercial property in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        5000000.00, 10000.00, 500.00, 'Commercial',
        'Commercial property in Batangas City.',
        'representative-commercial-05.jpg'
    UNION ALL SELECT
        'Farm Lot in Batangas City',
        'Farm lot in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        2000000.00, 1000.00, 2000.00, 'Farmlot',
        'Farm lot in Batangas City.',
        'representative-farm-05.jpg'
    UNION ALL SELECT
        'Beach Lot in Batangas City',
        'Beach lot in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        4000000.00, 4000.00, 1000.00, 'Beachlot',
        'Beach lot in Batangas City.',
        'representative-beach-06.jpg'
    UNION ALL SELECT
        'Condo Unit in Batangas City',
        'Condo unit in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        3500000.00, 70000.00, 50.00, 'Condo',
        'Condo unit with a listed floor area of 50 sq.m.',
        'representative-condo-04.jpg'
    UNION ALL SELECT
        'House and Lot in Batangas City',
        'House and lot property in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        4500000.00, 18000.00, 250.00, 'House and Lot',
        'House and lot property with a listed lot area of 250 sq.m.',
        'representative-residential-06.jpg'
    UNION ALL SELECT
        'Townhouse in Batangas City',
        'Townhouse property in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        3200000.00, 20000.00, 160.00, 'Townhouse',
        'Townhouse with a listed area of 160 sq.m.',
        'representative-residential-07.jpg'
    UNION ALL SELECT
        'Apartment in Batangas City',
        'Apartment property in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        2500000.00, 20000.00, 125.00, 'Apartment',
        'Apartment property with a listed area of 125 sq.m.',
        'representative-condo-05.jpg'
    UNION ALL SELECT
        'Warehouse in Batangas City',
        'Warehouse property in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        8000000.00, 8000.00, 1000.00, 'Warehouse',
        'Warehouse property with a listed area of 1,000 sq.m.',
        'representative-commercial-06.jpg'
    UNION ALL SELECT
        'Office Space in Batangas City',
        'Office space in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        2800000.00, 28000.00, 100.00, 'Office Space',
        'Office space with a listed floor area of 100 sq.m.',
        'representative-commercial-07.jpg'
    UNION ALL SELECT
        'Resort in Batangas City',
        'Resort property in Batangas City, Batangas. Contact our team to confirm availability and arrange a viewing.',
        12000000.00, 12000.00, 1000.00, 'Resort',
        'Resort property with a listed land area of 1,000 sq.m.',
        'representative-beach-07.jpg'
) AS demo
JOIN property_locations AS location
    ON location.municipalityName = 'Batangas City'
    AND location.provinceName = 'Batangas'
    AND location.isActive = 1
    AND location.isDeleted = 0
WHERE NOT EXISTS (
    SELECT 1
    FROM properties AS existing
    WHERE existing.title = demo.title
      AND existing.propertyType = demo.propertyType
);

-- Keep every current available listing on a distinct, credited representative photo.
UPDATE properties AS property
JOIN (
    SELECT 'Punta Fuego Coastal Sea-View Lot' AS title, 'Beachlot' AS propertyType, 'representative-beach-01.jpg' AS image
    UNION ALL SELECT 'White Sand Beachfront Leisure Lot', 'Beachlot', 'representative-beach-02.jpg'
    UNION ALL SELECT 'Playa Calatagan Beachside Residential Lot', 'Beachlot', 'representative-beach-03.jpg'
    UNION ALL SELECT 'Private Cove Beachfront Estate', 'Beachlot', 'representative-beach-04.jpg'
    UNION ALL SELECT 'Matabungkay Sandy Coastal Lot', 'Beachlot', 'representative-beach-05.jpg'
    UNION ALL SELECT 'Beach Lot in Batangas City', 'Beachlot', 'representative-beach-06.jpg'
    UNION ALL SELECT 'Resort in Batangas City', 'Resort', 'representative-beach-07.jpg'
    UNION ALL SELECT 'Tagaytay-Nasugbu Highway Commercial Lot', 'Commercial', 'representative-commercial-01.jpg'
    UNION ALL SELECT 'Ayala Highway Commercial Corner Parcel', 'Commercial', 'representative-commercial-02.jpg'
    UNION ALL SELECT 'Port Access Industrial & Commercial Lot', 'Commercial', 'representative-commercial-03.jpg'
    UNION ALL SELECT 'Pallocan Commercial Strip Land Parcel', 'Commercial', 'representative-commercial-04.jpg'
    UNION ALL SELECT 'Commercial Property in Batangas City', 'Commercial', 'representative-commercial-05.jpg'
    UNION ALL SELECT 'Warehouse in Batangas City', 'Warehouse', 'representative-commercial-06.jpg'
    UNION ALL SELECT 'Office Space in Batangas City', 'Office Space', 'representative-commercial-07.jpg'
    UNION ALL SELECT 'Canyon Cove 2-Bedroom Beachfront Condo', 'Condo', 'representative-condo-01.jpg'
    UNION ALL SELECT 'Lipa City CBD Studio Condominium', 'Condo', 'representative-condo-02.jpg'
    UNION ALL SELECT 'Batangas Bay 1-Bedroom Executive Condo', 'Condo', 'representative-condo-03.jpg'
    UNION ALL SELECT 'Condo Unit in Batangas City', 'Condo', 'representative-condo-04.jpg'
    UNION ALL SELECT 'Apartment in Batangas City', 'Apartment', 'representative-condo-05.jpg'
    UNION ALL SELECT 'Mount Batulao Foothills Agro-Farm Parcel', 'Farmlot', 'representative-farm-01.jpg'
    UNION ALL SELECT 'Highland Eco-Farm & Hobby Orchard', 'Farmlot', 'representative-farm-02.jpg'
    UNION ALL SELECT 'Calatagan Agri-Tourism Coastal Farm', 'Farmlot', 'representative-farm-03.jpg'
    UNION ALL SELECT 'Sugarcane & Fruit Agro-Lot', 'Farmlot', 'representative-farm-04.jpg'
    UNION ALL SELECT 'Farm Lot in Batangas City', 'Farmlot', 'representative-farm-05.jpg'
    UNION ALL SELECT 'Sandari Batulao Eco-Residential Lot', 'Residential', 'representative-residential-01.jpg'
    UNION ALL SELECT 'Baseview Homes Corner Residential Lot', 'Residential', 'representative-residential-02.jpg'
    UNION ALL SELECT 'Peninsula Breeze Residential Lot', 'Residential', 'representative-residential-03.jpg'
    UNION ALL SELECT 'Grand Heights Subdivision Residential Lot', 'Residential', 'representative-residential-04.jpg'
    UNION ALL SELECT 'Residential Lot in Batangas City', 'Residential', 'representative-residential-05.jpg'
    UNION ALL SELECT 'House and Lot in Batangas City', 'House and Lot', 'representative-residential-06.jpg'
    UNION ALL SELECT 'Townhouse in Batangas City', 'Townhouse', 'representative-residential-07.jpg'
) AS assigned_images
    ON assigned_images.title = property.title
    AND assigned_images.propertyType = property.propertyType
SET property.image = assigned_images.image
WHERE property.status = 'Available'
  AND property.isDeleted = 0;
