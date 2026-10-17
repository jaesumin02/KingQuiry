-- One-time location/area migration for an existing legacy KingQuiry database.
-- For a new database, import schema.sql instead; do not run this ALTER twice.

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

ALTER TABLE properties
    ADD COLUMN locationID INT UNSIGNED NULL AFTER location,
    ADD COLUMN barangay VARCHAR(120) NULL AFTER locationID,
    ADD COLUMN areaSqm DECIMAL(12,2) NULL AFTER barangay,
    ADD KEY idx_properties_location (locationID),
    ADD CONSTRAINT fk_properties_location
        FOREIGN KEY (locationID)
        REFERENCES property_locations (locationID)
        ON UPDATE CASCADE
        ON DELETE SET NULL;
