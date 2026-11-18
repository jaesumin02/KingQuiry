-- ==============================================================================
-- KingQuiry Capstone Presentation Demo Seed Data (Idempotent)
-- Kings Land Properties
--
-- This script creates dedicated, safe demo test accounts and sample listings
-- for final presentation and defense dry-runs.
-- IT DOES NOT OVERWRITE OR DELETE REAL CLIENT RECORDS.
--
-- DEMO ACCOUNTS CREATED:
-- 1. Client Account:
--    Email:    client.demo@kingquiry.ph
--    Password: Client123!
--    Role:     Client
--
-- 2. Sales Agent Account:
--    Email:    agent.demo@kingquiry.ph
--    Password: Agent123!
--    Role:     Agent
--
-- 3. System Administrator Account:
--    Email:    admin.demo@kingquiry.ph
--    Password: Admin123!
--    Role:     Admin
-- ==============================================================================

-- 1. INSERT OR UPDATE DEMO ACCOUNTS
INSERT INTO users (fullname, email, phone, address, passwordHash, role, accountStatus)
VALUES 
    (
        'Jasmine Ramos (Demo Client)',
        'client.demo@kingquiry.ph',
        '0917 123 4567',
        'Nasugbu, Batangas',
        '$2y$10$rL7J5LDZT2AX1IdhURYvE.M.lybUgLjJ/nW44OFY9fP4dBTGutxPm', -- Client123!
        'Client',
        'Active'
    ),
    (
        'Brent Marc Alcover (Demo Agent)',
        'agent.demo@kingquiry.ph',
        '0965 803 0933',
        'Kings Land Main Office, Nasugbu, Batangas',
        '$2y$10$.YJK/xWsfc8QQ4H3/BO4..HqVEhdtRHAGKrZrdWp0Z9jV1NIe.aZW', -- Agent123!
        'Agent',
        'Active'
    ),
    (
        'Zydryx Policarpio (Demo Admin)',
        'admin.demo@kingquiry.ph',
        '0965 803 0933',
        'Kings Land Headquarters, Nasugbu, Batangas',
        '$2y$10$gwU0dvgecMtyCoUyJMyeSOneY4HztT.4pBWpr9tcpfnFKGA4OJ/zO', -- Admin123!
        'Admin',
        'Active'
    )
ON DUPLICATE KEY UPDATE 
    fullname = VALUES(fullname),
    phone = VALUES(phone),
    address = VALUES(address),
    passwordHash = VALUES(passwordHash),
    role = VALUES(role),
    accountStatus = 'Active';

-- 2. INSERT DEMO PROPERTIES (1 Available, 1 Sold)
-- Get a default Nasugbu locationID if exists
SET @nasugbuLocationID = (SELECT locationID FROM property_locations WHERE municipalityName LIKE '%Nasugbu%' LIMIT 1);
SET @lipaLocationID = (SELECT locationID FROM property_locations WHERE municipalityName LIKE '%Lipa%' LIMIT 1);

INSERT INTO properties (
    title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, status, image
)
VALUES
    (
        'Demo — Horizon View Residential Parcel in Nasugbu',
        'Prime titled residential land ideal for vacation homes or private villas. Clean title, road right-of-way, water and electricity connections available on site. Settle down payment within 7 days of reservation under standard Contract to Sell terms.',
        2400000.00,
        8000.00,
        'Nasugbu, Batangas',
        @nasugbuLocationID,
        'Munting Indang',
        300.00,
        'Residential',
        'Available',
        'representative-residential-01.jpg'
    ),
    (
        'Demo — Port Access Commercial Lot (Sold)',
        'Commercial parcel with highway access near port logistics hubs. Transaction closed, Deed of Absolute Sale (DOAS) executed, and property officially turned over to the client.',
        5000000.00,
        10000.00,
        'Batangas City, Batangas',
        @lipaLocationID,
        'Bolbok',
        500.00,
        'Commercial',
        'Sold',
        'representative-commercial-01.jpg'
    )
ON DUPLICATE KEY UPDATE 
    price = VALUES(price),
    status = VALUES(status);

-- 3. LOG AN AUDIT TRAIL ENTRY FOR SETUP
SET @adminUserId = (SELECT userID FROM users WHERE email = 'admin.demo@kingquiry.ph' LIMIT 1);

INSERT INTO auditlogs (userID, action, timestamp)
VALUES (
    @adminUserId,
    'Initialized capstone presentation demo accounts and seed listings (Client, Agent, Admin).',
    NOW()
);
