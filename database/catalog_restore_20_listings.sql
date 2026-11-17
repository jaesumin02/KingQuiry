START TRANSACTION;

UPDATE properties
SET isDeleted = 1, deletedAt = COALESCE(deletedAt, CURRENT_TIMESTAMP)
WHERE LEFT(title, 8) = '[SAMPLE]' AND isDeleted = 0;

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 4, 'White Sand Beachfront Leisure Lot',
       'Direct beachfront property with fine light sand and gentle surf. Ideal for boutique beach resort, eco-glamping, or private family retreat.',
       8900000.00, 14833.33, 'Nasugbu, Batangas', 19, 'Natipuan', 600.00, 'Beachlot',
       'Direct beach frontage of 22 meters, flat sandy terrain, freshwater well source',
       'Available', 'representative-beach-01.jpg', '2026-11-13 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 4);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 6, 'Tagaytay-Nasugbu Highway Commercial Lot',
       'High-visibility commercial parcel located along the main tourist corridor. Perfect for a restaurant destination, petrol station, or retail pitstop.',
       16500000.00, 13750.00, 'Nasugbu, Batangas', 19, 'Kaylaway', 1200.00, 'Commercial',
       '38-meter national highway frontage, flat grading, 3-phase electricity available',
       'Available', 'representative-commercial-03.jpg', '2026-11-11 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 6);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 7, 'Sandari Batulao Eco-Residential Lot',
       'Serene residential lot in a wellness-oriented mountain community. Enjoy cool mountain air, nature trails, and organic community gardens.',
       2850000.00, 9500.00, 'Nasugbu, Batangas', 19, 'Kaylaway', 300.00, 'Residential',
       'Flat corner lot, masterplanned drainage, gated subdivision with roving security',
       'Available', 'representative-residential-02.jpg', '2026-11-10 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 7);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 11, 'Ayala Highway Commercial Corner Parcel',
       'Strategic commercial corner along the busiest commercial strip of Lipa. High vehicular volume suitable for bank, showroom, fast-food franchise, or medical center.',
       24000000.00, 30000.00, 'Lipa City, Batangas', 14, 'Marawoy', 800.00, 'Commercial',
       'Corner lot with dual road frontage, flat terrain, complete commercial utility connections',
       'Available', 'representative-commercial-04.jpg', '2026-11-12 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 11);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 12, 'Baseview Homes Corner Residential Lot',
       'Affordable corner lot inside an established, friendly residential neighborhood near Robinsons Lipa. Clean title, tax declaration updated.',
       2100000.00, 10500.00, 'Lipa City, Batangas', 14, 'Banay-banay', 200.00, 'Residential',
       'Corner orientation, flat elevation, gated community with roving security and playground',
       'Available', 'representative-residential-03.jpg', '2026-11-11 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 12);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 14, 'Private Cove Beachfront Estate',
       'Rare beachfront property on a secluded cove with calm crystal-clear swimming waters and living coral reef. Ideal for ultra-luxury private villa.',
       14800000.00, 18500.00, 'Calatagan, Batangas', 8, 'Bagong Silang', 800.00, 'Beachlot',
       'Direct 30-meter beach frontage, white sand, sunset orientation, private road easement',
       'Available', 'representative-beach-03.jpg', '2026-11-13 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 14);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 15, 'Calatagan Agri-Tourism Coastal Farm',
       'Expansive agricultural land located 5 minutes from Calatagan golf club and lighthouse. Excellent investment for glamping or dragon fruit farming.',
       7200000.00, 900.00, 'Calatagan, Batangas', 8, 'Biga', 8000.00, 'Farmlot',
       'Flat agricultural parcel, fenced, electricity along the road, good ground water source',
       'Available', 'representative-farm-03.jpg', '2026-11-12 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 15);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 16, 'Peninsula Breeze Residential Lot',
       'Quiet subdivision lot located within Calatagan town proper with easy access to municipal hall, market, and boat slipways.',
       1650000.00, 6875.00, 'Calatagan, Batangas', 8, 'Poblacion', 240.00, 'Residential',
       'Flat terrain, complete neighborhood utilities, quiet street, clean title',
       'Available', 'representative-residential-04.jpg', '2026-11-11 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 16);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 19, 'Batangas Bay 1-Bedroom Executive Condo',
       'Premium high-rise condominium unit overlooking the vibrant harbor. Turnkey condition with modern fixtures, fitness gym, and rooftop lounge.',
       3450000.00, 76666.67, 'Batangas City, Batangas', 5, 'Kumintang Ibaba', 45.00, 'Condo',
       '7th floor harbor view, standby power generator, fiber internet ready, 1 parking slot included',
       'Available', 'representative-condo-03.jpg', '2026-11-12 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 19);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 21, 'Matabungkay Sandy Coastal Lot',
       'Prime beachside parcel a few minutes walk from Matabungkay Beach. Great location for rental vacation cottages or a private beach getaway home.',
       3800000.00, 9500.00, 'Lian, Batangas', 13, 'Matabungkay', 400.00, 'Beachlot',
       'Sandy soil, flat topography, accessible via cemented road, utilities connected',
       'Available', 'representative-beach-04.jpg', '2026-11-13 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 21);

INSERT INTO properties (propertyID, title, description, price, pricePerSqm, location, locationID, barangay, areaSqm, propertyType, landCharacteristics, status, image, createdAt, isDeleted)
SELECT 22, 'Sugarcane & Fruit Agro-Lot',
       'Agricultural productive farm parcel near the Lian river basin with excellent irrigation and fertile ground. Accessible by farm-to-market road.',
       3100000.00, 1033.33, 'Lian, Batangas', 13, 'Binubusan', 3000.00, 'Farmlot',
       'Flat agricultural land, year-round water source, peaceful rural environment',
       'Available', 'representative-farm-04.jpg', '2026-11-10 15:31:58', 0
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM properties WHERE propertyID = 22);

COMMIT;
