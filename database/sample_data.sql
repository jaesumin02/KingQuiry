-- ═══════════════════════════════════════════════════════════════
-- KingQuiry Optional Sample Property Data
-- ═══════════════════════════════════════════════════════════════
-- Import this file AFTER importing schema.sql only if you want
-- starter / demonstration property listings in Batangas City.
-- ═══════════════════════════════════════════════════════════════

USE `kingquiry_db`;

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

-- Optional Multi-Photo Gallery for Sample Properties
INSERT INTO property_images (propertyID, imagePath, caption, sortOrder, isPrimary)
SELECT p.propertyID, g.imagePath, g.caption, g.sortOrder, g.isPrimary
FROM properties p
JOIN (
    SELECT 'Residential Lot in Batangas City' AS title, 'representative-residential-01.jpg' AS imagePath, 'Neighborhood frontage' AS caption, 1 AS sortOrder, 1 AS isPrimary
    UNION ALL SELECT 'Residential Lot in Batangas City', 'representative-residential-02.jpg', 'Lot elevation & terrain', 2, 0
    UNION ALL SELECT 'Residential Lot in Batangas City', 'representative-residential-03.jpg', 'Access street view', 3, 0
    UNION ALL SELECT 'Residential Lot in Batangas City', 'representative-residential-05.jpg', 'Corner boundary view', 4, 0
    UNION ALL SELECT 'Farm Lot in Batangas City', 'representative-farm-01.jpg', 'Main lot & open landscape', 1, 1
    UNION ALL SELECT 'Farm Lot in Batangas City', 'representative-farm-02.jpg', 'Cultivated terrain', 2, 0
    UNION ALL SELECT 'Farm Lot in Batangas City', 'representative-farm-03.jpg', 'Access pathway', 3, 0
    UNION ALL SELECT 'Farm Lot in Batangas City', 'representative-farm-05.jpg', 'Surrounding tree line', 4, 0
    UNION ALL SELECT 'Beach Lot in Batangas City', 'representative-beach-06.jpg', 'Shoreline frontage', 1, 1
    UNION ALL SELECT 'Beach Lot in Batangas City', 'representative-beach-01.jpg', 'Coastal panoramic view', 2, 0
    UNION ALL SELECT 'Beach Lot in Batangas City', 'representative-beach-02.jpg', 'Beach boundary', 3, 0
    UNION ALL SELECT 'Beach Lot in Batangas City', 'representative-beach-03.jpg', 'Clear water shoreline', 4, 0
    UNION ALL SELECT 'Commercial Property in Batangas City', 'representative-commercial-05.jpg', 'Commercial frontage', 1, 1
    UNION ALL SELECT 'Commercial Property in Batangas City', 'representative-commercial-01.jpg', 'Open layout', 2, 0
    UNION ALL SELECT 'Commercial Property in Batangas City', 'representative-commercial-02.jpg', 'Street perspective', 3, 0
    UNION ALL SELECT 'Commercial Property in Batangas City', 'representative-commercial-03.jpg', 'Parking & loading bay', 4, 0
    UNION ALL SELECT 'Condo Unit in Batangas City', 'representative-condo-04.jpg', 'Living room & balcony', 1, 1
    UNION ALL SELECT 'Condo Unit in Batangas City', 'representative-condo-01.jpg', 'Bedroom suite', 2, 0
    UNION ALL SELECT 'Condo Unit in Batangas City', 'representative-condo-02.jpg', 'Kitchenette nook', 3, 0
    UNION ALL SELECT 'Condo Unit in Batangas City', 'representative-condo-03.jpg', 'Building amenities', 4, 0
    UNION ALL SELECT 'House and Lot in Batangas City', 'representative-residential-06.jpg', 'Exterior facade', 1, 1
    UNION ALL SELECT 'House and Lot in Batangas City', 'representative-residential-04.jpg', 'Front yard & garage', 2, 0
    UNION ALL SELECT 'House and Lot in Batangas City', 'representative-residential-02.jpg', 'Living area structure', 3, 0
    UNION ALL SELECT 'House and Lot in Batangas City', 'representative-residential-07.jpg', 'Backyard perimeter', 4, 0
    UNION ALL SELECT 'Townhouse in Batangas City', 'representative-residential-04.jpg', 'Unit exterior', 1, 1
    UNION ALL SELECT 'Townhouse in Batangas City', 'representative-residential-07.jpg', 'Living layout', 2, 0
    UNION ALL SELECT 'Townhouse in Batangas City', 'representative-residential-02.jpg', 'Upper level view', 3, 0
) g ON g.title = p.title
WHERE NOT EXISTS (
    SELECT 1 FROM property_images pi WHERE pi.propertyID = p.propertyID AND pi.imagePath = g.imagePath
);
