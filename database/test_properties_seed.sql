-- ==============================================================================
-- Kings Land Properties (Philippines) — Test Properties Seed Dataset
-- ==============================================================================
-- Purpose:
--   Populates realistic property listings across Batangas with varied density:
--     - High volume (4-6 listings): Nasugbu, Lipa City, Calatagan, Batangas City
--     - Low volume (1-2 listings):  Lian, San Juan, Mabini, Taal, Santo Tomas City,
--                                   Tanauan City, Balayan, Tuy, Cuenca, Malvar
--     - Zero volume (0 listings):   Lemery, Bauan, Lobo, Ibaan, Agoncillo, Talisay,
--                                   etc. (to verify "No matching properties" empty state)
--     - All 5 Kings Land categories: Residential Lot, Farm Lot, Beach Lot, Condo, Commercial
-- ==============================================================================

USE `kingquiry_db`;

-- Optional: Uncomment the following line if you want to clear existing properties before inserting
-- DELETE FROM `properties`;

INSERT INTO `properties` 
    (`title`, `description`, `price`, `pricePerSqm`, `location`, `locationID`, `barangay`, `areaSqm`, `propertyType`, `landCharacteristics`, `status`, `image`, `createdAt`)
VALUES

-- ══════════════════════════════════════════════════════════════════════════════
-- 1. NASUGBU (HIGH VOLUME — Beachfront, Condos, Scenic Land)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Punta Fuego Coastal Sea-View Lot',
    'Spectacular elevated coastal parcel inside an exclusive peninsula enclave. Unobstructed 180-degree sunset and sea views with access to private yacht club, white sand beaches, and golf greens.',
    12500000.00,
    25000.00,
    'Nasugbu, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Nasugbu' LIMIT 1),
    'Balaytigue',
    500.00,
    'Beachlot',
    'Elevated sloping terrain, paved 8-meter road, underground utilities, 24/7 guarded gate',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 1 DAY
),
(
    'Canyon Cove 2-Bedroom Beachfront Condo',
    'Fully furnished resort condominium unit facing the West Philippine Sea. Features wrap-around balcony, resort pool privileges, and strong rental yields on Airbnb.',
    5900000.00,
    84285.71,
    'Nasugbu, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Nasugbu' LIMIT 1),
    'Wawa',
    70.00,
    'Condo',
    'Mid-rise beachfront development, balcony facing bay, parking slot, backup generator',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 2 DAY
),
(
    'White Sand Beachfront Leisure Lot',
    'Direct beachfront property with fine light sand and gentle surf. Ideal for boutique beach resort, eco-glamping, or private family retreat.',
    8900000.00,
    14833.33,
    'Nasugbu, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Nasugbu' LIMIT 1),
    'Natipuan',
    600.00,
    'Beachlot',
    'Direct beach frontage of 22 meters, flat sandy terrain, freshwater well source',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 3 DAY
),
(
    'Mount Batulao Foothills Agro-Farm Parcel',
    'Fertile rolling agricultural land with cool mountain breezes and panoramic landscape. Planted with fruiting mango, avocado, and coffee trees.',
    4800000.00,
    1200.00,
    'Nasugbu, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Nasugbu' LIMIT 1),
    'Kaylaway',
    4000.00,
    'Farmlot',
    'Gently rolling topography, rich volcanic loamy soil, year-round cool breeze, concrete road access',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 4 DAY
),
(
    'Tagaytay-Nasugbu Highway Commercial Lot',
    'High-visibility commercial parcel located along the main tourist corridor. Perfect for a restaurant destination, petrol station, or retail pitstop.',
    16500000.00,
    13750.00,
    'Nasugbu, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Nasugbu' LIMIT 1),
    'Kaylaway',
    1200.00,
    'Commercial',
    '38-meter national highway frontage, flat grading, 3-phase electricity available',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 5 DAY
),
(
    'Sandari Batulao Eco-Residential Lot',
    'Serene residential lot in a wellness-oriented mountain community. Enjoy cool mountain air, nature trails, and organic community gardens.',
    2850000.00,
    9500.00,
    'Nasugbu, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Nasugbu' LIMIT 1),
    'Kaylaway',
    300.00,
    'Residential',
    'Flat corner lot, masterplanned drainage, gated subdivision with roving security',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 6 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 2. LIPA CITY (HIGH VOLUME — Residential, Farmlots, Commercial, Condos)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Ayala Greenfield-Style Golf Residential Lot',
    'Premium residential lot in a high-end gated subdivision near Mount Malarayat. Cool highland climate year-round, clean title, ready for architectural design.',
    4500000.00,
    12857.14,
    'Lipa City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Lipa City' LIMIT 1),
    'Dagatan',
    350.00,
    'Residential',
    'Rectangular flat lot, wide curbs and concrete sidewalks, clubhouse and swimming pool access',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 1 DAY
),
(
    'Lipa City CBD Studio Condominium',
    'Modern urban studio condo within walking distance to De La Salle Lipa and shopping centers. Outstanding investment for rental income.',
    2650000.00,
    94642.86,
    'Lipa City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Lipa City' LIMIT 1),
    'Mataas na Lupa',
    28.00,
    'Condo',
    '10th floor with mountain view, infinity pool, fitness center, 24/7 concierge',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 2 DAY
),
(
    'Highland Eco-Farm & Hobby Orchard',
    'Sprawling agricultural parcel with rich volcanic soil and mature rambutan, lanzones, and durian trees. Includes perimeter fencing and deep-well water pump.',
    6200000.00,
    1240.00,
    'Lipa City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Lipa City' LIMIT 1),
    'Lodlod',
    5000.00,
    'Farmlot',
    'Flat to gentle slope, fertile soil, concrete barangay road access, high-speed fiber internet ready',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 3 DAY
),
(
    'Ayala Highway Commercial Corner Parcel',
    'Strategic commercial corner along the busiest commercial strip of Lipa. High vehicular volume suitable for bank, showroom, fast-food franchise, or medical center.',
    24000000.00,
    30000.00,
    'Lipa City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Lipa City' LIMIT 1),
    'Marawoy',
    800.00,
    'Commercial',
    'Corner lot with dual road frontage, flat terrain, complete commercial utility connections',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 4 DAY
),
(
    'Baseview Homes Corner Residential Lot',
    'Affordable corner lot inside an established, friendly residential neighborhood near Robinsons Lipa. Clean title, tax declaration updated.',
    2100000.00,
    10500.00,
    'Lipa City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Lipa City' LIMIT 1),
    'Banay-banay',
    200.00,
    'Residential',
    'Corner orientation, flat elevation, gated community with roving security and playground',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 5 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 3. CALATAGAN (HIGH VOLUME — Beachfronts, Peninsula Farmlots)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Playa Calatagan Beachside Residential Lot',
    'Exclusive resort community lot just a short stroll from the beach clubhouse and waterpark. Build your dream family holiday home by the sea.',
    5200000.00,
    14857.14,
    'Calatagan, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Calatagan' LIMIT 1),
    'Santa Ana',
    350.00,
    'Beachlot',
    'Flat topography, walking distance to beach, underground drainage, 24/7 security',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 1 DAY
),
(
    'Private Cove Beachfront Estate',
    'Rare beachfront property on a secluded cove with calm crystal-clear swimming waters and living coral reef. Ideal for ultra-luxury private villa.',
    14800000.00,
    18500.00,
    'Calatagan, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Calatagan' LIMIT 1),
    'Bagong Silang',
    800.00,
    'Beachlot',
    'Direct 30-meter beach frontage, white sand, sunset orientation, private road easement',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 3 DAY
),
(
    'Calatagan Agri-Tourism Coastal Farm',
    'Expansive agricultural land located 5 minutes from Calatagan golf club and lighthouse. Excellent investment for glamping or dragon fruit farming.',
    7200000.00,
    900.00,
    'Calatagan, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Calatagan' LIMIT 1),
    'Biga',
    8000.00,
    'Farmlot',
    'Flat agricultural parcel, fenced, electricity along the road, good ground water source',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 4 DAY
),
(
    'Peninsula Breeze Residential Lot',
    'Quiet subdivision lot located within Calatagan town proper with easy access to municipal hall, market, and boat slipways.',
    1650000.00,
    6875.00,
    'Calatagan, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Calatagan' LIMIT 1),
    'Poblacion',
    240.00,
    'Residential',
    'Flat terrain, complete neighborhood utilities, quiet street, clean title',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 5 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 4. BATANGAS CITY (HIGH VOLUME — Capital City Commercial, Port, Condos, Subdivisions)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Port Access Industrial & Commercial Lot',
    'Prime commercial/industrial land situated near the Batangas International Port and STAR Tollway exit. High-capacity container truck accessible.',
    28500000.00,
    19000.00,
    'Batangas City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Batangas City' LIMIT 1),
    'Santa Clara',
    1500.00,
    'Commercial',
    'Wide frontage, 3-phase heavy industrial power, deep truck turning radius, fully concrete road',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 1 DAY
),
(
    'Grand Heights Subdivision Residential Lot',
    'Elevated residential lot offering sweeping city lights views. Located in one of the most secure and established executive communities in the capital.',
    3200000.00,
    11428.57,
    'Batangas City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Batangas City' LIMIT 1),
    'Alangilan',
    280.00,
    'Residential',
    'Elevated flood-free position, concrete curbs, clubhouse, active homeowners association',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 2 DAY
),
(
    'Batangas Bay 1-Bedroom Executive Condo',
    'Premium high-rise condominium unit overlooking the vibrant harbor. Turnkey condition with modern fixtures, fitness gym, and rooftop lounge.',
    3450000.00,
    76666.67,
    'Batangas City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Batangas City' LIMIT 1),
    'Kumintang Ibaba',
    45.00,
    'Condo',
    '7th floor harbor view, standby power generator, fiber internet ready, 1 parking slot included',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 4 DAY
),
(
    'Pallocan Commercial Strip Land Parcel',
    'High-traffic commercial lot near SM City Batangas and major corporate banks. Ideal for low-rise office building or multi-unit commercial rental.',
    19800000.00,
    33000.00,
    'Batangas City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Batangas City' LIMIT 1),
    'Pallocan West',
    600.00,
    'Commercial',
    '20-meter road frontage, commercial zoning, flat paved topography, high foot traffic',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 5 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 5. LIAN (LOW VOLUME — 2 Listings)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Matabungkay Sandy Coastal Lot',
    'Prime beachside parcel a few minutes walk from Matabungkay Beach. Great location for rental vacation cottages or a private beach getaway home.',
    3800000.00,
    9500.00,
    'Lian, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Lian' LIMIT 1),
    'Matabungkay',
    400.00,
    'Beachlot',
    'Sandy soil, flat topography, accessible via cemented road, utilities connected',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 3 DAY
),
(
    'Sugarcane & Fruit Agro-Lot',
    'Agricultural productive farm parcel near the Lian river basin with excellent irrigation and fertile ground. Accessible by farm-to-market road.',
    3100000.00,
    1033.33,
    'Lian, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Lian' LIMIT 1),
    'Binubusan',
    3000.00,
    'Farmlot',
    'Flat agricultural land, year-round water source, peaceful rural environment',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 6 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 6. SAN JUAN / LAIYA (LOW VOLUME — 2 Listings)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Laiya White Sand Beach Resort Lot',
    'Exceptional coastal land in the heart of Laiya resort corridor. Clean waters, white sand, and established tourism zone with high weekend traffic.',
    11500000.00,
    23000.00,
    'San Juan, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'San Juan' LIMIT 1),
    'Laiya-Aplaya',
    500.00,
    'Beachlot',
    'Beach frontage, resort zone zoning, concrete access road, high tourism footfall',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 2 DAY
),
(
    'San Juan Organic Coconut Agro-Farm',
    'Peaceful farm property planted with over 150 mature coconut trees and fruit-bearing bananas. Just 15 minutes away from Laiya coast.',
    4500000.00,
    900.00,
    'San Juan, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'San Juan' LIMIT 1),
    'Abung',
    5000.00,
    'Farmlot',
    'Gently undulating terrain, clean title, fenced perimeter, roadside electricity',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 7 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 7. MABINI / ANILAO (LOW VOLUME — 2 Listings)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Anilao Cliffside Ocean-View Dive Lot',
    'Dramatic oceanfront cliff lot in world-famous Anilao dive sanctuary. Perfect for specialized scuba dive resort or private coastal sanctuary.',
    9200000.00,
    20444.44,
    'Mabini, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Mabini' LIMIT 1),
    'Ligaya',
    450.00,
    'Beachlot',
    'Rocky coastal terrain with direct marine sanctuary access, stunning sunsets over Tingloy Island',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 4 DAY
),
(
    'Mabini Ridge Residential Lot',
    'Quiet residential lot overlooking Balayan Bay and Batangas channel. Enjoy cool sea breeze and tranquil surroundings.',
    1950000.00,
    7800.00,
    'Mabini, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Mabini' LIMIT 1),
    'Solo',
    250.00,
    'Residential',
    'Elevated terrain, overlooking sea view, concrete municipal road frontage',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 8 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 8. TAAL (LOW VOLUME — 1 Listing)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Heritage Town Center Commercial Lot',
    'Character commercial property located in the historic heritage town of Taal, walking distance to the famous Basilica. Ideal for boutique hotel, cafe, or antique gallery.',
    6800000.00,
    17000.00,
    'Taal, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Taal' LIMIT 1),
    'Poblacion Zone 6',
    400.00,
    'Commercial',
    'Heritage architectural zone, cobblestone curb access, high tourist footfall, clean title',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 3 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 9. SANTO TOMAS CITY (LOW VOLUME — 1 Listing)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'FPIP Vicinity Industrial & Commercial Parcel',
    'Strategic commercial lot adjacent to First Philippine Industrial Park. Tremendous demand for logistics, commissary, staff housing, or convenience commercial center.',
    14500000.00,
    18125.00,
    'Santo Tomas City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Santo Tomas City' LIMIT 1),
    'San Rafael',
    800.00,
    'Commercial',
    'Direct highway feeder access, flat terrain, heavy power and utility lines accessible',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 5 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 10. TANAUAN CITY (LOW VOLUME — 1 Listing)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Mount Makiling View Subdivision Lot',
    'Spacious residential lot located in an upscale gated community with uninterrupted views of Mount Makiling. Accessible via STAR Tollway Tanauan exit.',
    2750000.00,
    11000.00,
    'Tanauan City, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Tanauan City' LIMIT 1),
    'Darasa',
    250.00,
    'Residential',
    'Flat topography, corner position, security-monitored entrance gate, community park',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 6 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 11. BALAYAN (LOW VOLUME — 1 Listing)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Balayan Bayfront Town Commercial Land',
    'Strategic corner commercial parcel near Balayan public market and transport terminal. Excellent customer visibility and high daytime commercial traffic.',
    9800000.00,
    16333.33,
    'Balayan, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Balayan' LIMIT 1),
    'District I',
    600.00,
    'Commercial',
    'Corner orientation, dual street frontage, completely flat, clean title',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 7 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 12. TUY (LOW VOLUME — 1 Listing)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Tuy Highway Agricultural & Commercial Lot',
    'Versatile roadfront parcel along the national highway connecting Tagaytay to Western Batangas. Perfect for warehouse, farm-to-table dining, or agri-trading post.',
    5200000.00,
    2600.00,
    'Tuy, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Tuy' LIMIT 1),
    'Poblacion',
    2000.00,
    'Farmlot',
    'Wide national highway frontage, flat topography, electricity and water available',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 8 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 13. CUENCA (LOW VOLUME — 1 Listing)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Mount Maculot View Highland Farm Lot',
    'Idyllic hobby farm situated in the shadows of Mount Maculot. Enjoy cool breezes, rich coffee and black pepper planting ground, and peaceful mountain vibes.',
    3600000.00,
    1440.00,
    'Cuenca, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Cuenca' LIMIT 1),
    'Dita',
    2500.00,
    'Farmlot',
    'Gentle slope with direct Mount Maculot view, fenced perimeter, paved road access',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 9 DAY
),

-- ══════════════════════════════════════════════════════════════════════════════
-- 14. MALVAR (LOW VOLUME — 1 Listing)
-- ══════════════════════════════════════════════════════════════════════════════
(
    'Malvar Light Industrial Park Corridor Lot',
    'Commercial and light industrial parcel near LIMA Technology Center. High growth zone with strong logistics and residential worker catchment.',
    7800000.00,
    13000.00,
    'Malvar, Batangas',
    (SELECT locationID FROM property_locations WHERE municipalityName = 'Malvar' LIMIT 1),
    'Santiago',
    600.00,
    'Commercial',
    'Paved wide road frontage, level flat ground, 3-phase power accessible',
    'Available',
    'default.jpg',
    NOW() - INTERVAL 10 DAY
);

-- ==============================================================================
-- 15. ZERO-VOLUME LOCATIONS (INTENTIONALLY LEFT WITH NO LISTINGS)
-- ==============================================================================
-- The following municipalities have ZERO listings to test the "No matching properties yet"
-- empty-state UI, map centering on empty locations, and the "Clear filters" flow:
--
--   1.  Agoncillo
--   2.  Alitagtag
--   3.  Balete
--   4.  Bauan
--   5.  Calaca City
--   6.  Ibaan
--   7.  Laurel
--   8.  Lemery
--   9.  Lobo
--   10. Mataasnakahoy
--   11. Padre Garcia
--   12. Rosario
--   13. San Jose
--   14. San Luis
--   15. San Nicolas
--   16. San Pascual
--   17. Santa Teresita
--   18. Talisay
--   19. Taysan
--   20. Tingloy
-- ==============================================================================
